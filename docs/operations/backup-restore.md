# Backup and restore

Sources: DEC-033, NFR-BCK-01, Doc 09 TRD §6.9, Doc 10 SDD §6.12/§8.4, Doc 11 §6.10 (SEC-090/091), PRV-033,
access-security-privacy §11, Doc 12 TC-BCK-001…005 (GATE-07). Scripts: `bin/backup.sh`, `bin/restore.sh`; automated
round trip: `tests/ops/backup-restore-roundtrip.sh`.

## 1. Targets and schedule (DEC-033)

| What | When | How | Kept |
|---|---|---|---|
| Database | every 4 h | `bin/backup.sh --db-only` | 30 days rolling (`MOMOS_BACKUP_RETENTION_DAYS`) |
| Full: database + `wp-content/uploads` + evidence store (`MOMOS_EVIDENCE_DIR`) | daily 04:45 JST (after retention 03:15 and 404 cleanup 04:20) | `bin/backup.sh` | 30 days |
| Pre-deploy snapshot | before every production deploy | `bin/backup.sh --db-only` | 30 days |
| Host backup (independent second copy) | daily | hosting panel | host default ≥ 14 days |
| Code | each tag | Git + release zip with `.sha256` | indefinite |
| Secrets / configuration | on change | Momo's password manager | current + previous |

**RPO ≤ 4 h** (the 4-hourly dump) and **RTO ≤ 8 h** (decision + restore + verification). Anonymised data disappears
from backups at most 30 days after anonymisation because older backups are deleted (DEC-034).

## 2. Setting it up on the server

1. Copy `ops/backup.sh` and `ops/restore.sh` from the release zip (the same files as `bin/` in the tag) to an ops
   directory outside the web root, e.g. `/opt/momos-ops/bin/`.
2. Environment for the cron user (e.g. `/opt/momos-ops/env`, mode 600, sourced by the crontab line):

   | Variable | Value |
   |---|---|
   | `MOMOS_WP_PATH` | WordPress root (the directory with `wp-config.php`) |
   | `MOMOS_WPCLI` | `wp --path=<WordPress root>` (word-split by the scripts) |
   | `MOMOS_BACKUP_DIR` | private directory **outside** the web root (scripts create it with mode 700, files 600) |
   | `MOMOS_EVIDENCE_DIR` | only if WordPress cannot be asked; by default the scripts read `Dietary\Storage\EvidenceStore::dir()` |
   | `MOMOS_BACKUP_GPG_RECIPIENT` | fingerprint of Momo's backup public key (import it once and verify the fingerprint; the private key stays with Momo's, Doc 11 §6.8) |
   | `MOMOS_BACKUP_REMOTE` | rclone remote of the Momo's-owned bucket, e.g. `momos-offsite:momos-backups` (write-only credentials, bucket lifecycle 30 days, encryption at rest, MFA, ≤ 2 named people) |

3. Crontab (server clock UTC): `15 */4 * * * . /opt/momos-ops/env && /opt/momos-ops/bin/backup.sh --db-only` and
   `45 19 * * * . /opt/momos-ops/env && /opt/momos-ops/bin/backup.sh`, with `MAILTO=` the ops mailbox: the script exits
   non-zero on any failure (dump, archive, encryption, missing `rclone` while a remote is configured, upload), so cron
   mails the error — that is the "backup failed" alert (SEC-083). Watch for missing mails too: the host backup report and
   a weekly look at the bucket listing cover a cron that silently stopped.

## 3. What `bin/backup.sh` produces

`$MOMOS_BACKUP_DIR/<UTC stamp>/` with `database.sql.gz` (`wp db export --single-transaction`, no site lock),
`uploads.tar.gz`, `evidence.tar.gz` (full runs), `MANIFEST` (`created_utc`, `kind` = `full` | `db-only`,
`momos_core_version`) and `SHA256SUMS`. With `MOMOS_BACKUP_GPG_RECIPIENT` the directory is replaced by one encrypted
`<stamp>.tar.gpg`. With `MOMOS_BACKUP_REMOTE` the new backup is copied off-site with rclone. Backups older than 30 days
are deleted locally (the bucket lifecycle deletes remote copies). The script prints the path of the new backup.

## 4. Restore

`bin/restore.sh` is destructive for the target database and refuses to run without `--yes`.

1. **Freeze**: `wp maintenance-mode activate` (503 page) and comment out the cron line — jobs and the notification
   queue stay paused until step 6.
2. **Fetch** the backup: `rclone copy <remote>/<stamp>.tar.gpg .` (or the local directory). For a real incident,
   restore to an isolated environment first when integrity is in doubt (SDD §6.12; never a publicly reachable staging).
3. **Run** `MOMOS_WP_PATH=… MOMOS_WPCLI="wp --path=…" bin/restore.sh <dir | file.tar.gpg> --yes`. The script:
   decrypts a `.tar.gpg` (needs the private key), verifies `SHA256SUMS` (nothing is imported on a mismatch),
   imports the dump through `wp db query` (stops and exits non-zero at the first SQL error), extracts uploads and the
   evidence store, runs `wp momos migrate`, runs the retention job `wp momos privacy retention` (PRV-033 / TC-BCK-005:
   data that passed its retention date while it sat in the backup is anonymised before the site reopens), flushes the
   object cache. The uncompressed dump lives only in a private temporary directory that is always removed.
   `MOMOS_RESTORE_SKIP_RETENTION=1` is only for a forensic copy that must stay as it was.
4. **Verify**: `wp momos smoke`; `wp momos audit verify`; reservation counts per branch for the last days and the
   latest event time match the backup point; a few media files and one certificate evidence download; translations
   visible in every enabled language.
5. **After restore — notifications**: rows that were `queued` at backup time may already have been sent
   after the backup point. Before cron runs again list them —
   `wp db query "SELECT id, template_key, recipient_type, reservation_id, created_at FROM <prefix>momo_notifications WHERE status IN ('queued','sending') ORDER BY id"` —
   compare with the email provider's sent log and cancel the duplicates:
   `wp db query "UPDATE <prefix>momo_notifications SET status='cancelled', last_error_code='restored_duplicate' WHERE id IN (…)"`
   (TC-BCK-003). Reservations made after the backup point are recovered from the salvage export
   ([rollback.md](rollback.md) §4) or, if lost, from the branch notification emails — phone those customers.
6. **Reopen** with the Business Owner's approval: cron line back, `wp momos jobs sync`, `wp maintenance-mode deactivate`,
   `wp momos jobs list` after 10 minutes (no `late`). Record start/end times (RTO) and the backup's `created_utc` vs
   the incident time (RPO) in the maintenance log.

## 5. Restore drill (GATE-07 before launch, then quarterly — SEC-091, TC-BCK-002)

1. Take the latest **off-site** full backup (not a local copy) — this also proves the off-site copy works.
2. Restore into an isolated environment with its own database (§4, steps 3–4), timing every step.
3. Run `wp momos smoke`, `wp momos audit verify`, the staging regression subset (TRD §6.10 item 3) and compare
   reservation counts / latest event timestamps / media / translations with production at the backup time.
4. Record: date, backup stamp, elapsed time vs RTO 8 h, data age vs RPO 4 h, results, problems, sign-off (security
   lead + CTO). File with the GATE-07 evidence. Destroy the restored copy afterwards (it holds production data).
5. Technical drill items done in the same session (Doc 11 §7.6): alert email delivery, maintenance mode, session
   destruction, secret rotation on staging.

Automated rehearsal (development/CI): `MOMOS_DB_NAME=<installed dev db> tests/ops/backup-restore-roundtrip.sh` —
full and DB-only backup, refusal without `--yes`, tampered archive, unreadable dump (must fail, never "Restored"),
exact round trip into a fresh database (every table checksum, options, uploads and evidence byte for byte, audit chain,
smoke), and the GPG format with the retention step. It needs `mysql -uroot` to create/drop `<db>_rt`.

### Drill record — build environment, 2026-11-19 (not production; production drill is a GATE-07 owner item)

| Item | Result |
|---|---|
| Source | seeded demo database `momos_c_docs` (24 tables, 10 reservations, 1,432 audit rows) + 427 MB uploads of the dev install + one evidence file |
| Backup | `bin/backup.sh` full: 11 s; `database.sql.gz` 193 KB, `uploads.tar.gz` 335 MB, `evidence.tar.gz`, `MANIFEST`, `SHA256SUMS`; files mode 600, directory 700 |
| Restore | into a new, empty database `momos_c_docs_restore` and a separate WordPress path: 4 s; checksums OK; migrate "Database is up to date"; retention run (0 rows due); `Restored from …` |
| Comparison | 24/24 tables present; every table `CHECKSUM TABLE`-identical except `wp_options` (transient `_transient_doing_cron` + `momos_heartbeat`, `momos_privacy_retention_last` written by the retention run) and `wp_momo_audit_log` (+1 row `retention.run`); uploads and evidence `diff -r` identical |
| Integrity | `wp momos audit verify` → `Success: 1433 rows verified.`; `wp momos smoke` → `Success: Smoke checks passed.` |
| Automated | `tests/ops/backup-restore-roundtrip.sh` → `# backup/restore round trip: 24 passed, 0 failed` |

Defects found by the drill in the previous scripts and fixed: (1) a failed SQL import was reported as success
(`wp db import` exits 0 after a failed statement; the script then ran `migrate` on an empty schema) — now
`wp db query < dump` and an explicit failure exit; (2) the uncompressed dump was written world-readable to the fixed path
`/tmp/momos-restore.sql` and left there when the import failed — now a private `mktemp` directory removed on exit;
(3) the evidence store was looked up in `wp-content/momos-private` while `EvidenceStore` defaults to
`{ABSPATH}/../momos-private/evidence`, so certificate evidence was silently not backed up — now asked from WordPress;
(4) restore onto a fresh server failed when `wp-content/` did not exist yet; (5) the `.tar.gpg` written by
`backup.sh` could not be restored by `restore.sh`; (6) a configured off-site remote without `rclone` was skipped
silently; (7) uploads archive errors were ignored; unknown options were ignored; (8) the restore did not run the
retention job required by PRV-033. The round-trip test fails 12 of its 24 checks against the previous scripts.
