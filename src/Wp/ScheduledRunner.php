<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Flusher;
use PurgeKit\Core\Run;
use PurgeKit\Core\ScheduledPurges;

/** Puts one-off purges on WP-Cron and runs them when they are due. */
final class ScheduledRunner {
	public const HOOK = 'purgekit_scheduled_purge';

	public function __construct( private ScheduledPurges $purges, private Flusher $flusher ) {}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		// Events can be lost (plugin deactivated, cron table rebuilt), so anything still stored is put back.
		foreach ( $this->purges->all() as $item ) {
			if ( ! wp_next_scheduled( self::HOOK, array( $item['id'] ) ) ) {
				wp_schedule_single_event( max( time(), $item['at'] ), self::HOOK, array( $item['id'] ) );
			}
		}
	}

	/** @return array{id:string,at:int,urls:string[],note:string}|null */
	public function add( int $at, array $urls, string $note ): ?array {
		$item = $this->purges->add( $at, $urls, $note, time() );
		if ( $item ) {
			wp_schedule_single_event( $item['at'], self::HOOK, array( $item['id'] ) );
		}
		return $item;
	}

	public function cancel( string $id ): bool {
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
		return null !== $this->purges->take( $id );
	}

	public function run( string $id ): void {
		$item = $this->purges->find( $id );
		if ( ! $item ) {
			return; // Cancelled in the meantime.
		}
		$run = array() === $item['urls'] ? $this->flusher->flushAll( 'scheduled-purge' ) : $this->flusher->flushUrls( 'scheduled-purge', $item['urls'] );
		if ( Run::LOCKED === $run->status ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK, array( $id ) ); // Another flush is running; try again shortly.
			return;
		}
		$this->purges->take( $id );
	}
}
