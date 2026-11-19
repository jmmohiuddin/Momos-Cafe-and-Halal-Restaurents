#!/usr/bin/env bash
# Automated round trip of bin/backup.sh → bin/restore.sh (DEC-033, NFR-BCK-01, TC-BCK-002 rehearsal, GATE-07).
# Backs up an installed development/CI database plus a small uploads/evidence fixture, restores it into a fresh
# throw-away database and checks that every table, file and the audit chain came back unchanged; also checks the
# refusal paths (no --yes, tampered archive, unreadable dump) and, when gpg is installed, the encrypted format.
#
# Usage (development/CI only — never against production):
#   MOMOS_DB_NAME=<installed source db> tests/ops/backup-restore-roundtrip.sh
# The target database "<source>_rt" is created and dropped with `mysql -uroot` (override: MOMOS_MYSQL_ADMIN).
# Exit 0 = all checks passed. Runbook: docs/operations/backup-restore.md.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SRC_DB="${MOMOS_DB_NAME:?set MOMOS_DB_NAME to an installed development database}"
DST_DB="${SRC_DB}_rt"
MYSQL_ADMIN="${MOMOS_MYSQL_ADMIN:-mysql -uroot}"
WPCLI="${MOMOS_WPCLI:-$ROOT/bin/wp}"
case "$SRC_DB" in *prod*) echo "Refusing to run against '$SRC_DB'." >&2; exit 2 ;; esac

T="$(mktemp -d "${TMPDIR:-/tmp}/momos-rt.XXXXXX")"
GNUPGHOME_DIR="$T/gnupg"
cleanup() { $MYSQL_ADMIN -e "DROP DATABASE IF EXISTS \`$DST_DB\`" >/dev/null 2>&1; rm -rf "$T"; }
trap cleanup EXIT

PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "ok - $1"; }
bad() { FAIL=$((FAIL + 1)); echo "not ok - $1"; [ -n "${2:-}" ] && sed 's/^/    /' <<<"$2"; return 0; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

fresh_target() {
  $MYSQL_ADMIN -e "DROP DATABASE IF EXISTS \`$DST_DB\`; CREATE DATABASE \`$DST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci; GRANT ALL ON \`$DST_DB\`.* TO 'momos'@'127.0.0.1'; GRANT ALL ON \`$DST_DB\`.* TO 'momos'@'localhost';"
}
table_count() { $MYSQL_ADMIN -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$1'"; }
tables() { $MYSQL_ADMIN -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema = '$1' ORDER BY table_name"; }
checksum() { $MYSQL_ADMIN -N -e "CHECKSUM TABLE \`$1\`.\`$2\`" | awk '{print $2}'; }
# Options compared without transients (WP-CLI bootstraps write `_transient_doing_cron`).
options() { $MYSQL_ADMIN -N -e "SELECT option_name, MD5(option_value) FROM \`$1\`.\`${2}options\` WHERE option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' ORDER BY option_name"; }
backup() { MOMOS_DB_NAME="$SRC_DB" MOMOS_WP_PATH="$T/src" MOMOS_EVIDENCE_DIR="$T/src-private/evidence" MOMOS_WPCLI="$WPCLI" "$ROOT/bin/backup.sh" "$@"; }
restore() { MOMOS_DB_NAME="$DST_DB" MOMOS_WP_PATH="$T/dst" MOMOS_EVIDENCE_DIR="$T/dst-private/evidence" MOMOS_WPCLI="$WPCLI" TMPDIR="$T/tmp" "$ROOT/bin/restore.sh" "$@"; }

[ "$(table_count "$SRC_DB")" -gt 0 ] || { echo "Source database '$SRC_DB' has no tables (run bin/dev-setup.sh first)." >&2; exit 2; }
PREFIX="$(MOMOS_DB_NAME="$SRC_DB" $WPCLI db prefix 2>/dev/null || echo wp_)"

# Fixture files: uploads (with a non-ASCII name and a sub-directory) and the private evidence store.
mkdir -p "$T/src/wp-content/uploads/2026/11" "$T/src-private/evidence" "$T/dst" "$T/tmp" "$T/backups"
printf 'jpeg-bytes' > "$T/src/wp-content/uploads/2026/11/dish.jpg"
printf 'webp-bytes' > "$T/src/wp-content/uploads/2026/11/メニュー.webp"
printf '%%PDF-1.4 evidence' > "$T/src-private/evidence/cert-abc123.pdf"

# 1 Full backup.
OUT="$(backup --dest "$T/backups" 2>"$T/backup.err")"; rc=$?
check "full backup exits 0" "[ $rc = 0 ]"
check "full backup contains database, uploads, evidence, MANIFEST and SHA256SUMS" \
  "[ -f '$OUT/database.sql.gz' ] && [ -f '$OUT/uploads.tar.gz' ] && [ -f '$OUT/evidence.tar.gz' ] && [ -f '$OUT/MANIFEST' ] && [ -f '$OUT/SHA256SUMS' ]"
check "checksums verify" "( cd '$OUT' && sha256sum -c --quiet SHA256SUMS )"
check "MANIFEST records a full backup" "grep -qx 'kind=full' '$OUT/MANIFEST'"
check "backup directory is private (700) and files are owner-only (600)" \
  "[ \"\$(stat -c %a '$OUT')\" = 700 ] && [ -z \"\$(find '$OUT' -type f ! -perm 600)\" ]"

# 2 DB-only backup (the 4-hourly run).
DBOUT="$(backup --db-only --dest "$T/backups-db" 2>/dev/null)"
check "--db-only backup has the dump and no file archives" "[ -f '$DBOUT/database.sql.gz' ] && [ ! -e '$DBOUT/uploads.tar.gz' ] && grep -qx 'kind=db-only' '$DBOUT/MANIFEST'"
check "unknown option is refused" "! backup --bogus >/dev/null 2>&1"

# 3 Refusals leave the target untouched.
fresh_target
restore "$OUT" >/dev/null 2>&1; rc=$?
check "restore without --yes is refused (exit 2) and imports nothing" "[ $rc = 2 ] && [ \"\$(table_count '$DST_DB')\" = 0 ]"

cp -R "$OUT" "$T/tampered"; printf 'x' >> "$T/tampered/database.sql.gz"
restore "$T/tampered" --yes >/dev/null 2>&1; rc=$?
check "tampered archive fails checksum verification and imports nothing" "[ $rc != 0 ] && [ \"\$(table_count '$DST_DB')\" = 0 ]"

mkdir -p "$T/broken"; printf 'THIS IS NOT SQL;\n' | gzip -9 > "$T/broken/database.sql.gz"; ( cd "$T/broken" && sha256sum -- * > SHA256SUMS )
ERR="$(restore "$T/broken" --yes 2>&1)"; rc=$?
check "unreadable dump: restore exits non-zero and never reports success" "[ $rc != 0 ] && ! grep -q 'Restored from' <<<\"\$ERR\" && grep -q 'Database import FAILED' <<<\"\$ERR\""
check "no decrypted or uncompressed dump is left in TMPDIR" "[ -z \"\$(ls -A '$T/tmp')\" ]"

# 4 Round trip (retention skipped so the comparison is exact; the encrypted run below covers retention).
fresh_target
ERR="$(MOMOS_RESTORE_SKIP_RETENTION=1 restore "$OUT" --yes 2>&1)"; rc=$?
if [ $rc = 0 ]; then ok "restore into a fresh database exits 0"; else bad "restore into a fresh database exits 0" "$ERR"; fi
check "same table set" "[ \"\$(tables '$SRC_DB')\" = \"\$(tables '$DST_DB')\" ]"
DIFFS=""
for t in $(tables "$SRC_DB"); do
  [ "$t" = "${PREFIX}options" ] && continue
  [ "$(checksum "$SRC_DB" "$t")" = "$(checksum "$DST_DB" "$t")" ] || DIFFS="$DIFFS $t"
done
check "every table except options has the same CHECKSUM${DIFFS:+ (differs:$DIFFS)}" "[ -z '$DIFFS' ]"
check "options are identical (transients excluded)" "diff <(options '$SRC_DB' '$PREFIX') <(options '$DST_DB' '$PREFIX') >/dev/null"
check "uploads restored byte for byte" "diff -r '$T/src/wp-content/uploads' '$T/dst/wp-content/uploads' >/dev/null"
check "evidence store restored byte for byte" "diff -r '$T/src-private/evidence' '$T/dst-private/evidence' >/dev/null"
check "audit chain verifies on the restored database" "MOMOS_DB_NAME='$DST_DB' $WPCLI momos audit verify >/dev/null 2>&1"
check "smoke checks pass on the restored database" "MOMOS_DB_NAME='$DST_DB' $WPCLI momos smoke >/dev/null 2>&1"
check "no temporary dump left after a successful restore" "[ -z \"\$(ls -A '$T/tmp')\" ]"

# 5 Encrypted backup (MOMOS_BACKUP_GPG_RECIPIENT) → restore from the .tar.gpg file, retention included (PRV-033).
if command -v gpg >/dev/null; then
  mkdir -p "$GNUPGHOME_DIR"; chmod 700 "$GNUPGHOME_DIR"
  export GNUPGHOME="$GNUPGHOME_DIR"
  gpg --batch --quiet --passphrase '' --quick-gen-key 'momos-roundtrip@example.invalid' default default never >/dev/null 2>&1
  ENC="$(MOMOS_BACKUP_GPG_RECIPIENT='momos-roundtrip@example.invalid' backup --dest "$T/backups-enc" 2>/dev/null)"
  check "encrypted backup produces one .tar.gpg and no clear-text directory" "[ -f '$ENC' ] && [ \"\${ENC%.tar.gpg}\" != '$ENC' ] && [ ! -d \"\${ENC%.tar.gpg}\" ]"
  fresh_target
  ERR="$(restore "$ENC" --yes 2>&1)"; rc=$?
  if [ $rc = 0 ]; then ok "restore from the encrypted archive exits 0"; else bad "restore from the encrypted archive exits 0" "$ERR"; fi
  LAST="$($MYSQL_ADMIN -N -e "SELECT action FROM \`$DST_DB\`.\`${PREFIX}momo_audit_log\` ORDER BY id DESC LIMIT 1")"
  check "restore ran the retention job (last audit row: $LAST) and the audit chain still verifies" \
    "[ '$LAST' = retention.run ] && MOMOS_DB_NAME='$DST_DB' $WPCLI momos audit verify >/dev/null 2>&1"
  check "no decrypted archive left in TMPDIR" "[ -z \"\$(ls -A '$T/tmp')\" ]"
else
  echo "skip - gpg not installed (encrypted format not tested)"
fi

echo "# backup/restore round trip: $PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
