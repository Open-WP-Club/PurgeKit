<?php
namespace PurgeKit\Core;

final class Queue {
	private const KEY = 'queue';

	public function __construct( private Store $store ) {}

	public function add( Change $change ): void {
		$items = $this->load();
		$row   = array(
			'type'      => $change->type,
			'urls'      => $change->urls,
			'site_wide' => $change->site_wide,
			'context'   => $change->context,
		);
		foreach ( $items as $existing ) {
			if ( $existing['site_wide'] === $row['site_wide'] && $existing['urls'] === $row['urls'] && $existing['type'] === $row['type'] ) {
				return;
			}
		}
		$items[] = $row;
		$this->store->set( self::KEY, $items );
	}

	public function count(): int {
		return count( $this->load() );
	}

	/** @return Change[] */
	public function drain(): array {
		$items = $this->load();
		$this->store->delete( self::KEY );

		foreach ( $items as $item ) {
			if ( $item['site_wide'] ) {
				return array( Change::siteWide( 'escalated' ) );
			}
		}
		return array_map( static fn( array $i ) => new Change( $i['type'], $i['urls'], false, $i['context'] ), $items );
	}

	private function load(): array {
		$items = $this->store->get( self::KEY, array() );
		return is_array( $items ) ? $items : array();
	}
}
