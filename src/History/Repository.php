<?php
namespace PurgeKit\History;

use PurgeKit\Core\HistoryWriter;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Run;

// The history table is custom, so direct queries are the only option and caching them would hide new rows.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
final class Repository implements HistoryWriter {
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'purgekit_history';
	}

	public function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . $this->table() . ' (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			source varchar(32) NOT NULL,
			status varchar(16) NOT NULL,
			plan_all tinyint(1) NOT NULL DEFAULT 0,
			urls_json longtext NOT NULL,
			results_json longtext NOT NULL,
			duration_ms int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) ' . $wpdb->get_charset_collate() . ';'
		);
	}

	public function record( string $source, Run $run, array $changes ): void {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'source'       => substr( $source, 0, 32 ),
				'status'       => $run->status,
				'plan_all'     => $run->plan && $run->plan->all ? 1 : 0,
				'urls_json'    => wp_json_encode( $run->plan ? $run->plan->urls : array() ),
				'results_json' => wp_json_encode(
					array_map(
						static fn( LayerResult $r ) => array(
							'layer'   => $r->layer,
							'status'  => $r->status,
							'message' => $r->message,
							'ms'      => $r->ms,
						),
						$run->results
					)
				),
				'duration_ms'  => array_sum( array_map( static fn( LayerResult $r ) => $r->ms, $run->results ) ),
			)
		);
	}

	public function recent( int $limit, int $offset, array $filters = array() ): array {
		global $wpdb;
		list( $where, $args ) = $this->where( $filters );
		$sql                  = 'SELECT * FROM ' . $this->table() . " $where ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows                 = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	public function count( array $filters = array() ): int {
		global $wpdb;
		list( $where, $args ) = $this->where( $filters );
		$sql                  = 'SELECT COUNT(*) FROM ' . $this->table() . " $where";
		return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function prune( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $this->table() . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function where( array $filters ): array {
		if ( ! empty( $filters['status'] ) ) {
			return array( 'WHERE status = %s', array( (string) $filters['status'] ) );
		}
		return array( '', array() );
	}

	private function hydrate( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'created_at'  => $row['created_at'],
			'source'      => $row['source'],
			'status'      => $row['status'],
			'all'         => (bool) $row['plan_all'],
			'urls'        => (array) json_decode( $row['urls_json'], true ),
			'results'     => (array) json_decode( $row['results_json'], true ),
			'duration_ms' => (int) $row['duration_ms'],
		);
	}
}
