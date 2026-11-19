<?php
/**
 * PHPStan bootstrap: constants that momos-core defines at runtime (momos-core.php) or that come from
 * wp-config.php. Values are placeholders for type inference only; nothing here is executed by WordPress.
 * Optional deployment constants (MOMOS_SMTP_*, MOMOS_TOKEN_KEY_*, …) are read through defined()/constant()
 * in Kernel\Env and need no stub.
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . '/.wp/' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', dirname( __DIR__, 2 ) . '/wp-content' );
defined( 'MOMOS_CORE_VERSION' ) || define( 'MOMOS_CORE_VERSION', '1.0.0' );
defined( 'MOMOS_CORE_FILE' ) || define( 'MOMOS_CORE_FILE', dirname( __DIR__, 2 ) . '/wp-content/plugins/momos-core/momos-core.php' );
defined( 'MOMOS_CORE_DIR' ) || define( 'MOMOS_CORE_DIR', dirname( __DIR__, 2 ) . '/wp-content/plugins/momos-core' );
defined( 'MOMOS_CORE_URL' ) || define( 'MOMOS_CORE_URL', 'https://example.test/wp-content/plugins/momos-core' );

// WordPress runtime constants (wp_cookie_constants() / wp-config.php) that the stubs do not declare.
foreach ( [ 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE', 'COOKIEPATH', 'SITECOOKIEPATH', 'PLUGINS_COOKIE_PATH', 'ADMIN_COOKIE_PATH', 'DB_NAME' ] as $momos_stan_const ) {
	defined( $momos_stan_const ) || define( $momos_stan_const, '' );
}
