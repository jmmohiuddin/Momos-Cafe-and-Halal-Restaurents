# Audit

**Purpose.** Append-only audit trail (F-ADM-03 / FR-ADM-004, SEC-080…084): one writer for every
`momos_audit()` call, a hash chain (`row_hmac`, keyed with `MOMOS_HASH_PEPPER` or its alias `MOMOS_HMAC_KEY`) to detect tampering, the SCR-A16 viewer, and
retention purge (domain vs. activity months from Settings → Privacy).

## Public services
- `momos_audit( string $action, string $object_type, ?int $object_id, ?int $branch_id, array $changes )`
  (`src/functions.php`) → `AuditLogger::record()`. Actions must exist in `Catalogue` (filter
  `momos/audit/catalogue`). `$changes` never contains customer PII (field names / before-after of
  non-personal values only).
- `AuditRepository` — filtered queries for the viewer and exports (prepared fragments only).

## Hooks
- Fires: `momos/audit/recorded`, `momos/audit/field_save_action` (filter), `momos/monitoring/alert` (write failure).
- Consumes: `momos/audit/log`, `momos/access/denied`, `momos/fields/saved`, `momos/fields/denied`,
  `momos/i18n/translation_saved`, `momos/cli`.

## Data
Table `momo_audit_log` (`occurred_at` UTC, actor, action, object, branch, changes JSON, request id, `row_hmac`).

## Capabilities
`momo_view_audit_log` (SA/HO) for **Momo's → Audit log** (`admin.php?page=momos-audit`).

## Jobs · CLI
- Job `momos_job_audit_retention`.
- `wp momos audit [verify] [--from=<id>]` — recomputes the hash chain and reports the first broken row.
- `wp momos audit purge` — runs the retention purge now (named lock; refuses while another purge runs).

## Tests
`AuditLoggerTest`, plus audit assertions across module tests (`AccessTestCaseTest` helpers).
