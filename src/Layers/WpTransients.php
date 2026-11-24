<?php
namespace PurgeKit\Layers;

// Transients are plain rows in the options table; there is no WordPress API to count or bulk-delete them.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
final class WpTransients implements Transients {
	public function usesExternalCache(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	public function count(): array {
		global $wpdb;
		$total   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_transient_timeout_' ) . '%' ) );
		$expired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s AND option_value < %d', $wpdb->options, $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) );
		return array(
			'total'   => $total,
			'expired' => $expired,
		);
	}

	public function deleteAll(): int {
		global $wpdb;
		$count = $this->count()['total'];
		// doing_cron is WP-Cron's lock; deleting it mid-run can start cron twice. PurgeKit's own are short-lived flags.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s AND option_name NOT IN (%s, %s) AND option_name NOT LIKE %s AND option_name NOT LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( '_transient_' ) . '%',
				'_transient_doing_cron',
				'_transient_timeout_doing_cron',
				$wpdb->esc_like( '_transient_purgekit_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_purgekit_' ) . '%'
			)
		);
		// Autoloaded transients also sit in these cached lists.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $count;
	}
}
