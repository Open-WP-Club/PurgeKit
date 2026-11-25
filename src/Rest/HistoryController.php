<?php
namespace PurgeKit\Rest;

final class HistoryController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'status'   => array(
						'type' => 'string',
						'enum' => array( 'ok', 'partial', 'failed', 'skipped' ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/history/(?P<id>\d+)/replay',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'replay' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/impact',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'impact' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 30,
						'enum'    => array( 7, 30, 90 ),
					),
				),
			)
		);
	}

	/** Runs an old flush again: the same URLs, or everything. A list that was shortened in the history is refused. */
	public function replay( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$row = $this->services->history->find( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'purgekit_no_row', 'That flush is not in the history any more.', array( 'status' => 404 ) );
		}
		if ( $row['all'] ) {
			return $this->run_response( $this->services->flusher->flushAll( 'replay' ) );
		}
		if ( array() === $row['urls'] ) {
			return new \WP_Error( 'purgekit_no_urls', 'That flush has no URLs to repeat.', array( 'status' => 409 ) );
		}
		if ( $row['urls_total'] > count( $row['urls'] ) ) {
			return new \WP_Error( 'purgekit_shortened', 'The history only kept part of that URL list, so it cannot be repeated exactly.', array( 'status' => 409 ) );
		}
		return $this->run_response( $this->services->flusher->flushUrls( 'replay', $row['urls'] ) );
	}

	public function impact( \WP_REST_Request $req ): array {
		$days = (int) $req['days'];
		$now  = time();
		return \PurgeKit\Core\Impact::summarise( $this->services->history->since( gmdate( 'Y-m-d 00:00:00', $now - ( $days - 1 ) * DAY_IN_SECONDS ) ), $now, $days );
	}

	public function get( \WP_REST_Request $req ): array {
		$per     = (int) $req['per_page'];
		$filters = $req['status'] ? array( 'status' => (string) $req['status'] ) : array();
		return array(
			'items' => $this->services->history->recent( $per, ( (int) $req['page'] - 1 ) * $per, $filters ),
			'total' => $this->services->history->count( $filters ),
		);
	}
}
