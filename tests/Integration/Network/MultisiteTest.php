<?php
namespace PurgeKit\Tests\Integration\Network;

use PurgeKit\Network\NetworkDefaults;
use PurgeKit\Network\SiteRunner;
use PurgeKit\Services;
use PurgeKit\Wp\NetworkStore;

/** Run with PURGEKIT_MULTISITE=1 (see bin/test-local.sh full); skipped on single site. */
final class MultisiteTest extends \WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
	}

	public function test_sites_inherit_network_defaults_and_can_override(): void {
		( new NetworkDefaults( new NetworkStore() ) )->update( array( 'mode' => 'auto' ) );
		$blog = self::factory()->blog->create();

		switch_to_blog( $blog );
		$s = Services::build();
		$this->assertSame( 'auto', $s->settings->mode() );
		$s->settings->update( array( 'mode' => 'scheduled' ) );
		$this->assertSame( 'scheduled', Services::build()->settings->mode() );
		restore_current_blog();

		$this->assertSame( 'auto', Services::build()->settings->mode(), 'The main site is unaffected by the override.' );
	}

	public function test_secrets_are_not_inherited(): void {
		$s = Services::build();
		$s->secrets->set( 'CLOUDFLARE_TOKEN', 'main-token' );
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		$this->assertFalse( Services::build()->secrets->has( 'CLOUDFLARE_TOKEN' ) );
		restore_current_blog();
	}

	public function test_flush_all_visits_each_site_where_the_plugin_is_active(): void {
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		Services::build()->history->install();
		update_option( 'active_plugins', array( plugin_basename( PURGEKIT_FILE ) ) );
		restore_current_blog();

		$rows = ( new SiteRunner() )->flushAll( 'test' );
		$urls = array_column( $rows, 'url' );
		$this->assertContains( get_home_url( $blog, '/' ), $urls );
		foreach ( $rows as $row ) {
			$this->assertContains( $row['status'], array( 'ok', 'partial', 'failed', 'skipped', 'locked' ) );
		}
	}

	public function test_flush_all_leaves_the_current_blog_unchanged(): void {
		$before = get_current_blog_id();
		( new SiteRunner() )->flushAll( 'test' );
		$this->assertSame( $before, get_current_blog_id() );
	}

	public function test_a_site_can_reset_an_override_and_inherits_again(): void {
		( new NetworkDefaults( new NetworkStore() ) )->update( array( 'mode' => 'auto' ) );
		$blog = self::factory()->blog->create();
		switch_to_blog( $blog );
		try {
			$s = Services::build();
			$s->settings->update( array( 'mode' => 'scheduled', 'cloudflare_zone' => 'z' ) );
			$this->assertSame( array( 'mode' ), Services::build()->settings->overridden() );
			$s->settings->reset( array( 'mode', 'cloudflare_zone' ) );
			$s = Services::build();
			$this->assertSame( 'auto', $s->settings->mode() );
			$this->assertSame( 'z', $s->settings->all()['cloudflare_zone'], 'Non-inheritable settings are not reset.' );
			$this->assertSame( array(), $s->settings->overridden() );
		} finally {
			restore_current_blog();
		}
	}
}
