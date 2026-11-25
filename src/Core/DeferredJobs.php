<?php
namespace PurgeKit\Core;

/** Rule jobs that wait a while before they run (for example the CDN, a minute after the cache plugin). */
final class DeferredJobs {
	public const KEY = 'deferred_jobs';
	public const MAX = 50;

	public function __construct( private Store $store ) {}

	/** @return array<int,array{id:string,at:int,all:bool,urls:string[],tags:string[],layers:?array,rules:string[]}> */
	public function all(): array {
		$items = $this->store->get( self::KEY, array() );
		return is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : array();
	}

	/**
	 * @param string[]      $rules
	 * @param string[]|null $layers
	 * @return array{id:string,at:int,all:bool,urls:string[],tags:string[],layers:?array,rules:string[]}|null Null when the list is full.
	 */
	public function add( int $at, PurgePlan $plan, ?array $layers, array $rules ): ?array {
		$items = $this->all();
		if ( count( $items ) >= self::MAX ) {
			return null;
		}
		$item    = array(
			'id'     => bin2hex( random_bytes( 4 ) ),
			'at'     => $at,
			'all'    => $plan->all,
			'urls'   => array_slice( array_map( 'strval', $plan->urls ), 0, 500 ),
			'tags'   => array_slice( array_map( 'strval', $plan->tags ), 0, 100 ),
			'layers' => $layers,
			'rules'  => $rules,
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

	/** Removes a job and returns it, or null when it is not there any more. */
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
