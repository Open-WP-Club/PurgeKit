<?php
namespace PurgeKit\Core;

/**
 * Counts, per layer, how many real purges in a row failed. A skipped result says nothing, so it keeps the
 * count as it was; a success starts it over. Used to warn once when one layer keeps failing.
 */
final class FailureStreaks {
	public const THRESHOLD = 3;

	/**
	 * @param array<string,int>     $streaks Layer id => failures in a row so far.
	 * @param LayerResult[]         $results
	 * @return array{streaks:array<string,int>,crossed:array<string,array{count:int,message:string}>} The new counts, and the layers that just reached the threshold.
	 */
	public static function advance( array $streaks, array $results, int $threshold = self::THRESHOLD ): array {
		$crossed = array();
		foreach ( $results as $r ) {
			if ( LayerResult::FAILED === $r->status ) {
				$streaks[ $r->layer ] = ( $streaks[ $r->layer ] ?? 0 ) + 1;
				if ( $threshold === $streaks[ $r->layer ] ) {
					$crossed[ $r->layer ] = array(
						'count'   => $threshold,
						'message' => $r->message,
					);
				}
			} elseif ( LayerResult::OK === $r->status ) {
				unset( $streaks[ $r->layer ] );
			}
		}
		return array(
			'streaks' => $streaks,
			'crossed' => $crossed,
		);
	}
}
