<?php
namespace PurgeKit\Rest;

final class FlushController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/flush',
			array(
				'methods'             => 'POST',
				'callback'            => fn( \WP_REST_Request $r ) => $this->flush_for( $r, 'manual' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/flush/preview',
			array(
				'methods'             => 'POST',
				'callback'            => fn( \WP_REST_Request $r ) => $this->services->flusher->preview( (bool) $r->get_param( 'force' ) ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}
}
