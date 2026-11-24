<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;

interface LayerInterface {
	public function id(): string;
	public function label(): string;
	/** One of the Tier constants; purging runs ascending. */
	public function tier(): int;
	public function detect(): bool;
	public function purgeUrls( array $urls ): LayerResult;
	public function purgeAll(): LayerResult;
	public function test(): LayerResult;
}
