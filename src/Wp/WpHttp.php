<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;

final class WpHttp implements Http {
	public function requestMany( string $method, array $urls, array $args = array() ): array {
		$out = array();
		if ( ! class_exists( '\WpOrg\Requests\Requests' ) ) {
			foreach ( $urls as $i => $url ) {
				try {
					$out[ $i ] = $this->request( $method, $url, $args );
				} catch ( HttpException $e ) {
					$out[ $i ] = $e;
				}
			}
			return $out;
		}
		$requests = array();
		foreach ( $urls as $i => $url ) {
			$requests[ $i ] = array(
				'url'     => $url,
				'type'    => $method,
				'headers' => $args['headers'] ?? array(),
			);
		}
		// Same switch WordPress uses for loopback requests, so self-signed local sites still warm.
		$verify  = apply_filters( 'https_local_ssl_verify', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$results = \WpOrg\Requests\Requests::request_multiple(
			$requests,
			array(
				'follow_redirects' => (int) ( $args['redirection'] ?? 5 ) > 0,
				'timeout'          => (int) ( $args['timeout'] ?? 15 ),
				'connect_timeout'  => 5,
				'verify'           => $verify ? \WpOrg\Requests\Requests::get_certificate_path() : false,
			)
		);
		foreach ( $urls as $i => $url ) {
			$r = $results[ $i ] ?? null;
			if ( $r instanceof \WpOrg\Requests\Response ) {
				$out[ $i ] = new HttpResponse( (int) $r->status_code, (string) $r->body, $r->headers->getAll() );
			} else {
				$out[ $i ] = new HttpException( $r instanceof \Throwable ? $r->getMessage() : 'Request failed' );
			}
		}
		return $out;
	}

	public function request( string $method, string $url, array $args = array() ): HttpResponse {
		$response = wp_remote_request( $url, array_merge( array( 'timeout' => 15 ), $args, array( 'method' => $method ) ) );
		if ( is_wp_error( $response ) ) {
			throw new HttpException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		$headers = wp_remote_retrieve_headers( $response );
		return new HttpResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers
		);
	}
}
