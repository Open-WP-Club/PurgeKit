<?php
namespace PurgeKit\History;

use PurgeKit\Core\HistoryWriter;
use PurgeKit\Core\LastResults;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Run;
use PurgeKit\Core\UrlList;

// The history table is custom, so direct queries are the only option and caching them would hide new rows.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
final class Repository implements HistoryWriter {
	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'purgekit_history';
	}

	/** Bump when install() changes the table; Plugin::init() reruns install() on a mismatch. */
	public const DB_VERSION = '2';

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
			verify_json longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) ' . $wpdb->get_charset_collate() . ';'
		);
	}

	public function record( string $source, Run $run, array $changes ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'source'       => substr( $source, 0, 32 ),
				'status'       => $run->status,
				'plan_all'     => $run->plan && $run->plan->all ? 1 : 0,
				'urls_json'    => UrlList::encode( $run->plan ? $run->plan->urls : array() ),
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
		return (int) $wpdb->insert_id;
	}

	/**
	 * A separate UPDATE (not part of record()), so a site whose table has not been migrated yet still
	 * records its history; the update just does nothing there.
	 */
	public function annotate( int $id, array $verification ): void {
		global $wpdb;
		$wpdb->update( $this->table(), array( 'verify_json' => wp_json_encode( $verification ) ), array( 'id' => $id ) );
	}

	public function recent( int $limit, int $offset, array $filters = array() ): array {
		global $wpdb;
		list( $where, $args ) = $this->where( $filters );
		$sql                  = 'SELECT * FROM ' . $this->table() . " $where ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows                 = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * The latest real result per layer, looked up in the most recent runs only.
	 *
	 * @return array<string,array{status:string,message:string,ms:int,at:string,source:string}>
	 */
	public function lastPerLayer( int $runs = 40 ): array {
		return LastResults::fromRows( $this->recent( $runs, 0 ) );
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

	/** @return array<string,mixed>|null */
	private function verification( mixed $json ): ?array {
		$data = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;
		return is_array( $data ) && isset( $data['status'] ) ? $data : null;
	}

	private function hydrate( array $row ): array {
		$urls = UrlList::decode( (string) $row['urls_json'] );
		return array(
			'id'           => (int) $row['id'],
			'created_at'   => $row['created_at'],
			'source'       => $row['source'],
			'status'       => $row['status'],
			'all'          => (bool) $row['plan_all'],
			'urls'         => $urls['urls'],
			'urls_total'   => $urls['total'],
			'results'      => (array) json_decode( $row['results_json'], true ),
			'duration_ms'  => (int) $row['duration_ms'],
			'verification' => $this->verification( $row['verify_json'] ?? null ),
		);
	}
}
