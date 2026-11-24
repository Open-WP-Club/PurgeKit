<?php
// phpcs:ignoreFile -- stand-ins for third-party classes that live in their own (often global) namespaces.

namespace {
	class Cache_Enabler {}

	class Breeze_PurgeCache {
		public static array $calls = array();
		public static function breeze_cache_flush( $flush_cache = true, $clear_ocp = true, $purge_all_html_folder = false ): void {
			self::$calls[] = array( $flush_cache, $clear_ocp, $purge_all_html_folder );
		}
	}

	class Swift_Performance_Cache {
		public static array $calls = array();
		public static function clear_all_cache(): void {
			self::$calls[] = array( 'all' );
		}
		public static function clear_permalink_cache( $permalink ): void {
			self::$calls[] = array( 'url', $permalink );
		}
	}

	class WPO_Page_Cache {
		public static array $calls = array();
		private static ?WPO_Page_Cache $instance = null;
		public static function instance(): self {
			return self::$instance ??= new self();
		}
		public function purge(): bool {
			self::$calls[] = array( 'purge' );
			return true;
		}
		public static function delete_cache_by_url( $url, $recursive = false ): bool {
			self::$calls[] = array( 'url', $url );
			return true;
		}
	}

	class WP_Optimize {
		public static function instance(): self {
			return new self();
		}
		public function get_page_cache(): WPO_Page_Cache {
			return WPO_Page_Cache::instance();
		}
	}

	if ( ! defined( 'WPO_VERSION' ) ) {
		define( 'WPO_VERSION', '4.7.1' );
	}
	if ( ! defined( 'BREEZE_VERSION' ) ) {
		define( 'BREEZE_VERSION', '2.6.5' );
	}
}

namespace FlyingPress {
	class Purge {
		public static array $calls = array();
		public static function purge_everything(): void {
			self::$calls[] = array( 'everything' );
		}
		public static function purge_urls( array $urls ): void {
			self::$calls[] = array( 'urls', $urls );
		}
	}
}
