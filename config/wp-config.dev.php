<?php
/**
 * Development / CI wp-config. Production uses config/wp-config.production.sample.php with secrets
 * from the host environment (never committed).
 */
define( 'DB_NAME', getenv( 'MOMOS_DB_NAME' ) ?: 'momos_dev' );
define( 'DB_USER', getenv( 'MOMOS_DB_USER' ) ?: 'momos' );
define( 'DB_PASSWORD', getenv( 'MOMOS_DB_PASSWORD' ) ?: 'momos_dev_pw' );
define( 'DB_HOST', getenv( 'MOMOS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'dev-only-auth-key-change-me' );
define( 'SECURE_AUTH_KEY', 'dev-only-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'dev-only-logged-in-key' );
define( 'NONCE_KEY', 'dev-only-nonce-key' );
define( 'AUTH_SALT', 'dev-only-auth-salt' );
define( 'SECURE_AUTH_SALT', 'dev-only-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'dev-only-logged-in-salt' );
define( 'NONCE_SALT', 'dev-only-nonce-salt' );

$table_prefix = 'wp_';

define( 'WP_ENVIRONMENT_TYPE', 'development' );
define( 'MOMOS_ENV', 'development' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', getenv( 'MOMOS_DEBUG_LOG' ) ?: true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISALLOW_FILE_EDIT', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'MOMOS_MAIL_TRANSPORT', 'log' ); // Development: emails are written to the notification log + mail catcher file, never sent.
define( 'MOMOS_MAIL_CATCHER_DIR', __DIR__ . '/wp-content/mail-catcher' );
define( 'MOMOS_TURNSTILE_MODE', 'test' ); // test = always pass; production requires real keys.
define( 'MOMOS_2FA_ENFORCE', getenv( 'MOMOS_2FA_ENFORCE' ) === '1' );
if ( getenv( 'MOMOS_FROZEN_NOW' ) ) {
	define( 'MOMOS_FROZEN_NOW', getenv( 'MOMOS_FROZEN_NOW' ) );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
