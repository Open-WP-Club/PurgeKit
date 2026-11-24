<?php
namespace PurgeKit\Layers;

/** Optional: a layer that can tell from response headers whether it served the page from its cache. */
interface ServesFromCache {
	/**
	 * @param array<string, string|string[]> $headers Response headers, keys lower-cased.
	 * @param string                         $body    Response body, for plugins that stamp an HTML comment instead of a header.
	 * @return string|null A short description when this layer served a cached copy, else null.
	 */
	public function servedFrom( array $headers, string $body = '' ): ?string;
}
