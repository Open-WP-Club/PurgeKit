<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\CallbackLayer;
use PurgeKit\Layers\Tier;

final class CallbackLayerTest extends TestCase {
	public function test_callback_layer_falls_back_to_purge_all_without_url_callback(): void {
		$calls = 0;
		$layer = new CallbackLayer( 'x', 'X', Tier::PLUGIN, static fn() => true, function () use ( &$calls ) {
			++$calls;
		} );
		$result = $layer->purgeUrls( array( 'https://a.test/' ) );
		$this->assertSame( 1, $calls );
		$this->assertSame( LayerResult::OK, $result->status );
		$this->assertSame( 'x', $result->layer );
	}

	public function test_url_callback_receives_urls(): void {
		$got   = null;
		$layer = new CallbackLayer( 'x', 'X', Tier::PLUGIN, static fn() => true, static fn() => null, function ( array $u ) use ( &$got ) {
			$got = $u;
		} );
		$layer->purgeUrls( array( 'https://a.test/a' ) );
		$this->assertSame( array( 'https://a.test/a' ), $got );
	}

	public function test_detect_uses_callback(): void {
		$layer = new CallbackLayer( 'x', 'X', Tier::HOST, static fn() => false, static fn() => null );
		$this->assertFalse( $layer->detect() );
		$this->assertSame( Tier::HOST, $layer->tier() );
	}
}
