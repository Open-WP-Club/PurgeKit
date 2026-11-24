<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\CloudflareLayer;
use PurgeKit\Secrets\Secrets;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeStore;

final class CloudflareLayerTest extends TestCase {
	private FakeHttp $http;

	private function layer( bool $token = true, bool $zone = true ): CloudflareLayer {
		$this->http = new FakeHttp();
		$settings   = new FakeStore();
		$secrets    = new Secrets( new FakeStore(), 'k' );
		if ( $token ) {
			$secrets->set( 'CLOUDFLARE_TOKEN', 'SECRET_TOKEN' );
		}
		if ( $zone ) {
			$settings->set( 'cloudflare_zone', 'zone123' );
		}
		return new CloudflareLayer( $this->http, $secrets, $settings );
	}

	public function test_undetected_without_token(): void {
		$this->assertFalse( $this->layer( false )->detect() );
		$this->assertFalse( $this->layer( true, false )->detect() );
		$this->assertTrue( $this->layer()->detect() );
	}

	public function test_purge_all_body_and_auth(): void {
		$l = $this->layer();
		$r = $l->purgeAll();
		$this->assertSame( LayerResult::OK, $r->status );
		$req = $this->http->requests[0];
		$this->assertSame( 'POST', $req['method'] );
		$this->assertSame( 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache', $req['url'] );
		$this->assertSame( '{"purge_everything":true}', $req['args']['body'] );
		$this->assertSame( 'Bearer SECRET_TOKEN', $req['args']['headers']['Authorization'] );
	}

	public function test_65_urls_makes_3_requests_of_30_30_5(): void {
		$l    = $this->layer();
		$urls = array_map( static fn( $i ) => "https://a.test/$i", range( 1, 65 ) );
		$l->purgeUrls( $urls );
		$this->assertCount( 3, $this->http->requests );
		$sizes = array_map( static fn( $r ) => count( json_decode( $r['args']['body'], true )['files'] ), $this->http->requests );
		$this->assertSame( array( 30, 30, 5 ), $sizes );
	}

	public function test_failure_message_hides_token(): void {
		$l = $this->layer();
		$this->http->queue( new HttpResponse( 403, '{"success":false,"errors":[{"message":"Bad token SECRET_TOKEN"}]}' ) );
		$r = $l->purgeAll();
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertStringNotContainsString( 'SECRET_TOKEN', $r->message );
		$this->assertStringContainsString( 'Bad token', $r->message );
	}

	public function test_transport_error_is_failed(): void {
		$l = $this->layer();
		$this->http->queue( new \PurgeKit\Core\HttpException( 'timeout' ) );
		$this->assertSame( LayerResult::FAILED, $l->purgeAll()->status );
	}

	public function test_failed_batch_stops_and_reports(): void {
		$l = $this->layer();
		$this->http->queue( new HttpResponse( 200, '{"success":true}' ), new HttpResponse( 500, 'oops' ) );
		$urls = array_map( static fn( $i ) => "https://a.test/$i", range( 1, 65 ) );
		$r    = $l->purgeUrls( $urls );
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertCount( 2, $this->http->requests );
	}
}
