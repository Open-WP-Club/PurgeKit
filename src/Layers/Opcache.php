<?php
namespace PurgeKit\Layers;

/** The PHP OPcache functions behind one small interface, so the layer can be tested without the extension. */
interface Opcache {
	/** opcache_get_status( false ), or null when OPcache is not active in this PHP process. */
	public function status(): ?array;

	/** The opcache.* ini settings, by name without the prefix (validate_timestamps, restrict_api, ...). */
	public function config(): array;

	/** opcache_reset(); false when PHP refused (for example opcache.restrict_api). */
	public function reset(): bool;
}
