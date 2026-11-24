<?php
// phpcs:ignoreFile -- a stand-in for a third-party class that lives in the global namespace.

class autoptimizeCache {
	public static int $cleared = 0;

	public static function clearall(): void {
		++self::$cleared;
	}
}
