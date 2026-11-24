<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;

final class WpHttp implements Http {
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
