<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\VarnishLayer;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeStore;

final class VarnishLayerTest extends TestCase {
	private function layer( bool $enabled = true ): array {
		$http     = new FakeHttp();
		$settings = new FakeStore();
		$settings->set( 'varnish_enabled', $enabled );
		return array( new VarnishLayer( $http, $settings, 'https://site.test' ), $http );
	}

	public function test_detect_follows_setting(): void {
		$this->assertTrue( $this->layer( true )[0]->detect() );
		$this->assertFalse( $this->layer( false )[0]->detect() );
	}

	public function test_purge_all_sends_regex_header(): void {
		list( $l, $http ) = $this->layer();
		$l->purgeAll();
		$this->assertSame( 'PURGE', $http->requests[0]['method'] );
		$this->assertSame( 'https://site.test/.*', $http->requests[0]['url'] );
		$this->assertSame( 'regex', $http->requests[0]['args']['headers']['X-Purge-Method'] );
	}

	public function test_purge_urls_one_request_each(): void {
		list( $l, $http ) = $this->layer();
		$r = $l->purgeUrls( array( 'https://site.test/a', 'https://site.test/b' ) );
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertCount( 2, $http->requests );
	}

	public function test_non_2xx_is_failed(): void {
		list( $l, $http ) = $this->layer();
		$http->queue( new HttpResponse( 405 ) );
		$this->assertSame( LayerResult::FAILED, $l->purgeAll()->status );
	}
}
