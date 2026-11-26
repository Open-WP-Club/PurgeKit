<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\BatchWindow;
use PurgeKit\Services;

/** Coalesces changes across requests; explicit manual purges remain immediate. */
final class AutoRunner {
	public const HOOK    = 'purgekit_auto_flush';
	private int $imports = 0;

	public function __construct( private Services $services ) {}

	public function register(): void {
		add_action( 'shutdown', array( $this, 'schedule' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		foreach ( array( 'import_start', 'purgekit_batch_begin' ) as $hook ) {
			add_action( $hook, array( $this, 'begin' ) );
		}
		foreach ( array( 'import_end', 'purgekit_batch_end' ) as $hook ) {
			add_action( $hook, array( $this, 'end' ) );
		}
	}

	public function begin(): void {
		++$this->imports;
		$this->services->store->set( 'batch_hold_until', time() + $this->services->settings->batchMaximum() );
	}

	public function end(): void {
		$this->imports = max( 0, $this->imports - 1 );
		if ( 0 === $this->imports ) {
			$this->services->store->delete( 'batch_hold_until' );
		}
		$this->schedule();
	}

	private function due(): int {
		$s = $this->services;
		return max( min( (int) $s->store->get( 'batch_hold_until', 0 ), (int) $s->store->get( 'queue_first_at', time() ) + $s->settings->batchMaximum() ), ( new BatchWindow( $s->store ) )->due( time(), $s->settings->batchDelay(), $s->settings->batchMaximum() ) );
	}

	private function ready(): bool {
		$s = $this->services;
		return 'auto' === $s->settings->mode() && $s->queue->count() > 0
			&& ! ( $s->settings->stormPause() && null !== $s->storm->tripped( time() ) );
	}

	public function schedule(): void {
		if ( ! $this->ready() ) {
			return;
		}
		$due = $this->due();
		if ( 0 === $this->imports && $due <= time() ) {
			$this->run();
			return;
		}
		$scheduled = wp_next_scheduled( self::HOOK );
		if ( $scheduled && $scheduled > $due && 0 === $this->imports ) {
			wp_clear_scheduled_hook( self::HOOK );
			$scheduled = false;
		}
		if ( ! $scheduled ) {
			wp_schedule_single_event( max( time() + 1, $due ), self::HOOK );
		}
	}

	public function run(): void {
		if ( ! $this->ready() ) {
			return;
		}
		$s   = $this->services;
		$due = $this->due();
		if ( $due > time() ) {
			wp_schedule_single_event( $due, self::HOOK );
			return;
		}
		$run = $s->flusher->flush( 'auto' );
		if ( 0 === $s->queue->count() ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
		if ( 'locked' === $run->status ) {
			wp_schedule_single_event( time() + 15, self::HOOK );
		}
	}
}
