<?php
namespace PurgeKit\Rest;

final class AssetsController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/assets/bump',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bump' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	/**
	 * Gives the site's CSS and JS files a new version, then purges everything, because the cached pages still
	 * point at the old addresses.
	 */
	public function bump(): \WP_REST_Response {
		$version         = $this->services->settings->bumpAssetVersion( time() );
		$run             = $this->services->flusher->flushAll( 'asset-bump' );
		$resp            = $this->run_response( $run );
		$data            = $resp->get_data();
		$data['version'] = $version;
		$resp->set_data( $data );
		return $resp;
	}
}
