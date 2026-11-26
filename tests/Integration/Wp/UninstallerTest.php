<?php
namespace PurgeKit\Tests\Integration\Wp;

use PurgeKit\Plugin;
use PurgeKit\Uninstaller;
use PurgeKit\Wp\Cron;

final class UninstallerTest extends \WP_UnitTestCase {
	public function test_uninstall_removes_table_options_and_cron(): void {
		$svc = Plugin::services();
		$svc->history->install();
		$svc->store->set( 'mode', 'auto' );
		$svc->secrets->set( 'CLOUDFLARE_TOKEN', 'x' );
		update_option( 'unrelated_option', 'keep' );
		wp_schedule_event( time() + 60, 'hourly', Cron::SCAN );
		wp_schedule_single_event( time() + 60, 'purgekit_warm' );
		wp_schedule_single_event( time() + 60, \PurgeKit\Wp\AutoRunner::HOOK );

		// The WordPress test case rewrites DROP TABLE into DROP TEMPORARY TABLE, so a real table that the plugin
		// activation created stays in place. Check the statement PurgeKit sends instead; a real drop was verified
		// by running the uninstaller through WP-CLI against the wp-env database.
		$statements = array();
		add_filter(
			'query',
			static function ( $query ) use ( &$statements ) {
				$statements[] = (string) $query;
				return $query;
			},
			1
		);

		( new Uninstaller() )->run();

		global $wpdb;
		$drops = array_filter( $statements, static fn( $q ) => 1 === preg_match( '/^DROP TABLE IF EXISTS\s+\S*purgekit_history/', trim( $q ) ) );
		$this->assertCount( 1, $drops, 'The history table is dropped.' );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'purgekit\\_%'" ) );
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
		$this->assertFalse( wp_next_scheduled( Cron::SCAN ) );
		$this->assertFalse( wp_next_scheduled( 'purgekit_warm' ) );
		$this->assertFalse( wp_next_scheduled( \PurgeKit\Wp\AutoRunner::HOOK ) );
	}
}
