<?php
namespace PurgeKit\Core;

/** A quiet window with a hard deadline so continuous writes cannot postpone purging forever. */
final class BatchWindow {
	public function __construct( private Store $store ) {}

	public function due( int $now, int $quiet, int $maximum ): int {
		$first = (int) $this->store->get( 'queue_first_at', 0 );
		$last  = (int) $this->store->get( 'queue_last_at', 0 );
		if ( $first <= 0 || $last <= 0 ) {
			return $now; // Queued changes from before batching was introduced must not wait forever.
		}
		return min( $last + max( 0, $quiet ), $first + max( $quiet, $maximum ) );
	}
}
