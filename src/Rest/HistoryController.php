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
