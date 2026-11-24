<?php
namespace PurgeKit\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use PurgeKit\Core\RuleStore;
use PurgeKit\Settings\ConfigOverrides;
use PurgeKit\Settings\Settings;
use PurgeKit\Settings\SettingsPorter;
use PurgeKit\Tests\Fakes\FakeStore;

final class SettingsPorterTest extends TestCase {
	private function porter( ?FakeStore $store = null, array $config = array() ): array {
		$raw      = $store ?? new FakeStore();
		$over     = new ConfigOverrides( $raw, $config );
		$settings = new Settings( $over );
		return array( new SettingsPorter( $settings, $over, new RuleStore( $over ) ), $settings, $over, $raw );
	}

	public function test_export_has_settings_providers_and_rules_but_never_secrets_or_the_staging_switch(): void {
		list( $porter, $settings, $over ) = $this->porter();
		$settings->update(
			array(
				'mode'           => 'auto',
				'allow_external' => true,
				'exclude_paths'  => '/cart/*',
			)
		);
		$over->set( 'fastly_service_id', 'svc1' );
		$over->set( 'secret_CLOUDFLARE_TOKEN', 'ENCRYPTEDTOKEN' );
		$over->set( 'rules', array( array( 'name' => 'Shop', 'action' => 'home' ) ) );

		$out  = $porter->export();
		$json = json_encode( $out );
		$this->assertSame( 1, $out['purgekit'] );
		$this->assertSame( 'auto', $out['settings']['mode'] );
		$this->assertArrayNotHasKey( 'allow_external', $out['settings'] );
		$this->assertSame( '/cart/*', $out['settings']['exclude_paths'] );
		$this->assertSame( 'svc1', $out['providers']['fastly_service_id'] );
		$this->assertSame( 'Shop', $out['rules'][0]['name'] );
		$this->assertStringNotContainsString( 'ENCRYPTEDTOKEN', $json );
		$this->assertStringNotContainsString( 'secret_', $json );
	}

	public function test_import_round_trips_into_another_site(): void {
		list( $a, $a_settings, $a_store ) = $this->porter();
		$a_settings->update( array( 'mode' => 'scheduled', 'schedule' => '6h', 'warm_sitemap_limit' => 40, 'disabled_layers' => array( 'varnish' ) ) );
		$a_store->set( 'fastly_service_id', 'svc1' );
		$export = json_decode( json_encode( $a->export() ), true );

		list( $b, $b_settings, $b_store ) = $this->porter();
		$result = $b->import( $export, array( 'varnish', 'cloudflare' ) );

		$this->assertSame( 'scheduled', $b_settings->mode() );
		$this->assertSame( '6h', $b_settings->schedule() );
		$this->assertSame( 40, $b_settings->warmSitemapLimit() );
		$this->assertSame( array( 'varnish' ), $b_settings->disabledLayers() );
		$this->assertSame( 'svc1', $b_store->get( 'fastly_service_id' ) );
		$this->assertContains( 'mode', $result['applied'] );
		$this->assertSame( array(), $result['ignored'] );
	}

	public function test_import_refuses_other_files_and_cannot_switch_the_staging_guard_off(): void {
		list( $porter, $settings ) = $this->porter();
		$this->assertNull( $porter->import( array( 'hello' => 'world' ), array() ) );
		$this->assertNull( $porter->import( array( 'purgekit' => 2 ), array() ), 'An unknown format version is refused.' );

		$result = $porter->import( array( 'purgekit' => 1, 'settings' => array( 'allow_external' => true, 'home_host' => 'evil.test', 'bogus' => 1 ) ), array() );
		$this->assertFalse( $settings->allowExternal() );
		$this->assertSame( array( 'allow_external', 'home_host', 'bogus' ), $result['ignored'] );
		$this->assertSame( array(), $result['applied'] );
	}

	public function test_invalid_values_are_reported_as_ignored(): void {
		list( $porter, $settings ) = $this->porter();
		$result = $porter->import( array( 'purgekit' => 1, 'settings' => array( 'mode' => 'banana', 'schedule' => 'weekly' ) ), array() );
		$this->assertSame( 'notify', $settings->mode() );
		$this->assertSame( array( 'schedule' ), $result['applied'] );
		$this->assertSame( array( 'mode' ), $result['ignored'] );
	}

	public function test_import_drops_unknown_layers_from_rules_and_only_touches_rules_when_present(): void {
		list( $porter, , $over ) = $this->porter();
		$porter->import( array( 'purgekit' => 1, 'rules' => array( array( 'name' => 'Shop', 'action' => 'all', 'layers' => array( 'cloudflare', 'ghost' ) ) ) ), array( 'cloudflare' ) );
		$this->assertSame( array( 'cloudflare' ), $over->get( 'rules' )[0]['layers'] );

		$result = $porter->import( array( 'purgekit' => 1, 'settings' => array() ), array( 'cloudflare' ) );
		$this->assertNull( $result['rules'] );
		$this->assertCount( 1, $over->get( 'rules' ), 'No rules in the file means the existing ones stay.' );
	}

	public function test_import_does_not_overwrite_settings_fixed_in_wp_config(): void {
		list( $porter, $settings ) = $this->porter( null, array( 'mode' => 'auto' ) );
		$result = $porter->import( array( 'purgekit' => 1, 'settings' => array( 'mode' => 'notify', 'schedule' => '3h' ) ), array(), array( 'mode' ) );
		$this->assertSame( 'auto', $settings->mode() );
		$this->assertSame( '3h', $settings->schedule() );
		$this->assertSame( array( 'mode' ), $result['ignored'] );
	}

	public function test_config_constants_override_and_lock_only_plain_settings(): void {
		list( , $settings, $over, $raw ) = $this->porter(
			null,
			array(
				'mode'                    => 'auto',
				'exclude_paths'           => array( '/cart/*', '/checkout/*' ),
				'secret_CLOUDFLARE_TOKEN' => 'nope',
				'queue'                   => 'nope',
				'bogus'                   => 1,
				'fastly_service_id'       => 'svc9',
			)
		);
		$this->assertSame( array( 'mode', 'exclude_paths', 'fastly_service_id' ), $over->locked() );
		$this->assertSame( 'auto', $settings->mode() );
		$this->assertSame( array( '/cart/*', '/checkout/*' ), $settings->excludePaths() );
		$this->assertSame( 'svc9', $over->get( 'fastly_service_id' ) );
		$this->assertNull( $over->get( 'secret_CLOUDFLARE_TOKEN' ), 'Secrets keep their own constants; they cannot be set through PURGEKIT_CONFIG.' );
		$this->assertNull( $over->get( 'queue' ) );

		$settings->update( array( 'mode' => 'notify', 'schedule' => '12h' ) );
		$this->assertSame( 'auto', $settings->mode(), 'A fixed setting cannot be changed from the admin.' );
		$this->assertNull( $raw->get( 'mode' ), 'And nothing is written for it.' );
		$this->assertSame( '12h', $settings->schedule() );
	}
}
