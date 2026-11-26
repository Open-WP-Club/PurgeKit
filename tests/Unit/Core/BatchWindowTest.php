<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\BatchWindow;
use PurgeKit\Core\Change;
use PurgeKit\Core\Queue;
use PurgeKit\Tests\Fakes\FakeStore;

final class BatchWindowTest extends TestCase {
	public function test_quiet_period_extends_but_never_passes_the_deadline(): void {
		$store = new FakeStore();
		$window = new BatchWindow( $store );
		$store->set( 'queue_first_at', 1000 );
		$store->set( 'queue_last_at', 1010 );
		$this->assertSame( 1030, $window->due( 1010, 20, 300 ) );
		$store->set( 'queue_last_at', 1299 );
		$this->assertSame( 1300, $window->due( 1299, 20, 300 ) );
		$this->assertSame( 1299, $window->due( 1299, 0, 300 ) );
	}

	public function test_queue_tracks_first_and_latest_changes_and_resets_on_drain(): void {
		$store = new FakeStore();
		$q = new Queue( $store );
		$q->add( Change::forUrls( 'post', array( 'https://a.test/a' ) ) );
		$this->assertNotNull( $store->get( 'queue_first_at' ) );
		$store->set( 'queue_first_at', 100 );
		$store->set( 'queue_last_at', 200 );
		$q->add( Change::forUrls( 'post', array( 'https://a.test/a' ) ) );
		$this->assertGreaterThan( 200, $store->get( 'queue_last_at' ), 'Another edit extends the quiet window, while the first timestamp keeps the deadline fixed.' );
		$q->add( Change::forUrls( 'post', array( 'https://a.test/b' ) ) );
		$this->assertSame( 100, $store->get( 'queue_first_at' ) );
		$this->assertGreaterThan( 200, $store->get( 'queue_last_at' ) );
		$q->drain();
		$this->assertNull( $store->get( 'queue_first_at' ) );
		$this->assertNull( $store->get( 'queue_last_at' ) );
	}
	public function test_legacy_queue_without_timestamps_is_due_immediately(): void {
		$this->assertSame( 1000, ( new BatchWindow( new FakeStore() ) )->due( 1000, 20, 300 ) );
	}

}
