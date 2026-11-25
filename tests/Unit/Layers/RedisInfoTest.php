<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Layers\RedisInfo;

final class RedisInfoTest extends TestCase {
	public function test_a_flat_answer_from_phpredis(): void {
		$n = RedisInfo::normalise(
			array(
				'redis_version'     => '7.2.4',
				'used_memory'       => '1048576',
				'maxmemory'         => '2097152',
				'maxmemory_policy'  => 'allkeys-lru',
				'keyspace_hits'     => '900',
				'keyspace_misses'   => '100',
				'evicted_keys'      => '5',
				'connected_clients' => '3',
				'uptime_in_seconds' => '90000',
				'db0'               => 'keys=120,expires=100,avg_ttl=0',
				'db1'               => 'keys=30,expires=0,avg_ttl=0',
			)
		);
		$this->assertSame( 'Redis', $n['name'] );
		$this->assertSame( '7.2.4', $n['version'] );
		$this->assertSame( 1048576, $n['used_memory'] );
		$this->assertSame( 2097152, $n['max_memory'] );
		$this->assertSame( 150, $n['keys'], 'Every database counts.' );
		$this->assertSame( 5, $n['evicted'] );
		$this->assertSame( 'allkeys-lru', $n['policy'] );
	}

	public function test_the_grouped_answer_from_predis_and_valkey(): void {
		$n = RedisInfo::normalise(
			array(
				'Server'   => array( 'valkey_version' => '8.0.1', 'redis_version' => '7.2.4', 'uptime_in_seconds' => 60 ),
				'Memory'   => array( 'used_memory' => 500, 'maxmemory' => 0 ),
				'Stats'    => array( 'keyspace_hits' => 1, 'keyspace_misses' => 3, 'evicted_keys' => 0 ),
				'Keyspace' => array( 'db0' => array( 'keys' => 7, 'expires' => 1 ) ),
			)
		);
		$this->assertSame( 'Valkey', $n['name'] );
		$this->assertSame( '8.0.1', $n['version'] );
		$this->assertSame( 0, $n['max_memory'] );
		$this->assertSame( 7, $n['keys'] );
		$this->assertSame( 3, $n['misses'] );
	}

	public function test_an_empty_answer_does_not_break(): void {
		$n = RedisInfo::normalise( array() );
		$this->assertSame( 'Redis-compatible server', $n['name'] );
		$this->assertSame( 0, $n['keys'] );
	}
}
