<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;

/** A layer that can purge by cache tag (surrogate key) as well as by URL. */
interface TagPurger {
	/** @param string[] $tags */
	public function purgeTags( array $tags ): LayerResult;
}
