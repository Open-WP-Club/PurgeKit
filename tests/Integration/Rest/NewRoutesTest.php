<?php
namespace PurgeKit\Tests\Integration\Rest;

use PurgeKit\Core\Change;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Plugin;
use PurgeKit\Services;

/** The REST routes behind the dashboard, Health, Impact, replay, retry and the asset bump. */
final class NewRoutesTest extends \WP_UnitTestCase {
	private Services $svc;
	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->svc = Plugin::services();
		$this->svc->history->install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . $this->svc->history->table() );
		$this->svc->queue->drain();
		$this->svc->lock->release( 'flush' );
		$this->svc->storm->reset();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		foreach ( array( 'asset_version', 'storm_pause', 'cache_tags', 'soft_purge', 'onboarded' ) as $key ) {
			$this->svc->store->delete( $key );
		}
		foreach ( \PurgeKit\Alerts\Notifier::CHANNELS as $c ) {
			$this->svc->secrets->delete( $c['secret'] );
		}
		parent::tear_down();
	}

	private function call( string $method, string $route, array $params = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/purgekit/v1' . $route );
		if ( $params ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}

	public function test_every_new_route_needs_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( array( array( 'GET', '/health' ), array( 'POST', '/health' ), array( 'GET', '/impact' ), array( 'POST', '/flush/retry' ), array( 'POST', '/storm/reset' ), array( 'POST', '/assets/bump' ), array( 'POST', '/history/1/replay' ) ) as $r ) {
			$this->assertSame( 403, $this->call( $r[0], $r[1] )->get_status(), implode( ' ', $r ) );
		}
	}

	public function test_status_has_what_the_dashboard_needs(): void {
		$this->svc->flusher->flushAll( 'manual' );
		$d = $this->call( 'GET', '/status' )->get_data();
		foreach ( array( 'pending', 'mode', 'layers_active', 'layers_failing', 'last_run', 'stats', 'recent', 'delayed', 'storm', 'paused', 'onboarded' ) as $key ) {
			$this->assertArrayHasKey( $key, $d, $key );
		}
		$this->assertSame( 'manual', $d['recent'][0]['source'] );
		$this->assertCount( 7, $d['stats']['week'] );
		$this->assertNull( $d['storm'] );
		$this->assertFalse( $d['onboarded'] );
	}

	public function test_a_flush_storm_shows_up_and_can_be_reset(): void {
		$run = new Run( Run::OK, array(), PurgePlan::everything() );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->svc->storm->record( 'auto', $run, time() );
		}
		$storm = $this->call( 'GET', '/status' )->get_data()['storm'];
		$this->assertSame( 5, $storm['count'] );
		$this->assertFalse( $storm['paused'], 'Only paused when the user asked for it, and in auto mode.' );
		$this->call( 'POST', '/storm/reset' );
		$this->assertNull( $this->call( 'GET', '/status' )->get_data()['storm'] );
	}

	public function test_health_without_the_network_lists_local_checks(): void {
		$this->svc->secrets->set( 'WEBHOOK_KEY', str_repeat( 'k', 40 ) );
		$d = $this->call( 'GET', '/health' )->get_data();
		$this->assertFalse( $d['networked'] );
		$ids = array_column( $d['checks'], 'id' );
		$this->assertContains( 'alerts', $ids, 'No alert channel is set up.' );
		$this->assertContains( 'webhook_key', $ids );
		$this->assertSame( 'recommended', $d['worst'] );
		$key = array_values( array_filter( $d['checks'], static fn( $c ) => 'webhook_key' === $c['id'] ) )[0];
		$this->assertSame( 'recommended', $key['status'], 'No rotation date was recorded.' );
		$this->svc->secrets->delete( 'WEBHOOK_KEY' );
	}

	public function test_health_with_the_network_probes_slack_without_sending_a_message(): void {
		$this->svc->secrets->set( 'SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/T/B/X' );
		$seen = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen[] = array( $url, $args['body'] ?? '' );
				return array( 'headers' => array(), 'body' => 'no_text', 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'cookies' => array(), 'filename' => null );
			},
			10,
			3
		);
		$d = $this->call( 'POST', '/health' )->get_data();
		remove_all_filters( 'pre_http_request' );
		$this->assertTrue( $d['networked'] );
		$this->assertSame( array( array( 'https://hooks.slack.com/services/T/B/X', '{}' ) ), $seen, 'An empty payload, so no message is posted.' );
		$slack = array_values( array_filter( $d['checks'], static fn( $c ) => 'slack' === $c['id'] ) );
		$this->assertSame( 'good', $slack[0]['status'] );
	}

	public function test_a_slack_webhook_that_was_removed_is_critical(): void {
		$this->svc->secrets->set( 'SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/T/B/X' );
		add_filter( 'pre_http_request', static fn() => array( 'headers' => array(), 'body' => 'no_service', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null ) );
		$d = $this->call( 'POST', '/health' )->get_data();
		remove_all_filters( 'pre_http_request' );
		$this->assertSame( 'critical', $d['worst'] );
	}

	public function test_retry_runs_only_the_chosen_active_layers(): void {
		$res = $this->call( 'POST', '/flush/retry', array( 'layers' => array( 'object-cache', 'not-a-layer' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'object-cache' ), array_column( $res->get_data()['results'], 'layer' ) );
		$this->assertSame( 'retry', $this->svc->history->recent( 1, 0 )[0]['source'] );
		$this->assertSame( 400, $this->call( 'POST', '/flush/retry', array( 'layers' => array( 'nope' ) ) )->get_status() );
	}

	public function test_replay_repeats_a_full_flush_and_a_url_flush_and_refuses_what_it_cannot_repeat(): void {
		$full = $this->svc->history->record( 'manual', new Run( Run::OK, array(), PurgePlan::everything() ), array() );
		$res  = $this->call( 'POST', "/history/$full/replay" );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['all'] );
		$this->assertSame( 'replay', $this->svc->history->recent( 1, 0 )[0]['source'] );

		$one = $this->svc->history->record( 'manual', new Run( Run::OK, array(), PurgePlan::forUrls( array( home_url( '/a/' ) ) ) ), array() );
		$this->assertSame( array( home_url( '/a/' ) ), $this->call( 'POST', "/history/$one/replay" )->get_data()['urls'] );

		$big  = array_map( static fn( $i ) => home_url( "/p$i/" ), range( 1, 300 ) );
		$long = $this->svc->history->record( 'manual', new Run( Run::OK, array(), PurgePlan::forUrls( $big ) ), array() );
		$this->assertSame( 409, $this->call( 'POST', "/history/$long/replay" )->get_status() );
		$this->assertSame( 404, $this->call( 'POST', '/history/999999/replay' )->get_status() );
	}

	public function test_impact_summarises_the_history(): void {
		$this->svc->history->record( 'auto', new Run( Run::OK, array(), PurgePlan::forUrls( array( home_url( '/a/' ), home_url( '/b/' ) ) ) ), array( Change::forUrls( 'post', array() ) ) );
		$this->svc->history->record( 'manual', new Run( Run::OK, array(), PurgePlan::everything() ), array() );
		$d = $this->call( 'GET', '/impact', array() )->get_data();
		$this->assertSame( 30, $d['days'] );
		$this->assertSame( 2, $d['flushes'] );
		$this->assertSame( 1, $d['targeted'] );
		$this->assertSame( 2, $d['urls'] );
		$this->assertSame( array( 'post' => 1 ), $d['by_change'] );
		$this->assertCount( 30, $d['per_day'] );
		$req = new \WP_REST_Request( 'GET', '/purgekit/v1/impact' );
		$req->set_param( 'days', 7 );
		$this->assertCount( 7, rest_do_request( $req )->get_data()['per_day'] );
		$req->set_param( 'days', 5 );
		$this->assertSame( 400, rest_do_request( $req )->get_status(), 'Only 7, 30 or 90.' );
	}

	public function test_the_asset_bump_sets_a_version_and_purges_everything(): void {
		$before = $this->svc->settings->assetVersion();
		$res    = $this->call( 'POST', '/assets/bump' );
		$this->assertSame( 200, $res->get_status() );
		$version = $res->get_data()['version'];
		$this->assertGreaterThan( $before, $version );
		$this->assertSame( $version, $this->svc->settings->assetVersion() );
		$this->assertTrue( $res->get_data()['all'] );
		$this->assertSame( 'asset-bump', $this->svc->history->recent( 1, 0 )[0]['source'] );
		$this->assertSame( $version, $this->call( 'GET', '/settings' )->get_data()['asset_version'] );
		$second = $this->call( 'POST', '/assets/bump' )->get_data()['version'];
		$this->assertGreaterThan( $version, $second, 'Two bumps in the same second still differ.' );
	}

	public function test_the_asset_version_is_never_exported(): void {
		$this->svc->settings->bumpAssetVersion( time() );
		$this->assertArrayNotHasKey( 'asset_version', $this->call( 'GET', '/settings/export' )->get_data()['settings'] );
		$this->assertArrayNotHasKey( 'onboarded', $this->call( 'GET', '/settings/export' )->get_data()['settings'] );
	}

	public function test_alert_channels_are_saved_only_with_a_valid_address_and_never_returned(): void {
		$d = $this->call( 'POST', '/settings', array(
			'discord_webhook_url' => 'https://discord.com/api/webhooks/1/abc',
			'teams_webhook_url'   => 'https://evil.test/x',
			'alert_webhook_url'   => 'http://hooks.example.com/plain',
		) )->get_data();
		$this->assertTrue( $d['discord_webhook_set'] );
		$this->assertFalse( $d['teams_webhook_set'], 'Not a Teams address.' );
		$this->assertFalse( $d['alert_webhook_set'], 'Not https.' );
		$this->assertStringNotContainsString( 'discord.com', wp_json_encode( $d ) );
		$d = $this->call( 'POST', '/settings', array( 'clear_alert_channel' => 'discord' ) )->get_data();
		$this->assertFalse( $d['discord_webhook_set'] );
	}

	public function test_the_new_switches_and_the_first_run_flag_are_saved(): void {
		$d = $this->call( 'POST', '/settings', array( 'cache_tags' => true, 'soft_purge' => true, 'storm_pause' => true, 'onboarded' => true ) )->get_data();
		$this->assertTrue( $d['cache_tags'] && $d['soft_purge'] && $d['storm_pause'] && $d['onboarded'] );
		$this->assertTrue( $this->call( 'GET', '/status' )->get_data()['onboarded'] );
	}
}
