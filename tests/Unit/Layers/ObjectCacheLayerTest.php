<?php
namespace PurgeKit\Tests\Unit\Layers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\ObjectCacheInfo;
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

	private function info( bool $persistent, ?array $server, ?bool $responding = true, string $backend = 'redis' ): ObjectCacheInfo {
		return new class( $persistent, $server, $responding, $backend ) implements ObjectCacheInfo {
			public function __construct( private bool $persistent, private ?array $server, private ?bool $responding, private string $backend ) {}
			public function describe(): array {
				return array(
					'persistent'     => $this->persistent,
					'backend'        => $this->backend,
					'driver'         => 'WP_Object_Cache',
					'client'         => 'PhpRedis (v6.0)',
					'request_hits'   => 4,
					'request_misses' => 1,
				);
			}
			public function server(): ?array {
				return $this->server;
			}
			public function responding(): ?bool {
				return $this->responding;
			}
		};
	}

	private function server( array $over = array() ): array {
		return $over + array(
			'name'        => 'Redis',
			'version'     => '7.2.4',
			'used_memory' => 50,
			'max_memory'  => 100,
			'policy'      => 'allkeys-lru',
			'hits'        => 90,
			'misses'      => 10,
			'keys'        => 1234,
			'evicted'     => 0,
			'clients'     => 3,
			'uptime'      => 90000,
		);
	}

	public function test_redis_figures_become_numbers_with_meters(): void {
		$m = array_column( ( new ObjectCacheLayer( $this->info( true, $this->server() ) ) )->metrics(), null, 'label' );
		$this->assertSame( 'quality', $m['Hit rate']['meter'] );
		$this->assertEqualsWithDelta( 90.0, $m['Hit rate']['percent'], 0.01 );
		$this->assertSame( 'usage', $m['Memory']['meter'] );
		$this->assertSame( 1234, $m['Keys']['number'] );
		$this->assertStringContainsString( 'allkeys-lru', $m['Evicted keys']['value'] );
		$this->assertStringContainsString( '1 d 1 h', $m['Clients, uptime']['value'] );
		$this->assertStringContainsString( 'PhpRedis', $m['Driver']['value'] );
	}

	public function test_without_a_server_the_page_load_counters_are_shown(): void {
		$m = array_column( ( new ObjectCacheLayer( $this->info( false, null, null, 'none' ) ) )->metrics(), 'value', 'label' );
		$this->assertStringStartsWith( 'Per request only', $m['Kind'] );
		$this->assertSame( '4 hits, 1 misses (80%)', $m['This page load'] );
	}

	public function test_the_check_fails_when_redis_is_silent_and_warns_when_it_is_full_or_evicting(): void {
		$silent = ( new ObjectCacheLayer( $this->info( true, null, false ) ) )->test();
		$this->assertSame( LayerResult::FAILED, $silent->status );
		$ok = ( new ObjectCacheLayer( $this->info( true, $this->server() ) ) )->test();
		$this->assertSame( LayerResult::OK, $ok->status );
		$this->assertFalse( $ok->warn );
		$this->assertStringContainsString( '1,234 keys', $ok->message );
		$full = ( new ObjectCacheLayer( $this->info( true, $this->server( array( 'used_memory' => 95, 'evicted' => 12 ) ) ) ) )->test();
		$this->assertTrue( $full->warn );
		$this->assertStringContainsString( '95%', $full->message );
		$this->assertStringContainsString( 'thrown out', $full->message );
		$plain = ( new ObjectCacheLayer( $this->info( false, null, null, 'none' ) ) )->test();
		$this->assertSame( LayerResult::OK, $plain->status );
		$this->assertFalse( $plain->warn );
	}
}
