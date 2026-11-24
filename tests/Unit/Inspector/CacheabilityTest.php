<?php
namespace PurgeKit\Tests\Unit\Inspector;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Inspector\Cacheability;
use PurgeKit\Layers\Registry;
use PurgeKit\Tests\Fakes\FakeHttp;
use PurgeKit\Tests\Fakes\FakeLayer;

final class CacheabilityTest extends TestCase {
	private FakeHttp $http;

	private function checker( FakeLayer ...$layers ): Cacheability {
		$this->http = new FakeHttp();
		return new Cacheability( $this->http, new Registry( $layers ) );
	}

	private function page( array $headers = array(), int $status = 200 ): HttpResponse {
		return new HttpResponse( $status, '<html></html>', $headers );
	}

	private function codes( array $report, string $severity ): array {
		return array_values( array_map( static fn( $f ) => $f['code'], array_filter( $report['findings'], static fn( $f ) => $severity === $f['severity'] ) ) );
	}

	public function test_a_second_request_served_from_a_cache_is_cached(): void {
		$layer         = new FakeLayer( 'cf', 50 );
		$layer->served = 'Cloudflare edge cache (cf-cache-status: HIT)';
		$c             = $this->checker( $layer );
		$this->http->queue( $this->page( array( 'cache-control' => 'public, max-age=300' ) ), $this->page( array( 'age' => '12', 'cache-control' => 'public, max-age=300' ) ) );
		$r = $c->check( 'https://a.test/' );
		$this->assertSame( Cacheability::CACHED, $r['verdict'] );
		$this->assertContains( 'Cloudflare edge cache (cf-cache-status: HIT)', $r['evidence'] );
		$this->assertContains( 'Age: 12', $r['evidence'] );
		$this->assertContains( 'ok', array_column( $r['findings'], 'severity' ) );
	}

	public function test_two_anonymous_get_requests_that_do_not_follow_redirects(): void {
		$c = $this->checker();
		$c->check( 'https://a.test/x/' );
		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( 'GET', $this->http->requests[0]['method'] );
		$this->assertSame( 0, $this->http->requests[0]['args']['redirection'] );
		$this->assertArrayNotHasKey( 'cookies', $this->http->requests[0]['args'] );
		$this->assertArrayNotHasKey( 'Cookie', $this->http->requests[0]['args']['headers'] );
	}

	public function test_a_cookie_on_an_anonymous_visit_blocks_caching_and_only_names_are_reported(): void {
		$c = $this->checker();
		$this->http->queue(
			$this->page( array( 'set-cookie' => 'PHPSESSID=SECRETVALUE123; path=/; HttpOnly, wp_woocommerce_session_abc=OTHERSECRET%7C1; expires=Wed, 21 Oct 2026 07:28:00 GMT; Path=/' ) ),
			$this->page()
		);
		$r = $c->check( 'https://a.test/' );
		$this->assertSame( Cacheability::BLOCKED, $r['verdict'] );
		$this->assertSame( array( 'PHPSESSID', 'wp_woocommerce_session_abc' ), $r['cookies'] );
		$this->assertSame( array( 'cookie', 'cookie' ), $this->codes( $r, 'blocker' ) );
		$json = json_encode( $r );
		$this->assertStringNotContainsString( 'SECRETVALUE123', $json );
		$this->assertStringNotContainsString( 'OTHERSECRET', $json );
		$this->assertStringContainsString( 'session_start()', $json );
		$this->assertStringContainsString( 'WooCommerce', $json );
	}

	public function test_several_set_cookie_lines_as_an_array_are_understood(): void {
		$c = $this->checker();
		$this->http->queue( $this->page( array( 'Set-Cookie' => array( 'a=1; path=/', 'b=2; Expires=Wed, 21 Oct 2026 07:28:00 GMT' ) ) ), $this->page() );
		$this->assertSame( array( 'a', 'b' ), $c->check( 'https://a.test/' )['cookies'] );
	}

	/** @dataProvider cacheControlCases */
	public function test_cache_control_blockers( string $value, int $blockers ): void {
		$c = $this->checker();
		$this->http->queue( $this->page( array( 'cache-control' => $value ) ), $this->page() );
		$r = $c->check( 'https://a.test/' );
		$this->assertCount( $blockers, $this->codes( $r, 'blocker' ), $value );
		$this->assertSame( $blockers > 0 ? Cacheability::BLOCKED : Cacheability::UNCERTAIN, $r['verdict'], $value );
	}

	public static function cacheControlCases(): array {
		return array(
			'wordpress default for personal pages' => array( 'no-cache, must-revalidate, max-age=0', 1 ),
			'the login page sends everything'      => array( 'no-store, no-cache, must-revalidate, max-age=0, private', 1 ),
			'no-store'                              => array( 'no-store', 1 ),
			'private'                               => array( 'private, max-age=600', 1 ),
			'zero lifetime'                         => array( 'public, max-age=0', 1 ),
			'public with a lifetime'                => array( 'public, max-age=3600', 0 ),
			'shared cache lifetime'                 => array( 's-maxage=600', 0 ),
		);
	}

	public function test_no_cache_control_is_only_an_info_note(): void {
		$c = $this->checker();
		$r = $c->check( 'https://a.test/' );
		$this->assertSame( array( 'cache-control' ), $this->codes( $r, 'info' ) );
		$this->assertSame( array(), $this->codes( $r, 'blocker' ) );
		$this->assertSame( Cacheability::UNCERTAIN, $r['verdict'] );
	}

	public function test_vary_pragma_and_expires(): void {
		$c = $this->checker();
		$this->http->queue( $this->page( array( 'vary' => 'Accept-Encoding, Cookie, User-Agent', 'pragma' => 'no-cache', 'expires' => 'Thu, 01 Jan 1970 00:00:00 GMT' ) ), $this->page() );
		$r = $c->check( 'https://a.test/' );
		$this->assertSame( array( 'pragma', 'expires', 'vary', 'vary' ), $this->codes( $r, 'warning' ) );

		$c2 = $this->checker();
		$this->http->queue( $this->page( array( 'vary' => '*' ) ), $this->page() );
		$this->assertContains( 'vary', $this->codes( $c2->check( 'https://a.test/' ), 'blocker' ) );
	}

	public function test_status_codes_and_redirects(): void {
		$c = $this->checker();
		$this->http->queue( $this->page( array(), 500 ), $this->page( array(), 500 ) );
		$this->assertSame( array( 'status' ), $this->codes( $c->check( 'https://a.test/' ), 'blocker' ) );

		$c = $this->checker();
		$this->http->queue( $this->page( array(), 404 ), $this->page( array(), 404 ) );
		$this->assertSame( array( 'status' ), $this->codes( $c->check( 'https://a.test/x' ), 'warning' ) );

		$c = $this->checker();
		$this->http->queue( $this->page( array( 'location' => 'https://a.test/new/' ), 301 ), $this->page( array( 'location' => 'https://a.test/new/' ), 301 ) );
		$r = $c->check( 'https://a.test/old/' );
		$this->assertContains( 'redirect', $this->codes( $r, 'info' ) );
		$this->assertStringContainsString( 'https://a.test/new/', json_encode( $r, JSON_UNESCAPED_SLASHES ) );
	}

	public function test_query_strings_are_flagged(): void {
		$c = $this->checker();
		$this->assertContains( 'query', $this->codes( $c->check( 'https://a.test/?s=cats' ), 'warning' ) );
		$c = $this->checker();
		$this->assertNotContains( 'query', $this->codes( $c->check( 'https://a.test/page/' ), 'warning' ) );
	}

	public function test_cloudflare_dynamic_and_bypass_are_explained(): void {
		$c = $this->checker();
		$this->http->queue( $this->page( array( 'cf-cache-status' => 'DYNAMIC' ) ), $this->page( array( 'cf-cache-status' => 'DYNAMIC' ) ) );
		$r = $c->check( 'https://a.test/' );
		$this->assertSame( Cacheability::BLOCKED, $r['verdict'] );
		$this->assertStringContainsString( 'Cache everything', json_encode( $r ) );

		$c = $this->checker();
		$this->http->queue( $this->page( array( 'cf-cache-status' => 'BYPASS' ) ), $this->page( array( 'cf-cache-status' => 'BYPASS' ) ) );
		$this->assertSame( Cacheability::BLOCKED, $c->check( 'https://a.test/' )['verdict'] );
	}

	public function test_a_hit_header_counts_even_without_a_known_layer(): void {
		$c = $this->checker();
		$this->http->queue( $this->page(), $this->page( array( 'x-cache' => 'HIT from edge' ) ) );
		$r = $c->check( 'https://a.test/' );
		$this->assertSame( Cacheability::CACHED, $r['verdict'] );
		$this->assertContains( 'x-cache: HIT from edge', $r['evidence'] );
	}

	public function test_the_header_summary_never_contains_cookies(): void {
		$c = $this->checker();
		$this->http->queue( $this->page( array( 'set-cookie' => 'a=SECRET', 'cache-control' => 'private' ) ), $this->page( array( 'cache-control' => 'private', 'set-cookie' => 'a=SECRET' ) ) );
		$r = $c->check( 'https://a.test/' );
		$this->assertArrayNotHasKey( 'set-cookie', $r['first']['headers'] );
		$this->assertArrayNotHasKey( 'set-cookie', $r['second']['headers'] );
		$this->assertSame( 'private', $r['first']['headers']['cache-control'] );
	}

	public function test_a_failed_request_is_reported_not_thrown(): void {
		$c = $this->checker();
		$this->http->queue( new HttpException( 'timed out' ) );
		$r = $c->check( 'https://a.test/' );
		$this->assertSame( Cacheability::ERROR, $r['verdict'] );
		$this->assertStringContainsString( 'timed out', $r['message'] );
	}

	public function test_several_cache_control_blockers_are_one_finding(): void {
		$c = $this->checker();
		$this->http->queue( $this->page( array( 'cache-control' => 'no-store, no-cache, must-revalidate, max-age=0, private' ) ), $this->page() );
		$report = $c->check( 'https://a.test/wp-login.php' );
		$this->assertCount( 1, $this->codes( $report, 'blocker' ) );
		$message = $report['findings'][0]['message'];
		$this->assertStringContainsString( 'no-store', $message );
		$this->assertStringContainsString( 'Also set: private, no-cache', $message );
	}
}
