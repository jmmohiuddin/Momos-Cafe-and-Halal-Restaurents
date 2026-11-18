# Defect register

Severity: S1 blocker · S2 major · S3 minor · S4 cosmetic. Status: Open · Fixed · Verified · Won't fix (with reason).

| ID | Found | Area | Description | Sev | Status | Fix / evidence |
|---|---|---|---|---|---|---|
| DEF-001 | Wave 1 (all teams) | Kernel DB | `Transaction::run` issued COMMIT inside the WordPress test transaction → rows leaked between tests | S2 | Fixed | SAVEPOINT when a transaction is already open; suite green twice on the same DB |
| DEF-002 | Wave 1 (Menu, Theme) | Kernel i18n | All `/en/ /ar/ …` URLs returned 404 under the PHP built-in server (PATH_INFO kept the prefix) | S2 | Fixed | Router strips PATH_INFO; `/en/`, `/ar/`, `/en/menu/` → 200 |
| DEF-003 | Wave 1 (Access) | Dev server | `/wp-admin/` redirected to the home page | S3 | Fixed | `bin/router.php` serves directory index; `/wp-admin` → 301 `/wp-admin/` → login |
| DEF-004 | Wave 1 (Reservation) | Kernel fields | Empty error notice box printed on unrelated admin pages | S4 | Fixed | Notice suppressed when no non-empty errors |
| DEF-005 | Theme | Kernel | `momos_bdi()` lacked `dir="ltr"` (UI spec C10) | S3 | Fixed | helper emits `<bdi dir="ltr">` |
| DEF-006 | Wave 1 (Branch) | Tests | Branch test set-up deleted reservations before notifications → FK errors logged | S4 | Open | Re-check after Wave 2 |
| DEF-007 | Theme | Security headers | Report-only CSP contains `upgrade-insecure-requests` (ignored, console warning) | S4 | Open | Drop directive from report-only header |
| DEF-008 | Wave 1 (Reservation) | CSV export | Every `+…` cell (all E.164 phones) gets the formula-guard `'` prefix | S4 | Open | Literal spec behaviour; HO to decide text column |
