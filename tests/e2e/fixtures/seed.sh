#!/usr/bin/env bash
# Reset an E2E database and load the fictional demo content (wp momos seed, DEC-047). Development/CI only:
# the seed command itself refuses to run in production.
#
# Usage:
#   MOMOS_DB_NAME=momos_e2e MOMOS_PORT=8190 tests/e2e/fixtures/seed.sh [--fresh] [--no-photos]
#     --fresh      drop and recreate the database first (full reinstall), otherwise only the sample
#                  content is removed (wp momos seed --reset) and seeded again
#     --no-photos  generate placeholder images instead of importing the local UI-baseline photos
# Then: MOMOS_DB_NAME=… MOMOS_PORT=… MOMOS_URL=… bin/serve.sh  and
#       MOMOS_E2E_BASE_URL=$MOMOS_URL npx playwright test --trace off   (in tests/e2e)
# Demo staff logins: demo_ho, demo_mm, demo_ce, demo_tr, demo_bm_kitahama, demo_rs_kitahama, demo_bm_namba,
# demo_rs_namba — development password Momos-Dev-2026!
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
export MOMOS_DB_NAME="${MOMOS_DB_NAME:-momos_e2e}"
export MOMOS_PORT="${MOMOS_PORT:-8190}"
export MOMOS_URL="${MOMOS_URL:-http://127.0.0.1:$MOMOS_PORT}"

FRESH=0
SEED_ARGS=()
for a in "$@"; do
  case "$a" in
    --fresh) FRESH=1 ;;
    --no-photos) SEED_ARGS+=(--no-photos) ;;
    *) echo "Unknown option: $a" >&2; exit 2 ;;
  esac
done

# Guard: only throw-away databases are reset here.
case "$MOMOS_DB_NAME" in
  *e2e*|*test*|*seed*|*qa*) ;;
  *)
    if [ "${MOMOS_SEED_ALLOW_ANY_DB:-0}" != 1 ]; then
      echo "Refusing to reset database '$MOMOS_DB_NAME' (name must contain e2e/test/seed/qa, or set MOMOS_SEED_ALLOW_ANY_DB=1)." >&2
      exit 1
    fi
    ;;
esac

if [ "$FRESH" = 1 ]; then
  "$ROOT/bin/dev-setup.sh" --reset --no-seed
else
  "$ROOT/bin/dev-setup.sh" --no-seed
fi

# The bootstrap registers the seed module through the `momos/modules` filter when the kernel does not list it yet.
WP=("$ROOT/bin/wp" "--require=$ROOT/wp-content/plugins/momos-core/src/Seed/cli-bootstrap.php")
# Deterministic demo dates: the seed places closures, special hours and reservations relative to "now", and the
# E2E specs assert those dates. Seed against a fixed JST reference date (override with MOMOS_SEED_NOW, or set
# MOMOS_SEED_NOW=real to seed against the real clock). The frozen-clock option is removed again afterwards.
SEED_NOW="${MOMOS_SEED_NOW:-2026-11-19T09:00:00+09:00}"
if [ "$SEED_NOW" != "real" ]; then "$ROOT/bin/wp" option update momos_frozen_now "$SEED_NOW" >/dev/null; fi
trap '"$ROOT/bin/wp" option delete momos_frozen_now >/dev/null 2>&1 || true' EXIT
"${WP[@]}" momos seed --reset --demo ${SEED_ARGS[@]+"${SEED_ARGS[@]}"}
"$ROOT/bin/wp" option delete momos_frozen_now >/dev/null 2>&1 || true
"$ROOT/bin/wp" rewrite flush >/dev/null
echo "E2E database '$MOMOS_DB_NAME' seeded. Base URL: $MOMOS_URL"
