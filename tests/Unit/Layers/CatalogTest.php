<?php
namespace PurgeKit\Tests\Unit\Layers;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PurgeKit\Layers\Catalog;
use PurgeKit\Layers\Tier;

final class CatalogTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function find( string $id ) {
		foreach ( Catalog::callbackLayers() as $layer ) {
			if ( $layer->id() === $id ) {
				return $layer;
			}
		}
		$this->fail( "No layer $id" );
	}

	public function test_wp_rocket_detected_only_when_function_exists(): void {
		$this->assertFalse( $this->find( 'wp-rocket' )->detect() );
		Monkey\Functions\when( 'rocket_clean_domain' )->justReturn( null );
		$this->assertTrue( $this->find( 'wp-rocket' )->detect() );
	}

	public function test_wp_rocket_purges_urls_with_clean_files(): void {
		Monkey\Functions\expect( 'rocket_clean_files' )->once()->with( array( 'https://a.test/x' ) );
		$this->find( 'wp-rocket' )->purgeUrls( array( 'https://a.test/x' ) );
	}

	public function test_litespeed_purges_each_url_via_action(): void {
		Monkey\Actions\expectDone( 'litespeed_purge_url' )->twice();
		$this->find( 'litespeed' )->purgeUrls( array( 'https://a.test/a', 'https://a.test/b' ) );
	}

	public function test_url_purge_falls_back_to_purge_all_when_unsupported(): void {
		Monkey\Actions\expectDone( 'rt_nginx_helper_purge_all' )->once();
		$this->find( 'nginx-helper' )->purgeUrls( array( 'https://a.test/a' ) );
	}

	public function test_all_catalog_ids_unique_and_tiers_valid(): void {
		$ids = array();
		foreach ( Catalog::callbackLayers() as $layer ) {
			$ids[] = $layer->id();
			$this->assertContains( $layer->tier(), array( Tier::PLUGIN, Tier::HOST ) );
		}
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
		$this->assertContains( 'kinsta', $ids );
		$this->assertContains( 'wp-engine', $ids );
		$this->assertCount( 16, $ids );
	}

	public function test_wp_fastest_cache_uses_class_and_action(): void {
		$this->assertFalse( $this->find( 'wp-fastest-cache' )->detect() );
		Monkey\Actions\expectDone( 'wpfc_clear_all_cache' )->once()->with( true );
		$this->find( 'wp-fastest-cache' )->purgeAll();
	}

	public function test_siteground_purges_everything_not_just_home(): void {
		Monkey\Functions\expect( 'sg_cachepress_purge_everything' )->once();
		Monkey\Functions\expect( 'sg_cachepress_purge_cache' )->never();
		$this->find( 'siteground' )->purgeAll();
	}

	public function test_kinsta_fails_loudly_without_api(): void {
		$GLOBALS['kinsta_cache'] = null;
		// The executor catches Throwable and records the layer as failed.
		$this->expectException( \RuntimeException::class );
		$this->find( 'kinsta' )->purgeAll();
	}
}
