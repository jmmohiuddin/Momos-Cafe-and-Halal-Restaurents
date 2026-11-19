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
# Operations scripts (docs/operations/backup-restore.md) ship with the artefact.
mkdir -p "$OUT/ops" && cp "$ROOT/bin/backup.sh" "$ROOT/bin/restore.sh" "$OUT/ops/" && chmod 755 "$OUT/ops/"*.sh
# Web-server deny rules (SEC-042, spec §9.3): root rules go above "# BEGIN WordPress" in /.htaccess (or the
# nginx include); the uploads rules are installed as wp-content/uploads/.htaccess (no PHP execution there).
cp -R "$ROOT/config/server" "$OUT/server"
# HTTPS + canonical-host 301 rules (FR-FND-004, TC-SEC-005): fill in the canonical host (= the siteurl host,
# e.g. MOMOS_CANONICAL_HOST=example.com) and its aliases (default www.<host>); see config/server/README.md.
if [ -n "${MOMOS_CANONICAL_HOST:-}" ]; then
	if ! printf '%s' "$MOMOS_CANONICAL_HOST" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'; then
		echo "MOMOS_CANONICAL_HOST must be a lowercase host name (no scheme, port or path): $MOMOS_CANONICAL_HOST" >&2
		exit 1
	fi
	ALIASES="${MOMOS_ALIAS_HOSTS:-www.$MOMOS_CANONICAL_HOST}"
	if ! printf '%s' "$ALIASES" | grep -Eq '^[a-z0-9.-]+( [a-z0-9.-]+)*$'; then
		echo "MOMOS_ALIAS_HOSTS must be space-separated lowercase host names: $ALIASES" >&2
		exit 1
	fi
	for f in "$OUT"/server/*.conf; do
		sed -i -e "s/__MOMOS_CANONICAL_HOST__/$MOMOS_CANONICAL_HOST/g" -e "s/__MOMOS_ALIAS_HOSTS__/$ALIASES/g" "$f"
	done
	if grep -l '__MOMOS_' "$OUT"/server/*.conf >/dev/null; then
		echo "Unfilled placeholder in server/*.conf" >&2
		exit 1
	fi
else
	echo "warning: MOMOS_CANONICAL_HOST not set — server/*.conf keep the __MOMOS_CANONICAL_HOST__ placeholder; fill it in before installing (FR-FND-004)." >&2
fi
mkdir -p "$OUT/wp-content/uploads"
cp "$ROOT/config/server/htaccess-uploads.conf" "$OUT/wp-content/uploads/.htaccess"
find "$OUT" -name "*.php" -print0 | xargs -0 -n 50 -P 4 php -l >/dev/null
( cd "$ROOT/build" && rm -f "momos-$VERSION.zip" && zip -qr "momos-$VERSION.zip" "momos-$VERSION" && sha256sum "momos-$VERSION.zip" > "momos-$VERSION.zip.sha256" )
echo "$ROOT/build/momos-$VERSION.zip"
