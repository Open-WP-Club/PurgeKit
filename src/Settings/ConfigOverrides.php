<?php
namespace PurgeKit\Settings;

use PurgeKit\Core\Store;
use PurgeKit\Layers\ProviderSpecs;

/**
 * Settings fixed in wp-config.php with `define( 'PURGEKIT_CONFIG', array( 'mode' => 'auto', ... ) )`.
 * A fixed setting always reads as the configured value and cannot be changed from the admin, so an agency
 * can roll out the same behaviour to many sites. Only plain settings can be fixed (see ALLOWED); secrets
 * keep their own `PURGEKIT_<NAME>` constants, and queued changes, history and the like are never touched.
 */
final class ConfigOverrides implements Store {
	/** @var array<string,mixed> */
	private array $config = array();

	/** @param array<mixed> $config */
	public function __construct( private Store $inner, array $config ) {
		$allowed = self::allowed();
		foreach ( $config as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, $allowed, true ) ) {
				// A list of paths is easier to write as an array in wp-config.php, but is stored as lines of text.
				$this->config[ $key ] = 'exclude_paths' === $key && is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : $value;
			}
		}
	}

	/** @return string[] */
	public static function allowed(): array {
		$provider_settings = array_keys( array_filter( ProviderSpecs::writableFields(), static fn( $kind ) => 'setting' === $kind ) );
		return array_merge( Settings::CONFIGURABLE, $provider_settings );
	}

	/** @return string[] The settings that wp-config.php fixes. */
	public function locked(): array {
		return array_keys( $this->config );
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		return array_key_exists( $key, $this->config ) ? $this->config[ $key ] : $this->inner->get( $key, $fallback );
	}

	public function set( string $key, mixed $value ): void {
		if ( ! array_key_exists( $key, $this->config ) ) {
			$this->inner->set( $key, $value );
		}
	}

	public function delete( string $key ): void {
		if ( ! array_key_exists( $key, $this->config ) ) {
			$this->inner->delete( $key );
		}
	}
}
