<?php
// Minimal stand-in for WP-CLI so the command class can be exercised under PHPUnit.
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static array $lines = array();
		public static ?int $halted = null;

		public static function reset(): void {
			self::$lines  = array();
			self::$halted = null;
		}
		public static function line( $m = '' ): void {
			self::$lines[] = (string) $m;
		}
		public static function success( $m ): void {
			self::$lines[] = 'Success: ' . $m;
		}
		public static function warning( $m ): void {
			self::$lines[] = 'Warning: ' . $m;
		}
		public static function error( $m ): void {
			self::$lines[] = 'Error: ' . $m;
			self::$halted  = 1;
			throw new \RuntimeException( 'WP_CLI::error: ' . $m );
		}
		public static function halt( $code ): void {
			self::$halted = (int) $code;
		}
		public static function add_command( $n, $c ): void {}
	}
}
