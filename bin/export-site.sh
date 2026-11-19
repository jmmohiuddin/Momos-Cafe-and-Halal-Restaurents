#!/usr/bin/env bash
# Export a self-contained STAGING/PREVIEW copy of the site for a WordPress importer (e.g. Hostinger
# "Import WordPress website": archive + SQL dump). Development/CI machines only.
#
# Usage:
#   MOMOS_EXPORT_URL=https://example.hostingersite.com bin/export-site.sh [--no-photos] [--out DIR]
#
# Produces in DIR (default build/export-<host>/):
#   site.zip         WordPress core + momos-core (production Composer deps) + momos theme + mu-loader + this
#                    site's media only, and a staging wp-config.php (random salts/secrets, noindex, mail never
#                    sent: log transport outside the web root, Turnstile test mode)
#   dump.sql         database of a freshly seeded demo site (fictional sample content, DEC-047) whose URLs are
#                    already the export URL
#   credentials.txt  random passwords of every account (mode 600) — hand to the owner privately, never commit
#
# The importer is expected to rewrite DB_NAME/DB_USER/DB_PASSWORD/DB_HOST in wp-config.php; if it does not,
# edit them after the import. Table prefix: wp_.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
URL="${MOMOS_EXPORT_URL:?Set MOMOS_EXPORT_URL, e.g. https://example.hostingersite.com}"
URL="${URL%/}"
if ! printf '%s' "$URL" | grep -Eq '^https?://[a-z0-9.-]+(:[0-9]+)?$'; then
	echo "MOMOS_EXPORT_URL must be scheme://host[:port] without a path: $URL" >&2
	exit 1
fi
HOST="$(printf '%s' "$URL" | sed -E 's#^https?://##; s#:.*##')"
PHOTOS=1
OUT="$ROOT/build/export-$HOST"
while [ $# -gt 0 ]; do
	case "$1" in
		--no-photos) PHOTOS=0 ;;
		--out) OUT="$2"; shift ;;
		*) echo "Unknown option: $1" >&2; exit 2 ;;
	esac
	shift
done

export MOMOS_DB_NAME="momos_seed_export"
export MOMOS_PORT="${MOMOS_EXPORT_PORT:-8197}"
export MOMOS_URL="http://127.0.0.1:$MOMOS_PORT"
WP="$ROOT/bin/wp"

echo "1/6 Seeding a fresh demo database ($MOMOS_DB_NAME) …"
SEED_ARGS=(--fresh)
[ "$PHOTOS" = 1 ] || SEED_ARGS+=(--no-photos)
MOMOS_SEED_NOW=real "$ROOT/tests/e2e/fixtures/seed.sh" "${SEED_ARGS[@]}" >/dev/null

echo "2/6 Preview settings: noindex, random account passwords …"
"$WP" option update blog_public 0 >/dev/null
rm -rf "$OUT" && mkdir -p "$OUT/site"
umask 077
{
	echo "Momo's preview site — $URL"
	echo "Generated $(date -u +%Y-%m-%dT%H:%MZ). Give to the owner privately; change the passwords after the first login."
	echo
} > "$OUT/credentials.txt"
for id in $("$WP" user list --field=ID); do
	login="$("$WP" user get "$id" --field=user_login)"
	pass="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | cut -c1-20)"
	# wp_set_password(): wp user update would re-validate the login (the dev "superadmin" is a disallowed name).
	"$WP" eval "wp_set_password( '$pass', $id );" >/dev/null
	printf '%-22s %s\n' "$login" "$pass" >> "$OUT/credentials.txt"
done
umask 022

echo "3/6 Database dump with the export URL …"
"$WP" search-replace "$MOMOS_URL" "$URL" --all-tables --precise --skip-columns=guid >/dev/null
"$WP" option update home "$URL" >/dev/null 2>&1 || true
"$WP" option update siteurl "$URL" >/dev/null 2>&1 || true
"$WP" db export "$OUT/dump.sql" --default-character-set=utf8mb4 >/dev/null
# Put the local URL back so the export database stays usable locally.
"$WP" search-replace "$URL" "$MOMOS_URL" --all-tables --precise --skip-columns=guid >/dev/null

echo "4/6 Files: WordPress core, plugin (production dependencies), theme, mu-loader …"
S="$OUT/site"
rsync -a --exclude .git --exclude wp-content --exclude wp-config.php --exclude composer.json "$ROOT/vendor/johnpbloch/wordpress-core/" "$S/"
mkdir -p "$S/wp-content/plugins" "$S/wp-content/themes" "$S/wp-content/uploads"
cp -R "$ROOT/wp-content/mu-plugins" "$S/wp-content/"
rsync -a --exclude .git --exclude vendor --exclude tests --exclude node_modules "$ROOT/wp-content/plugins/momos-core" "$S/wp-content/plugins/"
( cd "$S/wp-content/plugins/momos-core" && COMPOSER_ALLOW_SUPERUSER=1 composer install -q --no-dev --prefer-dist --optimize-autoloader --no-interaction )
rsync -a --exclude .git --exclude node_modules "$ROOT/wp-content/themes/momos" "$S/wp-content/themes/"
cp "$ROOT/config/server/htaccess-uploads.conf" "$S/wp-content/uploads/.htaccess"
cp "$ROOT/config/server/htaccess-deny-all.conf" "$S/wp-content/mu-plugins/.htaccess" 2>/dev/null || true

echo "5/6 Media of this site only …"
"$WP" eval '
	foreach ( get_posts( [ "post_type" => "attachment", "post_status" => "any", "posts_per_page" => -1, "fields" => "ids" ] ) as $id ) {
		$file = get_attached_file( $id );
		if ( ! $file ) { continue; }
		echo $file, "\n";
		$meta = wp_get_attachment_metadata( $id );
		foreach ( (array) ( $meta["sizes"] ?? [] ) as $size ) {
			echo dirname( $file ), "/", $size["file"], "\n";
		}
		if ( ! empty( $meta["original_image"] ) ) { echo dirname( $file ), "/", $meta["original_image"], "\n"; }
	}' | sort -u | while read -r f; do
	[ -f "$f" ] || continue
	rel="${f#*wp-content/uploads/}"
	mkdir -p "$S/wp-content/uploads/$(dirname "$rel")"
	cp "$f" "$S/wp-content/uploads/$rel"
	# WebP siblings generated by the theme (same basename).
	for w in "${f%.*}.webp" "$f.webp"; do [ -f "$w" ] && cp "$w" "$S/wp-content/uploads/$(dirname "$rel")/" || true; done
done

rnd() { head -c 48 /dev/urandom | base64 | tr -d '\n/+=' | cut -c1-64; }
cat > "$S/wp-config.php" <<PHP
<?php
/**
 * Momo's STAGING / PREVIEW configuration generated by bin/export-site.sh (do not use for production:
 * see config/wp-config.production.sample.php). The importer replaces the DB_* values below.
 */
define( 'DB_NAME', 'database_name_here' );
define( 'DB_USER', 'username_here' );
define( 'DB_PASSWORD', 'password_here' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wp_';

define( 'AUTH_KEY', '$(rnd)' );
define( 'SECURE_AUTH_KEY', '$(rnd)' );
define( 'LOGGED_IN_KEY', '$(rnd)' );
define( 'NONCE_KEY', '$(rnd)' );
define( 'AUTH_SALT', '$(rnd)' );
define( 'SECURE_AUTH_SALT', '$(rnd)' );
define( 'LOGGED_IN_SALT', '$(rnd)' );
define( 'NONCE_SALT', '$(rnd)' );

// Staging: every response is noindex (Seo/SecurityHeaders), robots.txt disallows all, mail subjects get [STAGING].
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
define( 'MOMOS_ENV', 'staging' );
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', dirname( __DIR__ ) . '/momos-private/logs/debug.log' );
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_UNFILTERED_HTML', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
define( 'FORCE_SSL_ADMIN', 0 === strpos( '$URL', 'https://' ) );

// Secrets for this preview only (random, never reused in production).
define( 'MOMOS_TOKEN_KEY_V1', '$(rnd)' );
define( 'MOMOS_TOKEN_KEY_CURRENT', 1 );
define( 'MOMOS_HASH_PEPPER', '$(rnd)' );
define( 'MOMOS_2FA_KEY', '$(head -c 32 /dev/urandom | base64 | tr -d '\n')' );

// Preview: no real email is ever sent (log transport; files kept outside the web root) and the
// reservation form uses the Turnstile test mode (no Cloudflare account needed).
define( 'MOMOS_MAIL_TRANSPORT', 'log' );
define( 'MOMOS_MAIL_CATCHER_DIR', dirname( __DIR__ ) . '/momos-private/mail' );
define( 'MOMOS_TURNSTILE_MODE', 'test' );
define( 'MOMOS_EVIDENCE_DIR', dirname( __DIR__ ) . '/momos-private/evidence' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
PHP
cat > "$S/.htaccess" <<'HT'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HT

echo "6/6 Archive …"
find "$S" -name .git -prune -exec rm -rf {} +
find "$S" -name '*.php' -print0 | xargs -0 -n 100 -P 4 php -l >/dev/null
( cd "$S" && rm -f ../site.zip && zip -qr ../site.zip . )
rm -rf "$S"
( cd "$OUT" && sha256sum site.zip dump.sql > SHA256SUMS )
ls -la "$OUT"
echo "Done. Upload site.zip + dump.sql to the importer; give credentials.txt to the owner privately."
