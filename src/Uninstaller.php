<?php
namespace PurgeKit;

use PurgeKit\Wp\Cron;

/** Removes everything PurgeKit stored. Runs per site on multisite. */
final class Uninstaller {
	public function run(): void {
		if ( is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				$this->clean_site();
				restore_current_blog();
			}
			return;
		}
		$this->clean_site();
	}

	private function clean_site(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}purgekit_history" );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'purgekit_' ) . '%' ) );
		// phpcs:enable
		foreach ( array( Cron::FLUSH, Cron::SCAN, Cron::PRUNE, 'purgekit_warm' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
