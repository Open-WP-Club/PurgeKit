<?php
namespace PurgeKit\Network;

use PurgeKit\Core\Store;

/** Reads fall back to the network defaults when the site has no value of its own. Writes always go to the site. */
final class InheritingStore implements Store {
	public function __construct( private Store $site, private NetworkDefaults $defaults ) {}

	public function get( string $key, mixed $fallback = null ): mixed {
		$sentinel = new \stdClass();
		$own      = $this->site->get( $key, $sentinel );
		if ( $own !== $sentinel ) {
			return $own;
		}
		return $this->defaults->value( $key, $fallback );
	}

	public function set( string $key, mixed $value ): void {
		$this->site->set( $key, $value );
	}

	public function delete( string $key ): void {
		$this->site->delete( $key );
	}

	/**
	 * Inheritable keys the site has its own value for.
	 *
	 * @return string[]
	 */
	public function overriddenKeys(): array {
		$sentinel = new \stdClass();
		return array_values( array_filter( NetworkDefaults::KEYS, fn( string $key ) => $this->site->get( $key, $sentinel ) !== $sentinel ) );
	}

	/**
	 * Deletes the site's own value so the network default applies again. Anything that is not an
	 * inheritable key is ignored, so this can never remove other settings or secrets.
	 *
	 * @param string[] $keys
	 */
	public function reset( array $keys ): void {
		foreach ( array_intersect( NetworkDefaults::KEYS, $keys ) as $key ) {
			$this->site->delete( $key );
		}
	}
}
