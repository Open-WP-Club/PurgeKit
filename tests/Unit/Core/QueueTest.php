<?php
namespace PurgeKit\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\Change;
use PurgeKit\Core\Queue;
use PurgeKit\Tests\Fakes\FakeStore;

final class QueueTest extends TestCase {
	public function test_dedupes_same_urls(): void {
		$q = new Queue( new FakeStore() );
		$q->add( Change::forUrls( 'post', array( 'https://a.test/x' ) ) );
		$q->add( Change::forUrls( 'post', array( 'https://a.test/x' ) ) );
		$this->assertSame( 1, $q->count() );
	}

	public function test_site_wide_collapses_everything(): void {
		$q = new Queue( new FakeStore() );
		$q->add( Change::forUrls( 'post', array( 'https://a.test/x' ) ) );
		$q->add( Change::siteWide( 'menu' ) );
		$q->add( Change::forUrls( 'post', array( 'https://a.test/y' ) ) );
		$out = $q->drain();
		$this->assertCount( 1, $out );
		$this->assertTrue( $out[0]->site_wide );
		$this->assertSame( 'escalated', $out[0]->type );
	}

	public function test_drain_empties_queue(): void {
		$q = new Queue( new FakeStore() );
		$q->add( Change::forUrls( 'post', array( 'https://a.test/x' ) ) );
		$this->assertCount( 1, $q->drain() );
		$this->assertSame( 0, $q->count() );
		$this->assertSame( array(), $q->drain() );
	}

	public function test_queue_survives_new_instance_with_same_store(): void {
		$store = new FakeStore();
		( new Queue( $store ) )->add( Change::forUrls( 'post', array( 'https://a.test/x' ) ) );
		$this->assertSame( 1, ( new Queue( $store ) )->count() );
	}
}
