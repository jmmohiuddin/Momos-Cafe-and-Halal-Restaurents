<?php
/**
 * Plugin Name: Momo's loader
 * Description: Loads momos-core early (must-use) so business logic is always active and cannot be deactivated from the dashboard.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$momos_core = WP_CONTENT_DIR . '/plugins/momos-core/momos-core.php';
// Skip while WordPress itself is not installed yet (fresh install / CLI install).
if ( is_readable( $momos_core ) && ( wp_installing() || false !== get_option( 'siteurl' ) ) ) {
	require_once $momos_core;
}
