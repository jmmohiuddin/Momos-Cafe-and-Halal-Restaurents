<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library with momos-core (mu-plugin) and the momos theme.
 */
$root = dirname( __DIR__, 2 );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
require_once $root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
$tests_dir = $root . '/vendor/wp-phpunit/wp-phpunit';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function () use ( $root ): void {
	require_once $root . '/wp-content/plugins/momos-core/momos-core.php';
} );
tests_add_filter( 'setup_theme', static function () use ( $root ): void {
	register_theme_directory( $root . '/wp-content/themes' );
	switch_theme( 'momos' );
} );

require $tests_dir . '/includes/bootstrap.php';

// Ensure schema exists in the test database.
\Momos\Kernel\Migration\Runner::run( true );
require_once __DIR__ . '/TestCase.php';
