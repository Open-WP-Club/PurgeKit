<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Layers\MemcachedInfo;

final class MemcachedInfoTest extends TestCase {
	public function test_servers_are_added_up_and_dead_ones_skipped(): void {
		$n = MemcachedInfo::normalise(
			array(
				'a:11211' => array( 'pid' => 10, 'version' => '1.6.21', 'bytes' => 100, 'limit_maxbytes' => 1000, 'get_hits' => 8, 'get_misses' => 2, 'curr_items' => 5, 'evictions' => 1, 'curr_connections' => 4, 'uptime' => 60 ),
				'b:11211' => array( 'pid' => 11, 'bytes' => 50, 'limit_maxbytes' => 1000, 'get_hits' => 2, 'get_misses' => 0, 'curr_items' => 3, 'evictions' => 0, 'curr_connections' => 1, 'uptime' => 90 ),
				'c:11211' => array( 'pid' => -1 ),
			)
		);
		$this->assertSame( 'Memcached', $n['name'] );
		$this->assertSame( '1.6.21', $n['version'] );
		$this->assertSame( 150, $n['used_memory'] );
		$this->assertSame( 2000, $n['max_memory'] );
		$this->assertSame( 10, $n['hits'] );
		$this->assertSame( 8, $n['keys'] );
		$this->assertSame( 5, $n['clients'] );
		$this->assertSame( 90, $n['uptime'] );
	}

	public function test_no_answering_server_is_null(): void {
		$this->assertNull( MemcachedInfo::normalise( array() ) );
		$this->assertNull( MemcachedInfo::normalise( array( 'a:11211' => array( 'pid' => -1 ) ) ) );
	}
}
