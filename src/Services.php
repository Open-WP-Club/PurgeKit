<?php
namespace PurgeKit;

use PurgeKit\Core\Executor;
use PurgeKit\Core\Flusher;
use PurgeKit\Core\Planner;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Queue;
use PurgeKit\Detect\FileScanner;
use PurgeKit\Detect\PostUrlExpander;
use PurgeKit\History\Repository;
use PurgeKit\Layers\Catalog;
use PurgeKit\Layers\CloudflareLayer;
use PurgeKit\Layers\ObjectCacheLayer;
use PurgeKit\Layers\Registry;
use PurgeKit\Layers\VarnishLayer;
use PurgeKit\Secrets\Secrets;
use PurgeKit\Settings\Settings;
use PurgeKit\Warm\Warmer;
use PurgeKit\Wp\Cron;
use PurgeKit\Wp\WpClock;
use PurgeKit\Wp\WpHttp;
use PurgeKit\Wp\WpLock;
use PurgeKit\Wp\WpStore;

/** The object graph. Built once per request by Plugin; tests build their own. */
final class Services {
	public WpStore $store;
	public WpHttp $http;
	public WpLock $lock;
	public Secrets $secrets;
	public Settings $settings;
	public Queue $queue;
	public Registry $registry;
	public Repository $history;
	public FileScanner $scanner;
	public Flusher $flusher;
	public Cron $cron;
	public Warmer $warmer;

	public static function build(): self {
		$s           = new self();
		$s->store    = new WpStore();
		$s->http     = new WpHttp();
		$s->lock     = new WpLock();
		$s->secrets  = new Secrets( $s->store, wp_salt( 'auth' ) );
		$s->settings = new Settings( $s->store );
		$s->queue    = new Queue( $s->store );
		$s->history  = new Repository();

		$layers      = array_merge(
			array( new ObjectCacheLayer() ),
			Catalog::callbackLayers(),
			array(
				new VarnishLayer( $s->http, $s->store, home_url() ),
				new CloudflareLayer( $s->http, $s->secrets, $s->store ),
			)
		);
		$layers      = apply_filters( 'purgekit_layers', $layers );
		$s->registry = new Registry( is_array( $layers ) ? $layers : array() );

		$s->warmer  = new Warmer( $s->http, $s->store, home_url( '/' ), 5, static fn() => (array) apply_filters( 'purgekit_preload_urls', array() ) );
		$s->flusher = new Flusher(
			$s->queue,
			new Planner( new PostUrlExpander(), home_url() ),
			new Executor( $s->registry, new WpClock() ),
			$s->lock,
			$s->history,
			static function ( PurgePlan $plan ) use ( $s ) {
				if ( ! $s->settings->warmEnabled() ) {
					return;
				}
				$s->warmer->enqueue( $plan );
				if ( ! wp_next_scheduled( 'purgekit_warm' ) ) {
					wp_schedule_single_event( time() + 5, 'purgekit_warm' );
				}
			}
		);

		$dirs       = apply_filters( 'purgekit_fingerprint_dirs', array( get_theme_root(), WP_PLUGIN_DIR ) );
		$exts       = apply_filters( 'purgekit_fingerprint_extensions', array( 'php', 'js', 'css', 'json', 'html' ) );
		$s->scanner = new FileScanner( $s->store, (array) $dirs, (array) $exts );
		$s->cron    = new Cron( $s->settings, $s->flusher, $s->queue, $s->scanner, $s->history );
		return $s;
	}
}
