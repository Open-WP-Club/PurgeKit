<?php
namespace PurgeKit;

use PurgeKit\Admin\AdminBar;
use PurgeKit\Admin\Menu;
use PurgeKit\Admin\Notices;
use PurgeKit\Detect\Hooks;
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
		( new Notices( $s ) )->register();
		( new Router( $s ) )->register();
		( new Menu() )->register();
		( new AdminBar( $s ) )->register();

		if ( 'auto' === $s->settings->mode() ) {
			add_action(
				'shutdown',
				static function () use ( $s ) {
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
		}
	}

	public static function activate(): void {
		$s = self::services();
		$s->history->install();
		if ( ! $s->secrets->has( 'WEBHOOK_KEY' ) ) {
			$s->secrets->set( 'WEBHOOK_KEY', wp_generate_password( 40, false ) );
		}
		$s->cron->sync();
	}

	public static function deactivate(): void {
		foreach ( array( Wp\Cron::FLUSH, Wp\Cron::SCAN, Wp\Cron::PRUNE ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
