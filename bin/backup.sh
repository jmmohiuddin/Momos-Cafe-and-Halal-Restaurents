#!/usr/bin/env bash
# Backup (DEC-033): database dump + uploads + private evidence store, timestamped, checksummed.
# Production: run daily (full) and every 4 h (DB only, --db-only) from server cron; copy the archive
# off-site to Momo's-owned storage (MOMOS_BACKUP_REMOTE, e.g. an rclone remote). Keep 30 days.
# Usage: bin/backup.sh [--db-only] [--dest DIR]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_PATH="${MOMOS_WP_PATH:-$ROOT/.wp}"
DEST="${MOMOS_BACKUP_DIR:-$ROOT/backups}"
DB_ONLY=0
while [ $# -gt 0 ]; do case "$1" in --db-only) DB_ONLY=1;; --dest) DEST="$2"; shift;; esac; shift; done
WPCLI="${MOMOS_WPCLI:-$ROOT/bin/wp}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$DEST/$STAMP"
mkdir -p "$OUT"; chmod 700 "$DEST" "$OUT"
# Consistent InnoDB dump without locking the site.
$WPCLI db export "$OUT/database.sql" --single-transaction --quick --default-character-set=utf8mb4 >/dev/null
gzip -9 "$OUT/database.sql"
if [ "$DB_ONLY" = 0 ]; then
  tar -czf "$OUT/uploads.tar.gz" -C "$WP_PATH/wp-content" uploads 2>/dev/null || true
  EVIDENCE="${MOMOS_EVIDENCE_DIR:-$WP_PATH/wp-content/momos-private}"
  [ -d "$EVIDENCE" ] && tar -czf "$OUT/evidence.tar.gz" -C "$(dirname "$EVIDENCE")" "$(basename "$EVIDENCE")"
fi
( cd "$OUT" && sha256sum * > SHA256SUMS )
# Optional encryption at rest (recommended for off-site copies): MOMOS_BACKUP_GPG_RECIPIENT.
if [ -n "${MOMOS_BACKUP_GPG_RECIPIENT:-}" ]; then
  tar -cf - -C "$DEST" "$STAMP" | gpg --batch --yes -e -r "$MOMOS_BACKUP_GPG_RECIPIENT" -o "$DEST/$STAMP.tar.gpg"
  rm -rf "$OUT"
fi
if [ -n "${MOMOS_BACKUP_REMOTE:-}" ] && command -v rclone >/dev/null; then
  rclone copy "$DEST" "$MOMOS_BACKUP_REMOTE" --include "$STAMP*/**" --include "$STAMP*.tar.gpg"
fi
# Rolling retention.
find "$DEST" -mindepth 1 -maxdepth 1 -mtime +"${MOMOS_BACKUP_RETENTION_DAYS:-30}" -exec rm -rf {} +
echo "$OUT"
