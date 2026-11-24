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
			if ( $existing['site_wide'] === $row['site_wide'] && $existing['urls'] === $row['urls'] && $existing['type'] === $row['type'] && ( $existing['context'] ?? array() ) == $row['context'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- array order is irrelevant here.
				return;
			}
		}
		$items[] = $row;
		$this->store->set( self::KEY, $items );
	}

	public function count(): int {
		return count( $this->load() );
	}

	/**
	 * @param bool $escalate Collapse everything into one site-wide change when any change is site-wide.
	 *                       Rules turn this off so they can still see what happened.
	 * @return Change[]
	 */
	public function drain( bool $escalate = true ): array {
		$changes = $this->peek( $escalate );
		$this->store->delete( self::KEY );
		return $changes;
	}

	/**
	 * Same result as drain() but leaves the queue untouched (used by the dry-run preview).
	 *
	 * @return Change[]
	 */
	public function peek( bool $escalate = true ): array {
		$items = $this->load();

		foreach ( $escalate ? $items : array() as $item ) {
			if ( $item['site_wide'] ) {
				return array( Change::siteWide( 'escalated' ) );
			}
		}
		return array_map( static fn( array $i ) => new Change( $i['type'], $i['urls'], (bool) $i['site_wide'], $i['context'] ), $items );
	}

	private function load(): array {
		$items = $this->store->get( self::KEY, array() );
		return is_array( $items ) ? $items : array();
	}
}
