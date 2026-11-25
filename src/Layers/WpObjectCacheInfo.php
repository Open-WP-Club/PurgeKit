<?php
namespace PurgeKit\Layers;

/**
 * Reads the object cache drop-in that is installed. Redis is read through the "Redis Object Cache" plugin's own
 * client (redis_instance() and redis_status()), which also covers Valkey and other Redis-compatible servers.
 * Other drop-ins are recognised by name only; no figures are asked from them.
 */
final class WpObjectCacheInfo implements ObjectCacheInfo {
	private function cache(): ?object {
		global $wp_object_cache;
		return is_object( $wp_object_cache ) ? $wp_object_cache : null;
	}

	private function redisClient( object $cache ): ?object {
		if ( ! method_exists( $cache, 'redis_instance' ) ) {
			return null;
		}
		try {
			$client = $cache->redis_instance();
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_object( $client ) ? $client : null;
	}

	public function describe(): array {
		$cache      = $this->cache();
		$persistent = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$class      = $cache ? ( new \ReflectionClass( $cache ) )->getName() : '';
		$backend    = 'none';
		$client     = '';
		if ( $persistent && $cache ) {
			$backend = 'other';
			if ( method_exists( $cache, 'redis_instance' ) || false !== stripos( $class, 'redis' ) ) {
				$backend = 'redis';
			} elseif ( false !== stripos( $class, 'memcache' ) ) {
				$backend = 'memcached';
			}
			if ( 'redis' === $backend && is_array( $cache->diagnostics ?? null ) ) {
				$client = (string) ( $cache->diagnostics['client'] ?? '' ); // A public property of the Redis Object Cache drop-in.
			}
		}
		return array(
			'persistent'     => $persistent,
			'backend'        => $backend,
			'driver'         => $cache ? ( new \ReflectionClass( $cache ) )->getShortName() : '',
			'client'         => $client,
			'request_hits'   => (int) ( $cache->cache_hits ?? 0 ),
			'request_misses' => (int) ( $cache->cache_misses ?? 0 ),
			'flush_scope'    => 'redis' === $backend ? $this->flushScope() : '',
		);
	}

	/** Redis Object Cache empties only its own keys when both a prefix and WP_REDIS_SELECTIVE_FLUSH are set; otherwise the whole database. */
	private function flushScope(): string {
		$prefix = defined( 'WP_REDIS_PREFIX' ) ? trim( (string) WP_REDIS_PREFIX ) : '';
		if ( '' !== $prefix && defined( 'WP_REDIS_SELECTIVE_FLUSH' ) && WP_REDIS_SELECTIVE_FLUSH ) {
			return 'Only keys with the prefix ' . $prefix;
		}
		return 'The whole Redis database (FLUSHDB). If other sites use it, set WP_REDIS_PREFIX and WP_REDIS_SELECTIVE_FLUSH';
	}

	/** @return object[] Memcached clients found on the drop-in, whatever it calls them. */
	private function memcachedClients( object $cache ): array {
		$found = array();
		foreach ( array( 'mc', 'default_mcs', 'm', 'memcached', 'client' ) as $prop ) {
			$value = $cache->$prop ?? null;
			foreach ( is_array( $value ) ? $value : array( $value ) as $candidate ) {
				if ( is_object( $candidate ) && method_exists( $candidate, 'getStats' ) ) {
					$found[ spl_object_id( $candidate ) ] = $candidate;
				}
			}
		}
		return array_values( $found );
	}

	public function server(): ?array {
		$cache = $this->cache();
		if ( $cache && ! $this->redisClient( $cache ) && $this->memcachedClients( $cache ) ) {
			return $this->memcachedStats( $cache );
		}
		$client = $cache ? $this->redisClient( $cache ) : null;
		if ( ! $client || ! method_exists( $client, 'info' ) ) {
			return null;
		}
		try {
			$info = $client->info();
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $info ) ? RedisInfo::normalise( $info ) : null;
	}

	/** @return array<string,mixed>|null */
	private function memcachedStats( object $cache ): ?array {
		$all = array();
		foreach ( $this->memcachedClients( $cache ) as $client ) {
			try {
				$stats = $client->getStats();
			} catch ( \Throwable $e ) {
				continue;
			}
			// Two clients may talk to the same server; the address keeps it from being counted twice.
			$all += is_array( $stats ) ? $stats : array();
		}
		return MemcachedInfo::normalise( $all );
	}

	public function responding(): ?bool {
		$cache = $this->cache();
		if ( $cache && ! method_exists( $cache, 'redis_status' ) && $this->memcachedClients( $cache ) ) {
			return null !== $this->memcachedStats( $cache );
		}
		if ( ! $cache || ! method_exists( $cache, 'redis_status' ) ) {
			return null;
		}
		try {
			return (bool) $cache->redis_status();
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
