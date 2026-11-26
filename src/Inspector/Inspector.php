<?php
namespace PurgeKit\Inspector;

use PurgeKit\Core\Clock;
use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Layers\Registry;
use PurgeKit\Layers\ServesFromCache;

/**
 * "Why isn't my change showing?": fetches a URL like a visitor, then again with a cache-busting
 * query string, and reports which cache layer is serving a copy that differs from the fresh one.
 */
final class Inspector {
	public const OK        = 'ok';
	public const STALE     = 'stale';
	public const DIFFERENT = 'different';
	public const ERROR     = 'error';

	private const HEADER_PATTERN = '/^(age|cache-control|expires|etag|last-modified|via|vary|x-.*cache.*|x-varnish|x-proxy-cache|cf-cache-status|cf-ray|x-litespeed.*)$/';

	public function __construct( private Http $http, private Registry $registry, private Clock $clock, private int $timeout = 15 ) {}

	/** @return array{verdict:string,url:string,message:string,layer:?string,served_by:array<int,array{layer:string,label:string,evidence:string,tier:int}>,visitor:array,fresh:array} */
	public function inspect( string $url, array $headers = array() ): array {
		$args = array(
			'headers'     => array_merge( $headers, array( 'X-PurgeKit-Inspect' => '1' ) ),
			'timeout'     => $this->timeout,
			'redirection' => 0,
		);
		try {
			$visitor = $this->http->request( 'GET', $url, $args );
			$fresh   = $this->http->request( 'GET', $this->bust( $url ), $args );
		} catch ( HttpException $e ) {
			return $this->result( self::ERROR, $url, 'Could not fetch the URL: ' . $e->getMessage() );
		}

		if ( $visitor->status >= 400 || $fresh->status >= 400 ) {
			return $this->result( self::ERROR, $url, 'Could not compare content: a request returned an HTTP error.', null, array(), $visitor, $fresh );
		}
		$headers   = array_change_key_case( $visitor->headers, CASE_LOWER );
		$served_by = array();
		foreach ( $this->registry->all() as $layer ) {
			if ( ! $layer instanceof ServesFromCache ) {
				continue;
			}
			$evidence = $layer->servedFrom( $headers, $visitor->body );
			if ( null !== $evidence ) {
				$served_by[] = array(
					'layer'    => $layer->id(),
					'label'    => $layer->label(),
					'evidence' => $evidence,
					'tier'     => $layer->tier(),
				);
			}
		}

		$same = $visitor->status === $fresh->status && $this->fingerprint( $visitor ) === $this->fingerprint( $fresh );
		if ( $same ) {
			return $this->result( self::OK, $url, 'The cached copy matches a fresh one.', null, $served_by, $visitor, $fresh );
		}
		if ( $served_by ) {
			// The request passes the outermost layer first, so the highest tier is the one answering.
			usort( $served_by, static fn( $a, $b ) => $b['tier'] <=> $a['tier'] );
			$top = $served_by[0];
			return $this->result( self::STALE, $url, "{$top['label']} is serving an out-of-date copy. Purge it.", $top['layer'], $served_by, $visitor, $fresh );
		}
		return $this->result( self::DIFFERENT, $url, 'The page differs from a fresh copy, but no known cache layer claims it. It may be dynamic content, or an unrecognised cache.', null, $served_by, $visitor, $fresh );
	}

	private function bust( string $url ): string {
		return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'purgekit_nocache=' . $this->clock->now();
	}

	/** Ignores HTML comments (cache plugins stamp them) and whitespace differences. */
	private function fingerprint( HttpResponse $r ): string {
		$body = preg_replace( '/<!--.*?-->/s', '', $r->body ) ?? $r->body;
		return md5( trim( (string) preg_replace( '/\s+/', ' ', $body ) ) );
	}

	private function result( string $verdict, string $url, string $message, ?string $layer = null, array $served_by = array(), ?HttpResponse $visitor = null, ?HttpResponse $fresh = null ): array {
		return array(
			'verdict'   => $verdict,
			'url'       => $url,
			'message'   => $message,
			'layer'     => $layer,
			'served_by' => array_values( $served_by ),
			'visitor'   => $this->summary( $visitor ),
			'fresh'     => $this->summary( $fresh ),
		);
	}

	private function summary( ?HttpResponse $r ): array {
		if ( ! $r ) {
			return array();
		}
		$headers = array();
		foreach ( array_change_key_case( $r->headers, CASE_LOWER ) as $name => $value ) {
			if ( 1 === preg_match( self::HEADER_PATTERN, (string) $name ) ) {
				$headers[ $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}
		return array(
			'status'  => $r->status,
			'headers' => $headers,
		);
	}
}
