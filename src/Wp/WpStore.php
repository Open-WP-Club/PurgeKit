<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Store;

/** Options-backed store; every key is prefixed so uninstall can find it. */
final class WpStore implements Store {
	public const PREFIX = 'purgekit_';

	public function get( string $key, mixed $fallback = null ): mixed {
		$raw = get_option( self::PREFIX . $key, null );
		return is_array( $raw ) && array_key_exists( 'v', $raw ) ? $raw['v'] : $fallback;
	}
	public function set( string $key, mixed $value ): void {
		// Wrapped, because update_option() silently ignores `false` for a missing option.
		update_option( self::PREFIX . $key, array( 'v' => $value ), false );
	}
	public function delete( string $key ): void {
		delete_option( self::PREFIX . $key );
	}
}
