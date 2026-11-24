<?php
namespace PurgeKit\Rest;

use PurgeKit\Layers\Tier;

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
			'/layers/(?P<id>[a-z0-9\-]+)/purge-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge_all' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/layers/(?P<id>[a-z0-9\-]+)/enabled',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_enabled' ),
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
		$last     = $this->services->history->lastPerLayer();
		$checks   = $this->checks();
		$disabled = $this->services->settings->disabledLayers();
		$order    = 0;
		$rows     = array();
		foreach ( $this->services->registry->all() as $l ) {
			$detected = $l->detect();
			$rows[]   = array(
				'id'         => $l->id(),
				'label'      => $l->label(),
				'tier'       => $l->tier(),
				'tier_label' => Tier::label( $l->tier() ),
				'detected'   => $detected,
				'enabled'    => ! in_array( $l->id(), $disabled, true ),
				'paused'     => $this->services->guard->reasonFor( $l->tier() ),
				'order'      => $detected ? ++$order : null,
				'last'       => $last[ $l->id() ] ?? null,
				'checked'    => $checks[ $l->id() ] ?? null,
			);
		}
		return $rows;
	}

	public function set_enabled( \WP_REST_Request $req ): array|\WP_Error {
		$id = (string) $req['id'];
		if ( ! $this->services->registry->find( $id ) ) {
			return new \WP_Error( 'purgekit_no_layer', 'Unknown layer', array( 'status' => 404 ) );
		}
		$disabled = array_diff( $this->services->settings->disabledLayers(), array( $id ) );
		if ( false === filter_var( $req->get_param( 'enabled' ), FILTER_VALIDATE_BOOLEAN ) ) {
			$disabled[] = $id;
		}
		$this->services->settings->update( array( 'disabled_layers' => array_values( $disabled ) ) );
		return $this->list();
	}

	public function purge_all( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$layer = $this->services->registry->find( (string) $req['id'] );
		if ( ! $layer ) {
			return new \WP_Error( 'purgekit_no_layer', 'Unknown layer', array( 'status' => 404 ) );
		}
		if ( ! $layer->detect() ) {
			return new \WP_Error( 'purgekit_layer_inactive', 'This layer is not detected on this site', array( 'status' => 409 ) );
		}
		return $this->run_response( $this->services->flusher->flushLayer( 'admin-layer', $layer->id() ) );
	}

	/** @return array<string,array{status:string,message:string,ms:int,at:int}> The latest connection check of each layer. */
	private function checks(): array {
		$saved = $this->services->store->get( 'layer_checks', array() );
		return is_array( $saved ) ? $saved : array();
	}

	/** Remembers a connection check, so it is still there after the page is reloaded. */
	private function remember_check( string $id, string $status, string $message, int $ms ): array {
		$entry         = array(
			'status'  => $status,
			'message' => mb_substr( $message, 0, 300 ),
			'ms'      => $ms,
			'at'      => time(),
		);
		$checks        = $this->checks();
		$checks[ $id ] = $entry;
		$known         = array_map( static fn( $l ) => $l->id(), $this->services->registry->all() );
		$this->services->store->set( 'layer_checks', array_intersect_key( $checks, array_flip( $known ) ) );
		return $entry;
	}

	public function test( \WP_REST_Request $req ): array|\WP_Error {
		$layer = $this->services->registry->find( (string) $req['id'] );
		if ( ! $layer ) {
			return new \WP_Error( 'purgekit_no_layer', 'Unknown layer', array( 'status' => 404 ) );
		}
		try {
			$r      = $layer->test();
			$status = $r->status;
			$text   = $r->message;
			$ms     = $r->ms;
		} catch ( \Throwable $e ) {
			$status = 'failed';
			$text   = $e->getMessage();
			$ms     = 0;
		}
		// A layer that is not set up was not checked, so that is not remembered as a result.
		$entry = 'skipped' === $status ? array(
			'status'  => $status,
			'message' => $text,
			'ms'      => $ms,
			'at'      => time(),
		) : $this->remember_check( $layer->id(), $status, $text, $ms );
		return array( 'layer' => $layer->id() ) + $entry;
	}
}
