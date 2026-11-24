<?php
namespace PurgeKit\Warm;

use PurgeKit\Core\Http;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Store;

/** Visits purged pages so the next visitor gets a cached copy. Each batch is fetched concurrently. */
final class Warmer {
	private const KEY = 'warm_queue';

	/** @var callable|null */
	private $extra;

	public function __construct( private Http $http, private Store $store, private string $home_url, private int $concurrency = 5, ?callable $extra = null ) {
		$this->extra = $extra;
	}

	public function enqueue( PurgePlan $plan ): void {
		$urls = $plan->all ? array_merge( array( $this->home_url ), $this->extra ? (array) ( $this->extra )() : array() ) : $plan->urls;
		$this->store->set( self::KEY, array_values( array_unique( array_merge( $this->queue(), $urls ) ) ) );
	}

	public function pending(): int {
		return count( $this->queue() );
	}

	public function processBatch(): int {
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

	private function queue(): array {
		$q = $this->store->get( self::KEY, array() );
		return is_array( $q ) ? $q : array();
	}
}
