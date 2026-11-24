<?php
namespace PurgeKit\Tests\Unit\Layers;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\Catalog;
use PurgeKit\Layers\Tier;

require_once __DIR__ . '/../../Fakes/CachePluginStubs.php';

final class CachePluginsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\Breeze_PurgeCache::$calls    = array();
		\Swift_Performance_Cache::$calls = array();
		\WPO_Page_Cache::$calls       = array();
		\FlyingPress\Purge::$calls    = array();
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

	public function test_cache_enabler_uses_its_two_documented_actions(): void {
		$layer = $this->find( 'cache-enabler' );
		$this->assertTrue( $layer->detect() );
		$this->assertSame( Tier::PLUGIN, $layer->tier() );
		Monkey\Actions\expectDone( 'cache_enabler_clear_complete_cache' )->once();
		Monkey\Actions\expectDone( 'cache_enabler_clear_page_cache_by_url' )->once()->with( 'https://a.test/x/' );
		$this->assertSame( LayerResult::OK, $layer->purgeAll()->status );
		$this->assertSame( LayerResult::OK, $layer->purgeUrls( array( 'https://a.test/x/' ) )->status );
	}

	public function test_cache_enabler_is_recognised_by_its_header_or_its_comment(): void {
		$layer = $this->find( 'cache-enabler' );
		$this->assertStringContainsString( 'Cache Enabler', (string) $layer->servedFrom( array( 'x-cache-handler' => 'cache-enabler-engine' ) ) );
		$this->assertStringContainsString( 'Cache Enabler', (string) $layer->servedFrom( array(), '<html><!-- Cache Enabler by KeyCDN @ Wed, 24 Mar 2021 (https-index.html.gz) --></html>' ) );
		$this->assertNull( $layer->servedFrom( array(), '<html></html>' ) );
	}

	public function test_wp_optimize_purges_through_its_page_cache_object_and_by_url(): void {
		$layer = $this->find( 'wp-optimize' );
		$this->assertTrue( $layer->detect() );
		$layer->purgeAll();
		$layer->purgeUrls( array( 'https://a.test/a/', 'https://a.test/b/' ) );
		$this->assertSame(
			array( array( 'purge' ), array( 'url', 'https://a.test/a/' ), array( 'url', 'https://a.test/b/' ) ),
			\WPO_Page_Cache::$calls
		);
	}

	public function test_wp_optimize_signature_header_and_comment(): void {
		$layer = $this->find( 'wp-optimize' );
		$this->assertNotNull( $layer->servedFrom( array( 'wpo-cache-status' => 'cached' ) ) );
		$this->assertNull( $layer->servedFrom( array( 'wpo-cache-status' => 'saving to cache' ) ) );
		$this->assertNotNull( $layer->servedFrom( array(), "</p>\n<!-- Cached by WP-Optimize - https://teamupdraft.com/wp-optimize/ - Last modified: Sat -->" ) );
		$this->assertNull( $layer->servedFrom( array(), '<!-- WP Optimize page cache - page NOT cached -->' ) );
	}

	public function test_flyingpress_uses_its_documented_static_api(): void {
		$layer = $this->find( 'flyingpress' );
		$this->assertTrue( $layer->detect() );
		$layer->purgeAll();
		$layer->purgeUrls( array( 'https://a.test/a/' ) );
		$this->assertSame( array( array( 'everything' ), array( 'urls', array( 'https://a.test/a/' ) ) ), \FlyingPress\Purge::$calls );
	}

	public function test_swift_performance_clears_all_or_each_permalink(): void {
		$layer = $this->find( 'swift-performance' );
		$this->assertTrue( $layer->detect() );
		$layer->purgeAll();
		$layer->purgeUrls( array( 'https://a.test/a/', 'https://a.test/b/' ) );
		$this->assertSame(
			array( array( 'all' ), array( 'url', 'https://a.test/a/' ), array( 'url', 'https://a.test/b/' ) ),
			\Swift_Performance_Cache::$calls
		);
		$this->assertNotNull( $layer->servedFrom( array( 'swift-performance' => 'HIT' ) ) );
		$this->assertNull( $layer->servedFrom( array( 'swift-performance' => 'MISS' ) ) );
		$this->assertNotNull( $layer->servedFrom( array(), '</p><!--Cached with Swift Performance-->' ) );
	}

	public function test_breeze_uses_the_plugins_own_full_clear_and_has_no_url_purge(): void {
		$layer = $this->find( 'breeze' );
		$this->assertTrue( $layer->detect() );
		$layer->purgeAll();
		$layer->purgeUrls( array( 'https://a.test/a/' ) );
		$this->assertSame( array( array( true, true, true ), array( true, true, true ) ), \Breeze_PurgeCache::$calls, 'A URL purge clears everything, because Breeze has no public per-URL call.' );
	}

	public function test_spinupwp_is_detected_by_its_action_and_uses_both_actions(): void {
		$layer = $this->find( 'spinupwp' );
		$this->assertFalse( $layer->detect(), 'No SpinupWP plugin, so nobody registered the action.' );
		add_action( 'spinupwp_purge_page_cache', static function () {} );
		$this->assertTrue( $layer->detect() );
		$this->assertSame( Tier::HOST, $layer->tier() );

		Monkey\Actions\expectDone( 'spinupwp_purge_page_cache' )->once();
		Monkey\Actions\expectDone( 'spinupwp_purge_url' )->twice();
		$layer->purgeAll();
		$layer->purgeUrls( array( 'https://a.test/a/', 'https://a.test/b/' ) );
	}

	public function test_pantheon_purges_paths_and_falls_back_to_everything(): void {
		$layer = $this->find( 'pantheon' );
		$this->assertFalse( $layer->detect() );
		Monkey\Functions\when( 'pantheon_wp_clear_edge_all' )->justReturn( null );
		$this->assertTrue( $layer->detect() );
		$this->assertSame( Tier::HOST, $layer->tier() );
		Monkey\Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );

		Monkey\Functions\expect( 'pantheon_wp_clear_edge_paths' )->once()->with( array( '/blog/', '/' ) );
		$layer->purgeUrls( array( 'https://a.test/blog/', 'https://a.test/blog/?x=1', 'https://a.test/' ) );
	}
}
