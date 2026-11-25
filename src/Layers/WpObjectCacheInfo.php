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
		);
	}

	public function server(): ?array {
		$cache  = $this->cache();
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

	public function responding(): ?bool {
		$cache = $this->cache();
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
