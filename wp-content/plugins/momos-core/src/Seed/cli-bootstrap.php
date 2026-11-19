<?php
/**
 * WP-CLI bootstrap for the seed module while it is not yet listed in Kernel\Plugin::MODULES:
 *   wp --require=wp-content/plugins/momos-core/src/Seed/cli-bootstrap.php momos seed --demo
 * Adds Momos\Seed\SeedModule through the documented `momos/modules` filter (before WordPress loads,
 * via WP_CLI::add_wp_hook). Harmless when the module is already registered by the kernel.
 *
 * @package momos-core
 */

if ( ! defined( 'WP_CLI' ) || ! class_exists( 'WP_CLI' ) ) {
	return;
}

WP_CLI::add_wp_hook(
	'momos/modules',
	static function ( $modules ) {
		$class = 'Momos\\Seed\\SeedModule';
		if ( is_array( $modules ) && ! isset( $modules[ $class ] ) && class_exists( $class ) ) {
			$modules[ $class ] = new $class();
		}
		return $modules;
	}
);
