<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;

final class ObjectCacheLayer extends AbstractLayer implements Metrics {
	public function id(): string {
		return 'object-cache';
	}
	public function label(): string {
		return 'Object cache';
	}
	public function tier(): int {
		return Tier::OBJECT;
	}
	public function detect(): bool {
		return true;
	}
	public function metrics(): array {
		global $wp_object_cache;
		$persistent = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
		$out        = array(
			array(
				'label' => 'Kind',
				'value' => $persistent ? 'Persistent (survives between requests)' : 'Per request only (nothing is kept between requests)',
			),
		);
		if ( is_object( $wp_object_cache ) ) {
			$out[]  = array(
				'label' => 'Driver',
				'value' => ( new \ReflectionClass( $wp_object_cache ) )->getShortName(),
			);
			$hits   = (int) ( $wp_object_cache->cache_hits ?? 0 );
			$misses = (int) ( $wp_object_cache->cache_misses ?? 0 );
			if ( $hits + $misses > 0 ) {
				$out[] = array(
					'label' => 'This page load',
					'value' => sprintf( '%d hits, %d misses (%.0f%%)', $hits, $misses, 100 * $hits / ( $hits + $misses ) ),
				);
			}
		}
		return $out;
	}

	public function purgeAll(): LayerResult {
		wp_cache_flush();
		return $this->ok();
	}
	public function purgeUrls( array $urls ): LayerResult {
		return $this->purgeAll();
	}
}
