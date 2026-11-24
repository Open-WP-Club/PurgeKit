<?php
namespace PurgeKit\Tests\Integration\Rest;

use PurgeKit\Core\Change;
use PurgeKit\Plugin;
use PurgeKit\Services;

final class RestTest extends \WP_UnitTestCase {
	private Services $svc;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->svc = Plugin::services();
		$this->svc->history->install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . $this->svc->history->table() );
		$this->svc->queue->drain();
		$this->svc->lock->release( 'flush' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		do_action( 'rest_api_init' );
	}

	private function call( string $method, string $route, array $params = array(), array $headers = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/purgekit/v1' . $route );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		if ( $params ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}

	public function test_flush_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'POST', '/flush' )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', '/status' )->get_status() );
	}

	public function test_status_reports_pending_and_mode(): void {
		wp_set_current_user( $this->admin );
		$this->svc->queue->add( Change::siteWide( 'x' ) );
		$data = $this->call( 'GET', '/status' )->get_data();
		$this->assertSame( 1, $data['pending'] );
		$this->assertSame( 'notify', $data['mode'] );
		$this->assertArrayHasKey( 'layers_active', $data );
	}

	public function test_flush_all_runs_layers_clears_queue_and_returns_run(): void {
		wp_set_current_user( $this->admin );
		$this->svc->queue->add( Change::siteWide( 'x' ) );
		$res = $this->call( 'POST', '/flush' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'ok', $res->get_data()['status'] );
		$this->assertSame( 'object-cache', $res->get_data()['results'][0]['layer'] );
		$this->assertSame( 0, $this->svc->queue->count() );
	}

	public function test_flush_urls_only_purges_those(): void {
		wp_set_current_user( $this->admin );
		$res = $this->call( 'POST', '/flush', array( 'urls' => array( home_url( '/a/' ), 'https://evil.test/' ) ) );
		$this->assertSame( array( home_url( '/a/' ) ), $res->get_data()['urls'] );
	}

	public function test_flush_while_locked_is_409(): void {
		wp_set_current_user( $this->admin );
		$this->svc->lock->acquire( 'flush', 120 );
		$this->assertSame( 409, $this->call( 'POST', '/flush' )->get_status() );
	}

	public function test_layers_lists_detected_flag(): void {
		wp_set_current_user( $this->admin );
		$layers = $this->call( 'GET', '/layers' )->get_data();
		$byid   = array_column( $layers, null, 'id' );
		$this->assertTrue( $byid['object-cache']['detected'] );
		$this->assertFalse( $byid['cloudflare']['detected'] );
		$this->assertSame( 10, $byid['object-cache']['tier'] );
	}

	public function test_layer_test_endpoint(): void {
		wp_set_current_user( $this->admin );
		$this->assertSame( 'ok', $this->call( 'POST', '/layers/object-cache/test' )->get_data()['status'] );
		$this->assertSame( 404, $this->call( 'POST', '/layers/nope/test' )->get_status() );
	}

	public function test_history_paginates(): void {
		wp_set_current_user( $this->admin );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->svc->flusher->flushAll( "s$i" );
		}
		$req = new \WP_REST_Request( 'GET', '/purgekit/v1/history' );
		$req->set_query_params( array( 'per_page' => 2, 'page' => 2 ) );
		$res = rest_do_request( $req );
		$this->assertCount( 1, $res->get_data()['items'] );
		$this->assertSame( 3, $res->get_data()['total'] );
	}

	public function test_settings_roundtrip_and_token_is_write_only(): void {
		wp_set_current_user( $this->admin );
		$res  = $this->call( 'POST', '/settings', array( 'mode' => 'auto', 'cloudflare_token' => 'TOP_SECRET_TOKEN', 'cloudflare_zone' => 'z1' ) );
		$json = wp_json_encode( $res->get_data() );
		$this->assertStringNotContainsString( 'TOP_SECRET_TOKEN', $json );
		$this->assertTrue( $res->get_data()['cloudflare_token_set'] );
		$this->assertSame( 'auto', $res->get_data()['mode'] );
		$this->assertStringNotContainsString( 'TOP_SECRET_TOKEN', wp_json_encode( $this->call( 'GET', '/settings' )->get_data() ) );
		$this->assertSame( 'TOP_SECRET_TOKEN', $this->svc->secrets->get( 'CLOUDFLARE_TOKEN' ) );
	}

	public function test_settings_change_syncs_cron(): void {
		wp_set_current_user( $this->admin );
		$this->call( 'POST', '/settings', array( 'mode' => 'scheduled', 'schedule' => 'hourly' ) );
		$this->assertNotFalse( wp_next_scheduled( 'purgekit_scheduled_flush' ) );
	}

	public function test_webhook_rejects_missing_wrong_and_short_key(): void {
		wp_set_current_user( $this->admin );
		$key = $this->call( 'POST', '/settings/webhook-key/rotate' )->get_data()['key'];
		wp_set_current_user( 0 );
		$this->assertSame( 403, $this->call( 'POST', '/webhook/flush' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/webhook/flush', array(), array( 'X-PurgeKit-Key' => 'wrong' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/webhook/flush', array(), array( 'X-PurgeKit-Key' => substr( $key, 0, 5 ) ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/webhook/flush', array(), array( 'X-PurgeKit-Key' => '' ) )->get_status() );
	}

	public function test_webhook_with_no_key_configured_rejects_everything(): void {
		$this->svc->secrets->set( 'WEBHOOK_KEY', '' );
		wp_set_current_user( 0 );
		$this->assertSame( 403, $this->call( 'POST', '/webhook/flush', array(), array( 'X-PurgeKit-Key' => '' ) )->get_status() );
	}

	public function test_webhook_accepts_correct_key_and_flushes(): void {
		wp_set_current_user( $this->admin );
		$key = $this->call( 'POST', '/settings/webhook-key/rotate' )->get_data()['key'];
		wp_set_current_user( 0 );
		$res = $this->call( 'POST', '/webhook/flush', array(), array( 'X-PurgeKit-Key' => $key ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'ok', $res->get_data()['status'] );
		$this->assertSame( 'webhook', $this->svc->history->recent( 1, 0 )[0]['source'] );
	}

	public function test_rotate_replaces_key(): void {
		wp_set_current_user( $this->admin );
		$a = $this->call( 'POST', '/settings/webhook-key/rotate' )->get_data()['key'];
		$b = $this->call( 'POST', '/settings/webhook-key/rotate' )->get_data()['key'];
		$this->assertNotSame( $a, $b );
		$this->assertGreaterThanOrEqual( 32, strlen( $b ) );
	}
}
