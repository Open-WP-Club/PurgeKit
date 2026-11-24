<?php
namespace PurgeKit\Wp;

use PurgeKit\Core\Store;

/** One network (site) option holding all network-level values. */
final class NetworkStore implements Store {
	public const OPTION = 'purgekit_network';

	public function get( string $key, mixed $fallback = null ): mixed {
		$all = get_site_option( self::OPTION, array() );
		return is_array( $all ) && array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public function set( string $key, mixed $value ): void {
		$all = get_site_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $key ] = $value;
		update_site_option( self::OPTION, $all );
	}

	public function delete( string $key ): void {
		$all = get_site_option( self::OPTION, array() );
		if ( is_array( $all ) ) {
			unset( $all[ $key ] );
			update_site_option( self::OPTION, $all );
		}
	}
}
