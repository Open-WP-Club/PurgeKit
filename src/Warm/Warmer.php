<?php
namespace PurgeKit\Warm;

use PurgeKit\Core\Http;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Store;

/** Visits purged pages so the next visitor gets a cached copy. Each batch is fetched concurrently. */
final class Warmer {
	private const KEY         = 'warm_queue';
	private const SITEMAP_KEY = 'warm_sitemap_limit';

	/** @var callable|null */
	private $extra;

	public function __construct( private Http $http, private Store $store, private string $home_url, private int $concurrency = 5, ?callable $extra = null, private ?SitemapReader $sitemap = null ) {
		$this->extra = $extra;
	}

	/**
	 * @param int $sitemap_limit For a purge-everything plan, also warm up to this many sitemap URLs.
	 *                           The sitemap is read later, in processBatch(), so a flush never waits for it.
	 */
	public function enqueue( PurgePlan $plan, int $sitemap_limit = 0 ): void {
		$urls = $plan->all ? array_merge( array( $this->home_url ), $this->extra ? (array) ( $this->extra )() : array() ) : $plan->urls;
		$this->store->set( self::KEY, array_values( array_unique( array_merge( $this->queue(), $urls ) ) ) );
		if ( $plan->all && $sitemap_limit > 0 && $this->sitemap ) {
			$this->store->set( self::SITEMAP_KEY, min( SitemapReader::MAX_LIMIT, $sitemap_limit ) );
		}
	}

	public function pending(): int {
		return count( $this->queue() ) + ( $this->sitemapLimit() > 0 ? 1 : 0 );
	}

	public function processBatch(): int {
		$this->expandSitemap();
		$queue = $this->queue();
		$batch = array_splice( $queue, 0, $this->concurrency );
		$this->store->set( self::KEY, $queue );
		if ( $batch ) {
			// One concurrent round; failures are returned and deliberately dropped, not retried.
			$this->http->requestMany(
				'GET',
				$batch,
				array(
					'headers' => array( 'X-PurgeKit-Warm' => '1' ),
					'timeout' => 10,
				)
			);
		}
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

	private function sitemapLimit(): int {
		$v = $this->store->get( self::SITEMAP_KEY, 0 );
		return is_int( $v ) ? $v : 0;
	}

	private function queue(): array {
		$q = $this->store->get( self::KEY, array() );
		return is_array( $q ) ? $q : array();
	}
}
