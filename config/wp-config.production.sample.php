<?php
/**
 * Production wp-config template (TRD §6.4, SDD §6). Copy to the server as wp-config.php and fill the
 * values from the host's secret store / environment. NEVER commit real values.
 */
define( 'DB_NAME', getenv( 'MOMOS_DB_NAME' ) );
define( 'DB_USER', getenv( 'MOMOS_DB_USER' ) );          // least-privilege user: SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, LOCK TABLES, REFERENCES (SDD §6.8)
define( 'DB_PASSWORD', getenv( 'MOMOS_DB_PASSWORD' ) );
define( 'DB_HOST', getenv( 'MOMOS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = getenv( 'MOMOS_TABLE_PREFIX' ) ?: 'mm7_';

// Unique keys and salts: https://api.wordpress.org/secret-key/1.1/salt/ (store in the host secret store).
define( 'AUTH_KEY', getenv( 'WP_AUTH_KEY' ) );
define( 'SECURE_AUTH_KEY', getenv( 'WP_SECURE_AUTH_KEY' ) );
define( 'LOGGED_IN_KEY', getenv( 'WP_LOGGED_IN_KEY' ) );
define( 'NONCE_KEY', getenv( 'WP_NONCE_KEY' ) );
define( 'AUTH_SALT', getenv( 'WP_AUTH_SALT' ) );
define( 'SECURE_AUTH_SALT', getenv( 'WP_SECURE_AUTH_SALT' ) );
define( 'LOGGED_IN_SALT', getenv( 'WP_LOGGED_IN_SALT' ) );
define( 'NONCE_SALT', getenv( 'WP_NONCE_SALT' ) );

// Environment and hardening (DEC-032, DEC-065).
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'MOMOS_ENV', 'production' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', getenv( 'MOMOS_DEBUG_LOG' ) ?: dirname( __DIR__ ) . '/momos-private/logs/debug.log' ); // outside the web root (spec §9.1)
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'DISALLOW_UNFILTERED_HTML', true ); // No unfiltered HTML for any role, ROLE-01 included (SEC-040, spec §9.1).
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'FORCE_SSL_ADMIN', true );
define( 'DISABLE_WP_CRON', true ); // Real cron: */1 * * * * wp action-scheduler run --group=momos && wp cron event run --due-now
define( 'WP_POST_REVISIONS', 20 );

// momos-core secrets (never stored in the database).
define( 'MOMOS_TOKEN_KEY_V1', getenv( 'MOMOS_TOKEN_KEY_V1' ) );   // status-link token key (DEC-064); rotate by adding _V2 and MOMOS_TOKEN_KEY_CURRENT=2
define( 'MOMOS_TOKEN_KEY_CURRENT', (int) ( getenv( 'MOMOS_TOKEN_KEY_CURRENT' ) ?: 1 ) );
define( 'MOMOS_HASH_PEPPER', getenv( 'MOMOS_HASH_PEPPER' ) );     // 32 random bytes: IP/UA/email/rate-limit hashing and audit row HMAC (DEC-059). Required: hashing fails closed without it.
// MOMOS_HMAC_KEY is the legacy alias of the pepper; define only one of them.
define( 'MOMOS_TURNSTILE_MODE', 'live' );
define( 'MOMOS_TURNSTILE_SITE_KEY', getenv( 'MOMOS_TURNSTILE_SITE_KEY' ) );
define( 'MOMOS_TURNSTILE_SECRET', getenv( 'MOMOS_TURNSTILE_SECRET' ) );
define( 'MOMOS_2FA_ENFORCE', true );

// Transactional email provider (DEC-018), e.g. Amazon SES ap-northeast-1 SMTP.
define( 'MOMOS_MAIL_TRANSPORT', 'smtp' );
define( 'MOMOS_SMTP_HOST', getenv( 'MOMOS_SMTP_HOST' ) );
define( 'MOMOS_SMTP_PORT', (int) ( getenv( 'MOMOS_SMTP_PORT' ) ?: 587 ) );
define( 'MOMOS_SMTP_SECURE', 'tls' );
define( 'MOMOS_SMTP_USER', getenv( 'MOMOS_SMTP_USER' ) );
define( 'MOMOS_SMTP_PASS', getenv( 'MOMOS_SMTP_PASS' ) );
define( 'MOMOS_SMTP_FROM', getenv( 'MOMOS_SMTP_FROM' ) );       // e.g. no-reply@momoscafehalal.com (SPF/DKIM/DMARC aligned)

// Private evidence store outside the web root (FR-DIET-001, SA-001 F098) — include in backups.
define( 'MOMOS_EVIDENCE_DIR', getenv( 'MOMOS_EVIDENCE_DIR' ) ?: dirname( __DIR__ ) . '/momos-private/evidence' );
// Real client IP header when behind the CDN (e.g. CF-Connecting-IP); leave unset when not behind a proxy.
// The header is honoured ONLY for requests whose REMOTE_ADDR is inside MOMOS_TRUSTED_PROXIES (the CDN's
// published ranges, e.g. https://www.cloudflare.com/ips/ — comma-separated CIDRs, keep in sync); otherwise
// REMOTE_ADDR is used (SDD §5.14, spec §7.6).
// define( 'MOMOS_TRUSTED_IP_HEADER', 'CF-Connecting-IP' );
// define( 'MOMOS_TRUSTED_PROXIES', getenv( 'MOMOS_TRUSTED_PROXIES' ) );
// Heartbeat (dead-man's switch) URL of the uptime monitor (NFR-OBS-01).
// define( 'MOMOS_HEARTBEAT_URL', getenv( 'MOMOS_HEARTBEAT_URL' ) );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
