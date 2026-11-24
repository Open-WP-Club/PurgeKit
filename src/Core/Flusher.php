<?php
namespace PurgeKit\Core;

final class Flusher {
	private const LOCK = 'flush';
	private const TTL  = 120;

	/** URLs listed per job in a preview; the real totals are reported separately. */
	public const PREVIEW_URL_CAP = 50;

	/** @var callable(PurgePlan): void */
	private $warm;
	/** @var callable(string, Run): void|null */
	private $finished;
	/** @var callable(Run): ?array|null */
	private $verify;

	public function __construct(
		private Queue $queue,
		private Planner $planner,
		private Executor $executor,
		private Lock $lock,
		private HistoryWriter $history,
		callable $warm,
		?callable $finished = null,
		private ?RuleStore $rules = null,
		?callable $verify = null
	) {
		$this->warm     = $warm;
		$this->finished = $finished;
		$this->verify   = $verify;
	}

	/** Flush whatever is queued. With $force, purge everything even if nothing is queued. */
	public function flush( string $source, bool $force = false ): Run {
		return $this->guarded(
			$source,
			function () use ( $force ): array {
				$set     = $this->rules ? $this->rules->load() : null;
				$routed  = $set && ! $set->isEmpty();
				$changes = $this->queue->drain( ! $routed );
				$built   = $this->resolve( $changes, $routed ? $set : null, $force );
				return array( $built['plan'], $changes ) + ( null !== $built['jobs'] ? array( 2 => $built['jobs'] ) : array() );
			}
		);
	}

	/**
	 * Dry run: what the next queued flush would do, without running it and without touching the queue.
	 *
	 * @return array{pending:int,empty:bool,all:bool,urls:string[],urls_total:int,routed:bool,jobs:array<int,array<string,mixed>>,skipped:array<int,array{type:string,rule:string,urls:string[]}>}
	 */
	public function preview( bool $force = false ): array {
		$set     = $this->rules ? $this->rules->load() : null;
		$routed  = $set && ! $set->isEmpty();
		$changes = $this->queue->peek( ! $routed );
		$built   = $this->resolve( $changes, $routed ? $set : null, $force );
		$plan    = $built['plan'];

		$jobs = array();
		if ( null !== $built['jobs'] ) {
			foreach ( $built['jobs'] as $job ) {
				$jobs[] = $this->describe( $job['plan'], $job['layers'], $job['rules'] );
			}
		} elseif ( ! $plan->isEmpty() ) {
			$jobs[] = $this->describe( $plan, null, array() );
		}

		return array(
			'pending'    => count( $this->queue->peek( false ) ),
			'empty'      => array() === $jobs,
			'all'        => $plan->all,
			'urls'       => array_slice( $plan->urls, 0, self::PREVIEW_URL_CAP ),
			'urls_total' => count( $plan->urls ),
			'routed'     => (bool) $routed,
			'jobs'       => $jobs,
			'skipped'    => $built['skipped'],
		);
	}

	/**
	 * Shared by flush() and preview() so the preview cannot drift from what really happens.
	 *
	 * @param Change[] $changes
	 * @return array{plan:PurgePlan,jobs:?array,skipped:array}
	 */
	private function resolve( array $changes, ?RuleSet $set, bool $force ): array {
		if ( array() === $changes && $force ) {
			return array(
				'plan'    => PurgePlan::everything(),
				'jobs'    => null,
				'skipped' => array(),
			);
		}
		if ( $set ) {
			$routed = $set->routeDetailed( $changes, $this->planner );
			return array(
				'plan'    => $this->merge( $routed['jobs'] ),
				'jobs'    => $routed['jobs'],
				'skipped' => $routed['skipped'],
			);
		}
		return array(
			'plan'    => $this->planner->plan( $changes ),
			'jobs'    => null,
			'skipped' => array(),
		);
	}

	/**
	 * @param ?string[] $layers
	 * @param string[]  $rules
	 */
	private function describe( PurgePlan $plan, ?array $layers, array $rules ): array {
		return array(
			'rules'      => $rules,
			'all'        => $plan->all,
			'urls'       => array_slice( $plan->urls, 0, self::PREVIEW_URL_CAP ),
			'urls_total' => count( $plan->urls ),
			'layers'     => $this->executor->layersFor( $layers ),
		);
	}

	public function flushUrls( string $source, array $urls ): Run {
		return $this->guarded(
			$source,
			function () use ( $urls ): array {
				$changes = array( Change::forUrls( 'manual', $urls ) );
				return array( $this->planner->plan( $changes ), $changes );
			}
		);
	}

	/** Purge everything on one layer only (the layer map's per-layer button). */
	public function flushLayer( string $source, string $layer_id ): Run {
		return $this->guarded(
			$source,
			static function () use ( $layer_id ): array {
				$plan = PurgePlan::everything();
				return array(
					$plan,
					array(),
					array(
						array(
							'plan'   => $plan,
							'layers' => array( $layer_id ),
						),
					),
				);
			}
		);
	}

	public function flushAll( string $source ): Run {
		return $this->guarded(
			$source,
			function (): array {
				$this->queue->drain(); // Everything is being purged, so pending changes are covered.
				return array( PurgePlan::everything(), array() );
			}
		);
	}

	/** @param callable(): array{0: PurgePlan, 1: Change[], 2?: array<int,array{plan:PurgePlan,layers:?array}>} $build */
	private function guarded( string $source, callable $build ): Run {
		if ( ! $this->lock->acquire( self::LOCK, self::TTL ) ) {
			return new Run( Run::LOCKED );
		}
		$id  = 0;
		$run = new Run( Run::SKIPPED );
		try {
			$built   = $build();
			$plan    = $built[0];
			$changes = $built[1];
			$run     = isset( $built[2] ) ? $this->runJobs( $built[2], $plan ) : $this->executor->run( $plan );
			$id      = $this->history->record( $source, $run, $changes );
			if ( $this->finished ) {
				try {
					( $this->finished )( $source, $run );
				} catch ( \Throwable $e ) {
					unset( $e ); // An alert problem must never break a flush.
				}
			}
			if ( Run::SKIPPED !== $run->status ) {
				( $this->warm )( $plan );
			}
		} finally {
			$this->lock->release( self::LOCK );
		}
		$this->verify( $run, $id );
		return $run;
	}

	/** Runs after the lock is released, so slow page fetches never hold up another flush. */
	private function verify( Run $run, int $id ): void {
		if ( ! $this->verify || $id <= 0 || ! in_array( $run->status, array( Run::OK, Run::PARTIAL ), true ) ) {
			return;
		}
		try {
			$outcome = ( $this->verify )( $run );
			if ( is_array( $outcome ) ) {
				$this->history->annotate( $id, $outcome );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // A verification problem must never break a flush.
		}
	}

	/**
	 * Runs each rule job on its own layers and reports them as one run.
	 *
	 * @param array<int,array{plan:PurgePlan,layers:?array}> $jobs
	 */
	private function runJobs( array $jobs, PurgePlan $merged ): Run {
		$results = array();
		$ran     = false;
		foreach ( $jobs as $job ) {
			$run = $this->executor->run( $job['plan'], $job['layers'] );
			array_push( $results, ...$run->results );
			$ran = $ran || Run::SKIPPED !== $run->status;
		}
		if ( array() === $results ) {
			return new Run( Run::SKIPPED, array(), $merged );
		}
		// Every layer was switched off or paused: nothing was purged, so this must not read as a success.
		return $ran ? Run::fromResults( $results, $merged ) : new Run( Run::SKIPPED, $results, $merged );
	}

	/** @param array<int,array{plan:PurgePlan,layers:?array}> $jobs */
	private function merge( array $jobs ): PurgePlan {
		$all  = false;
		$urls = array();
		foreach ( $jobs as $job ) {
			$all  = $all || $job['plan']->all;
			$urls = array_merge( $urls, $job['plan']->urls );
		}
		return $all ? PurgePlan::everything() : PurgePlan::forUrls( array_values( array_unique( $urls ) ) );
	}
}
