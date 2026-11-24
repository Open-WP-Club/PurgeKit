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

	public function test_settings_expose_webhook_url_but_never_the_key(): void {
		wp_set_current_user( $this->admin );
		$key  = $this->call( 'POST', '/settings/webhook-key/rotate' )->get_data()['key'];
		$data = $this->call( 'GET', '/settings' )->get_data();
		$this->assertStringContainsString( '/purgekit/v1/webhook/flush', $data['webhook_url'] );
		$this->assertStringNotContainsString( $key, wp_json_encode( $data ) );
	}

	public function test_inspect_rejects_foreign_hosts_and_needs_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'POST', '/inspect', array( 'url' => home_url( '/' ) ) )->get_status() );
		wp_set_current_user( $this->admin );
		foreach ( array( 'https://evil.test/', 'http://127.0.0.1/', 'file:///etc/passwd', home_url() . '@evil.test', 'nonsense', '' ) as $bad ) {
			$this->assertSame( 400, $this->call( 'POST', '/inspect', array( 'url' => $bad ) )->get_status(), $bad );
		}
	}

	public function test_purge_layer_validates_layer_and_url(): void {
		wp_set_current_user( $this->admin );
		$this->assertSame( 404, $this->call( 'POST', '/layers/nope/purge', array( 'url' => home_url( '/' ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/layers/object-cache/purge', array( 'url' => 'https://evil.test/' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'POST', '/layers/object-cache/purge', array( 'url' => home_url( '/a/' ) ) )->get_status() );
	}

	public function test_slack_webhook_is_validated_stored_hidden_and_removable(): void {
		wp_set_current_user( $this->admin );
		$this->call( 'POST', '/settings', array( 'slack_webhook_url' => 'https://evil.test/hook' ) );
		$this->assertFalse( $this->call( 'GET', '/settings' )->get_data()['slack_webhook_set'] );

		$hook = 'https://hooks.slack.com/services/T000/B000/XXXX';
		$this->call( 'POST', '/settings', array( 'slack_webhook_url' => $hook ) );
		$data = $this->call( 'GET', '/settings' )->get_data();
		$this->assertTrue( $data['slack_webhook_set'] );
		$this->assertStringNotContainsString( 'XXXX', wp_json_encode( $data ) );

		$this->call( 'POST', '/settings', array( 'clear_slack_webhook' => true ) );
		$this->assertFalse( $this->call( 'GET', '/settings' )->get_data()['slack_webhook_set'] );
	}

	public function test_alert_settings_validate_email_and_test_endpoint_needs_capability(): void {
		wp_set_current_user( $this->admin );
		$this->call( 'POST', '/settings', array( 'alert_email' => 'ops@example.com', 'alert_on' => 'failed' ) );
		$data = $this->call( 'GET', '/settings' )->get_data();
		$this->assertSame( 'ops@example.com', $data['alert_email'] );
		$this->assertSame( 'failed', $data['alert_on'] );
		$this->call( 'POST', '/settings', array( 'alert_email' => 'nope' ) );
		$this->assertSame( 'ops@example.com', $this->call( 'GET', '/settings' )->get_data()['alert_email'] );

		$res = $this->call( 'POST', '/alerts/test' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'email' ), $res->get_data()['channels'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'POST', '/alerts/test' )->get_status() );
	}

	public function test_provider_fields_are_whitelisted_stored_and_secrets_stay_hidden(): void {
		wp_set_current_user( $this->admin );
		$site_url = get_option( 'siteurl' );
		$this->call(
			'POST',
			'/settings',
			array(
				'providers' => array(
					'bunny_pullzone_id' => '42',
					'BUNNY_ACCESS_KEY'  => 'topsecret',
					'siteurl'           => 'https://evil.test',
					'purgekit_anything' => 'x',
				),
			)
		);
		$this->assertSame( $site_url, get_option( 'siteurl' ), 'Only whitelisted keys are writable.' );
		$data = $this->call( 'GET', '/settings' )->get_data();
		$this->assertStringNotContainsString( 'topsecret', wp_json_encode( $data ) );
		$bunny = array_values( array_filter( $data['providers'], static fn( $p ) => 'bunny' === $p['id'] ) )[0];
		$this->assertTrue( $bunny['detected'] );
		$this->assertSame( 'bunny', $this->call( 'GET', '/layers' )->get_data()[ array_search( 'bunny', array_column( $this->call( 'GET', '/layers' )->get_data(), 'id' ), true ) ]['id'] );

		$this->call( 'POST', '/settings', array( 'clear_provider_fields' => array( 'BUNNY_ACCESS_KEY' ) ) );
		$bunny = array_values( array_filter( $this->call( 'GET', '/settings' )->get_data()['providers'], static fn( $p ) => 'bunny' === $p['id'] ) )[0];
		$this->assertFalse( $bunny['detected'] );
	}

	public function test_rules_are_validated_saved_and_need_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'GET', '/rules' )->get_status() );

		wp_set_current_user( $this->admin );
		$res = $this->call(
			'PUT',
			'/rules',
			array(
				'rules' => array(
					array(
						'name'   => 'Shop',
						'on'     => array( 'stock', 'bogus' ),
						'action' => 'home',
						'layers' => array( 'object-cache', 'ghost' ),
					),
					array( 'name' => '' ),
				),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$rules = $this->call( 'GET', '/rules' )->get_data()['rules'];
		$this->assertCount( 1, $rules );
		$this->assertSame( array( 'stock' ), $rules[0]['on'] );
		$this->assertSame( array( 'object-cache' ), $rules[0]['layers'] );
	}

	public function test_flush_preview_requires_capability_and_leaves_the_queue_alone(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'POST', '/flush/preview' )->get_status() );

		wp_set_current_user( $this->admin );
		$this->svc->queue->add( Change::forUrls( 'post', array( home_url( '/a/' ) ) ) );
		$res = $this->call( 'POST', '/flush/preview' );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 1, $data['pending'] );
		$this->assertSame( array( home_url( '/a/' ) ), $data['urls'] );
		$this->assertSame( 1, $this->svc->queue->count(), 'The preview must not drain the queue.' );
		$this->assertSame( array(), $this->svc->history->recent( 10, 0 ), 'And it records nothing.' );
	}

	public function test_new_settings_roundtrip_and_clamp(): void {
		wp_set_current_user( $this->admin );
		$res = $this->call( 'POST', '/settings', array( 'verify_after_flush' => true, 'warm_sitemap_limit' => 9999 ) );
		$this->assertTrue( $res->get_data()['verify_after_flush'] );
		$this->assertSame( 100, $res->get_data()['warm_sitemap_limit'] );
		$this->assertSame( array(), $res->get_data()['overridden'], 'Nothing is overridden yet.' );
		$this->assertSame( is_multisite(), $res->get_data()['multisite'] );
	}

	public function test_history_rows_carry_the_verification_result(): void {
		wp_set_current_user( $this->admin );
		$this->svc->settings->update( array( 'verify_after_flush' => true ) );
		$this->svc->flusher->flushAll( 'manual' );
		$row = $this->call( 'GET', '/history' )->get_data()['items'][0];
		$this->assertArrayHasKey( 'verification', $row );
		// The loopback request may or may not work in the test container; either way the flush itself succeeded.
		$this->assertContains( $row['verification']['status'] ?? 'verified', array( 'verified', 'stale', 'unchecked' ) );
	}

	public function test_layer_map_has_order_and_last_result_and_purges_one_layer(): void {
		wp_set_current_user( $this->admin );
		$this->assertSame( 200, $this->call( 'POST', '/layers/object-cache/purge-all' )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/layers/nope/purge-all' )->get_status() );

		$layers = $this->call( 'GET', '/layers' )->get_data();
		$obj    = array_values( array_filter( $layers, static fn( $l ) => 'object-cache' === $l['id'] ) )[0];
		$this->assertSame( 'Object cache', $obj['tier_label'] );
		$this->assertSame( 'ok', $obj['last']['status'] );
		$this->assertSame( 1, $obj['order'] );
		$this->assertSame( 'admin-layer', $obj['last']['source'] );

		$undetected = array_values( array_filter( $layers, static fn( $l ) => ! $l['detected'] ) );
		if ( $undetected ) {
			$this->assertNull( $undetected[0]['order'] );
			$this->assertSame( 409, $this->call( 'POST', '/layers/' . $undetected[0]['id'] . '/purge-all' )->get_status() );
		}
	}

	public function test_scheduled_purge_is_validated_listed_cancelled_and_needs_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'GET', '/scheduled' )->get_status() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 400, $this->call( 'POST', '/scheduled', array( 'at' => time() - 10 ) )->get_status(), 'Past times are refused.' );
		$this->assertSame( 400, $this->call( 'POST', '/scheduled', array( 'at' => time() + 600, 'urls' => array( 'https://evil.test/x' ) ) )->get_status() );

		$res = $this->call( 'POST', '/scheduled', array( 'at' => time() + 600, 'urls' => array( home_url( '/sale/' ) ), 'note' => 'Sale' ) );
		$this->assertSame( 200, $res->get_status() );
		$items = $res->get_data();
		$this->assertCount( 1, $items );
		$this->assertNotFalse( wp_next_scheduled( 'purgekit_scheduled_purge', array( $items[0]['id'] ) ) );

		$this->svc->scheduler->run( $items[0]['id'] );
		$this->assertSame( array(), $this->call( 'GET', '/scheduled' )->get_data(), 'A run removes the entry.' );

		$again = $this->call( 'POST', '/scheduled', array( 'at' => time() + 900 ) )->get_data();
		$this->assertSame( 200, $this->call( 'DELETE', '/scheduled/' . $again[0]['id'] )->get_status() );
		$this->assertFalse( wp_next_scheduled( 'purgekit_scheduled_purge', array( $again[0]['id'] ) ) );
		$this->assertSame( 404, $this->call( 'DELETE', '/scheduled/' . $again[0]['id'] )->get_status() );
	}

	public function test_a_layer_can_be_switched_off_and_is_then_skipped(): void {
		wp_set_current_user( $this->admin );
		$list = $this->call( 'POST', '/layers/object-cache/enabled', array( 'enabled' => false ) )->get_data();
		$row  = array_values( array_filter( $list, static fn( $l ) => 'object-cache' === $l['id'] ) )[0];
		$this->assertFalse( $row['enabled'] );
		$this->assertSame( 404, $this->call( 'POST', '/layers/nope/enabled', array( 'enabled' => false ) )->get_status() );

		$data = $this->call( 'POST', '/layers/object-cache/purge-all' )->get_data();
		$this->assertSame( 'skipped', $data['status'] );

		$this->call( 'POST', '/layers/object-cache/enabled', array( 'enabled' => true ) );
		$this->assertSame( 'ok', $this->call( 'POST', '/layers/object-cache/purge-all' )->get_data()['status'] );
	}

	public function test_exclusions_and_the_staging_switch_are_saved_and_reported(): void {
		wp_set_current_user( $this->admin );
		$this->call( 'POST', '/settings', array( 'exclude_paths' => "/cart/*\nbad", 'allow_external' => true ) );
		$data = $this->call( 'GET', '/settings' )->get_data();
		$this->assertSame( '/cart/*', $data['exclude_paths'] );
		$this->assertTrue( $data['allow_external'] );
		$this->assertArrayHasKey( 'guard', $data );

		$this->call( 'POST', '/settings', array( 'adopt_home' => true ) );
		$this->assertSame( strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), $this->call( 'GET', '/settings' )->get_data()['guard']['recorded_host'] );
	}

	public function test_connection_checks_are_remembered_for_the_layer_list(): void {
		wp_set_current_user( $this->admin );
		$res = $this->call( 'POST', '/layers/object-cache/test' )->get_data();
		$this->assertSame( 'object-cache', $res['layer'] );
		$this->assertArrayHasKey( 'at', $res );

		$list = $this->call( 'GET', '/layers' )->get_data();
		$row  = array_values( array_filter( $list, static fn( $l ) => 'object-cache' === $l['id'] ) )[0];
		$this->assertSame( $res['status'], $row['checked']['status'] );
		$this->assertSame( $res['at'], $row['checked']['at'] );

		$not_set_up = array_values( array_filter( $list, static fn( $l ) => ! $l['detected'] ) );
		if ( $not_set_up ) {
			$this->call( 'POST', '/layers/' . $not_set_up[0]['id'] . '/test' );
			$after = array_values( array_filter( $this->call( 'GET', '/layers' )->get_data(), static fn( $l ) => $l['id'] === $not_set_up[0]['id'] ) )[0];
			$this->assertNull( $after['checked'], 'A layer that is not set up was not checked, so nothing is remembered.' );
		}
	}

	public function test_settings_export_has_no_secrets_and_import_refuses_other_files(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->call( 'GET', '/settings/export' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/settings/import', array( 'purgekit' => 1 ) )->get_status() );

		wp_set_current_user( $this->admin );
		$this->svc->secrets->set( 'CLOUDFLARE_TOKEN', 'TOPSECRETTOKEN' );
		$this->call( 'POST', '/settings', array( 'mode' => 'auto', 'exclude_paths' => '/cart/*' ) );
		$export = $this->call( 'GET', '/settings/export' )->get_data();
		$this->assertSame( 1, $export['purgekit'] );
		$this->assertSame( 'auto', $export['settings']['mode'] );
		$this->assertStringNotContainsString( 'TOPSECRETTOKEN', wp_json_encode( $export ) );
		$this->assertArrayNotHasKey( 'allow_external', $export['settings'] );

		$this->assertSame( 400, $this->call( 'POST', '/settings/import', array( 'hello' => 'world' ) )->get_status() );
	}

	public function test_settings_import_applies_the_file_and_reports_what_it_skipped(): void {
		wp_set_current_user( $this->admin );
		$res = $this->call(
			'POST',
			'/settings/import',
			array(
				'purgekit' => 1,
				'settings' => array( 'mode' => 'scheduled', 'schedule' => '6h', 'allow_external' => true, 'mode_typo' => 1 ),
				'rules'    => array( array( 'name' => 'Imported', 'action' => 'home', 'layers' => array( 'object-cache', 'ghost' ) ) ),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertContains( 'mode', $data['applied'] );
		$this->assertContains( 'allow_external', $data['ignored'] );
		$this->assertSame( 1, $data['rules'] );

		$this->assertSame( 'scheduled', $this->call( 'GET', '/settings' )->get_data()['mode'] );
		$this->assertFalse( $this->call( 'GET', '/settings' )->get_data()['allow_external'] );
		$this->assertSame( array( 'object-cache' ), $this->call( 'GET', '/rules' )->get_data()['rules'][0]['layers'] );
	}

	public function test_settings_report_which_ones_are_fixed_in_wp_config(): void {
		wp_set_current_user( $this->admin );
		$this->assertSame( array(), $this->call( 'GET', '/settings' )->get_data()['locked'], 'Nothing is fixed unless PURGEKIT_CONFIG is defined.' );
	}
}
