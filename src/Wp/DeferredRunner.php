<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\DeferredJobs;
use PurgeKit\Core\Flusher;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;

/** Puts postponed rule jobs on WP-Cron and runs them when they are due. */
final class DeferredRunner {
	public const HOOK = 'purgekit_deferred_job';

	public function __construct( private DeferredJobs $jobs, private Flusher $flusher ) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		// Events can be lost (cron table rebuilt), so anything still stored is put back.
		foreach ( $this->jobs->all() as $item ) {
			if ( ! wp_next_scheduled( self::HOOK, array( $item['id'] ) ) ) {
				wp_schedule_single_event( max( time(), (int) $item['at'] ), self::HOOK, array( $item['id'] ) );
			}
		}
	}

	/**
	 * @param string[]|null $layers
	 * @param string[]      $rules
	 */
	public function defer( PurgePlan $plan, ?array $layers, array $rules, int $delay ): bool {
		$item = $this->jobs->add( time() + $delay, $plan, $layers, $rules );
		if ( ! $item ) {
			return false; // The list is full: the job then runs right away instead of being lost.
		}
		wp_schedule_single_event( $item['at'], self::HOOK, array( $item['id'] ) );
		return true;
	}

	public function run( string $id ): void {
		$job = $this->jobs->find( $id );
		if ( ! $job ) {
			return;
		}
		$run = $this->flusher->runDeferred( $job );
		if ( Run::LOCKED === $run->status ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $id ) );
			return;
		}
		$this->jobs->take( $id );
	}
}
