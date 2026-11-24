<?php
namespace PurgeKit\Core;

final class PurgePlan {
	public function __construct( public bool $all, public array $urls = array() ) {}

	public static function everything(): self {
		return new self( true );
	}

	public static function forUrls( array $urls ): self {
		return new self( false, $urls );
	}

	public function isEmpty(): bool {
		return ! $this->all && array() === $this->urls;
	}
}
