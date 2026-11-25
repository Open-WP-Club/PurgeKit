<?php
namespace PurgeKit\Core;

/**
 * The saved history of the live cache figures: per layer, per figure, a short list of [time, value].
 * Pure; the caller keeps it wherever it likes.
 *
 * @phpstan-type Series array<string,array{at:int,series:array<string,array<int,array{0:int,1:float}>>}>
 */
final class MetricSeries {
	public const MIN_GAP = 1500;  // Seconds between two samples of a layer (a little under the half hour).
	public const MAX     = 336;   // Two weeks of hourly samples.

	/**
	 * The figures worth drawing: a percent (memory, hit rate) or a count (keys, entries), keyed by their label.
	 *
	 * @param array<int,array<string,mixed>> $metrics
	 * @return array<string,float>
	 */
	public static function values( array $metrics ): array {
		$out = array();
		foreach ( $metrics as $m ) {
			$label = (string) ( $m['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			if ( isset( $m['percent'] ) && is_numeric( $m['percent'] ) ) {
				$out[ $label ] = round( (float) $m['percent'], 1 );
			} elseif ( isset( $m['number'] ) && is_numeric( $m['number'] ) ) {
				$out[ $label ] = (float) $m['number'];
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed>  $all    What was saved before.
	 * @param array<string,float>  $values Figures of one layer now.
	 * @return array{0:array<string,mixed>,1:bool} The new whole, and whether a sample was added.
	 */
	public static function record( array $all, string $layer, array $values, int $now ): array {
		if ( array() === $values ) {
			return array( $all, false );
		}
		$entry = $all[ $layer ] ?? array(
			'at'     => 0,
			'series' => array(),
		);
		if ( $now - (int) $entry['at'] < self::MIN_GAP ) {
			return array( $all, false );
		}
		foreach ( $values as $label => $value ) {
			$points                    = (array) ( $entry['series'][ $label ] ?? array() );
			$points[]                  = array( $now, $value );
			$entry['series'][ $label ] = array_slice( $points, -self::MAX );
		}
		$entry['at']   = $now;
		$all[ $layer ] = $entry;
		return array( $all, true );
	}

	/**
	 * Drops what belongs to layers that no longer exist.
	 *
	 * @param array<string,mixed> $all
	 * @param string[]            $known
	 * @return array<string,mixed>
	 */
	public static function keep( array $all, array $known ): array {
		return array_intersect_key( $all, array_flip( $known ) );
	}
}
