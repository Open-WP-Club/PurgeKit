<?php
namespace PurgeKit\Core;

final class RuleStore {
	private const KEY = 'rules';

	public function __construct( private Store $store ) {}

	public function load(): RuleSet {
		return RuleSet::fromArray( $this->store->get( self::KEY, array() ) );
	}

	public function save( RuleSet $rules ): void {
		$this->store->set( self::KEY, $rules->toArray() );
	}
}
