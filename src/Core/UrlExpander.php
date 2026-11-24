<?php
namespace PurgeKit\Core;

interface UrlExpander {
	/** @return string[] Absolute URLs affected by the change. */
	public function expand( Change $change ): array;
}
