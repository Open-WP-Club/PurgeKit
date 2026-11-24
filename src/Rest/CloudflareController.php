<?php
namespace PurgeKit\Rest;

use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Layers\CloudflareLayer;

/** Cloudflare actions beyond the normal flush: purge by prefix or tag, development mode, APO status. */
final class CloudflareController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/cloudflare/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/cloudflare/development-mode',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'development_mode' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/cloudflare/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	private function layer(): CloudflareLayer|\WP_Error {
		$layer = $this->services->registry->find( 'cloudflare' );
		if ( ! $layer instanceof CloudflareLayer || ! $layer->detect() ) {
			return new \WP_Error( 'purgekit_cloudflare_not_set_up', 'Cloudflare is not set up: add the API token and the zone ID first.', array( 'status' => 409 ) );
		}
		return $layer;
	}

	public function status(): array|\WP_Error {
		$layer = $this->layer();
		if ( is_wp_error( $layer ) ) {
			return $layer;
		}
		return array(
			'development_mode' => $layer->developmentMode(),
			'apo'              => $layer->apoStatus(),
		);
	}

	public function development_mode( \WP_REST_Request $req ): array|\WP_Error {
		$layer = $this->layer();
		if ( is_wp_error( $layer ) ) {
			return $layer;
		}
		return $layer->developmentMode( filter_var( $req->get_param( 'enabled' ), FILTER_VALIDATE_BOOLEAN ) );
	}

	/** Purges by prefix and/or tag. Honours the staging pause and a layer that is switched off, like any other purge. */
	public function purge( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$layer = $this->layer();
		if ( is_wp_error( $layer ) ) {
			return $layer;
		}
		if ( in_array( $layer->id(), $this->services->settings->disabledLayers(), true ) ) {
			return new \WP_Error( 'purgekit_layer_off', 'Cloudflare is switched off in the layer map.', array( 'status' => 409 ) );
		}
		$paused = $this->services->guard->reasonFor( $layer->tier() );
		if ( null !== $paused ) {
			return new \WP_Error( 'purgekit_paused', $paused, array( 'status' => 409 ) );
		}

		$wanted = array();
		foreach ( array( 'prefixes', 'tags' ) as $kind ) {
			$list = array_values( array_filter( (array) $req->get_param( $kind ), 'is_string' ) );
			if ( $list ) {
				$wanted[ $kind ] = $list;
			}
		}
		if ( ! $wanted ) {
			return new \WP_Error( 'purgekit_nothing_to_purge', 'Give at least one prefix or tag.', array( 'status' => 400 ) );
		}

		$results = array();
		$labels  = array();
		foreach ( $wanted as $kind => $list ) {
			$start      = microtime( true );
			$result     = $layer->purgeBy( $kind, $list );
			$result->ms = (int) round( ( microtime( true ) - $start ) * 1000 );
			$results[]  = $result;
			$labels     = array_merge( $labels, array_map( static fn( $item ) => ( 'tags' === $kind ? 'tag:' : 'prefix:' ) . $item, $list ) );
		}
		$run = Run::fromResults( $results, PurgePlan::forUrls( $labels ) );
		$this->services->history->record( 'admin-cloudflare', $run, array() );
		return $this->run_response( $run );
	}
}
