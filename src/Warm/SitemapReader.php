<?php
namespace PurgeKit\Warm;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;

// This class runs without WordPress in unit tests, so wp_parse_url() is not available.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url
/**
 * Reads page URLs from the site's own sitemap. It tries the standard locations in turn and uses the
 * first one that answers with a valid sitemap: WordPress core (wp-sitemap.xml), Yoast SEO and Rank Math
 * (sitemap_index.xml), All in One SEO, The SEO Framework and Google XML Sitemaps (sitemap.xml) and
 * SEOPress (sitemaps.xml). When an SEO plugin is active it normally replaces the core sitemap. Only http(s)
 * URLs on the site's host are returned or fetched, and the XML is parsed without network access or
 * entity expansion, so a hostile sitemap cannot make the server request other hosts or read files.
 */
final class SitemapReader {
	public const MAX_LIMIT       = 100;
	public const MAX_BYTES       = 1000000;
	public const MAX_SUB_SITEMAP = 3;
	public const PATHS           = array( 'wp-sitemap.xml', 'sitemap_index.xml', 'sitemap.xml', 'sitemaps.xml' );

	private string $host;
	private string $home;
	/** @var string[] */
	private array $paths;

	/** @param string[] $paths Sitemap locations relative to the home URL, tried in order. */
	public function __construct( private Http $http, string $home_url, array $paths = self::PATHS ) {
		$this->host  = strtolower( (string) parse_url( $home_url, PHP_URL_HOST ) );
		$this->home  = rtrim( $home_url, '/' ) . '/';
		$this->paths = array_values(
			array_filter(
				$paths,
				static fn( $p ) => is_string( $p ) && 1 === preg_match( '#^[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)*\.xml$#', $p )
			)
		);
	}

	/** @return string[] De-duplicated own-host URLs, at most $limit (never more than MAX_LIMIT). */
	public function urls( int $limit ): array {
		$limit = max( 0, min( self::MAX_LIMIT, $limit ) );
		if ( 0 === $limit || '' === $this->host ) {
			return array();
		}
		$root = null;
		foreach ( $this->paths as $path ) {
			$root = $this->load( $this->home . $path );
			if ( null !== $root ) {
				break;
			}
		}
		if ( null === $root ) {
			return array();
		}
		$found = array();
		if ( 'sitemapindex' === $root['kind'] ) {
			$subs = array_slice( $root['locs'], 0, self::MAX_SUB_SITEMAP );
			foreach ( $subs as $sub ) {
				$doc = $this->load( $sub );
				if ( null !== $doc && 'urlset' === $doc['kind'] ) {
					$found = array_merge( $found, $doc['locs'] );
				}
				if ( count( array_unique( $found ) ) >= $limit ) {
					break;
				}
			}
		} else {
			$found = $root['locs'];
		}
		return array_slice( array_values( array_unique( $found ) ), 0, $limit );
	}

	/** @return array{kind:string,locs:string[]}|null */
	private function load( string $url ): ?array {
		if ( ! $this->isOwn( $url ) ) {
			return null;
		}
		try {
			$res = $this->http->request(
				'GET',
				$url,
				array(
					'headers' => array( 'X-PurgeKit-Warm' => '1' ),
					'timeout' => 5,
				)
			);
		} catch ( HttpException $e ) {
			return null;
		}
		return 200 === $res->status ? $this->parse( $res->body ) : null;
	}

	/** @return array{kind:string,locs:string[]}|null */
	private function parse( string $xml ): ?array {
		if ( '' === $xml || strlen( $xml ) > self::MAX_BYTES || false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		// LIBXML_NONET blocks network access; LIBXML_NOENT is deliberately not set, so entities stay unexpanded.
		$loaded = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $loaded ? $doc->documentElement : null;
		if ( ! $root || ! in_array( $root->localName, array( 'urlset', 'sitemapindex' ), true ) ) {
			return null;
		}
		$locs = array();
		foreach ( $doc->getElementsByTagNameNS( '*', 'loc' ) as $node ) {
			$loc = trim( (string) $node->textContent );
			if ( $this->isOwn( $loc ) ) {
				$locs[ $loc ] = true;
			}
		}
		return array(
			'kind' => (string) $root->localName,
			'locs' => array_map( 'strval', array_keys( $locs ) ),
		);
	}

	private function isOwn( string $url ): bool {
		$parts = parse_url( $url );
		return is_array( $parts )
			&& in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& strtolower( $parts['host'] ?? '' ) === $this->host;
	}
}
