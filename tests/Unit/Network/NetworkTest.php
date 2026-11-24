<?php
namespace PurgeKit\Tests\Unit\Network;

use PHPUnit\Framework\TestCase;
use PurgeKit\Network\InheritingStore;
use PurgeKit\Network\NetworkDefaults;
use PurgeKit\Settings\Settings;
use PurgeKit\Tests\Fakes\FakeStore;

final class NetworkTest extends TestCase {
	private function build(): array {
		$site     = new FakeStore();
		$defaults = new NetworkDefaults( new FakeStore() );
		return array( $site, $defaults, new InheritingStore( $site, $defaults ) );
	}

	public function test_defaults_validate_and_ignore_unknown_keys(): void {
		$d = new NetworkDefaults( new FakeStore() );
		$d->update(
			array(
				'mode'              => 'bogus',
				'schedule'          => '6h',
				'skip_if_unchanged' => false,
				'cloudflare_zone'   => 'z',
			)
		);
		$this->assertSame(
			array(
				'schedule'          => '6h',
				'skip_if_unchanged' => false,
			),
			$d->all()
		);
		$this->assertNull( $d->value( 'cloudflare_zone' ) );
	}

	public function test_site_inherits_network_default_until_it_saves_its_own(): void {
		list( $site, $defaults, $store ) = $this->build();
		$defaults->update( array( 'mode' => 'auto' ) );
		$settings = new Settings( $store );
		$this->assertSame( 'auto', $settings->mode() );
		$settings->update( array( 'mode' => 'scheduled' ) );
		$this->assertSame( 'scheduled', $settings->mode() );
		$defaults->update( array( 'mode' => 'notify' ) );
		$this->assertSame( 'scheduled', $settings->mode() );
		$this->assertSame( 'scheduled', $site->get( 'mode' ) );
	}

	public function test_a_site_override_of_false_beats_a_true_default(): void {
		list( , $defaults, $store ) = $this->build();
		$defaults->update( array( 'warm_enabled' => true ) );
		$settings = new Settings( $store );
		$settings->update( array( 'warm_enabled' => false ) );
		$this->assertFalse( $settings->warmEnabled() );
	}

	public function test_secrets_and_other_keys_never_inherit(): void {
		list( , , $store ) = $this->build();
		$this->assertNull( $store->get( 'secret_CLOUDFLARE_TOKEN' ) );
		$this->assertSame( 'fb', $store->get( 'cloudflare_zone', 'fb' ) );
	}

	public function test_clear_removes_only_listed_known_keys(): void {
		$d = new NetworkDefaults( new FakeStore() );
		$d->update( array( 'mode' => 'auto', 'schedule' => '6h' ) );
		$d->clear( array( 'mode', 'cloudflare_zone' ) );
		$this->assertSame( array( 'schedule' => '6h' ), $d->all() );
	}

	public function test_overridden_lists_only_keys_the_site_saved_itself(): void {
		list( , $defaults, $store ) = $this->build();
		$defaults->update( array( 'mode' => 'auto', 'schedule' => '6h' ) );
		$settings = new Settings( $store );
		$this->assertSame( array(), $settings->overridden() );
		$settings->update( array( 'mode' => 'scheduled', 'warm_enabled' => false, 'cloudflare_zone' => 'z' ) );
		$this->assertSame( array( 'mode', 'warm_enabled' ), $settings->overridden() );
	}

	public function test_reset_makes_the_site_inherit_again(): void {
		list( $site, $defaults, $store ) = $this->build();
		$defaults->update( array( 'mode' => 'auto' ) );
		$settings = new Settings( $store );
		$settings->update( array( 'mode' => 'scheduled' ) );
		$settings->reset( array( 'mode' ) );
		$this->assertSame( 'auto', $settings->mode() );
		$this->assertNull( $site->get( 'mode' ) );
		$this->assertSame( array(), $settings->overridden() );
	}

	public function test_reset_is_limited_to_inheritable_keys(): void {
		list( $site, , $store ) = $this->build();
		$settings = new Settings( $store );
		$site->set( 'cloudflare_zone', 'z1' );
		$site->set( 'secret_CLOUDFLARE_TOKEN', 'tok' );
		$site->set( 'alert_email', 'a@b.test' );
		$settings->reset( array( 'cloudflare_zone', 'secret_CLOUDFLARE_TOKEN', 'alert_email', 'queue', 123, array( 'mode' ) ) );
		$this->assertSame( 'z1', $site->get( 'cloudflare_zone' ) );
		$this->assertSame( 'tok', $site->get( 'secret_CLOUDFLARE_TOKEN' ) );
		$this->assertSame( 'a@b.test', $site->get( 'alert_email' ) );
	}

	public function test_a_site_can_pin_again_after_a_reset(): void {
		list( , $defaults, $store ) = $this->build();
		$defaults->update( array( 'schedule' => '6h' ) );
		$settings = new Settings( $store );
		$settings->update( array( 'schedule' => 'weekly' ) );
		$settings->reset( array( 'schedule' ) );
		$settings->update( array( 'schedule' => '6h' ) );
		$this->assertSame( array( 'schedule' ), $settings->overridden(), 'A value equal to the default can be pinned.' );
	}
}
