<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Store;
use PurgeKit\Secrets\Secrets;

final class CloudflareLayer extends AbstractLayer implements ServesFromCache, TagPurger {
	private const BATCH           = 30;
	private const MAX_PER_REQUEST = 100;

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

	/** Purge by cache tag (the Cache-Tag header PurgeKit adds when "Cache tags" is on). */
	public function purgeTags( array $tags ): LayerResult {
		return $this->purgeBy( 'tags', $tags );
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
	 * What Cloudflare says about the saved token: its status and when it expires. Only user tokens can be
	 * checked this way, so a null answer means "could not tell", not "bad".
	 *
	 * @return array{status:string,expires_on:?string}|null
	 */
	public function tokenInfo(): ?array {
		$token = (string) $this->secrets->get( 'CLOUDFLARE_TOKEN' );
		if ( '' === $token ) {
			return null;
		}
		try {
			$response = $this->http->request( 'GET', 'https://api.cloudflare.com/client/v4/user/tokens/verify', array( 'headers' => array( 'Authorization' => 'Bearer ' . $token ) ) );
		} catch ( HttpException $e ) {
			unset( $e );
			return null;
		}
		$data = json_decode( $response->body, true );
		if ( $response->status < 200 || $response->status >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
			return null;
		}
		return array(
			'status'     => (string) ( $data['result']['status'] ?? '' ),
			'expires_on' => isset( $data['result']['expires_on'] ) ? (string) $data['result']['expires_on'] : null,
		);
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

	/**
	 * Purge by URL prefix ("example.com/blog/", no scheme, query or fragment), by cache tag, or by host.
	 * All plans can do this since April 2025. One request takes at most 100 entries, and the free plan allows
	 * 5 requests a minute, so a long list can run into the rate limit; the error then says so.
	 *
	 * @param 'prefixes'|'tags'|'hosts' $kind
	 * @param string[]                  $items
	 */
	public function purgeBy( string $kind, array $items ): LayerResult {
		$clean = array();
		foreach ( $items as $item ) {
			$value = is_string( $item ) ? $this->clean( $kind, trim( $item ) ) : null;
			if ( null !== $value ) {
				$clean[ $value ] = true;
			}
		}
		if ( array() === $clean ) {
			return $this->fail( 'Nothing valid to purge. Prefixes look like example.com/blog/ (no https://, no ?query), tags and hosts take no spaces or commas.' );
		}
		foreach ( array_chunk( array_keys( $clean ), self::MAX_PER_REQUEST ) as $chunk ) {
			$result = $this->send( array( $kind => $chunk ) );
			if ( LayerResult::OK !== $result->status ) {
				return $result;
			}
		}
		return $this->ok( sprintf( 'Purged %d %s.', count( $clean ), $kind ) );
	}

	private function clean( string $kind, string $value ): ?string {
		if ( '' === $value || strlen( $value ) > 1024 ) {
			return null;
		}
		if ( 'prefixes' === $kind ) {
			$value = (string) preg_replace( '#^https?://#i', '', $value );
			if ( 1 !== preg_match( '#^([A-Za-z0-9.\-]+(?::\d+)?)(/[^\s?\#]*)?$#', $value, $m ) ) {
				return null;
			}
			return strtolower( $m[1] ) . ( $m[2] ?? '' ); // The host is not case sensitive, the path is.
		}
		if ( 'hosts' === $kind ) {
			return 1 === preg_match( '/^[A-Za-z0-9.\-]+$/', $value ) ? strtolower( $value ) : null;
		}
		return 1 === preg_match( '/^[^\s,]+$/', $value ) ? $value : null; // A cache tag: no spaces, no commas.
	}

	/**
	 * Development mode makes Cloudflare skip its cache for a while (it switches itself off after three hours).
	 * Needs a token with the Zone Settings permission, which a purge-only token does not have.
	 *
	 * @return array{ok:bool,on:bool,seconds_left:int,message:string}
	 */
	public function developmentMode( ?bool $turn_on = null ): array {
		$answer = null === $turn_on
			? $this->request( 'GET', '/settings/development_mode' )
			: $this->request( 'PATCH', '/settings/development_mode', array( 'value' => $turn_on ? 'on' : 'off' ) );
		if ( ! $answer['ok'] ) {
			return array(
				'ok'           => false,
				'on'           => false,
				'seconds_left' => 0,
				'message'      => $this->settingsHint( $answer['message'] ),
			);
		}
		$result = (array) ( $answer['data']['result'] ?? array() );
		$on     = 'on' === ( $result['value'] ?? 'off' );
		// time_remaining is negative (or false) once it has expired or when it was never switched on.
		$left = $on ? max( 0, (int) ( $result['time_remaining'] ?? 0 ) ) : 0;
		return array(
			'ok'           => true,
			'on'           => $on,
			'seconds_left' => $left,
			'message'      => '',
		);
	}

	/**
	 * Whether Automatic Platform Optimization is on for the zone. Read-only: switching it needs the whole
	 * configuration and is better done in the Cloudflare dashboard.
	 *
	 * @return array{ok:bool,enabled:bool,wp_plugin:bool,hostnames:string[],message:string}
	 */
	public function apoStatus(): array {
		$answer = $this->request( 'GET', '/settings/automatic_platform_optimization' );
		if ( ! $answer['ok'] ) {
			return array(
				'ok'        => false,
				'enabled'   => false,
				'wp_plugin' => false,
				'hostnames' => array(),
				'message'   => $this->settingsHint( $answer['message'] ),
			);
		}
		$value = (array) ( $answer['data']['result']['value'] ?? array() );
		return array(
			'ok'        => true,
			'enabled'   => ! empty( $value['enabled'] ),
			'wp_plugin' => ! empty( $value['wp_plugin'] ),
			'hostnames' => array_values( array_map( 'strval', (array) ( $value['hostnames'] ?? array() ) ) ),
			'message'   => '',
		);
	}

	private function settingsHint( string $message ): string {
		return $message . ' (reading or changing zone settings needs a token with the Zone · Zone Settings permission)';
	}

	private function send( array $payload ): LayerResult {
		return $this->call( 'POST', '/purge_cache', $payload );
	}

	private function call( string $method, string $path, ?array $payload = null ): LayerResult {
		$answer = $this->request( $method, $path, $payload );
		return $answer['ok'] ? $this->ok() : $this->fail( $answer['message'] );
	}

	/** @return array{ok:bool,data:array<mixed>,message:string} */
	private function request( string $method, string $path, ?array $payload = null ): array {
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
			return array(
				'ok'      => false,
				'data'    => array(),
				'message' => str_replace( $token, '[redacted]', $e->getMessage() ),
			);
		}

		$data = json_decode( $response->body, true );
		if ( $response->status >= 200 && $response->status < 300 && is_array( $data ) && ! empty( $data['success'] ) ) {
			return array(
				'ok'      => true,
				'data'    => $data,
				'message' => '',
			);
		}
		$message = is_array( $data ) ? (string) ( $data['errors'][0]['message'] ?? '' ) : '';
		$message = '' !== $message ? $message : 'HTTP ' . $response->status;
		if ( 429 === $response->status ) {
			$message = 'Cloudflare is rate limiting purge requests (the free plan allows 5 a minute). Try again shortly.';
		}
		return array(
			'ok'      => false,
			'data'    => array(),
			'message' => str_replace( $token, '[redacted]', $message ),
		);
	}
}
