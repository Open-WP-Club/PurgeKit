<?php
namespace PurgeKit\Admin;

use PurgeKit\History\CsvExporter;
use PurgeKit\Services;

/** Downloads the flush history as CSV (admin-post, nonce and capability protected). */
final class HistoryExport {
	public const ACTION = 'purgekit_history_csv';
	private const PAGE  = 500;
	private const MAX   = 50000;

	public function __construct( private Services $services ) {}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'download' ) );
	}

	/** URL the History tab links to; carries a nonce. */
	public static function url( string $status = '' ): string {
		$args = array(
			'action'   => self::ACTION,
			'_wpnonce' => wp_create_nonce( self::ACTION ),
		);
		if ( '' !== $status ) {
			$args['status'] = $status;
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	public function download(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( apply_filters( 'purgekit_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'purgekit' ), 403 );
		}
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked above.
		$filters = in_array( $status, array( 'ok', 'partial', 'failed', 'skipped' ), true ) ? array( 'status' => $status ) : array();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="purgekit-history-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$csv = new CsvExporter();
		echo "\xEF\xBB\xBF" . $csv->header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML; the BOM makes Excel read UTF-8.
		for ( $offset = 0; $offset < self::MAX; $offset += self::PAGE ) {
			$rows = $this->services->history->recent( self::PAGE, $offset, $filters );
			if ( ! $rows ) {
				break;
			}
			echo $csv->rows( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV cells are quoted and formula-safe.
		}
		exit;
	}
}
