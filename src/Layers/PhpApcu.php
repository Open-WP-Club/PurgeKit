<?php
namespace PurgeKit\Layers;

final class PhpApcu implements Apcu {
	public function status(): ?array {
		if ( ! function_exists( 'apcu_cache_info' ) || ( function_exists( 'apcu_enabled' ) && ! apcu_enabled() ) ) {
			return null;
		}
		$info = @apcu_cache_info( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $info ) ) {
			return null;
		}
		$sma   = function_exists( 'apcu_sma_info' ) ? @apcu_sma_info( true ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$total = is_array( $sma ) ? (int) ( $sma['num_seg'] ?? 1 ) * (int) ( $sma['seg_size'] ?? 0 ) : 0;
		$free  = is_array( $sma ) ? (int) ( $sma['avail_mem'] ?? 0 ) : 0;
		return array(
			'entries'   => (int) ( $info['num_entries'] ?? 0 ),
			'hits'      => (int) ( $info['num_hits'] ?? 0 ),
			'misses'    => (int) ( $info['num_misses'] ?? 0 ),
			'mem_used'  => max( 0, $total - $free ),
			'mem_total' => $total,
		);
	}

	public function clear(): bool {
		return function_exists( 'apcu_clear_cache' ) && apcu_clear_cache();
	}
}
