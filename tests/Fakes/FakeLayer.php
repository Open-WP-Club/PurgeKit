<?php
namespace PurgeKit\Tests\Fakes;

use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\LayerInterface;

final class FakeLayer implements LayerInterface {
	public array $calls = array();

	public function __construct(
		private string $id,
		private int $tier,
		private bool $detected = true,
		private ?\Throwable $throws = null,
		private string $status = LayerResult::OK,
		private ?\ArrayObject $log = null
	) {}

	public function id(): string {
		return $this->id;
	}
	public function label(): string {
		return strtoupper( $this->id );
	}
	public function tier(): int {
		return $this->tier;
	}
	public function detect(): bool {
		return $this->detected;
	}
	public function purgeUrls( array $urls ): LayerResult {
		return $this->record( 'urls', $urls );
	}
	public function purgeAll(): LayerResult {
		return $this->record( 'all', array() );
	}
	public function test(): LayerResult {
		return new LayerResult( $this->id, LayerResult::OK );
	}

	private function record( string $kind, array $urls ): LayerResult {
		$this->calls[] = array( $kind, $urls );
		$this->log?->append( $this->tier );
		if ( $this->throws ) {
			throw $this->throws;
		}
		return new LayerResult( $this->id, $this->status );
	}
}
