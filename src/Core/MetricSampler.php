<?php
namespace PurgeKit\Core;

use PurgeKit\Layers\Metrics;
use PurgeKit\Layers\Registry;

/** Takes a sample of the live figures of every cache that has them and keeps the history in the store. */
final class MetricSampler {
	private const KEY = 'metric_series';

	public function __construct( private Registry $registry, private Store $store ) {}

	/** Adds a sample for each layer that is due, and says how many were taken. */
	public function sample( int $now ): int {
		$all   = (array) $this->store->get( self::KEY, array() );
		$taken = 0;
		foreach ( $this->registry->active() as $layer ) {
			if ( ! $layer instanceof Metrics ) {
				continue;
			}
			try {
				$values = MetricSeries::values( $layer->metrics() );
			} catch ( \Throwable $e ) {
				continue; // A cache that cannot be read now is simply missing from the history.
			}
			list( $all, $added ) = MetricSeries::record( $all, $layer->id(), $values, $now );
			$taken              += $added ? 1 : 0;
		}
		if ( $taken > 0 ) {
			$known = array_map( static fn( $l ) => $l->id(), $this->registry->all() );
			$this->store->set( self::KEY, MetricSeries::keep( $all, $known ) );
		}
		return $taken;
	}

	/** @return array<string,array<string,array<int,array{0:int,1:float}>>> Layer => figure => points. */
	public function series(): array {
		$out = array();
		foreach ( (array) $this->store->get( self::KEY, array() ) as $layer => $entry ) {
			$out[ (string) $layer ] = (array) ( $entry['series'] ?? array() );
		}
		return $out;
	}
}
