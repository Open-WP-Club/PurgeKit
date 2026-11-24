<?php
/**
 * Removes PurgeKit data when the plugin is deleted.
 *
 * @package PurgeKit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/vendor/autoload.php';

( new PurgeKit\Uninstaller() )->run();
