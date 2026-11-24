<?php
namespace PurgeKit\Tests\Integration\Wp;

use PurgeKit\Wp\WpStore;

final class WpStoreTest extends \WP_UnitTestCase {
	public function test_false_and_zero_and_empty_values_round_trip(): void {
		$s = new WpStore();
		foreach ( array( 'f' => false, 'z' => 0, 'e' => '', 'a' => array(), 'n' => null ) as $key => $value ) {
			$s->set( $key, $value );
			$this->assertSame( $value, $s->get( $key, 'default' ), "key $key" );
		}
	}

	public function test_missing_key_returns_fallback_and_delete_works(): void {
		$s = new WpStore();
		$this->assertSame( 'd', $s->get( 'nope', 'd' ) );
		$s->set( 'k', 'v' );
		$s->delete( 'k' );
		$this->assertSame( 'd', $s->get( 'k', 'd' ) );
	}
}
