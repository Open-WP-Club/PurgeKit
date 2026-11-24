<?php
namespace PurgeKit\Tests\Unit\Secrets;

use PHPUnit\Framework\TestCase;
use PurgeKit\Secrets\Secrets;
use PurgeKit\Tests\Fakes\FakeStore;

final class SecretsTest extends TestCase {
	public function test_stored_secret_round_trips_and_is_not_plaintext_in_store(): void {
		$store = new FakeStore();
		$s     = new Secrets( $store, 'salt-material' );
		$s->set( 'CLOUDFLARE_TOKEN', 'tok_123' );
		$this->assertSame( 'tok_123', $s->get( 'CLOUDFLARE_TOKEN' ) );
		$this->assertTrue( $s->has( 'CLOUDFLARE_TOKEN' ) );
		$this->assertStringNotContainsString( 'tok_123', json_encode( $store->data ) );
	}

	public function test_secret_constant_beats_stored(): void {
		define( 'PURGEKIT_TEST_ONLY_SECRET', 'from-constant' );
		$s = new Secrets( new FakeStore(), 'salt' );
		$s->set( 'TEST_ONLY_SECRET', 'from-store' );
		$this->assertSame( 'from-constant', $s->get( 'TEST_ONLY_SECRET' ) );
	}

	public function test_missing_secret_is_null_and_not_has(): void {
		$s = new Secrets( new FakeStore(), 'salt' );
		$this->assertNull( $s->get( 'NOPE' ) );
		$this->assertFalse( $s->has( 'NOPE' ) );
	}

	public function test_wrong_key_material_cannot_decrypt(): void {
		$store = new FakeStore();
		( new Secrets( $store, 'one' ) )->set( 'X', 'secret' );
		$this->assertNull( ( new Secrets( $store, 'two' ) )->get( 'X' ) );
	}
}
