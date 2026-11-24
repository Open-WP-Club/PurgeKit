<?php
namespace PurgeKit\Core;

final class Flusher {
	private const LOCK = 'flush';
	private const TTL  = 120;

	/** @var callable(PurgePlan): void */
	private $warm;

	public function __construct(
		private Queue $queue,
		private Planner $planner,
		private Executor $executor,
		private Lock $lock,
		private HistoryWriter $history,
		callable $warm
	) {
		$this->warm = $warm;
	}

	/** Flush whatever is queued. With $force, purge everything even if nothing is queued. */
	public function flush( string $source, bool $force = false ): Run {
		return $this->guarded(
			$source,
			function () use ( $force ): array {
				$changes = $this->queue->drain();
				if ( array() === $changes && $force ) {
					return array( PurgePlan::everything(), $changes );
				}
				return array( $this->planner->plan( $changes ), $changes );
			}
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

	public function flushAll( string $source ): Run {
		return $this->guarded(
			$source,
			function (): array {
				$this->queue->drain(); // Everything is being purged, so pending changes are covered.
				return array( PurgePlan::everything(), array() );
			}
		);
	}

	/** @param callable(): array{0: PurgePlan, 1: Change[]} $build */
	private function guarded( string $source, callable $build ): Run {
		if ( ! $this->lock->acquire( self::LOCK, self::TTL ) ) {
			return new Run( Run::LOCKED );
		}
		try {
			list( $plan, $changes ) = $build();
			$run                    = $this->executor->run( $plan );
			$this->history->record( $source, $run, $changes );
			if ( Run::SKIPPED !== $run->status ) {
				( $this->warm )( $plan );
			}
			return $run;
		} finally {
			$this->lock->release( self::LOCK );
		}
	}
}
