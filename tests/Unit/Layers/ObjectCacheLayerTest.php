<?php
namespace PurgeKit\Tests\Unit\Layers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\ObjectCacheLayer;
use PurgeKit\Layers\Tier;

final class ObjectCacheLayerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_flushes_wp_cache_for_all_and_urls(): void {
		Monkey\Functions\expect( 'wp_cache_flush' )->twice();
		$l = new ObjectCacheLayer();
		$this->assertTrue( $l->detect() );
		$this->assertSame( Tier::OBJECT, $l->tier() );
		$this->assertSame( LayerResult::OK, $l->purgeAll()->status );
		$this->assertSame( LayerResult::OK, $l->purgeUrls( array( 'https://a.test/' ) )->status );
	}
}
