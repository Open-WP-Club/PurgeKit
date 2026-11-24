<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;

final class CallbackLayer extends AbstractLayer implements ServesFromCache {
	/** @var callable */
	private $detect;
	/** @var callable */
	private $purge_all;
	/** @var callable|null */
	private $purge_urls;
	/** @var callable|null */
	private $fingerprint;

	public function __construct(
		private string $id,
		private string $label,
		private int $tier,
		callable $detect,
		callable $purge_all,
		?callable $purge_urls = null,
		?callable $fingerprint = null,
		private bool $all_only = false
	) {
		$this->fingerprint = $fingerprint;
		$this->detect      = $detect;
		$this->purge_all   = $purge_all;
		$this->purge_urls  = $purge_urls;
	}

	public function id(): string {
		return $this->id;
	}
	public function label(): string {
		return $this->label;
	}
	public function tier(): int {
		return $this->tier;
	}
	public function detect(): bool {
		return (bool) ( $this->detect )();
	}

	public function servedFrom( array $headers, string $body = '' ): ?string {
		return $this->fingerprint ? ( $this->fingerprint )( $headers, $body ) : null;
	}

	public function purgeAll(): LayerResult {
		( $this->purge_all )();
		return $this->ok();
	}

	public function purgeUrls( array $urls ): LayerResult {
		if ( $this->all_only ) {
			return new LayerResult( $this->id, LayerResult::SKIPPED, 'Only cleared when everything is purged.' );
		}
		if ( null === $this->purge_urls ) {
			return $this->purgeAll();
		}
		( $this->purge_urls )( $urls );
		return $this->ok();
	}
}
