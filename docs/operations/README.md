# Operations runbooks

| Runbook | Use it for |
|---|---|
| [deployment.md](deployment.md) | hosting prerequisites, secrets (names), cron, first install, release by tag through staging, staging rules |
| [rollback.md](rollback.md) | code rollback, database decision tree (migrations are forward-only), salvage, verification |
| [backup-restore.md](backup-restore.md) | `bin/backup.sh` / `bin/restore.sh`, DEC-033 schedule, off-site copy, RPO/RTO, restore drill and its record |
| [monitoring.md](monitoring.md) | job heartbeats, alert emails, critical log lines, uptime monitor, logs and redaction |
| [incident-response.md](incident-response.md) | severities, contacts, APPI breach procedure, secret rotation (incl. token keys), comms templates |
| [updates-and-patching.md](updates-and-patching.md) | monthly window, ≤ 72 h critical patches, composer audit, core pin, plugin register |

Go-live gates: [../RELEASE_CHECKLIST.md](../RELEASE_CHECKLIST.md). Admin how-to for staff: [../ADMIN_GUIDE_ja_en.md](../ADMIN_GUIDE_ja_en.md).

Every command here is WP-CLI against the site (`wp --path=<WordPress root> …`; in development `bin/wp …`).
The automated backup/restore rehearsal is `tests/ops/backup-restore-roundtrip.sh` (development/CI database only).
