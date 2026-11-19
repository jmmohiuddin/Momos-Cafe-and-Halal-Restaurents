#!/usr/bin/env bash
# Backup (DEC-033): database dump + uploads + private evidence store, timestamped, checksummed.
# Production: run daily (full) and every 4 h (DB only, --db-only) from server cron; copy the archive
# off-site to Momo's-owned storage (MOMOS_BACKUP_REMOTE, e.g. an rclone remote). Keep 30 days.
# Runbook: docs/operations/backup-restore.md. Exits non-zero on any failure (cron mails the error).
# Usage: bin/backup.sh [--db-only] [--dest DIR]
# Environment: MOMOS_WP_PATH (WordPress root), MOMOS_WPCLI (e.g. "wp --path=/var/www/html"), MOMOS_BACKUP_DIR,
#   MOMOS_EVIDENCE_DIR (default: asked from WordPress), MOMOS_BACKUP_GPG_RECIPIENT, MOMOS_BACKUP_REMOTE,
#   MOMOS_BACKUP_RETENTION_DAYS (30).
set -euo pipefail
umask 077 # The dump contains personal data: files readable by the backup user only.
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_PATH="${MOMOS_WP_PATH:-$ROOT/.wp}"
DEST="${MOMOS_BACKUP_DIR:-$ROOT/backups}"
DB_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --db-only) DB_ONLY=1 ;;
    --dest) DEST="${2:?--dest needs a directory}"; shift ;;
    *) echo "Unknown option: $1 (usage: bin/backup.sh [--db-only] [--dest DIR])" >&2; exit 2 ;;
  esac
  shift
done
WPCLI="${MOMOS_WPCLI:-$ROOT/bin/wp}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$DEST/$STAMP"
mkdir -p "$OUT"; chmod 700 "$DEST" "$OUT"
# Consistent InnoDB dump without locking the site.
$WPCLI db export "$OUT/database.sql" --single-transaction --quick --default-character-set=utf8mb4 >/dev/null
gzip -9 "$OUT/database.sql"
if [ "$DB_ONLY" = 0 ]; then
  if [ -d "$WP_PATH/wp-content/uploads" ]; then
    tar -czf "$OUT/uploads.tar.gz" -C "$WP_PATH/wp-content" uploads
  else
    echo "warning: no uploads directory at $WP_PATH/wp-content/uploads" >&2
  fi
  # Same resolution as Dietary\Storage\EvidenceStore::dir(): constant/env, else {ABSPATH}/../momos-private/evidence.
  EVIDENCE="${MOMOS_EVIDENCE_DIR:-$($WPCLI eval 'echo class_exists( "\Momos\Dietary\Storage\EvidenceStore" ) ? \Momos\Dietary\Storage\EvidenceStore::dir() : "";' 2>/dev/null || true)}"
  EVIDENCE="${EVIDENCE:-$(dirname "$WP_PATH")/momos-private/evidence}"
  if [ -d "$EVIDENCE" ]; then
    tar -czf "$OUT/evidence.tar.gz" -C "$(dirname "$EVIDENCE")" "$(basename "$EVIDENCE")"
  else
    echo "note: no evidence directory at $EVIDENCE (nothing uploaded yet?)" >&2
  fi
fi
printf 'created_utc=%s\nkind=%s\nmomos_core_version=%s\n' "$STAMP" "$([ "$DB_ONLY" = 1 ] && echo db-only || echo full)" \
  "$($WPCLI eval 'echo defined( "MOMOS_CORE_VERSION" ) ? MOMOS_CORE_VERSION : "unknown";' 2>/dev/null || echo unknown)" > "$OUT/MANIFEST"
( cd "$OUT" && sha256sum -- * > SHA256SUMS )
# Optional encryption at rest (recommended for off-site copies): MOMOS_BACKUP_GPG_RECIPIENT.
if [ -n "${MOMOS_BACKUP_GPG_RECIPIENT:-}" ]; then
  tar -cf - -C "$DEST" "$STAMP" | gpg --batch --yes --trust-model always -e -r "$MOMOS_BACKUP_GPG_RECIPIENT" -o "$DEST/$STAMP.tar.gpg"
  rm -rf "$OUT"
  OUT="$DEST/$STAMP.tar.gpg"
fi
if [ -n "${MOMOS_BACKUP_REMOTE:-}" ]; then
  # A configured but impossible off-site copy is a failed backup (alert), never a silent skip.
  command -v rclone >/dev/null || { echo "MOMOS_BACKUP_REMOTE is set but rclone is not installed: off-site copy NOT made ($OUT)" >&2; exit 1; }
  rclone copy "$DEST" "$MOMOS_BACKUP_REMOTE" --include "$STAMP/**" --include "$STAMP.tar.gpg"
fi
# Rolling retention.
find "$DEST" -mindepth 1 -maxdepth 1 -mtime +"${MOMOS_BACKUP_RETENTION_DAYS:-30}" -exec rm -rf {} +
echo "$OUT"
