<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Store;
use PurgeKit\Secrets\Secrets;

/**
 * A CDN or firewall cache that is purged with plain HTTP calls, described by a spec array
 * (see ProviderSpecs) instead of a class per provider.
 *
 * Placeholders in url, headers and body: `{NAME}` is a secret (UPPER_CASE) or a setting (lower_case),
 * `{basic:NAME}` is base64("secret:") for HTTP Basic auth, `{url}`, `{url_noscheme}` and `{url_encoded}`
 * are the page being purged, `{tags}` is the space separated list of cache tags of a tag purge. A body element that is exactly `{urls_noscheme}` or `{urls}` expands to the batch's URL list
 * (without or with the scheme).
 */
final class ApiLayer extends AbstractLayer implements ServesFromCache, TagPurger {
	/** @param array<string, mixed> $spec */
	public function __construct( private array $spec, private Http $http, private Secrets $secrets, private Store $settings ) {}

	public function id(): string {
		return (string) $this->spec['id'];
	}
	public function label(): string {
		return (string) $this->spec['label'];
	}
	public function tier(): int {
		return (int) ( $this->spec['tier'] ?? Tier::CDN );
	}

	public function detect(): bool {
		foreach ( (array) ( $this->spec['secrets'] ?? array() ) as $name ) {
			if ( ! $this->secrets->has( $name ) ) {
				return false;
			}
		}
		foreach ( (array) ( $this->spec['settings'] ?? array() ) as $key ) {
			if ( '' === trim( (string) $this->settings->get( $key, '' ) ) ) {
				return false;
			}
		}
		return true;
	}

	public function test(): LayerResult {
		if ( ! $this->detect() ) {
			return new LayerResult( $this->id(), LayerResult::SKIPPED, 'Not detected' );
		}
		if ( empty( $this->spec['test'] ) ) {
			return $this->ok( 'Credentials are saved; this provider has no read-only check.' );
		}
		return $this->call( (array) $this->spec['test'], array() );
	}

	public function purgeAll(): LayerResult {
		return $this->call( (array) $this->spec['all'], array() );
	}

	public function purgeUrls( array $urls ): LayerResult {
		if ( empty( $this->spec['url'] ) ) {
			return $this->purgeAll(); // The provider cannot purge one URL.
		}
		$req   = (array) $this->spec['url'];
		$size  = max( 1, (int) ( $req['batch'] ?? 1 ) );
		$urls  = array_values( array_map( 'strval', $urls ) );
		$final = $this->ok();
		foreach ( array_chunk( $urls, $size ) as $chunk ) {
			$result = $this->call( $req, $chunk );
			if ( LayerResult::OK !== $result->status ) {
				return $result;
			}
		}
		return $final;
	}

	/** Only providers whose spec has a `tags` request purge by tag; for the others the URLs already did the work. */
	public function purgeTags( array $tags ): LayerResult {
		if ( empty( $this->spec['tags'] ) ) {
			return $this->ok();
		}
		$req  = (array) $this->spec['tags'];
		$size = max( 1, (int) ( $req['batch'] ?? 100 ) );
		foreach ( array_chunk( array_values( array_map( 'strval', $tags ) ), $size ) as $chunk ) {
			$result = $this->call( $req, array(), $chunk );
			if ( LayerResult::OK !== $result->status ) {
				return $result;
			}
		}
		return $this->ok( sprintf( '%d cache tags purged.', count( $tags ) ) );
	}

	public function servedFrom( array $headers, string $body = '' ): ?string {
		$fp = $this->spec['fingerprint'] ?? null;
		if ( ! is_array( $fp ) ) {
			return null;
		}
		if ( ! empty( $fp['requires'] ) ) {
			$need = Fingerprint::value( $headers, (string) $fp['requires'][0] );
			if ( '' === $need || 1 !== preg_match( (string) $fp['requires'][1], $need ) ) {
				return null;
			}
		}
		return Fingerprint::header( (string) $fp['header'], (string) $fp['pattern'], $this->label() )( $headers );
	}

	/** One request per URL in a batch is not needed here: `{url*}` placeholders use the first, `{urls_noscheme}` uses all. */
	private function call( array $req, array $urls, array $tags = array() ): LayerResult {
		$first             = $urls[0] ?? '';
		$context           = $this->context( $first );
		$context['{tags}'] = implode( ' ', $tags );
		try {
			$headers = (array) ( $req['headers'] ?? array() );
			// Soft purge marks the copy stale instead of deleting it, so a purge does not send a burst of requests to the origin.
			if ( ! empty( $req['soft_headers'] ) && (bool) $this->settings->get( 'soft_purge', false ) ) {
				$headers = array_merge( $headers, (array) $req['soft_headers'] );
			}
			$args = array( 'headers' => $this->fill( $headers, $context, $urls ) );
			if ( isset( $req['body'] ) ) {
				$body = $this->fill( (array) $req['body'], $context, $urls );
				if ( 'form' === ( $req['body_type'] ?? 'json' ) ) {
					$args['body']                    = http_build_query( $body );
					$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
				} else {
					$args['body']                    = json_encode( $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
					$args['headers']['Content-Type'] = 'application/json';
				}
			}
			$target = (string) $this->fill( (string) $req['url'], $context, $urls );
			if ( 1 !== preg_match( '#^https?://[^\s/]+#i', $target ) ) {
				return $this->fail( 'The address is not a valid http(s) URL.' );
			}
			$response = $this->http->request( (string) $req['method'], $target, $args );
		} catch ( HttpException $e ) {
			return $this->fail( $this->redact( $e->getMessage() ) );
		}
		return $this->judge( $response );
	}

	private function judge( HttpResponse $r ): LayerResult {
		if ( $r->status < 200 || $r->status >= 300 ) {
			$data    = json_decode( $r->body, true );
			$message = is_array( $data ) ? (string) ( $data['Message'] ?? $data['msg'] ?? $data['detail'] ?? $data['errors'][0]['message'] ?? '' ) : '';
			return $this->fail( $this->redact( '' !== $message ? $message : 'HTTP ' . $r->status ) );
		}
		$data = json_decode( $r->body, true );
		if ( is_array( $data ) && ( ! empty( $data['error'] ) || ( array_key_exists( 'success', $data ) && false === $data['success'] ) ) ) {
			$msg = is_array( $data['messages'] ?? null ) ? implode( '; ', array_map( 'strval', $data['messages'] ) ) : 'The provider reported an error.';
			return $this->fail( $this->redact( $msg ) );
		}
		return $this->ok();
	}

	/** @return array<string, string> */
	private function context( string $url ): array {
		$ctx = array(
			'{url}'          => $url,
			'{url_noscheme}' => (string) preg_replace( '#^https?://#i', '', $url ),
			'{url_encoded}'  => rawurlencode( $url ),
		);
		foreach ( (array) ( $this->spec['settings'] ?? array() ) as $key ) {
			$ctx[ '{' . $key . '}' ] = rawurlencode( trim( (string) $this->settings->get( $key, '' ) ) );
		}
		foreach ( (array) ( $this->spec['secrets'] ?? array() ) as $name ) {
			$secret                         = (string) $this->secrets->get( $name );
			$ctx[ '{' . $name . '}' ]       = $secret;
			$ctx[ '{basic:' . $name . '}' ] = base64_encode( $secret . ':' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return $ctx;
	}

	/**
	 * @param string|array<mixed> $value
	 * @param array<string,string> $ctx
	 * @param string[]             $urls
	 * @return string|array<mixed>
	 */
	private function fill( string|array $value, array $ctx, array $urls ): string|array {
		if ( is_string( $value ) ) {
			return strtr( $value, $ctx );
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( '{urls_noscheme}' === $v ) {
				$out[ $k ] = array_map( static fn( $u ) => (string) preg_replace( '#^https?://#i', '', $u ), $urls );
				continue;
			}
			if ( '{urls}' === $v ) {
				$out[ $k ] = array_values( $urls );
				continue;
			}
			$out[ $k ] = is_string( $v ) || is_array( $v ) ? $this->fill( $v, $ctx, $urls ) : $v; // true, false and numbers stay as they are.
		}
		return $out;
	}

	private function redact( string $text ): string {
		foreach ( (array) ( $this->spec['secrets'] ?? array() ) as $name ) {
			$secret = (string) $this->secrets->get( $name );
			if ( '' !== $secret ) {
				$text = str_replace( $secret, '[redacted]', $text );
			}
		}
		return $text;
	}
}
