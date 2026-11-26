<?php
namespace PurgeKit\Inspector;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Layers\Registry;
use PurgeKit\Layers\ServesFromCache;

// This class runs without WordPress in unit tests, so wp_parse_url() is not available.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url
/**
 * "Why isn't it cached?" Fetches a page twice as an anonymous visitor (no cookies) and explains what keeps
 * a cache from storing it: cookies set on the response, Cache-Control, Vary, the status code, and what the
 * known cache layers answered the second time. It reads headers only and never reports cookie values.
 */
final class Cacheability {
	public const CACHED    = 'cached';
	public const BLOCKED   = 'blocked';
	public const UNCERTAIN = 'uncertain';
	public const ERROR     = 'error';

	public const BLOCKER = 'blocker';
	public const WARNING = 'warning';
	public const INFO    = 'info';
	public const OK      = 'ok';

	private const HEADER_PATTERN = '/^(age|cache-control|expires|pragma|vary|etag|last-modified|via|cf-cache-status|x-.*cache.*|x-varnish|x-proxy-cache|x-litespeed.*)$/';

	public function __construct( private Http $http, private Registry $registry ) {}

	/** @return array<string,mixed> */
	public function check( string $url, array $headers = array() ): array {
		$args = array(
			'headers'     => array_merge( $headers, array( 'X-PurgeKit-Inspect' => '1' ) ),
			'timeout'     => 15,
			'redirection' => 0, // A redirect is a finding of its own, not something to follow silently.
		);
		try {
			$first  = $this->http->request( 'GET', $url, $args );
			$second = $this->http->request( 'GET', $url, $args );
		} catch ( HttpException $e ) {
			return array(
				'verdict'  => self::ERROR,
				'url'      => $url,
				'message'  => 'Could not fetch the URL: ' . $e->getMessage(),
				'findings' => array(),
				'evidence' => array(),
				'cookies'  => array(),
				'first'    => array(),
				'second'   => array(),
			);
		}

		$findings = $this->findings( $url, $first );
		$evidence = $this->evidence( $second );
		$blockers = array_filter( $findings, static fn( $f ) => self::BLOCKER === $f['severity'] );

		if ( $evidence ) {
			$verdict = self::CACHED;
			$message = 'Yes: a cache answered the second request. ' . implode( '; ', $evidence ) . '.';
		} elseif ( $blockers ) {
			$verdict = self::BLOCKED;
			$message = 'No cache is likely to store this page. See the reasons below.';
		} else {
			$verdict = self::UNCERTAIN;
			$message = 'Nothing in the response stops caching, but no cache answered the second request. Either no cache is set up for this page, or the cache needs another visit to fill.';
		}
		return array(
			'verdict'  => $verdict,
			'url'      => $url,
			'message'  => $message,
			'findings' => $findings,
			'evidence' => $evidence,
			'cookies'  => $this->cookieNames( $first ),
			'first'    => $this->summary( $first ),
			'second'   => $this->summary( $second ),
		);
	}

	/** @return array<int,array{severity:string,code:string,message:string}> */
	private function findings( string $url, HttpResponse $r ): array {
		$h   = array_change_key_case( $r->headers, CASE_LOWER );
		$out = array();
		$add = static function ( string $severity, string $code, string $message ) use ( &$out ): void {
			$out[] = array(
				'severity' => $severity,
				'code'     => $code,
				'message'  => $message,
			);
		};

		// Status.
		if ( $r->status >= 500 ) {
			$add( self::BLOCKER, 'status', "The page answered with an error (HTTP {$r->status}). Error pages are not cached." );
		} elseif ( $r->status >= 400 ) {
			$add( self::WARNING, 'status', "The page answers HTTP {$r->status}. Caches store these briefly or not at all." );
		} elseif ( $r->status >= 300 ) {
			$to = $this->value( $h, 'location' );
			$add( self::INFO, 'redirect', 'The URL redirects' . ( '' !== $to ? " to $to" : '' ) . '. Check the address it ends up on, which is the one visitors get cached.' );
		}

		// Cookies set on an anonymous visit.
		foreach ( $this->cookieNames( $r ) as $name ) {
			$add( self::BLOCKER, 'cookie', $this->cookieHint( $name ) );
		}

		// Cache-Control.
		$cc = $this->cacheControl( $this->value( $h, 'cache-control' ) );
		if ( array() === $cc ) {
			$add( self::INFO, 'cache-control', 'There is no Cache-Control header, so each cache falls back on its own rules. Cloudflare, for one, does not cache HTML pages by default.' );
		} else {
			// One finding however many of these are set: WordPress sends all three together for personal pages.
			$reasons = array(
				'no-store' => 'forbids any cache from storing the page',
				'private'  => 'keeps the page out of shared caches such as a CDN',
				'no-cache' => 'makes caches check with the server every time. WordPress sends this for pages it treats as personal; a caching plugin normally replaces it for pages it caches',
			);
			$found   = array_values( array_intersect( array_keys( $reasons ), array_keys( $cc ) ) );
			if ( array() !== $found ) {
				$first = $found[0];
				$add( self::BLOCKER, 'cache-control', "Cache-Control: $first {$reasons[ $first ]}." . ( count( $found ) > 1 ? ' Also set: ' . implode( ', ', array_slice( $found, 1 ) ) . '.' : '' ) );
			}
			$shared    = $cc['s-maxage'] ?? $cc['max-age'] ?? null;
			$forbidden = array() !== array_intersect_key( $cc, array_flip( array( 'no-store', 'private', 'no-cache' ) ) );
			if ( null !== $shared && '0' === $shared && ! $forbidden ) {
				$add( self::BLOCKER, 'cache-control', 'The lifetime is 0 seconds, so the page is stale the moment it is stored.' );
			} elseif ( null !== $shared && (int) $shared > 0 && ! $forbidden ) {
				$add( self::OK, 'cache-control', sprintf( 'Cache-Control allows caching for %d seconds.', (int) $shared ) );
			}
		}
		if ( 1 === preg_match( '/no-cache/i', $this->value( $h, 'pragma' ) ) ) {
			$add( self::WARNING, 'pragma', 'Pragma: no-cache asks caches not to reuse the page.' );
		}
		$expires = $this->value( $h, 'expires' );
		if ( '' !== $expires && array() === $cc ) {
			$when = strtotime( $expires );
			if ( false !== $when && $when <= time() ) {
				$add( self::WARNING, 'expires', 'The Expires header is already in the past, so the page counts as stale.' );
			}
		}

		// Vary.
		$vary = strtolower( $this->value( $h, 'vary' ) );
		if ( '*' === trim( $vary ) ) {
			$add( self::BLOCKER, 'vary', 'Vary: * means every request is different, so nothing can be reused.' );
		}
		if ( str_contains( $vary, 'cookie' ) ) {
			$add( self::WARNING, 'vary', 'Vary: Cookie makes a separate copy for every cookie value, which defeats a shared cache. Look for a plugin that adds it.' );
		}
		if ( str_contains( $vary, 'user-agent' ) ) {
			$add( self::WARNING, 'vary', 'Vary: User-Agent splits the cache into many copies, one per browser string.' );
		}

		// The URL itself.
		if ( null !== ( parse_url( $url, PHP_URL_QUERY ) ) && '' !== (string) parse_url( $url, PHP_URL_QUERY ) ) {
			$add( self::WARNING, 'query', 'This URL has a query string. Many caches skip such URLs, or keep a copy per query string.' );
		}

		// What Cloudflare says about it.
		$cf = strtoupper( $this->value( $h, 'cf-cache-status' ) );
		if ( 'DYNAMIC' === $cf ) {
			$add( self::BLOCKER, 'cloudflare', 'Cloudflare marks this page DYNAMIC: it does not cache HTML unless a Cache Rule says "Cache everything" or Automatic Platform Optimization is on.' );
		} elseif ( 'BYPASS' === $cf ) {
			$add( self::BLOCKER, 'cloudflare', 'Cloudflare BYPASSED its cache for this page, because of a cookie, Cache-Control: private or no-store, or a rule.' );
		}
		return $out;
	}

	/** @return string[] How the second response shows that something cached it. */
	private function evidence( HttpResponse $second ): array {
		$h   = array_change_key_case( $second->headers, CASE_LOWER );
		$out = array();
		foreach ( $this->registry->all() as $layer ) {
			if ( $layer instanceof ServesFromCache ) {
				$hit = $layer->servedFrom( $h, $second->body );
				if ( null !== $hit ) {
					$out[] = $hit;
				}
			}
		}
		$age = $this->value( $h, 'age' );
		if ( ctype_digit( $age ) && (int) $age > 0 ) {
			$out[] = "Age: $age";
		}
		foreach ( array( 'x-cache', 'x-cache-status', 'x-fastcgi-cache', 'x-proxy-cache', 'x-litespeed-cache' ) as $name ) {
			$v = $this->value( $h, $name );
			if ( 1 === preg_match( '/\bhit\b/i', $v ) ) {
				$out[] = "$name: $v";
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** @return string[] Cookie names only. Values can be session tokens, so they are never read out. */
	private function cookieNames( HttpResponse $r ): array {
		$raw   = array_change_key_case( $r->headers, CASE_LOWER )['set-cookie'] ?? '';
		$lines = is_array( $raw ) ? $raw : array( (string) $raw );
		$names = array();
		foreach ( $lines as $line ) {
			// Several cookies may arrive joined with commas; a comma inside an Expires date is not followed by "name=".
			$parts = preg_split( '/,\s*(?=[^=;,\s]+=)/', (string) $line );
			foreach ( false === $parts ? array() : $parts as $cookie ) {
				if ( 1 === preg_match( '/^\s*([^=;\s]+)=/', $cookie, $m ) ) {
					$names[ $m[1] ] = true;
				}
			}
		}
		return array_keys( $names );
	}

	private function cookieHint( string $name ): string {
		$lower = strtolower( $name );
		if ( str_contains( $lower, 'woocommerce' ) ) {
			return "Cookie $name: WooCommerce is setting a cart or session cookie. That is normal on the cart and checkout, but a first-time visitor on a shop or product page should not get one.";
		}
		if ( 'phpsessid' === $lower || 1 === preg_match( '/^(sess|session)/', $lower ) ) {
			return "Cookie $name: a PHP session was started, usually by a plugin or theme calling session_start(). That alone stops most page caches; find the code that starts it.";
		}
		if ( 'wordpress_test_cookie' === $lower ) {
			return 'Cookie wordpress_test_cookie: this is the login form, which must not be cached.';
		}
		if ( str_starts_with( $lower, 'wordpress_logged_in' ) || str_starts_with( $lower, 'comment_author' ) ) {
			return "Cookie $name: the page is personalised for a signed-in or returning commenter.";
		}
		return "Cookie $name is set on an anonymous visit. Most caches do not store a response that sets a cookie.";
	}

	/** @return array<string,string> */
	private function cacheControl( string $value ): array {
		$out = array();
		foreach ( explode( ',', strtolower( $value ) ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits                    = explode( '=', $part, 2 );
			$out[ trim( $bits[0] ) ] = trim( $bits[1] ?? '', " \t\"" );
		}
		return $out;
	}

	/** @param array<string,mixed> $headers Lower-case keys. */
	private function value( array $headers, string $name ): string {
		$v = $headers[ $name ] ?? '';
		return trim( is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v );
	}

	/** @return array{status:int,headers:array<string,string>} Cache-related headers only; never Set-Cookie. */
	private function summary( HttpResponse $r ): array {
		$headers = array();
		foreach ( array_change_key_case( $r->headers, CASE_LOWER ) as $name => $value ) {
			if ( 1 === preg_match( self::HEADER_PATTERN, (string) $name ) ) {
				$headers[ $name ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		}
		return array(
			'status'  => $r->status,
			'headers' => $headers,
		);
	}
}
