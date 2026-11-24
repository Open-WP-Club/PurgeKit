<?php
namespace PurgeKit\Layers;

/** The APCu functions behind a small interface, so the layer can be tested without the extension. */
interface Apcu {
	/**
	 * What APCu is holding, or null when it is not active in this PHP process.
	 *
	 * @return array{entries:int,hits:int,misses:int,mem_used:int,mem_total:int}|null
	 */
	public function status(): ?array;

	/** apcu_clear_cache(). */
	public function clear(): bool;
}
