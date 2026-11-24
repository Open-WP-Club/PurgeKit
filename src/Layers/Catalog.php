<?php
namespace PurgeKit\Layers;

// Third-party hooks and functions are called here by design.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

/**
 * Cache plugins and hosts that expose a plain PHP purge function or action.
 * Names were checked on 2026-11-24 against the plugin sources on wordpress.org
 * (LiteSpeed, W3TC, WP Super Cache, WP Fastest Cache, Nginx Helper, SiteGround) and
 * WP Rocket's docs. WP Engine and Kinsta are closed mu-plugins: community-documented
 * only, so those rows are guarded and fail loudly instead of silently doing nothing.
 */
final class Catalog {
	/** @return CallbackLayer[] */
	public static function callbackLayers(): array {
		return array(
			new CallbackLayer(
				'wp-rocket',
				'WP Rocket',
				Tier::PLUGIN,
				static fn() => function_exists( 'rocket_clean_domain' ),
				static fn() => rocket_clean_domain(),
				static fn( array $urls ) => rocket_clean_files( $urls )
			),
			new CallbackLayer(
				'litespeed',
				'LiteSpeed Cache',
				Tier::PLUGIN,
				static fn() => defined( 'LSCWP_V' ),
				static fn() => do_action( 'litespeed_purge_all' ),
				static function ( array $urls ) {
					foreach ( $urls as $url ) {
						do_action( 'litespeed_purge_url', $url );
					}
				}
			),
			new CallbackLayer(
				'w3tc',
				'W3 Total Cache',
				Tier::PLUGIN,
				static fn() => function_exists( 'w3tc_flush_all' ),
				static fn() => w3tc_flush_all(),
				static function ( array $urls ) {
					foreach ( $urls as $url ) {
						w3tc_flush_url( $url );
					}
				}
			),
			new CallbackLayer(
				'wp-super-cache',
				'WP Super Cache',
				Tier::PLUGIN,
				static fn() => function_exists( 'wp_cache_clear_cache' ),
				static fn() => wp_cache_clear_cache(),
				static function ( array $urls ) {
					foreach ( $urls as $url ) {
						function_exists( 'wpsc_delete_url_cache' ) ? wpsc_delete_url_cache( $url ) : wp_cache_clear_cache();
					}
				}
			),
			new CallbackLayer(
				'wp-fastest-cache',
				'WP Fastest Cache',
				Tier::PLUGIN,
				// wpfc_clear_all_cache() is a method of WpFastestCache, not a global function; the action is the public entry point.
				static fn() => class_exists( 'WpFastestCache' ),
				static fn() => do_action( 'wpfc_clear_all_cache', true )
			),
			new CallbackLayer(
				'nginx-helper',
				'Nginx Helper',
				Tier::PLUGIN,
				static fn() => defined( 'NGINX_HELPER_BASENAME' ),
				static fn() => do_action( 'rt_nginx_helper_purge_all' )
			),
			new CallbackLayer(
				'siteground',
				'SiteGround Optimizer',
				Tier::HOST,
				static fn() => function_exists( 'sg_cachepress_purge_everything' ) || function_exists( 'sg_cachepress_purge_cache' ),
				// sg_cachepress_purge_cache() without a URL only purges the home page.
				static fn() => function_exists( 'sg_cachepress_purge_everything' ) ? sg_cachepress_purge_everything() : sg_cachepress_purge_cache(),
				static function ( array $urls ) {
					foreach ( $urls as $url ) {
						function_exists( 'sg_cachepress_purge_cache' ) ? sg_cachepress_purge_cache( $url ) : sg_cachepress_purge_everything();
					}
				}
			),
			new CallbackLayer(
				'wp-engine',
				'WP Engine',
				Tier::HOST,
				static fn() => class_exists( 'WpeCommon' ),
				static function () {
					if ( ! method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
						throw new \RuntimeException( 'WpeCommon::purge_varnish_cache() not available' );
					}
					if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
						\WpeCommon::purge_memcached();
					}
					\WpeCommon::purge_varnish_cache();
				}
			),
			new CallbackLayer(
				'kinsta',
				'Kinsta',
				Tier::HOST,
				static fn() => class_exists( '\Kinsta\Cache' ),
				static function () {
					global $kinsta_cache;
					if ( ! is_object( $kinsta_cache ) || ! isset( $kinsta_cache->kinsta_cache_purge ) || ! method_exists( $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' ) ) {
						throw new \RuntimeException( 'Kinsta cache API not available' );
					}
					$kinsta_cache->kinsta_cache_purge->purge_complete_caches();
				}
			),
		);
	}
}
