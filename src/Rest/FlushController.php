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
		register_rest_route(
			self::NS,
			'/flush/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/storm/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'storm_reset' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	/** Purges again, on the layers that failed only (and for the same URLs, when it was not a full purge). */
	public function retry( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$known  = array_map( static fn( $l ) => $l->id(), $this->services->registry->active() );
		$layers = array_values( array_intersect( array_filter( (array) $req->get_param( 'layers' ), 'is_string' ), $known ) );
		if ( array() === $layers ) {
			return new \WP_Error( 'purgekit_no_layers', 'Choose at least one active layer to retry.', array( 'status' => 400 ) );
		}
		$urls = array_values( array_filter( (array) $req->get_param( 'urls' ), 'is_string' ) );
		return $this->run_response( $this->services->flusher->flushLayers( 'retry', $layers, $urls ) );
	}

	/** "Resume automatic flushing" after a flush storm. */
	public function storm_reset(): array {
		$this->services->storm->reset();
		return array( 'storm' => null );
	}
}
