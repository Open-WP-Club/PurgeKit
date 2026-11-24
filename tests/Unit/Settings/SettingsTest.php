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

	public function test_verification_is_off_by_default_and_can_be_enabled(): void {
		$s = new Settings( new FakeStore() );
		$this->assertFalse( $s->verifyAfterFlush() );
		$s->update( array( 'verify_after_flush' => true ) );
		$this->assertTrue( $s->all()['verify_after_flush'] );
	}

	public function test_warm_sitemap_limit_defaults_to_20_and_is_clamped(): void {
		$s = new Settings( new FakeStore() );
		$this->assertSame( 20, $s->warmSitemapLimit() );
		$s->update( array( 'warm_sitemap_limit' => '35' ) );
		$this->assertSame( 35, $s->warmSitemapLimit() );
		$s->update( array( 'warm_sitemap_limit' => 5000 ) );
		$this->assertSame( 100, $s->warmSitemapLimit() );
		$s->update( array( 'warm_sitemap_limit' => -4 ) );
		$this->assertSame( 0, $s->warmSitemapLimit() );
		$s->update( array( 'warm_sitemap_limit' => 'lots' ) );
		$this->assertSame( 0, $s->warmSitemapLimit(), 'Garbage is ignored and the last valid value stays.' );
		$this->assertSame( 0, $s->all()['warm_sitemap_limit'] );
	}

	public function test_reset_and_overridden_do_nothing_outside_multisite(): void {
		$store = new FakeStore();
		$s     = new Settings( $store );
		$s->update( array( 'mode' => 'auto' ) );
		$this->assertSame( array(), $s->overridden() );
		$s->reset( array( 'mode' ) );
		$this->assertSame( 'auto', $s->mode() );
	}

	public function test_exclusions_disabled_layers_and_the_staging_switch_are_validated(): void {
		$s = new Settings( new FakeStore() );
		$this->assertSame( array(), $s->excludePaths() );
		$this->assertFalse( $s->allowExternal() );

		$s->update(
			array(
				'exclude_paths'   => "/cart/*\n  /checkout/ \nnot-a-path\n\n/cart/*\n*.json",
				'disabled_layers' => array( 'cloudflare', 'Bad Id!', 5, 'cloudflare' ),
				'allow_external'  => true,
			)
		);
		$this->assertSame( array( '/cart/*', '/checkout/', '*.json' ), $s->excludePaths() );
		$this->assertSame( array( 'cloudflare' ), $s->disabledLayers() );
		$this->assertTrue( $s->allowExternal() );
		$this->assertSame( "/cart/*\n/checkout/\n*.json", $s->all()['exclude_paths'] );
	}

	public function test_exclusions_are_capped(): void {
		$s = new Settings( new FakeStore() );
		$s->update( array( 'exclude_paths' => implode( "\n", array_map( static fn( $i ) => "/p$i/", range( 1, 80 ) ) ) ) );
		$this->assertCount( 50, $s->excludePaths() );
	}

	public function test_home_host_is_remembered_lowercase(): void {
		$s = new Settings( new FakeStore() );
		$s->setHomeHost( 'Site.TEST' );
		$this->assertSame( 'site.test', $s->homeHost() );
	}
}
