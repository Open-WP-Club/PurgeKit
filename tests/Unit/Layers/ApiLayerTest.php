<?php
namespace PurgeKit\Tests\Unit\Layers;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\LayerResult;
use PurgeKit\Layers\ApiLayer;
use PurgeKit\Layers\ProviderSpecs;
use PurgeKit\Secrets\Secrets;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeStore;

final class ApiLayerTest extends TestCase {
	private FakeHttp $http;
	private FakeStore $settings;
	private Secrets $secrets;

	private function layer( string $id, array $values = array() ): ApiLayer {
		$this->http     = new FakeHttp();
		$this->settings = new FakeStore();
		$this->secrets  = new Secrets( new FakeStore(), 'k' );
		$spec           = ProviderSpecs::all()[ $id ];
		foreach ( $spec['fields'] as $f ) {
			$v = $values[ $f['key'] ] ?? ( 'secret' === $f['kind'] ? 'SECRET_' . $f['key'] : 'id123' );
			'secret' === $f['kind'] ? $this->secrets->set( $f['key'], $v ) : $this->settings->set( $f['key'], $v );
		}
		return new ApiLayer( $spec, $this->http, $this->secrets, $this->settings );
	}

	public function test_every_provider_spec_is_well_formed(): void {
		foreach ( ProviderSpecs::all() as $id => $spec ) {
			$this->assertSame( $id, $spec['id'] );
			$this->assertNotEmpty( $spec['label'] );
			foreach ( array_merge( $spec['secrets'], $spec['settings'] ) as $key ) {
				$this->assertContains( $key, array_column( $spec['fields'], 'key' ), "$id field $key" );
			}
			foreach ( array( 'all', 'url', 'test' ) as $part ) {
				if ( isset( $spec[ $part ] ) ) {
					$url = $spec[ $part ]['url'];
					if ( str_starts_with( $url, '{' ) ) {
						// The address is supplied by the site owner, so the field itself must insist on http(s).
						$field = array_values( array_filter( $spec['fields'], static fn( $f ) => '{' . $f['key'] . '}' === $url ) )[0] ?? array();
						$this->assertNotEmpty( $field['pattern'] ?? '', "$id $part takes a user URL, so its field needs a pattern" );
					} else {
						$this->assertStringStartsWith( 'https://', $url, "$id $part must use https" );
					}
				}
			}
			$this->assertSame( array(), array_intersect( array_keys( ProviderSpecs::writableFields() ), array() ) );
		}
	}

	public function test_detection_needs_every_secret_and_setting(): void {
		$l = $this->layer( 'fastly' );
		$this->assertTrue( $l->detect() );
		$this->settings->set( 'fastly_service_id', '  ' );
		$this->assertFalse( $l->detect() );
		$sucuri = $this->layer( 'sucuri' );
		$this->assertTrue( $sucuri->detect() );
	}

	public function test_fastly_purge_all_and_url(): void {
		$l = $this->layer( 'fastly' );
		$this->assertSame( LayerResult::OK, $l->purgeAll()->status );
		$r = $this->http->requests[0];
		$this->assertSame( 'POST', $r['method'] );
		$this->assertSame( 'https://api.fastly.com/service/id123/purge_all', $r['url'] );
		$this->assertSame( 'SECRET_FASTLY_TOKEN', $r['args']['headers']['Fastly-Key'] );
		$l->purgeUrls( array( 'https://a.test/x?y=1' ) );
		$this->assertSame( 'https://api.fastly.com/purge/a.test/x?y=1', $this->http->requests[1]['url'] );
	}

	public function test_bunny_url_purge_is_exact_and_encoded(): void {
		$l = $this->layer( 'bunny' );
		$l->purgeUrls( array( 'https://a.test/' ) );
		$this->assertSame( 'https://api.bunny.net/purge?url=https%3A%2F%2Fa.test%2F&async=false&exactPath=true', $this->http->requests[0]['url'] );
		$this->assertSame( 'SECRET_BUNNY_ACCESS_KEY', $this->http->requests[0]['args']['headers']['AccessKey'] );
		$l->purgeAll();
		$this->assertSame( 'https://api.bunny.net/pullzone/id123/purgeCache', $this->http->requests[1]['url'] );
	}

	public function test_keycdn_batches_urls_without_scheme_and_uses_basic_auth(): void {
		$l    = $this->layer( 'keycdn' );
		$urls = array_map( static fn( $i ) => "https://a.test/$i", range( 1, 120 ) );
		$l->purgeUrls( $urls );
		$this->assertCount( 3, $this->http->requests );
		$req = $this->http->requests[0];
		$this->assertSame( 'DELETE', $req['method'] );
		$body = json_decode( $req['args']['body'], true );
		$this->assertCount( 50, $body['urls'] );
		$this->assertSame( 'a.test/1', $body['urls'][0] );
		$this->assertSame( 'Basic ' . base64_encode( 'SECRET_KEYCDN_API_KEY:' ), $req['args']['headers']['Authorization'] );
		$this->assertSame( 'application/json', $req['args']['headers']['Content-Type'] );
	}

	public function test_sucuri_is_form_encoded_and_url_purge_falls_back_to_purge_all(): void {
		$l = $this->layer( 'sucuri' );
		$l->purgeUrls( array( 'https://a.test/x' ) );
		$req = $this->http->requests[0];
		$this->assertSame( 'https://waf.sucuri.net/api?v2', $req['url'] );
		parse_str( $req['args']['body'], $form );
		$this->assertSame( array( 'k' => 'SECRET_SUCURI_API_KEY', 's' => 'SECRET_SUCURI_API_SECRET', 'a' => 'clear_cache' ), $form );
		$this->assertSame( 'application/x-www-form-urlencoded', $req['args']['headers']['Content-Type'] );
	}

	public function test_errors_never_leak_secrets(): void {
		$l = $this->layer( 'bunny' );
		$this->http->queue( new HttpResponse( 401, '{"Message":"Bad key SECRET_BUNNY_ACCESS_KEY"}' ) );
		$r = $l->purgeAll();
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertStringNotContainsString( 'SECRET_BUNNY_ACCESS_KEY', $r->message );
		$this->http->queue( new HttpException( 'cannot reach SECRET_BUNNY_ACCESS_KEY' ) );
		$this->assertStringNotContainsString( 'SECRET_', $l->purgeAll()->message );
	}

	public function test_a_200_with_an_error_body_is_a_failure(): void {
		$l = $this->layer( 'sucuri' );
		$this->http->queue( new HttpResponse( 200, '{"status":0,"error":1,"messages":["Invalid API key"]}' ) );
		$r = $l->purgeAll();
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertStringContainsString( 'Invalid API key', $r->message );
	}

	public function test_a_failed_batch_stops_the_rest(): void {
		$l = $this->layer( 'keycdn' );
		$this->http->queue( new HttpResponse( 500, 'oops' ) );
		$r = $l->purgeUrls( array_map( static fn( $i ) => "https://a.test/$i", range( 1, 120 ) ) );
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_test_uses_the_read_only_call_or_reports_none(): void {
		$l = $this->layer( 'fastly' );
		$this->assertSame( LayerResult::OK, $l->test()->status );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertSame( 'https://api.fastly.com/service/id123', $this->http->requests[0]['url'] );
		$s = $this->layer( 'sucuri' );
		$this->assertSame( LayerResult::OK, $s->test()->status );
		$this->assertCount( 0, $this->http->requests, 'Sucuri has no read-only check, so nothing is sent.' );
	}

	public function test_fingerprints(): void {
		$f = $this->layer( 'fastly' );
		$this->assertNotNull( $f->servedFrom( array( 'x-cache' => 'HIT, HIT', 'x-served-by' => 'cache-fra123' ) ) );
		$this->assertNull( $f->servedFrom( array( 'x-cache' => 'HIT' ) ), 'x-cache alone is not specific to Fastly.' );
		$this->assertNotNull( $this->layer( 'bunny' )->servedFrom( array( 'cdn-cache' => 'HIT' ) ) );
		$this->assertNull( $this->layer( 'bunny' )->servedFrom( array( 'cdn-cache' => 'MISS' ) ) );
	}

	public function test_custom_webhook_posts_the_event_and_the_full_urls(): void {
		$l = $this->layer( 'webhook', array( 'PURGE_WEBHOOK_URL' => 'https://front.test/api/revalidate?token=T0KEN' ) );
		$this->assertTrue( $l->detect() );

		$this->assertSame( LayerResult::OK, $l->purgeAll()->status );
		$req = $this->http->requests[0];
		$this->assertSame( 'POST', $req['method'] );
		$this->assertSame( 'https://front.test/api/revalidate?token=T0KEN', $req['url'] );
		$this->assertSame( array( 'event' => 'purge', 'all' => true ), json_decode( $req['args']['body'], true ) );

		$this->assertSame( LayerResult::OK, $l->purgeUrls( array( 'https://a.test/x/', 'https://a.test/y/' ) )->status );
		$this->assertSame(
			array( 'event' => 'purge', 'all' => false, 'urls' => array( 'https://a.test/x/', 'https://a.test/y/' ) ),
			json_decode( $this->http->requests[1]['args']['body'], true )
		);
	}

	public function test_custom_webhook_batches_urls_and_hides_the_token_in_errors(): void {
		$l    = $this->layer( 'webhook', array( 'PURGE_WEBHOOK_URL' => 'https://front.test/hook?token=T0KEN' ) );
		$urls = array_map( static fn( $i ) => "https://a.test/$i/", range( 1, 250 ) );
		$l->purgeUrls( $urls );
		$this->assertCount( 3, $this->http->requests );
		$this->assertCount( 100, json_decode( $this->http->requests[0]['args']['body'], true )['urls'] );

		$this->http->queue( new \PurgeKit\Core\HttpException( 'cURL error: could not connect to https://front.test/hook?token=T0KEN' ) );
		$r = $l->purgeAll();
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertStringNotContainsString( 'T0KEN', $r->message );
	}

	public function test_custom_webhook_refuses_a_non_http_address_and_makes_no_request(): void {
		$l = $this->layer( 'webhook', array( 'PURGE_WEBHOOK_URL' => 'file:///etc/passwd' ) );
		$r = $l->purgeAll();
		$this->assertSame( LayerResult::FAILED, $r->status );
		$this->assertSame( array(), $this->http->requests );
		$this->assertFalse( \PurgeKit\Layers\ProviderSpecs::accepts( 'PURGE_WEBHOOK_URL', 'ftp://x.test/a' ) );
		$this->assertTrue( \PurgeKit\Layers\ProviderSpecs::accepts( 'PURGE_WEBHOOK_URL', 'https://x.test/a?t=1' ) );
		$this->assertTrue( \PurgeKit\Layers\ProviderSpecs::accepts( 'FASTLY_TOKEN', 'anything' ) );
	}
}
