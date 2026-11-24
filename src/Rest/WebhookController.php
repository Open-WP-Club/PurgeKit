<?php
namespace PurgeKit\Rest;

final class WebhookController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/webhook/flush',
			array(
				'methods'             => 'POST',
				'callback'            => fn( \WP_REST_Request $r ) => $this->flush_for( $r, 'webhook' ),
				'permission_callback' => array( $this, 'authorised' ),
			)
		);
	}

	public function authorised( \WP_REST_Request $req ): bool|\WP_Error {
		$expected = (string) $this->services->secrets->get( 'WEBHOOK_KEY' );
		$given    = (string) $req->get_header( 'x-purgekit-key' );
		if ( '' !== $expected && hash_equals( $expected, $given ) ) {
			return true;
		}
		return new \WP_Error( 'purgekit_forbidden', 'Invalid key.', array( 'status' => 403 ) );
	}
}
