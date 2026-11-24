<?php
namespace PurgeKit\Core;

// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- runs without WordPress in unit tests.
/** One-off purges planned for a given time (for example a campaign that goes live at 09:00). */
final class ScheduledPurges {
	public const KEY       = 'scheduled_purges';
	public const MAX       = 20;
	public const MAX_URLS  = 50;
	public const MAX_AHEAD = 366 * 86400;

	private string $host;

	public function __construct( private Store $store, string $home_url ) {
		$this->host = strtolower( (string) parse_url( $home_url, PHP_URL_HOST ) );
	}

	/** @return array<int,array{id:string,at:int,urls:string[],note:string}> Soonest first. */
	public function all(): array {
		$items = $this->store->get( self::KEY, array() );
		$items = is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : array();
		usort( $items, static fn( $a, $b ) => (int) $a['at'] <=> (int) $b['at'] );
		return $items;
	}

	/**
	 * @param string[] $urls Own-host URLs to purge; empty means purge everything.
	 * @return array{id:string,at:int,urls:string[],note:string}|null Null when the request is not acceptable.
	 */
	public function add( int $at, array $urls, string $note, int $now ): ?array {
		$items = $this->all();
		if ( $at <= $now || $at > $now + self::MAX_AHEAD || count( $items ) >= self::MAX ) {
			return null;
		}
		$clean = array();
		foreach ( $urls as $url ) {
			$parts = is_string( $url ) ? parse_url( trim( $url ) ) : false;
			if ( is_array( $parts ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ! isset( $parts['user'] ) && strtolower( $parts['host'] ?? '' ) === $this->host ) {
				$clean[ trim( $url ) ] = true;
			}
		}
		if ( array() !== $urls && array() === $clean ) {
			return null; // The caller gave URLs but none were usable; do not silently turn that into "purge everything".
		}
		$item    = array(
			'id'   => bin2hex( random_bytes( 4 ) ),
			'at'   => $at,
			'urls' => array_slice( array_keys( $clean ), 0, self::MAX_URLS ),
			'note' => mb_substr( trim( $note ), 0, 100 ),
		);
		$items[] = $item;
		$this->store->set( self::KEY, $items );
		return $item;
	}

	public function find( string $id ): ?array {
		foreach ( $this->all() as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	/** Removes an entry and returns it, or null when it is not there (already run or cancelled). */
	public function take( string $id ): ?array {
		$items = $this->all();
		foreach ( $items as $i => $item ) {
			if ( $item['id'] === $id ) {
				unset( $items[ $i ] );
				$this->store->set( self::KEY, array_values( $items ) );
				return $item;
			}
		}
		return null;
	}
}
