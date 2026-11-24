<?php
/**
 * Plugin Name:       PurgeKit
 * Description:       One control centre for every cache layer on your site. Detects changes, purges your cache plugin, host, CDN and Varnish in the right order, and warms the cache afterwards.
 * Plugin URI:        https://github.com/Open-WP-Club/PurgeKit
 * Author:            Open WP Club
 * Author URI:        https://openwpclub.com
 * Version:           0.1.4
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       purgekit
 *
 * @package PurgeKit
 */

defined( 'ABSPATH' ) || exit;

define( 'PURGEKIT_VERSION', '0.1.4' );
define( 'PURGEKIT_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';

PurgeKit\Plugin::boot();
