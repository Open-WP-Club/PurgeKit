<?php
namespace PurgeKit\Tests\Integration\Rest;

use PurgeKit\Plugin;
use PurgeKit\Inspector\Variants;

final class VariantsTest extends \WP_UnitTestCase {
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' );
		add_filter( 'pre_http_request', array( $this, 'http' ), 10, 3 );
	}

	public function http( mixed $pre, array $args, string $url ): array {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array( 'age' => '60', 'set-cookie' => 'secret=value' ), 'body' => '<p>Same</p>', 'cookies' => array() );
	}

	private function call( array $variants ): \WP_REST_Response {
		$r = new \WP_REST_Request( 'POST', '/purgekit/v1/inspect/variants' );
		$r->set_param( 'variants', $variants );
		return rest_do_request( $r );
	}

	public function test_compares_variants_without_leaking_cookies_and_preserves_language_paths_and_queries(): void {
		$a = array( 'url' => home_url( '/bg/?currency=BGN' ), 'device' => 'mobile', 'language' => 'bg-BG' );
		$b = array( 'url' => home_url( '/en/?currency=EUR' ), 'device' => 'desktop', 'language' => 'en' );
		$r = $this->call( array( $a, $b ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertCount( 2, $r->get_data()['variants'] );
		$this->assertCount( 8, $this->requests );
		$this->assertSame( Variants::MOBILE, $this->requests[0]['args']['headers']['User-Agent'] );
		$this->assertSame( 'bg-BG', $this->requests[0]['args']['headers']['Accept-Language'] );
		$this->assertSame( Variants::DESKTOP, $this->requests[4]['args']['headers']['User-Agent'] );
		$this->assertStringNotContainsString( 'value', wp_json_encode( $r->get_data() ) );
		$this->assertSame( array( 'secret' ), $r->get_data()['variants'][0]['cacheability']['cookies'] );
	}

	public function test_all_inputs_are_validated_before_any_requests(): void {
		$good = array( 'url' => home_url( '/' ), 'device' => 'desktop', 'language' => '' );
		foreach ( array( array(), array_fill( 0, 5, $good ), array( $good, array_merge( $good, array( 'url' => 'https://external.test/' ) ) ), array( array_merge( $good, array( 'language' => "en\r\nCookie: bad" ) ) ), array( array_merge( $good, array( 'device' => 'crawler' ) ) ), array( array_merge( $good, array( 'url' => preg_replace( '#://([^/:]+)(?::[0-9]+)?#', '://$1:123', home_url( '/' ) ) ) ) ), array( array( 'url' => array() ) ) ) as $items ) {
			$this->assertSame( 400, $this->call( $items )->get_status() );
		}
		$this->assertSame( array(), $this->requests );
	}

	public function test_subscriber_cannot_make_probe_requests(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( array( array( 'url' => home_url( '/' ), 'device' => 'desktop', 'language' => '' ) ) )->get_status() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_reasons_survive_preview_flush_and_history(): void {
		$s = Plugin::services();
		$s->queue->drain();
		$s->history->install();
		$s->queue->add( \PurgeKit\Core\Change::forUrls( 'post', array( home_url( '/old/' ) ), array( 'post_id' => 999999, 'snapshot' => true ) ) );
		$preview = $s->flusher->preview();
		$this->assertStringContainsString( 'previous URL', $preview['jobs'][0]['reasons'][home_url( '/old/' )][0] );
		$s->flusher->flush( 'manual' );
		$row = $s->history->recent( 1, 0 )[0];
		$this->assertSame( $preview['jobs'][0]['reasons'], $row['trigger']['reasons'] );
		$this->assertSame( $preview['jobs'], $row['trigger']['jobs'] );
	}
}
