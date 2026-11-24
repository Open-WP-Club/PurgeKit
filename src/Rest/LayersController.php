<?php
namespace PurgeKit\Rest;

final class LayersController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/layers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/layers/(?P<id>[a-z0-9\-]+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	public function list(): array {
		return array_map(
			static fn( $l ) => array(
				'id'       => $l->id(),
				'label'    => $l->label(),
				'tier'     => $l->tier(),
				'detected' => $l->detect(),
			),
			$this->services->registry->all()
		);
	}

	public function test( \WP_REST_Request $req ): array|\WP_Error {
		$layer = $this->services->registry->find( (string) $req['id'] );
		if ( ! $layer ) {
			return new \WP_Error( 'purgekit_no_layer', 'Unknown layer', array( 'status' => 404 ) );
		}
		try {
			$r = $layer->test();
		} catch ( \Throwable $e ) {
			return array(
				'layer'   => $layer->id(),
				'status'  => 'failed',
				'message' => $e->getMessage(),
				'ms'      => 0,
			);
		}
		return array(
			'layer'   => $r->layer,
			'status'  => $r->status,
			'message' => $r->message,
			'ms'      => $r->ms,
		);
	}
}
