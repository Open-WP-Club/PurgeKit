<?php
namespace PurgeKit\Settings;

use PurgeKit\Core\Store;
use PurgeKit\Network\InheritingStore;

/** Typed, validated accessors over the Store. Never holds secrets. */
final class Settings {
	public const MODES     = array( 'notify', 'auto', 'scheduled' );
	public const SCHEDULES = array( 'hourly', '3h', '6h', '12h', 'daily', 'weekly' );

	public const SITEMAP_LIMIT_DEFAULT = 20;
	public const SITEMAP_LIMIT_MAX     = 100;

	public function __construct( private Store $store ) {}

	public function mode(): string {
		$v = $this->store->get( 'mode', 'notify' );
		return in_array( $v, self::MODES, true ) ? $v : 'notify';
	}

	public function schedule(): string {
		$v = $this->store->get( 'schedule', 'daily' );
		return in_array( $v, self::SCHEDULES, true ) ? $v : 'daily';
	}

	public function skipIfUnchanged(): bool {
		return (bool) $this->store->get( 'skip_if_unchanged', true );
	}

	public function alertEmail(): string {
		$v = trim( (string) $this->store->get( 'alert_email', '' ) );
		return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : '';
	}

	/** 'problems' alerts on failed and partial runs; 'failed' only when every layer failed. */
	public function alertOn(): string {
		return 'failed' === $this->store->get( 'alert_on', 'problems' ) ? 'failed' : 'problems';
	}

	public function warmEnabled(): bool {
		return (bool) $this->store->get( 'warm_enabled', true );
	}

	public function verifyAfterFlush(): bool {
		return (bool) $this->store->get( 'verify_after_flush', false );
	}

	/** How many sitemap URLs a purge-everything warms. 0 turns it off; capped at SITEMAP_LIMIT_MAX. */
	public function warmSitemapLimit(): int {
		$v = $this->store->get( 'warm_sitemap_limit', self::SITEMAP_LIMIT_DEFAULT );
		return is_numeric( $v ) ? max( 0, min( self::SITEMAP_LIMIT_MAX, (int) $v ) ) : self::SITEMAP_LIMIT_DEFAULT;
	}

	/**
	 * Inheritable keys this site has its own value for (multisite only; empty elsewhere).
	 *
	 * @return string[]
	 */
	public function overridden(): array {
		return $this->store instanceof InheritingStore ? $this->store->overriddenKeys() : array();
	}

	/**
	 * Drops the site's own value so it inherits the network default again. Only inheritable keys count.
	 *
	 * @param array<int,mixed> $keys
	 */
	public function reset( array $keys ): void {
		if ( $this->store instanceof InheritingStore ) {
			$this->store->reset( array_values( array_filter( $keys, 'is_string' ) ) );
		}
	}

	public function all(): array {
		return array(
			'mode'               => $this->mode(),
			'schedule'           => $this->schedule(),
			'skip_if_unchanged'  => $this->skipIfUnchanged(),
			'warm_enabled'       => $this->warmEnabled(),
			'warm_sitemap_limit' => $this->warmSitemapLimit(),
			'verify_after_flush' => $this->verifyAfterFlush(),
			'cloudflare_zone'    => (string) $this->store->get( 'cloudflare_zone', '' ),
			'alert_email'        => $this->alertEmail(),
			'alert_on'           => $this->alertOn(),
			'varnish_enabled'    => (bool) $this->store->get( 'varnish_enabled', false ),
			'nginx_cache_path'   => (string) $this->store->get( 'nginx_cache_path', '' ),
			'nginx_cache_key'    => (string) $this->store->get( 'nginx_cache_key', '' ),
			'nginx_cache_levels' => (string) $this->store->get( 'nginx_cache_levels', '' ),
		);
	}

	/** Unknown keys and invalid values are ignored. */
	public function update( array $patch ): void {
		if ( isset( $patch['mode'] ) && in_array( $patch['mode'], self::MODES, true ) ) {
			$this->store->set( 'mode', $patch['mode'] );
		}
		if ( isset( $patch['schedule'] ) && in_array( $patch['schedule'], self::SCHEDULES, true ) ) {
			$this->store->set( 'schedule', $patch['schedule'] );
		}
		foreach ( array( 'skip_if_unchanged', 'warm_enabled', 'varnish_enabled', 'verify_after_flush' ) as $flag ) {
			if ( isset( $patch[ $flag ] ) ) {
				$this->store->set( $flag, (bool) $patch[ $flag ] );
			}
		}
		if ( isset( $patch['warm_sitemap_limit'] ) && is_numeric( $patch['warm_sitemap_limit'] ) ) {
			$this->store->set( 'warm_sitemap_limit', max( 0, min( self::SITEMAP_LIMIT_MAX, (int) $patch['warm_sitemap_limit'] ) ) );
		}
		if ( isset( $patch['alert_email'] ) && is_string( $patch['alert_email'] ) ) {
			$email = trim( $patch['alert_email'] );
			if ( '' === $email || false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$this->store->set( 'alert_email', $email );
			}
		}
		if ( isset( $patch['alert_on'] ) && in_array( $patch['alert_on'], array( 'problems', 'failed' ), true ) ) {
			$this->store->set( 'alert_on', $patch['alert_on'] );
		}
		foreach ( array( 'nginx_cache_path', 'nginx_cache_key', 'nginx_cache_levels' ) as $text ) {
			if ( isset( $patch[ $text ] ) && is_string( $patch[ $text ] ) ) {
				$this->store->set( $text, trim( $patch[ $text ] ) );
			}
		}
		if ( isset( $patch['cloudflare_zone'] ) && is_string( $patch['cloudflare_zone'] ) ) {
			$this->store->set( 'cloudflare_zone', trim( $patch['cloudflare_zone'] ) );
		}
	}
}
