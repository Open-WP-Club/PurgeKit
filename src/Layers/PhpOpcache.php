<?php
namespace PurgeKit\Layers;

final class PhpOpcache implements Opcache {
	public function status(): ?array {
		if ( ! function_exists( 'opcache_get_status' ) ) {
			return null;
		}
		// restrict_api can make this raise a warning on some hosts; the answer is what matters.
		$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $status ) && ! empty( $status['opcache_enabled'] ) ? $status : null;
	}

	public function config(): array {
		$all = function_exists( 'ini_get_all' ) ? ini_get_all( 'opcache', false ) : array();
		$out = array();
		foreach ( is_array( $all ) ? $all : array() as $key => $value ) {
			$out[ (string) preg_replace( '/^opcache\./', '', (string) $key ) ] = $value;
		}
		return $out;
	}

	public function reset(): bool {
		return function_exists( 'opcache_reset' ) && @opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
