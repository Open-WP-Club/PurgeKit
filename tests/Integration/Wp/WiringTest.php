<?php
namespace PurgeKit\Tests\Integration\Wp;

use PurgeKit\Core\Change;
use PurgeKit\Core\Run;
use PurgeKit\Services;

final class WiringTest extends \WP_UnitTestCase {
	private Services $svc;

	public function set_up(): void {
		parent::set_up();
		$this->svc = Services::build();
		$this->svc->history->install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . $this->svc->history->table() );
		$this->svc->queue->drain();
	}

	public function test_scheduled_mode_skips_when_queue_empty(): void {
		$this->svc->settings->update( array( 'mode' => 'scheduled' ) );
		$run = $this->svc->flusher->flush( 'schedule' );
		$this->assertSame( Run::SKIPPED, $run->status );
		$this->assertSame( 'skipped', $this->svc->history->recent( 1, 0 )[0]['status'] );
	}

	public function test_flush_all_runs_object_cache_layer_and_records(): void {
		$run = $this->svc->flusher->flushAll( 'test' );
		$this->assertContains( 'object-cache', array_map( static fn( $r ) => $r->layer, $run->results ) );
		$this->assertSame( 'test', $this->svc->history->recent( 1, 0 )[0]['source'] );
	}

	public function test_lock_blocks_second_flush(): void {
		$this->assertTrue( $this->svc->lock->acquire( 'flush', 120 ) );
		$this->assertSame( Run::LOCKED, $this->svc->flusher->flushAll( 'test' )->status );
		$this->svc->lock->release( 'flush' );
		$this->assertNotSame( Run::LOCKED, $this->svc->flusher->flushAll( 'test' )->status );
	}

	public function test_cron_event_follows_mode(): void {
		$this->svc->settings->update( array( 'mode' => 'scheduled', 'schedule' => 'hourly' ) );
		$this->svc->cron->sync();
		$this->assertNotFalse( wp_next_scheduled( 'purgekit_scheduled_flush' ) );
		$this->svc->settings->update( array( 'mode' => 'notify' ) );
		$this->svc->cron->sync();
		$this->assertFalse( wp_next_scheduled( 'purgekit_scheduled_flush' ) );
	}

	public function test_pending_changes_drive_notice_count(): void {
		$this->svc->queue->add( Change::siteWide( 'x' ) );
		$this->assertSame( 1, $this->svc->queue->count() );
	}

	public function test_flush_enqueues_warming_and_schedules_event(): void {
		wp_clear_scheduled_hook( 'purgekit_warm' );
		$this->svc->flusher->flushAll( 'test' );
		$this->assertGreaterThan( 0, $this->svc->warmer->pending() );
		$this->assertNotFalse( wp_next_scheduled( 'purgekit_warm' ) );
	}

	public function test_warming_disabled_enqueues_nothing(): void {
		$this->svc->settings->update( array( 'warm_enabled' => false ) );
		$before = $this->svc->warmer->pending();
		$this->svc->flusher->flushAll( 'test' );
		$this->assertSame( $before, $this->svc->warmer->pending() );
	}
}
