<?php
namespace PurgeKit\Tests\Unit\Layers;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use PurgeKit\Layers\WpObjectCacheInfo;

/** The Redis Object Cache drop-in is imitated: its public client, status method and diagnostics property. */
final class WpObjectCacheInfoTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_object_cache'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function dropIn( bool $connected, ?array $info, bool $throws = false ): object {
		$client = new class( $info, $throws ) {
			public function __construct( private ?array $info, private bool $throws ) {}
			public function info(): ?array {
				if ( $this->throws ) {
					throw new \RuntimeException( 'gone' );
				}
				return $this->info;
			}
		};
		return new class( $connected, $client ) {
			public int $cache_hits       = 7;
			public int $cache_misses     = 3;
			public array $diagnostics    = array( 'client' => 'PhpRedis (v6.0.2)' );
			public function __construct( private bool $connected, private object $client ) {}
			public function redis_status(): bool {
				return $this->connected;
			}
			public function redis_instance(): object {
				return $this->client;
			}
		};
	}

	public function test_a_redis_drop_in_is_recognised_and_read(): void {
		Monkey\Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		$GLOBALS['wp_object_cache'] = $this->dropIn( true, array( 'redis_version' => '7.2.4', 'keyspace_hits' => '5', 'keyspace_misses' => '5', 'db0' => 'keys=9,expires=1' ) );
		$i = new WpObjectCacheInfo();
		$d = $i->describe();
		$this->assertTrue( $d['persistent'] );
		$this->assertSame( 'redis', $d['backend'] );
		$this->assertSame( 'PhpRedis (v6.0.2)', $d['client'] );
		$this->assertSame( 7, $d['request_hits'] );
		$this->assertTrue( $i->responding() );
		$s = $i->server();
		$this->assertSame( 9, $s['keys'] );
		$this->assertSame( '7.2.4', $s['version'] );
	}

	public function test_a_redis_that_does_not_answer_gives_no_figures(): void {
		Monkey\Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		$GLOBALS['wp_object_cache'] = $this->dropIn( false, null, true );
		$i = new WpObjectCacheInfo();
		$this->assertFalse( $i->responding() );
		$this->assertNull( $i->server(), 'An exception from the client is swallowed.' );
	}

	public function test_a_memcached_or_unknown_drop_in_is_named_but_not_asked(): void {
		Monkey\Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		$GLOBALS['wp_object_cache'] = new class() {};
		$d = ( new WpObjectCacheInfo() )->describe();
		$this->assertSame( 'other', $d['backend'] );
		$this->assertNull( ( new WpObjectCacheInfo() )->server() );
		$this->assertNull( ( new WpObjectCacheInfo() )->responding() );
	}

	public function test_without_a_drop_in_nothing_is_persistent(): void {
		Monkey\Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		$GLOBALS['wp_object_cache'] = new class() {};
		$d = ( new WpObjectCacheInfo() )->describe();
		$this->assertFalse( $d['persistent'] );
		$this->assertSame( 'none', $d['backend'] );
	}
}
