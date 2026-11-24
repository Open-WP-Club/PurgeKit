<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;

abstract class AbstractLayer implements LayerInterface {
	protected function ok( string $message = '' ): LayerResult {
		return new LayerResult( $this->id(), LayerResult::OK, $message );
	}

	protected function fail( string $message ): LayerResult {
		return new LayerResult( $this->id(), LayerResult::FAILED, $message );
	}

	public function test(): LayerResult {
		return $this->detect() ? $this->ok() : new LayerResult( $this->id(), LayerResult::SKIPPED, 'Not detected' );
	}
}
