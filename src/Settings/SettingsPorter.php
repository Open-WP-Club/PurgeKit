<?php
namespace PurgeKit\Settings;

use PurgeKit\Core\RuleSet;
use PurgeKit\Core\RuleStore;
use PurgeKit\Core\Store;
use PurgeKit\Layers\ProviderSpecs;

/**
 * Settings as a file, to copy a setup from one site to another. Secrets (API tokens, webhook keys, the
 * Slack URL) are never exported: they would end up in e-mail attachments and backups. Two site-specific
 * settings are left out as well: the "allow purges from staging" switch, so an export from a test site
 * cannot switch the safety off on a live one, and the remembered home address.
 */
final class SettingsPorter {
	public const FORMAT = 1;

	/** Left out of exports and ignored on import. */
	private const NEVER = array( 'allow_external' );

	public function __construct( private Settings $settings, private Store $store, private RuleStore $rules ) {}

	/** @return array<string,mixed> */
	public function export(): array {
		$providers = array();
		foreach ( $this->providerKeys() as $key ) {
			$providers[ $key ] = (string) $this->store->get( $key, '' );
		}
		return array(
			'purgekit'  => self::FORMAT,
			'settings'  => array_diff_key( $this->settings->all(), array_flip( self::NEVER ) ),
			'providers' => $providers,
			'rules'     => $this->rules->load()->toArray(),
		);
	}

	/**
	 * @param array<mixed> $data        A decoded export.
	 * @param string[]     $known_layers Layer ids that exist on this site; others are dropped from rules.
	 * @param string[]     $locked      Settings that wp-config.php fixes; they cannot be changed here.
	 * @return array{applied:string[],ignored:string[],rules:?int}|null Null when this is not a PurgeKit export.
	 */
	public function import( array $data, array $known_layers, array $locked = array() ): ?array {
		if ( self::FORMAT !== ( $data['purgekit'] ?? null ) ) {
			return null;
		}
		$applied = array();
		$ignored = array();

		$offered = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		$allowed = array_diff( Settings::CONFIGURABLE, self::NEVER );
		$patch   = array();
		foreach ( $offered as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) || in_array( $key, $locked, true ) ) {
				$ignored[] = (string) $key;
				continue;
			}
			$patch[ $key ] = $value;
		}
		$this->settings->update( $patch );
		$now = $this->settings->all();
		foreach ( $patch as $key => $value ) {
			// Settings quietly drops values it does not accept, so compare what is stored now with what was offered.
			$kept              = in_array( $key, array( 'exclude_paths', 'disabled_layers' ), true ) || $now[ $key ] == $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			$kept ? $applied[] = $key : $ignored[] = $key;
		}

		$providers = is_array( $data['providers'] ?? null ) ? $data['providers'] : array();
		foreach ( $providers as $key => $value ) {
			if ( in_array( $key, $this->providerKeys(), true ) && is_string( $value ) && ! in_array( $key, $locked, true ) ) {
				$this->store->set( $key, trim( $value ) );
				$applied[] = $key;
			} else {
				$ignored[] = (string) $key;
			}
		}

		$rule_count = null;
		if ( array_key_exists( 'rules', $data ) ) {
			$set = RuleSet::fromArray( $data['rules'], $known_layers );
			$this->rules->save( $set );
			$rule_count = count( $set->rules() );
		}
		return array(
			'applied' => $applied,
			'ignored' => $ignored,
			'rules'   => $rule_count,
		);
	}

	/** @return string[] Provider fields that are plain settings (ids, zone names), not secrets. */
	private function providerKeys(): array {
		return array_keys( array_filter( ProviderSpecs::writableFields(), static fn( $kind ) => 'setting' === $kind ) );
	}
}
