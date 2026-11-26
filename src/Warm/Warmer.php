<?php
namespace PurgeKit\Warm;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpResponse;
use PurgeKit\Core\Clock;
use PurgeKit\Core\Lock;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Store;

/** Visits purged pages so the next visitor gets a cached copy. Each batch is fetched concurrently. */
// Plain PHP URL checks also run in unit tests without WordPress.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url
final class Warmer {
	private const KEY         = 'warm_queue';
	private const SITEMAP_KEY = 'warm_sitemap_limit';

	/** @var callable|null */
	private $extra;

	public function __construct( private Http $http, private Store $store, private string $home_url, private int $concurrency = 5, ?callable $extra = null, private ?SitemapReader $sitemap = null, private ?Clock $clock = null, private ?Lock $lock = null ) {
		$this->extra = $extra;
	}

	/**
	 * @param int $sitemap_limit For a purge-everything plan, also warm up to this many sitemap URLs.
	 *                           The sitemap is read later, in processBatch(), so a flush never waits for it.
	 */
	public function enqueue( PurgePlan $plan, int $sitemap_limit = 0 ): void {
		if ( 0 === $this->pending() ) {
			$this->store->set(
				'warm_progress',
				array(
					'successful' => 0,
					'failed'     => 0,
					'retries'    => 0,
				)
			);
		}
		$urls     = $plan->all ? array_merge( array( $this->home_url ), $this->extra ? (array) ( $this->extra )() : array() ) : $plan->urls;
		$priority = (array) $this->store->get( 'warm_priority_urls', array() );
		if ( $plan->all ) {
			$urls = array_merge( $priority, $urls );
		}
		$urls = array_merge( array_values( array_intersect( $priority, $urls ) ), $this->queue(), $urls );
		$urls = array_filter( $urls, fn( $url ) => $this->own( $url ) );
		$this->store->set( self::KEY, array_values( array_unique( $urls ) ) );
		if ( $plan->all && $sitemap_limit > 0 && $this->sitemap ) {
			$this->store->set( self::SITEMAP_KEY, min( SitemapReader::MAX_LIMIT, $sitemap_limit ) );
		}
	}

	public function pending(): int {
		return count( $this->queue() ) + ( $this->sitemapLimit() > 0 ? 1 : 0 );
	}

	public function processBatch(): int {
		if ( $this->lock && ! $this->lock->acquire( 'warm', 120 ) ) {
			return 0;
		}
		try {
			return $this->work();
		} finally {
			$this->lock?->release( 'warm' );
		}
	}

	private function now(): int {
		return $this->clock ? (int) floor( $this->clock->now() / 1000 ) : time();
	}

	/** Progress of the current warming cycle. Failed means exhausted or a permanent HTTP error. */
	public function progress(): array {
		return array(
			'pending' => $this->pending(),
			'next_at' => $this->nextAt(),
		) + (array) $this->store->get(
			'warm_progress',
			array(
				'successful' => 0,
				'failed'     => 0,
				'retries'    => 0,
			)
		);
	}

	public function nextAt(): int {
		$now   = $this->now();
		$retry = (array) $this->store->get( 'warm_retry', array() );
		$times = array_map( static fn( $url ) => (int) ( $retry[ $url ]['at'] ?? $now ), $this->queue() );
		return max( $now + 5, (int) $this->store->get( 'warm_pause_until', 0 ), $times ? min( $times ) : 0 );
	}

	private function work(): int {
		$now = $this->now();
		if ( $now < (int) $this->store->get( 'warm_pause_until', 0 ) ) {
			return 0;
		}
		$this->expandSitemap();
		$queue = $this->queue();
		$retry = (array) $this->store->get( 'warm_retry', array() );
		$batch = array();
		foreach ( $queue as $url ) {
			if ( count( $batch ) < max( 1, $this->concurrency ) && (int) ( $retry[ $url ]['at'] ?? 0 ) <= $now ) {
				$batch[] = $url;
			}
		}
		if ( ! $batch ) {
			return 0;
		}
		try {
			$results = $this->http->requestMany(
				'GET',
				$batch,
				array(
					'headers'     => array( 'X-PurgeKit-Warm' => '1' ),
					'timeout'     => 10,
					'redirection' => 0,
				)
			);
		} catch ( \Throwable $e ) {
			$results = array_fill( 0, count( $batch ), $e );
		}
		$progress = (array) $this->store->get(
			'warm_progress',
			array(
				'successful' => 0,
				'failed'     => 0,
				'retries'    => 0,
			)
		);
		$done     = array();
		foreach ( $batch as $i => $url ) {
			$r         = $results[ $i ] ?? null;
			$attempt   = (int) ( $retry[ $url ]['attempt'] ?? 0 ) + 1;
			$ok        = $r instanceof HttpResponse && $r->status >= 200 && $r->status < 300;
			$transient = ! $r instanceof HttpResponse || in_array( $r->status, array( 408, 429 ), true ) || $r->status >= 500;
			$delay     = 15 * ( 2 ** ( $attempt - 1 ) );
			if ( $r instanceof HttpResponse && in_array( $r->status, array( 429, 503 ), true ) ) {
				$header = array_change_key_case( $r->headers, CASE_LOWER )['retry-after'] ?? '';
				$header = is_scalar( $header ) ? (string) $header : '';
				$wait   = ctype_digit( $header ) ? (int) $header : max( 0, (int) strtotime( $header ) - $now );
				$delay  = max( $delay, min( 3600, $wait ) );
				$this->store->set( 'warm_pause_until', max( (int) $this->store->get( 'warm_pause_until', 0 ), $now + $delay ) );
			}
			if ( ! $ok && $transient && $attempt < 4 ) {
				$retry[ $url ] = array(
					'attempt' => $attempt,
					'at'      => $now + $delay,
				);
				++$progress['retries'];
			} else {
				$done[] = $url;
				unset( $retry[ $url ] );
				++$progress[ $ok ? 'successful' : 'failed' ];
			}
		}
		// Re-read so URLs enqueued while the HTTP requests ran are retained.
		$this->store->set( self::KEY, array_values( array_diff( $this->queue(), $done ) ) );
		$this->store->set( 'warm_retry', $retry );
		$this->store->set( 'warm_progress', $progress );
		return count( $batch );
	}

	/** Turns the pending sitemap request into queued URLs (once), after the URLs that were queued first. */
	private function expandSitemap(): void {
		$limit = $this->sitemapLimit();
		if ( $limit <= 0 ) {
			return;
		}
		$this->store->delete( self::SITEMAP_KEY );
		if ( ! $this->sitemap ) {
			return;
		}
		try {
			$urls = $this->sitemap->urls( $limit );
		} catch ( \Throwable $e ) {
			return; // Warming is best effort.
		}
		$this->store->set( self::KEY, array_values( array_unique( array_merge( $this->queue(), $urls ) ) ) );
	}

	private function own( mixed $url ): bool {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$p    = parse_url( $url );
		$home = parse_url( $this->home_url );
		return is_array( $p ) && is_array( $home ) && in_array( $p['scheme'] ?? '', array( 'https', 'http' ), true )
			&& ! isset( $p['user'] ) && ! isset( $p['pass'] )
			&& strtolower( $p['host'] ?? '' ) === strtolower( $home['host'] ?? '' )
			&& ( $p['port'] ?? null ) === ( $home['port'] ?? null );
	}

	private function sitemapLimit(): int {
		$v = $this->store->get( self::SITEMAP_KEY, 0 );
		return is_int( $v ) ? $v : 0;
	}

	private function queue(): array {
		$q = $this->store->get( self::KEY, array() );
		return is_array( $q ) ? $q : array();
	}
}
