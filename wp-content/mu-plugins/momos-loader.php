<?php
/**
 * Plugin Name: Momo's loader
 * Description: Loads momos-core early (must-use) so business logic is always active and cannot be deactivated from the dashboard.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$momos_core = WP_CONTENT_DIR . '/plugins/momos-core/momos-core.php';
// Skip while WordPress itself is not installed yet (fresh install / CLI install).
if ( is_readable( $momos_core ) && ! wp_installing() && false !== get_option( 'siteurl' ) ) {
	// Development/CI link the plugin into .wp/ with a symlink: register the real path so plugin_basename(),
	// plugins_url() (MOMOS_CORE_URL) and load_plugin_textdomain() resolve to momos-core/… (no-op in production).
	wp_register_plugin_realpath( $momos_core );
	require_once $momos_core;
}
