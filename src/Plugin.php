<?php
namespace PurgeKit;

use PurgeKit\Admin\AdminBar;
use PurgeKit\Admin\EditorPanel;
use PurgeKit\Admin\HealthNotice;
use PurgeKit\Admin\HistoryExport;
use PurgeKit\Admin\Menu;
use PurgeKit\Admin\Notices;
use PurgeKit\Admin\NetworkPage;
use PurgeKit\Detect\Hooks;
use PurgeKit\Detect\TagHeaders;
use PurgeKit\Rest\Router;

final class Plugin {
	private static ?Services $services = null;

	public static function boot(): void {
		register_activation_hook( PURGEKIT_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( PURGEKIT_FILE, array( self::class, 'deactivate' ) );
		add_action( 'plugins_loaded', array( self::class, 'init' ) );
	}

	public static function services(): Services {
		if ( null === self::$services ) {
			self::$services = Services::build();
		}
		return self::$services;
	}

	public static function init(): void {
		$s = self::services();
		self::migrate( $s );
		( new Hooks( $s->queue ) )->register();
		$s->cron->register();
		add_action(
			'purgekit_warm',
			static function () use ( $s ) {
				$s->warmer->processBatch();
				if ( $s->warmer->pending() > 0 && ! wp_next_scheduled( 'purgekit_warm' ) ) {
					wp_schedule_single_event( time() + 5, 'purgekit_warm' );
				}
			}
		);
		self::remember_home( $s );
		$s->scheduler->register();
		$s->deferrer->register();
		( new TagHeaders( $s->settings ) )->register();
		self::asset_version( $s );
		( new HealthNotice( $s ) )->register();
		( new EditorPanel() )->register();
		( new Notices( $s ) )->register();
		( new Router( $s ) )->register();
		( new Menu() )->register();
		( new AdminBar( $s ) )->register();

		if ( 'auto' === $s->settings->mode() ) {
			add_action(
				'shutdown',
				static function () use ( $s ) {
					// During a flush storm (when the user asked for it) changes stay queued, as in "notify me".
					if ( $s->settings->stormPause() && null !== $s->storm->tripped( time() ) ) {
						return;
					}
					if ( $s->queue->count() > 0 ) {
						$s->flusher->flush( 'auto' );
					}
				}
			);
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'purgekit', new Cli\Command( $s ) );
		}
		if ( is_admin() ) {
			add_action( 'admin_init', array( $s->cron, 'sync' ) );
			( new HistoryExport( $s ) )->register();
			( new \PurgeKit\Admin\StatsExport( $s ) )->register();
		}
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( self::class, 'new_site' ), 900 );
			( new NetworkPage() )->register();
		}
	}

	/** "Reload assets in browsers": a new version on the site's own CSS and JS addresses. */
	private static function asset_version( Services $s ): void {
		$version = $s->settings->assetVersion();
		if ( $version <= 0 ) {
			return;
		}
		$host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$rewrite = static fn( $src ) => is_string( $src ) ? Core\AssetVersion::rewrite( $src, $version, $host ) : $src;
		add_filter( 'style_loader_src', $rewrite, 99 );
		add_filter( 'script_loader_src', $rewrite, 99 );
	}

	/** Remembers the address the site had when it was set up, so a cloned copy can be recognised later. */
	private static function remember_home( Services $s ): void {
		if ( '' === $s->settings->homeHost() && 'production' === wp_get_environment_type() ) {
			$s->settings->setHomeHost( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
	}

	/** Upgrades the history table when the plugin was updated without being reactivated. */
	private static function migrate( Services $s ): void {
		if ( get_option( 'purgekit_db_version' ) !== History\Repository::DB_VERSION ) {
			$s->history->install();
			update_option( 'purgekit_db_version', History\Repository::DB_VERSION, true );
		}
	}

	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				try {
					self::activate_site( Services::build() );
				} finally {
					restore_current_blog();
				}
			}
			return;
		}
		self::activate_site( self::services() );
		set_transient( 'purgekit_activation_redirect', 1, 30 );
	}

	/** Per-site setup: history table, webhook key, scheduled events. */
	private static function activate_site( Services $s ): void {
		$s->history->install();
		update_option( 'purgekit_db_version', History\Repository::DB_VERSION, true );
		if ( ! $s->secrets->has( 'WEBHOOK_KEY' ) ) {
			$s->secrets->set( 'WEBHOOK_KEY', wp_generate_password( 40, false ) );
			$s->store->set( 'webhook_key_at', time() );
		}
		$s->cron->sync();
	}

	/** A site created after a network-wide activation needs its own table and key. */
	public static function new_site( \WP_Site $site ): void {
		if ( ! self::network_active() ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::activate_site( Services::build() );
		} finally {
			restore_current_blog();
		}
	}

	/** Reads the site option directly: is_plugin_active_for_network() is not loaded outside wp-admin. */
	public static function network_active(): bool {
		$active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $active ) && isset( $active[ plugin_basename( PURGEKIT_FILE ) ] );
	}

	public static function deactivate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				try {
					self::clear_cron();
				} finally {
					restore_current_blog();
				}
			}
			return;
		}
		self::clear_cron();
	}

	private static function clear_cron(): void {
		foreach ( array( Wp\Cron::FLUSH, Wp\Cron::SCAN, Wp\Cron::PRUNE, Wp\Cron::SAMPLE, Wp\Cron::DIGEST ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
