<?php
// phpcs:ignoreFile -- stand-ins for third-party code that lives in other namespaces.

namespace Perfmatters {
	class CSS {
		public static int $cleared = 0;
		public static function clear_used_css(): void {
			++self::$cleared;
		}
	}
	class Minify {
		public static array $cleared = array();
		public static function clear_minified( string $type ): void {
			self::$cleared[] = $type;
		}
	}
}

namespace {
	/** Oxygen's universal CSS function, as a third-party snippet calls it. */
	function oxygen_vsb_cache_universal_css() {
		++$GLOBALS['purgekit_oxygen_calls'];
		return $GLOBALS['purgekit_oxygen_result'] ?? true;
	}
}
