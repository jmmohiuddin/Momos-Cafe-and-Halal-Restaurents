<?php
/**
 * Plugin Name: momos-core
 * Description: Business logic for Momo's Cafe & Halal Restaurant — branches, menu, dietary claims, reservations, notifications, access, audit, API, presentation and privacy.
 * Version: 1.0.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Nimikh for Momo's Cafe & Halal Restaurant
 * Text Domain: momos-core
 * License: Proprietary
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'MOMOS_CORE_VERSION' ) ) {
	return; // Loaded already (mu-plugin loader + regular plugin activation).
}

define( 'MOMOS_CORE_VERSION', '1.0.0' );
define( 'MOMOS_CORE_FILE', __FILE__ );
define( 'MOMOS_CORE_DIR', __DIR__ );
define( 'MOMOS_CORE_URL', plugins_url( '', __FILE__ ) );

if ( PHP_VERSION_ID < 80300 ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>momos-core requires PHP 8.3 or later.</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
require_once __DIR__ . '/src/functions.php';

\Momos\Kernel\Plugin::instance()->boot();
