#!/usr/bin/env bash
# Restore a backup made by bin/backup.sh (checksums are verified first). Destructive for the target DB:
# requires --yes. Run the restore test on staging before launch and quarterly (DEC-033, GATE-07).
# Runbook: docs/operations/backup-restore.md (maintenance mode, cron, notification queue, verification).
# Usage: bin/restore.sh BACKUP_DIR|BACKUP.tar.gpg --yes
# Environment: MOMOS_WP_PATH (WordPress root), MOMOS_WPCLI (e.g. "wp --path=/var/www/html"),
#   MOMOS_EVIDENCE_DIR (default: asked from WordPress), MOMOS_RESTORE_SKIP_RETENTION=1 (forensic copies only).
set -euo pipefail
umask 077
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:?usage: bin/restore.sh BACKUP_DIR|BACKUP.tar.gpg --yes}"; CONFIRM="${2:-}"
[ "$CONFIRM" = "--yes" ] || { echo "Refusing to restore without --yes" >&2; exit 2; }
WP_PATH="${MOMOS_WP_PATH:-$ROOT/.wp}"
WPCLI="${MOMOS_WPCLI:-$ROOT/bin/wp}"
# Private scratch space for the decrypted archive and the uncompressed dump (personal data), always removed.
WORK="$(mktemp -d "${TMPDIR:-/tmp}/momos-restore.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
if [ -f "$SRC" ] && [ "${SRC%.tar.gpg}" != "$SRC" ]; then
  gpg --batch --quiet -d "$SRC" | tar -xf - -C "$WORK"
  SRC="$WORK/$(basename "${SRC%.tar.gpg}")"
fi
[ -d "$SRC" ] || { echo "Not a backup directory: $SRC" >&2; exit 1; }
[ -f "$SRC/database.sql.gz" ] && [ -f "$SRC/SHA256SUMS" ] || { echo "Incomplete backup (database.sql.gz/SHA256SUMS missing): $SRC" >&2; exit 1; }
( cd "$SRC" && sha256sum -c --quiet SHA256SUMS ) || { echo "Checksum verification failed: nothing restored." >&2; exit 1; }
gunzip -c "$SRC/database.sql.gz" > "$WORK/database.sql"
# `wp db query < file` (not `wp db import`): the mysql client stops at the first SQL error and exits non-zero,
# whereas `wp db import` (mysql -e "SOURCE …; COMMIT;") reports success after a failed statement.
if ! $WPCLI db query < "$WORK/database.sql" >/dev/null; then
  echo "Database import FAILED: the target database may be partly restored. Do not reopen the site." >&2
  exit 1
fi
rm -f "$WORK/database.sql"
if [ -f "$SRC/uploads.tar.gz" ]; then
  mkdir -p "$WP_PATH/wp-content"
  tar -xzf "$SRC/uploads.tar.gz" -C "$WP_PATH/wp-content"
fi
if [ -f "$SRC/evidence.tar.gz" ]; then
  # Same resolution as Dietary\Storage\EvidenceStore::dir() (see bin/backup.sh).
  EVIDENCE="${MOMOS_EVIDENCE_DIR:-$($WPCLI eval 'echo class_exists( "\Momos\Dietary\Storage\EvidenceStore" ) ? \Momos\Dietary\Storage\EvidenceStore::dir() : "";' 2>/dev/null || true)}"
  EVIDENCE="${EVIDENCE:-$(dirname "$WP_PATH")/momos-private/evidence}"
  mkdir -p "$(dirname "$EVIDENCE")"
  tar -xzf "$SRC/evidence.tar.gz" -C "$(dirname "$EVIDENCE")"
  if [ "$(tar -tzf "$SRC/evidence.tar.gz" | head -1 | cut -d/ -f1)" != "$(basename "$EVIDENCE")" ]; then
    echo "warning: the evidence archive was made from a directory with another name; check $(dirname "$EVIDENCE")" >&2
  fi
fi
$WPCLI momos migrate
# PRV-033 / TC-BCK-005: re-anonymise data that passed its retention date while it sat in the backup,
# before the site reopens.
if [ "${MOMOS_RESTORE_SKIP_RETENTION:-0}" != 1 ]; then
  $WPCLI momos privacy retention
fi
$WPCLI cache flush >/dev/null || true
echo "Restored from $SRC"
echo "Next (docs/operations/backup-restore.md): wp momos smoke; wp momos audit verify; review queued notifications; then cron and maintenance mode off."
