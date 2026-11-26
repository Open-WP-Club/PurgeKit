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
	/** @var callable(PurgePlan, ?array, array, int): bool|null */
	private $defer;

	public function __construct(
		private Queue $queue,
		private Planner $planner,
		private Executor $executor,
		private Lock $lock,
		private HistoryWriter $history,
		callable $warm,
		?callable $finished = null,
		private ?RuleStore $rules = null,
		?callable $verify = null,
		?callable $defer = null
	) {
		$this->warm     = $warm;
		$this->finished = $finished;
		$this->verify   = $verify;
		$this->defer    = $defer;
	}

	/** Flush whatever is queued. With $force, purge everything even if nothing is queued. */
	public function flush( string $source, bool $force = false ): Run {
		return $this->guarded(
			$source,
			function () use ( $force ): array {
				$set     = $this->rules ? $this->rules->load() : null;
				$routed  = $set && ! $set->isEmpty();
				$changes = $this->queue->drain( false );
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
		$changes = $this->queue->peek( false );
		$built   = $this->resolve( $changes, $routed ? $set : null, $force );
		$plan    = $built['plan'];

		$jobs = array();
		if ( null !== $built['jobs'] ) {
			foreach ( $built['jobs'] as $job ) {
				$jobs[] = $this->describe( $job['plan'], $job['layers'], $job['rules'], (int) ( $job['delay'] ?? 0 ), (string) ( $job['when'] ?? Rule::ALWAYS ) );
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
	 * Dry run of a flush for given URLs, or of "purge everything" when $urls is null (what `flush --dry-run` shows).
	 *
	 * @param string[]|null $urls
	 * @return array<string,mixed> Same shape as preview().
	 */
	public function previewTarget( ?array $urls ): array {
		$plan = null === $urls ? PurgePlan::everything() : $this->planner->plan( array( Change::forUrls( 'manual', $urls ) ) );
		return array(
			'pending'    => count( $this->queue->peek( false ) ),
			'empty'      => $plan->isEmpty(),
			'all'        => $plan->all,
			'urls'       => array_slice( $plan->urls, 0, self::PREVIEW_URL_CAP ),
			'urls_total' => count( $plan->urls ),
			'routed'     => false,
			'jobs'       => $plan->isEmpty() ? array() : array( $this->describe( $plan, null, array() ) ),
			'skipped'    => array(),
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
	private function describe( PurgePlan $plan, ?array $layers, array $rules, int $delay = 0, string $when = Rule::ALWAYS ): array {
		return array(
			'rules'      => $rules,
			'delay'      => $delay,
			'when'       => $when,
			'tags'       => count( $plan->tags ),
			'all'        => $plan->all,
			'urls'       => array_slice( $plan->urls, 0, self::PREVIEW_URL_CAP ),
			'urls_total' => count( $plan->urls ),
			'layers'     => $this->executor->layersFor( $layers ),
			'reasons'    => array_map( static fn( $why ) => array_slice( $why, 0, 10 ), array_slice( $plan->reasons, 0, self::PREVIEW_URL_CAP, true ) ),
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
		return $this->flushLayers( $source, array( $layer_id ) );
	}

	/**
	 * Purge on the given layers only: everything, or just the URLs. Used by "retry the layers that failed".
	 *
	 * @param string[] $layer_ids
	 * @param string[] $urls
	 */
	public function flushLayers( string $source, array $layer_ids, array $urls = array() ): Run {
		return $this->guarded(
			$source,
			function () use ( $layer_ids, $urls ): array {
				$plan = array() === $urls ? PurgePlan::everything() : $this->planner->plan( array( Change::forUrls( 'manual', $urls ) ) );
				return array(
					$plan,
					array(),
					array(
						array(
							'plan'   => $plan,
							'layers' => $layer_ids,
						),
					),
				);
			}
		);
	}

	/** Runs a job a rule had postponed. Called by the WordPress cron event. */
	public function runDeferred( array $job ): Run {
		$plan          = $job['all'] ? PurgePlan::everything() : PurgePlan::forUrls( (array) $job['urls'], (array) ( $job['tags'] ?? array() ) );
		$plan->reasons = (array) ( $job['reasons'] ?? array() );
		return $this->guarded(
			'delayed',
			static fn(): array => array(
				$plan,
				array(),
				array(
					array(
						'plan'   => $plan,
						'layers' => $job['layers'] ?? null,
						'rules'  => (array) ( $job['rules'] ?? array() ),
					),
				),
			)
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
			$built     = $build();
			$plan      = $built[0];
			$changes   = $built[1];
			$run       = isset( $built[2] ) ? $this->runJobs( $built[2], $plan ) : $this->executor->run( $plan );
			$run->jobs = isset( $built[2] )
				? array_map( fn( $job ) => $this->describe( $job['plan'], $job['layers'] ?? null, $job['rules'] ?? array(), (int) ( $job['delay'] ?? 0 ), (string) ( $job['when'] ?? Rule::ALWAYS ) ), array_slice( $built[2], 0, 50 ) )
				: array( $this->describe( $plan, null, array() ) );
			$id        = $this->history->record( $source, $run, $changes );
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
				$run->verification = $outcome;
				$this->history->annotate( $id, $outcome );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // A verification problem must never break a flush.
		}
	}

	/**
	 * Runs each rule job on its own layers and reports them as one run. Jobs that must wait go to the
	 * deferred list; jobs that only run after a failure are left out when nothing failed so far.
	 *
	 * @param array<int,array{plan:PurgePlan,layers:?array,rules?:string[],delay?:int,when?:string}> $jobs
	 */
	private function runJobs( array $jobs, PurgePlan $merged ): Run {
		// Jobs that always run go first, so "only if something failed" can look at what they did.
		usort( $jobs, static fn( array $a, array $b ) => ( Rule::ON_FAILURE === ( $a['when'] ?? '' ) ) <=> ( Rule::ON_FAILURE === ( $b['when'] ?? '' ) ) );

		$results  = array();
		$ran      = false;
		$failed   = false;
		$rules    = array();
		$deferred = 0;
		foreach ( $jobs as $job ) {
			$names = array_values( array_map( 'strval', (array) ( $job['rules'] ?? array() ) ) );
			if ( Rule::ON_FAILURE === ( $job['when'] ?? Rule::ALWAYS ) && ! $failed ) {
				continue; // Nothing failed, so the fallback is not needed.
			}
			array_push( $rules, ...$names );
			$delay = (int) ( $job['delay'] ?? 0 );
			if ( $delay > 0 && $this->defer && ( $this->defer )( $job['plan'], $job['layers'], $names, $delay ) ) {
				++$deferred;
				continue;
			}
			$run = $this->executor->run( $job['plan'], $job['layers'] );
			array_push( $results, ...$run->results );
			$ran    = $ran || Run::SKIPPED !== $run->status;
			$failed = $failed || in_array( $run->status, array( Run::FAILED, Run::PARTIAL ), true );
		}
		if ( array() === $results ) {
			$out = new Run( Run::SKIPPED, array(), $merged );
		} else {
			// Every layer was switched off or paused: nothing was purged, so this must not read as a success.
			$out = $ran ? Run::fromResults( $results, $merged ) : new Run( Run::SKIPPED, $results, $merged );
		}
		$out->rules    = array_values( array_unique( $rules ) );
		$out->deferred = $deferred;
		return $out;
	}

	/** @param array<int,array{plan:PurgePlan,layers:?array}> $jobs */
	private function merge( array $jobs ): PurgePlan {
		$all     = false;
		$urls    = array();
		$tags    = array();
		$reasons = array();
		foreach ( $jobs as $job ) {
			foreach ( $job['plan']->reasons as $url => $why ) {
				$reasons[ $url ] = array_values( array_unique( array_merge( $reasons[ $url ] ?? array(), $why ) ) );
			}
			$all  = $all || $job['plan']->all;
			$urls = array_merge( $urls, $job['plan']->urls );
			$tags = array_merge( $tags, $job['plan']->tags );
		}
		$plan          = $all ? PurgePlan::everything() : PurgePlan::forUrls( array_values( array_unique( $urls ) ), array_values( array_unique( $tags ) ) );
		$plan->reasons = $reasons;
		return $plan;
	}
}
