# Privacy

**Purpose.** Personal-data lifecycle for reservation requests (F-PRV-02/03, FR-PRV-002/003/004,
PRV-003/012/013/016/017, DEC-034; `docs/specs/access-security-privacy.md` §10): the daily retention /
anonymisation job, the single `Anonymizer`, the data-subject request tool (SCR-A19) with WordPress core
exporter/eraser integration, and the consent version registry.

## Public services
- `Retention\Anonymizer::anonymize_reservation( $id, $reason )` — the only code that nulls PII columns of
  `momo_reservations` (and scrubs event/notification payloads); idempotent, row-locked.
- `Dsr\DsrService` — `search()` (exact email or reference; audited without the search term), `bundle()` (JSON
  export), `correct()`, `anonymize()` (typed-reference confirmation; active future requests are kept).
- `Consent\ConsentRegistry` — `current()`, `stored_key()`, `add_version()`, `activate()`, `history()`; legal
  page publish check (`momos/fields/publish_errors/page`).

## Hooks
- Fires: `momos/reservation/anonymized` (after commit), `momos/monitoring/alert` (retention job stale),
  filter `momos/privacy/retention_steps` (other modules add steps: Access, Notification, Audit).
- Consumes: `momos/fields/publish_errors/page`, `momos/cli`.

## Data
No own tables; acts on `momo_reservations`, `momo_reservation_events`, `momo_notifications`, `momo_audit_log`.
Options `momo_consent_versions`, `momos_privacy_retention_last`, `momos_privacy_retention_since`.
Retention periods come from Settings → Privacy.

## Capabilities
`momo_manage_dsr` (SA/HO only; vendors denied) for **Momo's → Data requests** (`admin.php?page=momos-dsr`).
The search term never appears in URLs, logs or audit changes.

## Jobs · CLI
- `momos_job_retention` (daily, named lock, batches) and `momos_job_retention_watch` (alerts when the last
  successful run is older than 26 h).
- `wp momos privacy retention [--dry-run]`, `wp momos privacy export --email=<email> [--file=<path>]`,
  `wp momos privacy erase --email=<email> [--yes]`, `wp momos privacy consent [list]|current|add|activate [<key>] [--privacy=… --policy=… --effective=…]`.

## Tests
`PrivacyRetentionTest`, `PrivacyDsrTest`, `PrivacyConsentTest`; E2E `privacy-dsr.spec.ts`.
