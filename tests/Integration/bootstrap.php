<?php
$purgekit_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';
defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) || define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
require_once $purgekit_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/purgekit.php';
	}
);

require $purgekit_tests_dir . '/includes/bootstrap.php';
