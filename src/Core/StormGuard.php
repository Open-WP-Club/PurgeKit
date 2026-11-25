<?php
namespace PurgeKit\Core;

/**
 * Notices a flush storm: many automatic full flushes in a short time (a cron or a plugin flushing in a loop).
 * It only keeps a short log and a "tripped" flag; what to do about it is up to the caller.
 */
final class StormGuard {
	public const LOG  = 'storm_log';
	public const TRIP = 'storm_trip';

	/** Sources started by a person on purpose never count. */
	private const HUMAN = array( 'manual', 'admin', 'admin-layer', 'retry', 'delayed' );

	public function __construct( private Store $store, private int $limit = 5, private int $window = 600, private int $ttl = 3600 ) {}

	/** Records a finished run. Returns true when this run is the one that tripped the guard. */
	public function record( string $source, Run $run, int $now ): bool {
		if ( in_array( $source, self::HUMAN, true ) || ! $run->plan || ! $run->plan->all ) {
			return false;
		}
		if ( ! in_array( $run->status, array( Run::OK, Run::PARTIAL, Run::FAILED ), true ) ) {
			return false;
		}
		$log   = array_values( array_filter( (array) $this->store->get( self::LOG, array() ), static fn( $t ) => is_int( $t ) ) );
		$log   = array_values( array_filter( $log, fn( int $t ) => $t > $now - $this->window ) );
		$log[] = $now;
		$this->store->set( self::LOG, array_slice( $log, -50 ) );
		if ( count( $log ) >= $this->limit && null === $this->tripped( $now ) ) {
			$this->store->set(
				self::TRIP,
				array(
					'at'     => $now,
					'count'  => count( $log ),
					'window' => $this->window,
					'source' => $source,
				)
			);
			return true;
		}
		return false;
	}

	/** @return array{at:int,count:int,window:int,source:string}|null */
	public function tripped( int $now ): ?array {
		$trip = $this->store->get( self::TRIP );
		if ( ! is_array( $trip ) || ! isset( $trip['at'] ) ) {
			return null;
		}
		if ( $now - (int) $trip['at'] > $this->ttl ) {
			$this->reset();
			return null;
		}
		return array(
			'at'     => (int) $trip['at'],
			'count'  => (int) ( $trip['count'] ?? 0 ),
			'window' => (int) ( $trip['window'] ?? $this->window ),
			'source' => (string) ( $trip['source'] ?? '' ),
		);
	}

	public function reset(): void {
		$this->store->delete( self::TRIP );
		$this->store->delete( self::LOG );
	}
}
