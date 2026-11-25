<?php
namespace PurgeKit\Layers;

/** Turns the answer of `INFO` (PhpRedis' flat list, or Predis' list grouped by section) into the few figures PurgeKit shows. Pure. */
final class RedisInfo {
	/**
	 * @param array<string,mixed> $info
	 * @return array{name:string,version:string,used_memory:int,max_memory:int,policy:string,hits:int,misses:int,keys:int,evicted:int,clients:int,uptime:int}
	 */
	public static function normalise( array $info ): array {
		$flat = array();
		foreach ( $info as $key => $value ) {
			if ( is_array( $value ) && ! str_starts_with( (string) $key, 'db' ) ) {
				foreach ( $value as $k => $v ) {
					$flat[ (string) $k ] = $v; // Predis groups the answer into sections.
				}
				continue;
			}
			$flat[ (string) $key ] = $value;
		}
		$keys = 0;
		foreach ( $flat as $k => $v ) {
			if ( 1 !== preg_match( '/^db\d+$/', $k ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$keys += (int) ( $v['keys'] ?? 0 );
			} elseif ( 1 === preg_match( '/keys=(\d+)/', (string) $v, $m ) ) {
				$keys += (int) $m[1];
			}
		}
		$name = isset( $flat['valkey_version'] ) ? 'Valkey' : ( isset( $flat['redis_version'] ) ? 'Redis' : 'Redis-compatible server' );
		return array(
			'name'        => $name,
			'version'     => (string) ( $flat['valkey_version'] ?? $flat['redis_version'] ?? '' ),
			'used_memory' => (int) ( $flat['used_memory'] ?? 0 ),
			'max_memory'  => (int) ( $flat['maxmemory'] ?? 0 ),
			'policy'      => (string) ( $flat['maxmemory_policy'] ?? '' ),
			'hits'        => (int) ( $flat['keyspace_hits'] ?? 0 ),
			'misses'      => (int) ( $flat['keyspace_misses'] ?? 0 ),
			'keys'        => $keys,
			'evicted'     => (int) ( $flat['evicted_keys'] ?? 0 ),
			'clients'     => (int) ( $flat['connected_clients'] ?? 0 ),
			'uptime'      => (int) ( $flat['uptime_in_seconds'] ?? 0 ),
		);
	}
}
