<?php
namespace PurgeKit\Network;

use PurgeKit\Core\Store;
use PurgeKit\Settings\Settings;

/** Network-wide default settings. A site that never saved its own value inherits these. */
final class NetworkDefaults {
	public const FLAGS = array( 'skip_if_unchanged', 'warm_enabled' );
	public const KEYS  = array( 'mode', 'schedule', 'skip_if_unchanged', 'warm_enabled' );

	public function __construct( private Store $store ) {}

	public function value( string $key, mixed $fallback = null ): mixed {
		return in_array( $key, self::KEYS, true ) ? $this->store->get( $key, $fallback ) : $fallback;
	}

	/** @return array<string, mixed> Only the keys that are set. */
	public function all(): array {
		$sentinel = new \stdClass();
		$out      = array();
		foreach ( self::KEYS as $key ) {
			$v = $this->store->get( $key, $sentinel );
			if ( $v !== $sentinel ) {
				$out[ $key ] = $v;
			}
		}
		return $out;
	}

	/** Unknown keys and invalid values are ignored, like Settings::update(). */
	public function update( array $patch ): void {
		if ( isset( $patch['mode'] ) && in_array( $patch['mode'], Settings::MODES, true ) ) {
			$this->store->set( 'mode', $patch['mode'] );
		}
		if ( isset( $patch['schedule'] ) && in_array( $patch['schedule'], Settings::SCHEDULES, true ) ) {
			$this->store->set( 'schedule', $patch['schedule'] );
		}
		foreach ( self::FLAGS as $flag ) {
			if ( isset( $patch[ $flag ] ) ) {
				$this->store->set( $flag, (bool) $patch[ $flag ] );
			}
		}
	}

	/** Removes defaults, so sites without their own value fall back to the built-in default again. */
	public function clear( array $keys ): void {
		foreach ( array_intersect( $keys, self::KEYS ) as $key ) {
			$this->store->delete( $key );
		}
	}
}
