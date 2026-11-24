<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Store;
use PurgeKit\Secrets\Secrets;

final class CloudflareLayer extends AbstractLayer implements ServesFromCache {
	private const BATCH = 30;

	public function __construct( private Http $http, private Secrets $secrets, private Store $settings ) {}

	public function id(): string {
		return 'cloudflare';
	}
	public function label(): string {
		return 'Cloudflare';
	}
	public function tier(): int {
		return Tier::CDN;
	}
	public function detect(): bool {
		return $this->secrets->has( 'CLOUDFLARE_TOKEN' ) && '' !== (string) $this->settings->get( 'cloudflare_zone', '' );
	}

	public function servedFrom( array $headers, string $body = '' ): ?string {
		return Fingerprint::header( 'cf-cache-status', '/^(HIT|STALE|REVALIDATED|UPDATING)$/i', 'Cloudflare edge cache' )( $headers );
	}

	public function purgeAll(): LayerResult {
		return $this->send( array( 'purge_everything' => true ) );
	}

	public function purgeUrls( array $urls ): LayerResult {
		foreach ( array_chunk( array_values( $urls ), self::BATCH ) as $chunk ) {
			$result = $this->send( array( 'files' => $chunk ) );
			if ( LayerResult::OK !== $result->status ) {
				return $result;
			}
		}
		return $this->ok();
	}

	/** Read-only check: the token can read the configured zone. Does not purge anything. */
	public function test(): LayerResult {
		if ( ! $this->detect() ) {
			return new LayerResult( $this->id(), LayerResult::SKIPPED, 'Not detected' );
		}
		return $this->call( 'GET', '' );
	}

	/**
	 * Look up the zone ID for a site host, trying parent domains (www.shop.example.com, shop.example.com, example.com).
	 * Needs a token that may read zones. Does not change any setting.
	 *
	 * @return array{zone: ?string, message: string}
	 */
	public function findZone( string $host, ?string $token ): array {
		$token = $token ?? (string) $this->secrets->get( 'CLOUDFLARE_TOKEN' );
		if ( '' === $token ) {
			return array(
				'zone'    => null,
				'message' => 'Enter or save a Cloudflare API token first.',
			);
		}
		$labels = explode( '.', strtolower( trim( $host ) ) );
		for ( $remaining = count( $labels ); $remaining >= 2; --$remaining ) {
			$name = implode( '.', $labels );
			try {
				$response = $this->http->request(
					'GET',
					'https://api.cloudflare.com/client/v4/zones?name=' . rawurlencode( $name ),
					array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) )
				);
			} catch ( HttpException $e ) {
				return array(
					'zone'    => null,
					'message' => str_replace( $token, '[redacted]', $e->getMessage() ),
				);
			}
			$data = json_decode( $response->body, true );
			if ( $response->status < 200 || $response->status >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
				$message = is_array( $data ) ? (string) ( $data['errors'][0]['message'] ?? '' ) : '';
				return array(
					'zone'    => null,
					'message' => str_replace( $token, '[redacted]', '' !== $message ? $message : 'HTTP ' . $response->status ),
				);
			}
			$id = (string) ( $data['result'][0]['id'] ?? '' );
			if ( '' !== $id ) {
				return array(
					'zone'    => $id,
					'message' => '',
				);
			}
			array_shift( $labels );
		}
		return array(
			'zone'    => null,
			'message' => 'No Cloudflare zone found for this domain on that account.',
		);
	}

	private function send( array $payload ): LayerResult {
		return $this->call( 'POST', '/purge_cache', $payload );
	}

	private function call( string $method, string $path, ?array $payload = null ): LayerResult {
		$token = (string) $this->secrets->get( 'CLOUDFLARE_TOKEN' );
		$zone  = rawurlencode( (string) $this->settings->get( 'cloudflare_zone', '' ) );
		$args  = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $payload ) {
			$args['body'] = json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
		try {
			$response = $this->http->request( $method, "https://api.cloudflare.com/client/v4/zones/$zone$path", $args );
		} catch ( HttpException $e ) {
			return $this->fail( str_replace( $token, '[redacted]', $e->getMessage() ) );
		}

		$data = json_decode( $response->body, true );
		if ( $response->status >= 200 && $response->status < 300 && is_array( $data ) && ! empty( $data['success'] ) ) {
			return $this->ok();
		}
		$message = is_array( $data ) ? (string) ( $data['errors'][0]['message'] ?? '' ) : '';
		$message = '' !== $message ? $message : 'HTTP ' . $response->status;
		return $this->fail( str_replace( $token, '[redacted]', $message ) );
	}
}
