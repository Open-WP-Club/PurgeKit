<?php
namespace PurgeKit\Settings;

use PurgeKit\Core\Store;
use PurgeKit\Network\InheritingStore;

/** Typed, validated accessors over the Store. Never holds secrets. */
final class Settings {
	/** Every setting that can be exported, imported or fixed in wp-config.php. Secrets and per-site state (home_host) are never in here. */
	public const CONFIGURABLE = array(
		'mode',
		'schedule',
		'skip_if_unchanged',
		'warm_enabled',
		'warm_sitemap_limit',
		'verify_after_flush',
		'storm_pause',
		'cache_tags',
		'soft_purge',
		'alert_cache_health',
		'alert_digest',
		'site_probe',
		'allow_external',
		'disabled_layers',
		'exclude_paths',
		'cloudflare_zone',
		'alert_email',
		'alert_on',
		'varnish_enabled',
		'opcache_enabled',
		'apcu_enabled',
		'transients_enabled',
		'builder_assets_enabled',
		'nginx_cache_path',
		'nginx_cache_key',
		'nginx_cache_levels',
	);

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

	/** Layers switched off in the layer map; their credentials stay saved. */
	public function disabledLayers(): array {
		$v = $this->store->get( 'disabled_layers', array() );
		return is_array( $v ) ? array_values( array_filter( $v, static fn( $id ) => is_string( $id ) && 1 === preg_match( '/^[a-z0-9\-]+$/', $id ) ) ) : array();
	}

	/** Path patterns that automatic and rule-driven flushes never purge ("/cart/*"). */
	public function excludePaths(): array {
		return self::lines( (string) $this->store->get( 'exclude_paths', '' ) );
	}

	/** Lets CDN purges run although the site looks like a staging or cloned copy. */
	public function allowExternal(): bool {
		return (bool) $this->store->get( 'allow_external', false );
	}

	/** The host the layers were set up for, remembered to notice a cloned site. */
	public function homeHost(): string {
		return (string) $this->store->get( 'home_host', '' );
	}

	public function setHomeHost( string $host ): void {
		$this->store->set( 'home_host', strtolower( $host ) );
	}

	/** @return string[] */
	private static function lines( string $text ): array {
		$out   = array();
		$split = preg_split( '/\R/', $text );
		foreach ( false === $split ? array() : $split as $line ) {
			$line = trim( $line );
			if ( '' !== $line && strlen( $line ) <= 200 && 1 === preg_match( '#^[/*]#', $line ) ) {
				$out[ $line ] = true;
			}
		}
		return array_slice( array_keys( $out ), 0, 50 );
	}

	public function warmEnabled(): bool {
		return (bool) $this->store->get( 'warm_enabled', true );
	}

	/** Stop automatic flushing while a flush storm lasts (the changes stay queued, as in "notify me"). */
	public function stormPause(): bool {
		return (bool) $this->store->get( 'storm_pause', false );
	}

	/** Send Surrogate-Key / Cache-Tag headers and purge by tag where a layer can. */
	public function cacheTags(): bool {
		return (bool) $this->store->get( 'cache_tags', false );
	}

	/** Mark content stale instead of deleting it where the CDN supports that. */
	public function softPurge(): bool {
		return (bool) $this->store->get( 'soft_purge', false );
	}

	/** Tell someone when a cache itself has a problem (Redis not answering, memory full), not only when a purge fails. */
	public function alertCacheHealth(): bool {
		return (bool) $this->store->get( 'alert_cache_health', true );
	}

	/** A short weekly summary of the numbers, sent to the alert channels. Off until asked for. */
	public function alertDigest(): bool {
		return (bool) $this->store->get( 'alert_digest', false );
	}

	/** Once an hour, fetch a few of the site's own pages as a visitor and note which ones a cache answered. */
	public function siteProbe(): bool {
		return (bool) $this->store->get( 'site_probe', true );
	}

	/** The number added to the site's own CSS and JS addresses; 0 means nothing is added. Never exported. */
	public function assetVersion(): int {
		$v = $this->store->get( 'asset_version', 0 );
		return is_numeric( $v ) ? max( 0, (int) $v ) : 0;
	}

	public function bumpAssetVersion( int $now ): int {
		$next = max( $now, $this->assetVersion() + 1 );
		$this->store->set( 'asset_version', $next );
		return $next;
	}

	/** Has the first-run setup been completed or skipped? Per site; never exported. */
	public function onboarded(): bool {
		return (bool) $this->store->get( 'onboarded', false );
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
			'mode'                   => $this->mode(),
			'schedule'               => $this->schedule(),
			'skip_if_unchanged'      => $this->skipIfUnchanged(),
			'warm_enabled'           => $this->warmEnabled(),
			'warm_sitemap_limit'     => $this->warmSitemapLimit(),
			'verify_after_flush'     => $this->verifyAfterFlush(),
			'storm_pause'            => $this->stormPause(),
			'cache_tags'             => $this->cacheTags(),
			'soft_purge'             => $this->softPurge(),
			'alert_cache_health'     => $this->alertCacheHealth(),
			'alert_digest'           => $this->alertDigest(),
			'site_probe'             => $this->siteProbe(),
			'onboarded'              => $this->onboarded(),
			'allow_external'         => $this->allowExternal(),
			'disabled_layers'        => $this->disabledLayers(),
			'exclude_paths'          => implode( "\n", $this->excludePaths() ),
			'cloudflare_zone'        => (string) $this->store->get( 'cloudflare_zone', '' ),
			'alert_email'            => $this->alertEmail(),
			'alert_on'               => $this->alertOn(),
			'opcache_enabled'        => (bool) $this->store->get( 'opcache_enabled', false ),
			'apcu_enabled'           => (bool) $this->store->get( 'apcu_enabled', false ),
			'transients_enabled'     => (bool) $this->store->get( 'transients_enabled', false ),
			'builder_assets_enabled' => (bool) $this->store->get( 'builder_assets_enabled', false ),
			'varnish_enabled'        => (bool) $this->store->get( 'varnish_enabled', false ),
			'nginx_cache_path'       => (string) $this->store->get( 'nginx_cache_path', '' ),
			'nginx_cache_key'        => (string) $this->store->get( 'nginx_cache_key', '' ),
			'nginx_cache_levels'     => (string) $this->store->get( 'nginx_cache_levels', '' ),
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
		foreach ( array( 'skip_if_unchanged', 'warm_enabled', 'varnish_enabled', 'opcache_enabled', 'apcu_enabled', 'transients_enabled', 'builder_assets_enabled', 'verify_after_flush', 'allow_external', 'storm_pause', 'cache_tags', 'soft_purge', 'alert_cache_health', 'alert_digest', 'site_probe', 'onboarded' ) as $flag ) {
			if ( isset( $patch[ $flag ] ) ) {
				$this->store->set( $flag, (bool) $patch[ $flag ] );
			}
		}
		if ( isset( $patch['exclude_paths'] ) && is_string( $patch['exclude_paths'] ) ) {
			$this->store->set( 'exclude_paths', implode( "\n", self::lines( $patch['exclude_paths'] ) ) );
		}
		if ( isset( $patch['disabled_layers'] ) && is_array( $patch['disabled_layers'] ) ) {
			$ids = array_filter( $patch['disabled_layers'], static fn( $id ) => is_string( $id ) && 1 === preg_match( '/^[a-z0-9\-]+$/', $id ) );
			$this->store->set( 'disabled_layers', array_values( array_unique( $ids ) ) );
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
