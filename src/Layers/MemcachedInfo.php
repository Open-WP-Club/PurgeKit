<?php
namespace PurgeKit\Layers;

/** Adds up what `Memcached::getStats()` reports for every server into the figures PurgeKit shows. Pure. */
final class MemcachedInfo {
	/**
	 * @param array<string,mixed> $stats Server address => its stats, as getStats() returns them.
	 * @return array{name:string,version:string,used_memory:int,max_memory:int,policy:string,hits:int,misses:int,keys:int,evicted:int,clients:int,uptime:int}|null Null when no server answered.
	 */
	public static function normalise( array $stats ): ?array {
		$out  = array(
			'name'        => 'Memcached',
			'version'     => '',
			'used_memory' => 0,
			'max_memory'  => 0,
			'policy'      => '',
			'hits'        => 0,
			'misses'      => 0,
			'keys'        => 0,
			'evicted'     => 0,
			'clients'     => 0,
			'uptime'      => 0,
		);
		$seen = 0;
		foreach ( $stats as $server ) {
			// A server that is down is reported with a pid of -1 (or not at all).
			if ( ! is_array( $server ) || (int) ( $server['pid'] ?? -1 ) < 0 ) {
				continue;
			}
			++$seen;
			$out['version']      = '' !== $out['version'] ? $out['version'] : (string) ( $server['version'] ?? '' );
			$out['used_memory'] += (int) ( $server['bytes'] ?? 0 );
			$out['max_memory']  += (int) ( $server['limit_maxbytes'] ?? 0 );
			$out['hits']        += (int) ( $server['get_hits'] ?? 0 );
			$out['misses']      += (int) ( $server['get_misses'] ?? 0 );
			$out['keys']        += (int) ( $server['curr_items'] ?? 0 );
			$out['evicted']     += (int) ( $server['evictions'] ?? 0 );
			$out['clients']     += (int) ( $server['curr_connections'] ?? 0 );
			$out['uptime']       = max( $out['uptime'], (int) ( $server['uptime'] ?? 0 ) );
		}
		return $seen > 0 ? $out : null;
	}
}
