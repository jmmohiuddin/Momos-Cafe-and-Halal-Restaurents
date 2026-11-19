#!/usr/bin/env bash
# Builds the deployable artefact build/momos-<version>.zip containing mu-plugins, momos-core (with
# production Composer dependencies) and the momos theme. Deploy by tag through staging (TRD §6.7).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="${1:-$(grep "define( 'MOMOS_CORE_VERSION'" "$ROOT/wp-content/plugins/momos-core/momos-core.php" | sed -E "s/.*'([0-9.]+)'.*/\1/")}"
OUT="$ROOT/build/momos-$VERSION"
rm -rf "$OUT" && mkdir -p "$OUT/wp-content/plugins" "$OUT/wp-content/themes"
cp -R "$ROOT/wp-content/mu-plugins" "$OUT/wp-content/"
rsync -a --exclude vendor --exclude tests "$ROOT/wp-content/plugins/momos-core" "$OUT/wp-content/plugins/"
( cd "$OUT/wp-content/plugins/momos-core" && composer install -q --no-dev --optimize-autoloader --no-interaction )
rsync -a --exclude node_modules "$ROOT/wp-content/themes/momos" "$OUT/wp-content/themes/"
# Static maintenance page (SCR-C20) as the WordPress drop-in, shown during `wp maintenance-mode activate`.
# Regenerate it first with `bin/wp momos maintenance-page` when brand/language settings change.
if [ -f "$ROOT/wp-content/themes/momos/static/maintenance.php" ]; then
	cp "$ROOT/wp-content/themes/momos/static/maintenance.php" "$OUT/wp-content/maintenance.php"
fi
cp "$ROOT/config/wp-config.production.sample.php" "$OUT/"
# Web-server deny rules (SEC-042, spec §9.3): root rules go above "# BEGIN WordPress" in /.htaccess (or the
# nginx include); the uploads rules are installed as wp-content/uploads/.htaccess (no PHP execution there).
cp -R "$ROOT/config/server" "$OUT/server"
mkdir -p "$OUT/wp-content/uploads"
cp "$ROOT/config/server/htaccess-uploads.conf" "$OUT/wp-content/uploads/.htaccess"
find "$OUT" -name "*.php" -print0 | xargs -0 -n 50 -P 4 php -l >/dev/null
( cd "$ROOT/build" && rm -f "momos-$VERSION.zip" && zip -qr "momos-$VERSION.zip" "momos-$VERSION" && sha256sum "momos-$VERSION.zip" > "momos-$VERSION.zip.sha256" )
echo "$ROOT/build/momos-$VERSION.zip"
