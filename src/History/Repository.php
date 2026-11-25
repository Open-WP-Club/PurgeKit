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
	public const DB_VERSION = '3';

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
			trigger_json longtext NULL,
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
				'trigger_json' => wp_json_encode( $this->trigger( $run, $changes ) ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Who or what started the flush: the signed-in user, the kind of request, the rules that decided it and
	 * the kinds of change that were queued. Never anything secret.
	 *
	 * @param \PurgeKit\Core\Change[] $changes
	 * @return array<string,mixed>
	 */
	private function trigger( Run $run, array $changes ): array {
		$out = array();
		$uid = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( $user ) {
				$out['user'] = array(
					'id'   => $uid,
					'name' => (string) $user->user_login,
				);
			}
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$out['via'] = 'cli';
		} elseif ( wp_doing_cron() ) {
			$out['via'] = 'cron';
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$out['via'] = 'rest';
		} else {
			$out['via'] = 'web';
		}
		if ( array() !== $run->rules ) {
			$out['rules'] = array_slice( $run->rules, 0, 10 );
		}
		if ( $run->deferred > 0 ) {
			$out['deferred'] = $run->deferred;
		}
		$types = array_values( array_unique( array_map( static fn( $c ) => $c->type, $changes ) ) );
		if ( array() !== $types ) {
			$out['changes'] = array_slice( $types, 0, 10 );
			$out['count']   = count( $changes );
		}
		return $out;
	}

	/**
	 * Flushes of the last day by status and a count per day for the last week.
	 *
	 * @return array{day:array<string,int>,week:array<int,array{date:string,total:int,problems:int}>}
	 */
	public function stats(): array {
		global $wpdb;
		$day = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS c FROM %i WHERE created_at >= %s GROUP BY status', $this->table(), gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ), ARRAY_A ) as $row ) {
			$day[ (string) $row['status'] ] = (int) $row['c'];
		}
		$found = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, COUNT(*) AS c, SUM(status IN ('failed','partial')) AS bad FROM %i WHERE created_at >= %s GROUP BY DATE(created_at)", $this->table(), gmdate( 'Y-m-d 00:00:00', time() - 6 * DAY_IN_SECONDS ) ), ARRAY_A ) as $row ) {
			$found[ (string) $row['d'] ] = array(
				'total'    => (int) $row['c'],
				'problems' => (int) $row['bad'],
			);
		}
		$week = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$date   = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$week[] = array(
				'date'     => $date,
				'total'    => $found[ $date ]['total'] ?? 0,
				'problems' => $found[ $date ]['problems'] ?? 0,
			);
		}
		return array(
			'day'  => $day,
			'week' => $week,
		);
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
		$f    = $this->filter( $filters );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE (%s = \'\' OR status = %s) AND (%s = \'\' OR results_json LIKE %s) ORDER BY id DESC LIMIT %d OFFSET %d', $this->table(), $f['status'], $f['status'], $f['like'], $f['like'], $limit, $offset ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Every flush since a moment, oldest first, for the Impact tab. Capped, so a very busy site stays cheap.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function since( string $from_gmt, int $limit = 5000 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE created_at >= %s ORDER BY id ASC LIMIT %d', $this->table(), $from_gmt, $limit ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
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
		$f = $this->filter( $filters );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE (%s = \'\' OR status = %s) AND (%s = \'\' OR results_json LIKE %s)', $this->table(), $f['status'], $f['status'], $f['like'], $f['like'] ) );
	}

	/**
	 * The filter values for the fixed WHERE clause above; an empty value means "do not filter on this".
	 *
	 * @param array<string,mixed> $filters
	 * @return array{status:string,like:string}
	 */
	private function filter( array $filters ): array {
		global $wpdb;
		$layer = (string) ( $filters['layer'] ?? '' );
		return array(
			'status' => (string) ( $filters['status'] ?? '' ),
			// Layer ids are plain slugs, and the results are stored as JSON, so the id appears as "layer":"id".
			'like'   => '' === $layer ? '' : '%' . $wpdb->esc_like( '"layer":"' . $layer . '"' ) . '%',
		);
	}

	public function prune( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), $cutoff ) );
	}

	/** @return array<string,mixed>|null */
	private function verification( mixed $json ): ?array {
		$data = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;
		return is_array( $data ) && isset( $data['status'] ) ? $data : null;
	}

	/** @return array<string,mixed> */
	private function triggerData( mixed $json ): array {
		$data = is_string( $json ) && '' !== $json ? json_decode( $json, true ) : null;
		return is_array( $data ) ? $data : array();
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
			'trigger'      => $this->triggerData( $row['trigger_json'] ?? null ),
		);
	}
}
