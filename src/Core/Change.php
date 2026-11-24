<?php
namespace PurgeKit\Core;

final class Change {
	public function __construct(
		public string $type,
		public array $urls = array(),
		public bool $site_wide = false,
		public array $context = array()
	) {}

	public static function forUrls( string $type, array $urls, array $context = array() ): self {
		return new self( $type, $urls, false, $context );
	}

	public static function siteWide( string $type, array $context = array() ): self {
		return new self( $type, array(), true, $context );
	}
}
