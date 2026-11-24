<?php
namespace PurgeKit\Rest;

final class ScheduledController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/scheduled',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'can' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add' ),
					'permission_callback' => array( $this, 'can' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/scheduled/(?P<id>[a-f0-9]{8})',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	public function list(): array {
		return $this->services->scheduled->all();
	}

	public function add( \WP_REST_Request $req ): array|\WP_Error {
		$urls = array_values( array_filter( (array) $req->get_param( 'urls' ), 'is_string' ) );
		$item = $this->services->scheduler->add( (int) $req->get_param( 'at' ), $urls, (string) $req->get_param( 'note' ) );
		if ( ! $item ) {
			return new \WP_Error( 'purgekit_bad_schedule', 'Pick a time in the future (within a year), use addresses on this site, and keep at most 20 scheduled purges.', array( 'status' => 400 ) );
		}
		return $this->list();
	}

	public function cancel( \WP_REST_Request $req ): array|\WP_Error {
		if ( ! $this->services->scheduler->cancel( (string) $req['id'] ) ) {
			return new \WP_Error( 'purgekit_no_schedule', 'Not found', array( 'status' => 404 ) );
		}
		return $this->list();
	}
}
