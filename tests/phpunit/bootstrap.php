<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library with momos-core (mu-plugin) and the momos theme.
 */
$momos_root = dirname( __DIR__, 2 );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
require_once $momos_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
$momos_tests_dir = $momos_root . '/vendor/wp-phpunit/wp-phpunit';
require_once $momos_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $momos_root ): void {
		require_once $momos_root . '/wp-content/plugins/momos-core/momos-core.php';
	}
);
// Admin strings stay in English (msgids) in tests: the site locale is Japanese, and assertions are written
// against the English source. Tests that need the Japanese catalog set $GLOBALS['momos_tests_load_ja'] = true.
tests_add_filter(
	'override_load_textdomain',
	static function ( $override, $domain ) {
		return in_array( $domain, [ 'momos-core', 'momos' ], true ) && empty( $GLOBALS['momos_tests_load_ja'] ) ? true : $override;
	},
	10,
	2
);
tests_add_filter(
	'setup_theme',
	static function () use ( $momos_root ): void {
		register_theme_directory( $momos_root . '/wp-content/themes' );
		switch_theme( 'momos' );
	}
);

require $momos_tests_dir . '/includes/bootstrap.php';

// Ensure schema exists in the test database (waits for a concurrent run on the same database; the lock is
// scoped to this database, so other suites/sites on the server cannot make it skip). Fail fast otherwise.
\Momos\Kernel\Migration\Runner::run( true, 120 );
if ( \Momos\Kernel\Db\Tables::missing() || \Momos\Kernel\Migration\Runner::pending() ) {
	fwrite( STDERR, 'momos-core migrations did not complete: missing tables [' . implode( ', ', \Momos\Kernel\Db\Tables::missing() ) . '], pending [' . implode( ', ', \Momos\Kernel\Migration\Runner::pending() ) . "]\n" );
	exit( 1 );
}
require_once __DIR__ . '/TestCase.php';
