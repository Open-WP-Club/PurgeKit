<?php
namespace PurgeKit;

use PurgeKit\Alerts\Notifier;
use PurgeKit\Core\EnvironmentGuard;
use PurgeKit\Core\DeferredJobs;
use PurgeKit\Core\Executor;
use PurgeKit\Core\Health;
use PurgeKit\Core\Flusher;
use PurgeKit\Core\Planner;
use PurgeKit\Core\PurgePlan;
use PurgeKit\Core\Run;
use PurgeKit\Core\Queue;
use PurgeKit\Core\Store;
use PurgeKit\Core\ScheduledPurges;
use PurgeKit\Core\StormGuard;
use PurgeKit\Core\RuleStore;
use PurgeKit\Detect\FileScanner;
use PurgeKit\Detect\PostUrlExpander;
use PurgeKit\History\Repository;
use PurgeKit\Inspector\Cacheability;
use PurgeKit\Inspector\Inspector;
use PurgeKit\Inspector\Verifier;
use PurgeKit\Layers\ApiLayer;
use PurgeKit\Layers\Catalog;
use PurgeKit\Layers\CloudflareLayer;
use PurgeKit\Layers\ApcuLayer;
use PurgeKit\Layers\NginxFastcgiLayer;
use PurgeKit\Layers\PhpApcu;
use PurgeKit\Layers\TransientsLayer;
use PurgeKit\Layers\WpTransients;
use PurgeKit\Layers\OpcacheLayer;
use PurgeKit\Layers\PhpOpcache;
use PurgeKit\Layers\ObjectCacheLayer;
use PurgeKit\Layers\ProviderSpecs;
use PurgeKit\Layers\Registry;
use PurgeKit\Layers\VarnishLayer;
use PurgeKit\Network\InheritingStore;
use PurgeKit\Network\NetworkDefaults;
use PurgeKit\Secrets\Secrets;
use PurgeKit\Settings\ConfigOverrides;
use PurgeKit\Settings\Settings;
use PurgeKit\Settings\SettingsPorter;
use PurgeKit\Warm\SitemapReader;
use PurgeKit\Warm\Warmer;
use PurgeKit\Wp\Cron;
use PurgeKit\Wp\DeferredRunner;
use PurgeKit\Wp\WpClock;
use PurgeKit\Wp\WpHttp;
use PurgeKit\Wp\WpMailer;
use PurgeKit\Wp\WpLock;
use PurgeKit\Wp\NetworkStore;
use PurgeKit\Wp\ScheduledRunner;
use PurgeKit\Wp\WpStore;

/** The object graph. Built once per request by Plugin; tests build their own. */
final class Services {
	public Store $store;
	public ConfigOverrides $config;
	public SettingsPorter $porter;
	public WpHttp $http;
	public WpLock $lock;
	public Secrets $secrets;
	public Settings $settings;
	public Queue $queue;
	public RuleStore $rules;
	public Registry $registry;
	public Repository $history;
	public FileScanner $scanner;
	public Flusher $flusher;
	public Cron $cron;
	public Warmer $warmer;
	public Inspector $inspector;
	public Cacheability $cacheability;
	public Verifier $verifier;
	public Notifier $notifier;
	public EnvironmentGuard $guard;
	public ScheduledPurges $scheduled;
	public ScheduledRunner $scheduler;
	public StormGuard $storm;
	public DeferredJobs $deferred;
	public DeferredRunner $deferrer;
	public PostUrlExpander $expander;

	public static function build(): self {
		$s = new self();
		// Settings fixed in wp-config.php (PURGEKIT_CONFIG) win over what is stored.
		$s->config  = new ConfigOverrides( new WpStore(), defined( 'PURGEKIT_CONFIG' ) && is_array( PURGEKIT_CONFIG ) ? PURGEKIT_CONFIG : array() );
		$s->store   = $s->config;
		$s->http    = new WpHttp();
		$s->lock    = new WpLock();
		$s->secrets = new Secrets( $s->store, wp_salt( 'auth' ) );
		// On multisite, sites inherit network defaults for the non-secret behaviour settings.
		$s->settings = new Settings( is_multisite() ? new InheritingStore( $s->store, new NetworkDefaults( new NetworkStore() ) ) : $s->store );
		$s->queue    = new Queue( $s->store );
		$s->rules    = new RuleStore( $s->store );
		$s->porter   = new SettingsPorter( $s->settings, $s->store, $s->rules );
		$s->history  = new Repository();

		$layers          = array_merge(
			array(
				new OpcacheLayer( $s->store, new PhpOpcache() ),
				new ApcuLayer( $s->store, new PhpApcu() ),
				new TransientsLayer( $s->store, new WpTransients() ),
				new ObjectCacheLayer(),
			),
			Catalog::callbackLayers(),
			Catalog::assetLayers( static fn() => (bool) $s->store->get( 'builder_assets_enabled', false ) ),
			array(
				new VarnishLayer( $s->http, $s->store, home_url() ),
				new NginxFastcgiLayer( $s->store ),
				new CloudflareLayer( $s->http, $s->secrets, $s->store ),
			),
			array_map( static fn( array $spec ) => new ApiLayer( $spec, $s->http, $s->secrets, $s->store ), array_values( ProviderSpecs::all() ) )
		);
		$layers          = apply_filters( 'purgekit_layers', $layers );
		$s->registry     = new Registry( is_array( $layers ) ? $layers : array() );
		$s->inspector    = new Inspector( $s->http, $s->registry, new WpClock() );
		$s->cacheability = new Cacheability( $s->http, $s->registry );
		$s->verifier     = new Verifier( new Inspector( $s->http, $s->registry, new WpClock(), 5 ), home_url( '/' ) );

		$s->warmer   = new Warmer( $s->http, $s->store, home_url( '/' ), 5, static fn() => (array) apply_filters( 'purgekit_preload_urls', array() ), new SitemapReader( $s->http, home_url( '/' ), (array) apply_filters( 'purgekit_sitemap_paths', SitemapReader::PATHS ) ) );
		$s->notifier = new Notifier( $s->http, new WpMailer(), $s->settings, $s->secrets, $s->store, new WpClock(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url( '/' ) );
		$host        = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$s->guard    = new EnvironmentGuard( wp_get_environment_type(), $host, $s->settings->homeHost(), $s->settings->allowExternal() );
		$s->storm    = new StormGuard( $s->store );
		$s->deferred = new DeferredJobs( $s->store );
		$s->expander = new PostUrlExpander();
		$s->flusher  = new Flusher(
			$s->queue,
			new Planner( $s->expander, home_url(), $s->settings->excludePaths(), $s->settings->cacheTags() ? array( $s->expander, 'tags' ) : null ),
			new Executor(
				$s->registry,
				new WpClock(),
				static fn( $layer ) => in_array( $layer->id(), $s->settings->disabledLayers(), true ) ? 'Switched off in the layer map.' : $s->guard->reasonFor( $layer->tier() )
			),
			$s->lock,
			$s->history,
			static function ( PurgePlan $plan ) use ( $s ) {
				if ( ! $s->settings->warmEnabled() ) {
					return;
				}
				$s->warmer->enqueue( $plan, $s->settings->warmSitemapLimit() );
				if ( ! wp_next_scheduled( 'purgekit_warm' ) ) {
					wp_schedule_single_event( time() + 5, 'purgekit_warm' );
				}
			},
			static function ( string $source, Run $run ) use ( $s ) {
				if ( in_array( $run->status, array( Run::OK, Run::PARTIAL, Run::FAILED ), true ) ) {
					$s->store->set( 'last_run', Health::summarise( $run, $source, time() ) );
				}
				$s->notifier->notify( $source, $run );
				$now = time();
				if ( $s->storm->record( $source, $run, $now ) ) {
					$trip = $s->storm->tripped( $now );
					$s->notifier->notifyStorm( (int) ( $trip['count'] ?? 0 ), (int) ( $trip['window'] ?? 600 ), $s->settings->stormPause() );
				}
			},
			$s->rules,
			static fn( Run $run ) => $s->settings->verifyAfterFlush() ? $s->verifier->verify( $run ) : null,
			static fn( PurgePlan $plan, ?array $layers, array $rules, int $delay ) => $s->deferrer->defer( $plan, $layers, $rules, $delay )
		);
		$s->deferrer = new DeferredRunner( $s->deferred, $s->flusher );

		$s->scheduled = new ScheduledPurges( $s->store, home_url() );
		$s->scheduler = new ScheduledRunner( $s->scheduled, $s->flusher );

		$dirs       = apply_filters( 'purgekit_fingerprint_dirs', array( get_theme_root(), WP_PLUGIN_DIR ) );
		$exts       = apply_filters( 'purgekit_fingerprint_extensions', array( 'php', 'js', 'css', 'json', 'html' ) );
		$s->scanner = new FileScanner( $s->store, (array) $dirs, (array) $exts );
		$s->cron    = new Cron( $s->settings, $s->flusher, $s->queue, $s->scanner, $s->history );
		return $s;
	}
}
