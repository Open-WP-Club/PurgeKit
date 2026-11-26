<?php
namespace PurgeKit\Rest;

use PurgeKit\Core\LayerResult;

final class InspectController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/inspect/variants',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'variants' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/inspect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'inspect' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/inspect/cacheability',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cacheability' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
		register_rest_route(
			self::NS,
			'/layers/(?P<id>[a-z0-9\-]+)/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge_layer' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	/** Compare up to four explicitly selected URL/device/language combinations. */
	public function variants( \WP_REST_Request $req ): array|\WP_Error {
		$items = $req->get_param( 'variants' );
		if ( ! is_array( $items ) || ! $items || count( $items ) > 4 ) {
			return new \WP_Error( 'purgekit_variants', 'Choose between one and four variants.', array( 'status' => 400 ) );
		}
		$validated = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_string( $item['url'] ?? null ) || ! is_string( $item['device'] ?? null ) || ! is_string( $item['language'] ?? null ) ) {
				return new \WP_Error( 'purgekit_variants', 'Each variant needs a URL, device and language.', array( 'status' => 400 ) );
			}
			$url = $this->own_url( $item['url'] );
			if ( null === $url ) {
				return new \WP_Error( 'purgekit_bad_url', 'Enter a URL on this site.', array( 'status' => 400 ) );
			}
			try {
				$headers = \PurgeKit\Inspector\Variants::headers( $item['device'], $item['language'] );
			} catch ( \InvalidArgumentException $e ) {
				return new \WP_Error( 'purgekit_variants', $e->getMessage(), array( 'status' => 400 ) );
			}
			$validated[] = array(
				'url'      => $url,
				'device'   => $item['device'],
				'language' => $item['language'],
				'headers'  => $headers,
			);
		}
		$out = array();
		foreach ( $validated as $v ) {
			$out[] = array(
				'url'          => $v['url'],
				'device'       => $v['device'],
				'language'     => $v['language'],
				'inspection'   => $this->services->inspector->inspect( $v['url'], $v['headers'] ),
				'cacheability' => $this->services->cacheability->check( $v['url'], $v['headers'] ),
			);
		}
		return array( 'variants' => $out );
	}

	public function inspect( \WP_REST_Request $req ): array|\WP_Error {
		$url = $this->own_url( (string) $req->get_param( 'url' ) );
		if ( null === $url ) {
			return new \WP_Error( 'purgekit_bad_url', 'Enter a URL on this site.', array( 'status' => 400 ) );
		}
		return $this->services->inspector->inspect( $url );
	}

	/** "Why isn't it cached?" for one page of this site. */
	public function cacheability( \WP_REST_Request $req ): array|\WP_Error {
		$url = $this->own_url( (string) $req->get_param( 'url' ) );
		if ( null === $url ) {
			return new \WP_Error( 'purgekit_bad_url', 'Enter a URL on this site.', array( 'status' => 400 ) );
		}
		return $this->services->cacheability->check( $url );
	}

	/** Purge one URL from one layer only (the inspector's "purge this layer" button). */
	public function purge_layer( \WP_REST_Request $req ): array|\WP_Error {
		$layer = $this->services->registry->find( (string) $req['id'] );
		$url   = $this->own_url( (string) $req->get_param( 'url' ) );
		if ( ! $layer ) {
			return new \WP_Error( 'purgekit_no_layer', 'Unknown layer', array( 'status' => 404 ) );
		}
		if ( null === $url ) {
			return new \WP_Error( 'purgekit_bad_url', 'Enter a URL on this site.', array( 'status' => 400 ) );
		}
		try {
			$r = $layer->purgeUrls( array( $url ) );
		} catch ( \Throwable $e ) {
			$r = new LayerResult( $layer->id(), LayerResult::FAILED, $e->getMessage() );
		}
		return array(
			'layer'   => $r->layer,
			'status'  => $r->status,
			'message' => $r->message,
		);
	}

	/** Only http(s) URLs on this site's host: the inspector must not become a proxy for arbitrary requests. */
	private function own_url( string $url ): ?string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return null;
		}
		if ( ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		if ( 0 !== strcasecmp( $parts['host'], $home['host'] ) ) {
			return null;
		}
		if ( ( $parts['scheme'] ?? '' ) !== ( $home['scheme'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return null;
		}
		return explode( '#', $url, 2 )[0];
	}
}
