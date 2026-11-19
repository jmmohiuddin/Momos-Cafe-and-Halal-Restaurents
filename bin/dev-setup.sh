#!/usr/bin/env bash
# Creates a local development WordPress in .wp/ wired to the repository code, installs it and seeds demo data.
# Usage: bin/dev-setup.sh [--reset] [--no-seed]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP="$ROOT/.wp"
PORT="${MOMOS_PORT:-8080}"
URL="${MOMOS_URL:-http://127.0.0.1:$PORT}"
DB="${MOMOS_DB_NAME:-momos_dev}"
RESET=0; SEED=1
for a in "$@"; do case "$a" in --reset) RESET=1;; --no-seed) SEED=0;; esac; done

# 1. Database server
if ! mysqladmin ping --silent 2>/dev/null; then
  mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld 2>/dev/null || true
  (mysqld_safe --user=mysql >/dev/null 2>&1 &)
  for i in $(seq 1 30); do mysqladmin ping --silent 2>/dev/null && break; sleep 1; done
fi
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci; CREATE USER IF NOT EXISTS 'momos'@'127.0.0.1' IDENTIFIED BY 'momos_dev_pw'; CREATE USER IF NOT EXISTS 'momos'@'localhost' IDENTIFIED BY 'momos_dev_pw'; GRANT ALL ON \`$DB\`.* TO 'momos'@'127.0.0.1'; GRANT ALL ON \`$DB\`.* TO 'momos'@'localhost'; FLUSH PRIVILEGES;"
if [ "$RESET" = 1 ]; then mysql -uroot -e "DROP DATABASE IF EXISTS \`$DB\`; CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;"; fi

# 2. Dependencies
[ -d "$ROOT/vendor" ] || ( cd "$ROOT" && composer install -q --no-interaction )
[ -d "$ROOT/wp-content/plugins/momos-core/vendor" ] || ( cd "$ROOT/wp-content/plugins/momos-core" && composer install -q --no-interaction )

# 3. WordPress core + links to repository code
mkdir -p "$WP"
if ! cmp -s "$ROOT/vendor/johnpbloch/wordpress-core/wp-includes/version.php" "$WP/wp-includes/version.php" 2>/dev/null; then
  rsync -a --delete --exclude wp-content --exclude wp-config.php "$ROOT/vendor/johnpbloch/wordpress-core/" "$WP/"
fi
mkdir -p "$WP/wp-content/plugins" "$WP/wp-content/themes" "$WP/wp-content/uploads" "$WP/wp-content/mail-catcher"
ln -sfn "$ROOT/wp-content/plugins/momos-core" "$WP/wp-content/plugins/momos-core"
ln -sfn "$ROOT/wp-content/themes/momos" "$WP/wp-content/themes/momos"
[ -f "$WP/wp-config.php" ] && cmp -s "$ROOT/config/wp-config.dev.php" "$WP/wp-config.php" || cp "$ROOT/config/wp-config.dev.php" "$WP/wp-config.php"

WPCLI="php -d memory_limit=512M $ROOT/vendor/wp-cli/wp-cli/php/boot-fs.php --allow-root --path=$WP"
if ! $WPCLI core is-installed 2>/dev/null; then
  $WPCLI core install --url="$URL" --title="Momo's Cafe & Halal Restaurant" --admin_user=superadmin --admin_password="${MOMOS_ADMIN_PASSWORD:-Momos-Dev-2026!}" --admin_email=admin@example.test --skip-email
fi
ln -sfn "$ROOT/wp-content/mu-plugins" "$WP/wp-content/mu-plugins"
$WPCLI option update siteurl "$URL" >/dev/null; $WPCLI option update home "$URL" >/dev/null
$WPCLI option update timezone_string 'Asia/Tokyo' >/dev/null
$WPCLI option update date_format 'Y-m-d' >/dev/null
$WPCLI option update time_format 'H:i' >/dev/null
$WPCLI rewrite structure '/news/%postname%/' >/dev/null
$WPCLI theme activate momos >/dev/null 2>&1 || true
$WPCLI momos migrate
# Japanese core language pack for the bilingual admin (needs downloads.wordpress.org; skipped when offline).
$WPCLI language core install ja >/dev/null 2>&1 || echo "note: WordPress ja language pack not installed (offline?) — core admin menus stay English"
if [ "$SEED" = 1 ]; then $WPCLI momos seed --demo; fi
$WPCLI rewrite flush >/dev/null
echo "Ready: $URL  (admin: $URL/wp-admin  user superadmin)"
echo "Start the server with: bin/serve.sh"
