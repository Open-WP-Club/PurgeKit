<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;

final class ObjectCacheLayer extends AbstractLayer {
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
	public function purgeAll(): LayerResult {
		wp_cache_flush();
		return $this->ok();
	}
	public function purgeUrls( array $urls ): LayerResult {
		return $this->purgeAll();
	}
}
