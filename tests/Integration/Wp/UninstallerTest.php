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

		( new Uninstaller() )->run();

		global $wpdb;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $svc->history->table() ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'purgekit\\_%'" ) );
		$this->assertSame( 'keep', get_option( 'unrelated_option' ) );
		$this->assertFalse( wp_next_scheduled( Cron::SCAN ) );
		$this->assertFalse( wp_next_scheduled( 'purgekit_warm' ) );
	}
}
