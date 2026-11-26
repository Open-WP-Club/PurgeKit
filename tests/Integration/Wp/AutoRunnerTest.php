<?php
namespace PurgeKit\Tests\Integration\Wp;

use PurgeKit\Core\Change;
use PurgeKit\Plugin;
use PurgeKit\Wp\AutoRunner;

final class AutoRunnerTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		$s = Plugin::services();
		$s->queue->drain();
		$s->history->install();
		$s->settings->update( array( 'mode' => 'auto', 'batch_delay' => 20, 'batch_maximum' => 300, 'warm_enabled' => false ) );
		$s->storm->reset();
		$s->lock->release( 'flush' );
		wp_clear_scheduled_hook( AutoRunner::HOOK );
	}

	public function tear_down(): void {
		$s = Plugin::services();
		$s->queue->drain();
		foreach ( array( 'mode', 'batch_delay', 'batch_maximum', 'warm_enabled', 'storm_pause', 'batch_hold_until' ) as $key ) {
			$s->store->delete( $key );
		}
		$s->lock->release( 'flush' );
		wp_clear_scheduled_hook( AutoRunner::HOOK );
		parent::tear_down();
	}

	private function change(): void {
		Plugin::services()->queue->add( Change::forUrls( 'manual', array( home_url( '/changed/' ) ) ) );
	}

	public function test_multiple_requests_share_one_event_and_do_not_flush_early(): void {
		$this->change();
		$r = new AutoRunner( Plugin::services() );
		$r->schedule();
		$at = wp_next_scheduled( AutoRunner::HOOK );
		$this->assertGreaterThanOrEqual( time() + 18, $at );
		$r->schedule();
		$this->assertSame( $at, wp_next_scheduled( AutoRunner::HOOK ) );
		$r->run();
		$this->assertSame( 1, Plugin::services()->queue->count() );
	}

	public function test_deadline_flushes_even_under_continuous_changes(): void {
		$s = Plugin::services();
		$this->change();
		$s->store->set( 'queue_first_at', time() - 301 );
		$s->store->set( 'queue_last_at', time() );
		( new AutoRunner( $s ) )->run();
		$this->assertSame( 0, $s->queue->count() );
		$this->assertSame( 'auto', $s->history->recent( 1, 0 )[0]['source'] );
	}

	public function test_zero_delay_remains_immediate_and_import_can_group_changes(): void {
		$s = Plugin::services();
		$s->settings->update( array( 'batch_delay' => 0 ) );
		$r = new AutoRunner( $s );
		$r->begin();
		$this->change();
		$r->schedule();
		$this->assertSame( 1, $s->queue->count() );
		$r->end();
		$this->assertSame( 0, $s->queue->count() );
	}

	public function test_other_modes_and_paused_storms_leave_changes_queued(): void {
		$s = Plugin::services();
		$this->change();
		$s->settings->update( array( 'mode' => 'notify' ) );
		$r = new AutoRunner( $s );
		$r->run();
		$r->schedule();
		$this->assertSame( 1, $s->queue->count() );
		$this->assertFalse( wp_next_scheduled( AutoRunner::HOOK ) );
	}

	public function test_locked_flush_is_retried_without_losing_changes(): void {
		$s = Plugin::services();
		$this->change();
		$s->settings->update( array( 'batch_delay' => 0 ) );
		$s->lock->acquire( 'flush', 120 );
		( new AutoRunner( $s ) )->run();
		$this->assertSame( 1, $s->queue->count() );
		$this->assertGreaterThanOrEqual( time() + 14, wp_next_scheduled( AutoRunner::HOOK ) );
	}
	public function test_import_holds_other_workers_until_end_or_the_hard_deadline(): void {
		$s = Plugin::services();
		$s->settings->update( array( 'batch_delay' => 0 ) );
		$import = new AutoRunner( $s );
		$import->begin();
		$this->change();
		( new AutoRunner( $s ) )->run();
		$this->assertSame( 1, $s->queue->count() );
		$s->store->set( 'queue_first_at', time() - 301 );
		( new AutoRunner( $s ) )->run();
		$this->assertSame( 0, $s->queue->count(), 'An abandoned import must not block flushing forever.' );
		$import->end();
	}

}
