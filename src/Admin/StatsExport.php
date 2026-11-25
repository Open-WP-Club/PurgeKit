<?php
namespace PurgeKit\Admin;

use PurgeKit\Core\StatsCsv;
use PurgeKit\Services;
use PurgeKit\Wp\CompareData;

/** Downloads the per-layer numbers as CSV (admin-post, nonce and capability protected). */
final class StatsExport {
	public const ACTION = 'purgekit_stats_csv';

	public function __construct( private Services $services ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
	}

	public static function url(): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public function download(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( apply_filters( 'purgekit_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'purgekit' ), 403 );
		}
		$days = isset( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="purgekit-layers-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo "\xEF\xBB\xBF" . ( new StatsCsv() )->build( ( new CompareData( $this->services ) )->rows( $days ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML; cells are quoted and formula-safe.
		exit;
	}
}
