#!/usr/bin/env bash
# Restore a backup made by bin/backup.sh (verify checksums first). Destructive for the target DB:
# requires --yes. Run the restore test on staging before launch and quarterly (DEC-033, GATE-07).
# Usage: bin/restore.sh BACKUP_DIR --yes
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:?backup directory}"; CONFIRM="${2:-}"
[ "$CONFIRM" = "--yes" ] || { echo "Refusing to restore without --yes"; exit 2; }
WP_PATH="${MOMOS_WP_PATH:-$ROOT/.wp}"
WPCLI="${MOMOS_WPCLI:-$ROOT/bin/wp}"
( cd "$SRC" && sha256sum -c SHA256SUMS )
gunzip -c "$SRC/database.sql.gz" > /tmp/momos-restore.sql
$WPCLI db import /tmp/momos-restore.sql >/dev/null && rm -f /tmp/momos-restore.sql
[ -f "$SRC/uploads.tar.gz" ] && tar -xzf "$SRC/uploads.tar.gz" -C "$WP_PATH/wp-content"
if [ -f "$SRC/evidence.tar.gz" ]; then
  EVIDENCE="${MOMOS_EVIDENCE_DIR:-$WP_PATH/wp-content/momos-private}"
  tar -xzf "$SRC/evidence.tar.gz" -C "$(dirname "$EVIDENCE")"
fi
$WPCLI momos migrate
$WPCLI cache flush >/dev/null || true
echo "Restored from $SRC"
