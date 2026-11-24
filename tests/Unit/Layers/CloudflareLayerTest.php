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

	public function test_test_skips_when_undetected(): void {
		$this->assertSame( LayerResult::SKIPPED, $this->layer( false )->test()->status );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_test_calls_zone_endpoint_read_only(): void {
		$l = $this->layer();
		$r = $l->test();
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertSame( 'https://api.cloudflare.com/client/v4/zones/zone123', $this->http->requests[0]['url'] );
		$this->assertSame( 'Bearer SECRET_TOKEN', $this->http->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_test_reports_failure_without_token(): void {
		$l = $this->layer();
		$this->http->queue( new HttpResponse( 403, '{"success":false,"errors":[{"message":"Invalid SECRET_TOKEN"}]}' ) );
		$r = $l->test();
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertStringNotContainsString( 'SECRET_TOKEN', $r->message );
	}

	public function test_find_zone_returns_the_matching_id(): void {
		$l = $this->layer( false, false );
		$this->http->queue( new HttpResponse( 200, '{"success":true,"result":[{"id":"zoneABC","name":"example.com"}]}' ) );
		$r = $l->findZone( 'example.com', 'TYPED_TOKEN' );
		$this->assertSame( 'zoneABC', $r['zone'] );
		$this->assertSame( 'https://api.cloudflare.com/client/v4/zones?name=example.com', $this->http->requests[0]['url'] );
		$this->assertSame( 'Bearer TYPED_TOKEN', $this->http->requests[0]['args']['headers']['Authorization'] );
	}

	public function test_find_zone_tries_parent_domains(): void {
		$l = $this->layer();
		$this->http->queue(
			new HttpResponse( 200, '{"success":true,"result":[]}' ),
			new HttpResponse( 200, '{"success":true,"result":[{"id":"zoneP","name":"example.com"}]}' )
		);
		$this->assertSame( 'zoneP', $l->findZone( 'www.shop.example.com', null )['zone'] );
		$this->assertCount( 2, $this->http->requests );
	}

	public function test_find_zone_reports_nothing_found_and_hides_token(): void {
		$l = $this->layer();
		$this->http->queue( new HttpResponse( 200, '{"success":true,"result":[]}' ), new HttpResponse( 200, '{"success":true,"result":[]}' ) );
		$r = $l->findZone( 'a.example.com', null );
		$this->assertNull( $r['zone'] );
		$this->http->queue( new HttpResponse( 403, '{"success":false,"errors":[{"message":"Bad SECRET_TOKEN"}]}' ) );
		$r = $l->findZone( 'example.com', null );
		$this->assertNull( $r['zone'] );
		$this->assertStringNotContainsString( 'SECRET_TOKEN', $r['message'] );
	}

	public function test_find_zone_without_any_token_does_not_call_the_api(): void {
		$l = $this->layer( false, false );
		$r = $l->findZone( 'example.com', null );
		$this->assertNull( $r['zone'] );
		$this->assertCount( 0, $this->http->requests );
	}

	private function json( array $result, bool $success = true, int $status = 200 ): HttpResponse {
		return new HttpResponse( $status, json_encode( array( 'success' => $success, 'result' => $result, 'errors' => array() ) ) );
	}

	public function test_purge_by_prefix_sends_prefixes_without_scheme_and_drops_bad_ones(): void {
		$l = $this->layer();
		$r = $l->purgeBy( 'prefixes', array( 'https://Example.com/blog/', 'example.com/shop/', 'example.com/a?x=1', 'example.com/b#frag', 'bad prefix', '', 5, 'example.com/blog/' ) );
		$this->assertSame( LayerResult::OK, $r->status );
		$this->assertSame( 'Purged 2 prefixes.', $r->message );
		$req = $this->http->requests[0];
		$this->assertSame( 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache', $req['url'] );
		$this->assertSame( array( 'prefixes' => array( 'example.com/blog/', 'example.com/shop/' ) ), json_decode( $req['args']['body'], true ) );
	}

	public function test_purge_by_tag_and_host_and_the_100_per_request_limit(): void {
		$l = $this->layer();
		$l->purgeBy( 'tags', array( 'post-1', 'term 5', 'a,b', 'term-5' ) );
		$this->assertSame( array( 'tags' => array( 'post-1', 'term-5' ) ), json_decode( $this->http->requests[0]['args']['body'], true ) );

		$l->purgeBy( 'hosts', array( 'WWW.Example.com', 'bad host' ) );
		$this->assertSame( array( 'hosts' => array( 'www.example.com' ) ), json_decode( $this->http->requests[1]['args']['body'], true ) );

		$many = array_map( static fn( $i ) => "tag-$i", range( 1, 250 ) );
		$l->purgeBy( 'tags', $many );
		$this->assertCount( 5, $this->http->requests, '250 tags are sent as 100 + 100 + 50, after the two earlier calls.' );
		$this->assertCount( 100, json_decode( $this->http->requests[2]['args']['body'], true )['tags'] );
		$this->assertCount( 50, json_decode( $this->http->requests[4]['args']['body'], true )['tags'] );
	}

	public function test_purge_by_refuses_when_nothing_valid_is_left_and_makes_no_request(): void {
		$l = $this->layer();
		$r = $l->purgeBy( 'prefixes', array( 'https://example.com/?q=1', ' ' ) );
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertSame( array(), $this->http->requests );
	}

	public function test_rate_limit_and_token_are_reported_safely(): void {
		$l = $this->layer();
		$this->http->queue( new HttpResponse( 429, json_encode( array( 'success' => false, 'errors' => array( array( 'message' => 'Rate limited for SECRET_TOKEN' ) ) ) ) ) );
		$r = $l->purgeBy( 'tags', array( 'a' ) );
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertStringContainsString( '5 a minute', $r->message );
		$this->assertStringNotContainsString( 'SECRET_TOKEN', $r->message );
	}

	public function test_development_mode_reads_and_sets_with_a_per_setting_call(): void {
		$l = $this->layer();
		$this->http->queue( $this->json( array( 'value' => 'on', 'time_remaining' => 7200 ) ) );
		$on = $l->developmentMode();
		$this->assertTrue( $on['ok'] );
		$this->assertTrue( $on['on'] );
		$this->assertSame( 7200, $on['seconds_left'] );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertSame( 'https://api.cloudflare.com/client/v4/zones/zone123/settings/development_mode', $this->http->requests[0]['url'] );

		$this->http->queue( $this->json( array( 'value' => 'off', 'time_remaining' => -500 ) ) );
		$off = $l->developmentMode( false );
		$this->assertFalse( $off['on'] );
		$this->assertSame( 0, $off['seconds_left'] );
		$this->assertSame( 'PATCH', $this->http->requests[1]['method'] );
		$this->assertSame( array( 'value' => 'off' ), json_decode( $this->http->requests[1]['args']['body'], true ) );

		$l->developmentMode( true );
		$this->assertSame( array( 'value' => 'on' ), json_decode( $this->http->requests[2]['args']['body'], true ) );
	}

	public function test_development_mode_that_was_never_switched_on_reads_as_off(): void {
		$l = $this->layer();
		$this->http->queue( $this->json( array( 'value' => 'off', 'time_remaining' => false ) ) );
		$this->assertSame( 0, $l->developmentMode()['seconds_left'] );
	}

	public function test_a_token_without_the_settings_permission_gets_a_clear_hint(): void {
		$l = $this->layer();
		$this->http->queue( new HttpResponse( 403, json_encode( array( 'success' => false, 'errors' => array( array( 'message' => 'Authentication error' ) ) ) ) ) );
		$r = $l->developmentMode( true );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'Authentication error', $r['message'] );
		$this->assertStringContainsString( 'Zone Settings', $r['message'] );
	}

	public function test_apo_status_is_read_only(): void {
		$l = $this->layer();
		$this->http->queue( $this->json( array( 'id' => 'automatic_platform_optimization', 'value' => array( 'enabled' => true, 'cf' => true, 'wordpress' => true, 'wp_plugin' => false, 'hostnames' => array( 'example.com' ) ) ) ) );
		$apo = $l->apoStatus();
		$this->assertTrue( $apo['ok'] );
		$this->assertTrue( $apo['enabled'] );
		$this->assertFalse( $apo['wp_plugin'] );
		$this->assertSame( array( 'example.com' ), $apo['hostnames'] );
		$this->assertCount( 1, $this->http->requests );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertStringEndsWith( '/settings/automatic_platform_optimization', $this->http->requests[0]['url'] );
	}
}
