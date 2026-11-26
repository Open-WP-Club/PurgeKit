<?php
namespace PurgeKit\Core;

final class PurgePlan {
	/** @param string[] $tags Cache tags (surrogate keys). Layers that cannot purge by tag use the URLs. */
	public function __construct( public bool $all, public array $urls = array(), public array $tags = array(), public array $reasons = array() ) {}

	public static function everything(): self {
		return new self( true );
	}

	public static function forUrls( array $urls, array $tags = array() ): self {
		return new self( false, $urls, $tags );
	}

	public function isEmpty(): bool {
		return ! $this->all && array() === $this->urls && array() === $this->tags;
	}
}
