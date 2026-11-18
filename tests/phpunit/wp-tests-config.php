<?php
// WordPress test-suite configuration (database `momos_test` is DROPPED and recreated by the suite).
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/.wp/' );
define( 'WP_DEFAULT_THEME', 'momos' );
define( 'WP_TESTS_MULTISITE', false );
define( 'WP_DEBUG', true );
define( 'DB_NAME', getenv( 'MOMOS_TEST_DB_NAME' ) ?: 'momos_test' );
define( 'DB_USER', 'momos' );
define( 'DB_PASSWORD', 'momos_dev_pw' );
define( 'DB_HOST', '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Momos Test' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'MOMOS_ENV', 'development' );
define( 'MOMOS_LOG_LEVEL', 'warning' );
define( 'MOMOS_MAIL_TRANSPORT', 'log' );
define( 'MOMOS_TURNSTILE_MODE', 'test' );
define( 'MOMOS_TOKEN_KEY_V1', 'test-token-key-v1-0123456789abcdef' );
define( 'MOMOS_HMAC_KEY', 'test-hmac-key-0123456789abcdef' );
