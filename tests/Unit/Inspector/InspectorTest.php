<?php
namespace PurgeKit\Tests\Unit\Inspector;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Inspector\Inspector;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeClock;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeLayer;

final class InspectorTest extends TestCase {
	private FakeHttp $http;

	private function inspector( FakeLayer ...$layers ): Inspector {
		$this->http = new FakeHttp();
		return new Inspector( $this->http, new Registry( $layers ), new FakeClock() );
	}

	private function layer( string $id, int $tier, ?string $served ): FakeLayer {
		$l         = new FakeLayer( $id, $tier );
		$l->served = $served;
		return $l;
	}

	public function test_matching_pages_are_ok(): void {
		$i = $this->inspector();
		$this->http->queue( new HttpResponse( 200, '<p>Hi</p>' ), new HttpResponse( 200, "<p>Hi</p>\n<!-- cached 12:00 -->" ) );
		$this->assertSame( Inspector::OK, $i->inspect( 'https://a.test/x' )['verdict'] );
	}

	public function test_fresh_request_uses_a_cache_buster(): void {
		$i = $this->inspector();
		$i->inspect( 'https://a.test/x?y=1' );
		$this->assertSame( 'https://a.test/x?y=1', $this->http->requests[0]['url'] );
		$this->assertMatchesRegularExpression( '#^https://a\.test/x\?y=1&purgekit_nocache=\d+$#', $this->http->requests[1]['url'] );
	}

	public function test_names_the_outermost_hitting_layer_as_stale(): void {
		$i = $this->inspector( $this->layer( 'plugin', 20, 'Plugin hit' ), $this->layer( 'cdn', 50, 'CDN hit' ), $this->layer( 'none', 30, null ) );
		$this->http->queue( new HttpResponse( 200, 'old', array( 'CF-Cache-Status' => 'HIT', 'X-Secret' => 'no' ) ), new HttpResponse( 200, 'new' ) );
		$r = $i->inspect( 'https://a.test/' );
		$this->assertSame( Inspector::STALE, $r['verdict'] );
		$this->assertSame( 'cdn', $r['layer'] );
		$this->assertSame( array( 'cdn', 'plugin' ), array_column( $r['served_by'], 'layer' ) );
		$this->assertArrayHasKey( 'cf-cache-status', $r['visitor']['headers'] );
		$this->assertArrayNotHasKey( 'x-secret', $r['visitor']['headers'] );
	}

	public function test_difference_without_a_known_layer(): void {
		$i = $this->inspector( $this->layer( 'plugin', 20, null ) );
		$this->http->queue( new HttpResponse( 200, 'a' ), new HttpResponse( 200, 'b' ) );
		$r = $i->inspect( 'https://a.test/' );
		$this->assertSame( Inspector::DIFFERENT, $r['verdict'] );
		$this->assertNull( $r['layer'] );
	}

	public function test_http_error_status_is_reported_as_an_error(): void {
		$i = $this->inspector();
		$this->http->queue( new HttpResponse( 404, 'x' ), new HttpResponse( 200, 'x' ) );
		$this->assertSame( Inspector::ERROR, $i->inspect( 'https://a.test/' )['verdict'] );
	}

	public function test_matching_server_errors_are_never_reported_as_fresh_content(): void {
		$i = $this->inspector();
		$this->http->queue( new HttpResponse( 503, 'error' ), new HttpResponse( 503, 'error' ) );
		$this->assertSame( Inspector::ERROR, $i->inspect( 'https://a.test/' )['verdict'] );
	}

	public function test_transport_error(): void {
		$i = $this->inspector();
		$this->http->queue( new HttpException( 'timeout' ) );
		$r = $i->inspect( 'https://a.test/' );
		$this->assertSame( Inspector::ERROR, $r['verdict'] );
		$this->assertStringContainsString( 'timeout', $r['message'] );
	}
}
