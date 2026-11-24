<?php
namespace PurgeKit\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use PurgeKit\Settings\Settings;
use PurgeKit\Tests\Fakes\FakeStore;

final class SettingsTest extends TestCase {
	public function test_defaults(): void {
		$s = new Settings( new FakeStore() );
		$this->assertSame( 'notify', $s->mode() );
		$this->assertSame( 'daily', $s->schedule() );
		$this->assertTrue( $s->skipIfUnchanged() );
		$this->assertTrue( $s->warmEnabled() );
	}

	public function test_invalid_mode_falls_back_to_notify(): void {
		$store = new FakeStore();
		$store->set( 'mode', 'banana' );
		$this->assertSame( 'notify', ( new Settings( $store ) )->mode() );
	}

	public function test_update_validates_and_ignores_unknown_keys(): void {
		$store = new FakeStore();
		$s     = new Settings( $store );
		$s->update( array( 'mode' => 'auto', 'schedule' => 'nonsense', 'evil' => 'x', 'varnish_enabled' => true, 'cloudflare_zone' => ' abc123 ' ) );
		$this->assertSame( 'auto', $s->mode() );
		$this->assertSame( 'daily', $s->schedule() );
		$this->assertArrayNotHasKey( 'evil', $store->data );
		$this->assertSame( 'abc123', $s->all()['cloudflare_zone'] );
		$this->assertTrue( $s->all()['varnish_enabled'] );
	}

	public function test_all_never_includes_secrets(): void {
		$store = new FakeStore();
		$store->set( 'secret_CLOUDFLARE_TOKEN', 'zzz' );
		$this->assertStringNotContainsString( 'zzz', json_encode( ( new Settings( $store ) )->all() ) );
	}
}
