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
| DEF-009 | Wave 2 (Seed crawl) | Theme address block | Postal code printed twice ("540-0000 〒540-0000 …"): `address-block.php` prepends it to `momo_address_ja`, which already contains it | S3 | Open | — |
| DEF-010 | Wave 2 (Seed crawl) | Branch hours display | After-midnight closing shows "17:00–2:00", "LO 1:30" with no next-day marker; AC-BR-04-01 expects "02:00" + next-day label | S2 | Open | — |
| DEF-011 | Wave 2 (Seed) | Sample content | No SAMPLE badge on sample items in non-production (content-strings §10.1 D5) | S3 | Open | — |
| DEF-012 | Wave 2 (Seed) | Sample content | No production publish block for `momo_is_sample` items (D2) and no `wp momos purge-samples` (D8); smoke check only reports | S2 | Open | — |
| DEF-013 | Wave 2 (Tooling) | Dev/CI | Plugin symlink broke `plugin_basename`, `MOMOS_CORE_URL` (admin CSS/JS 404 in dev) and Japanese admin catalog loading | S2 | Fixed | `wp_register_plugin_realpath` in mu-loader; `MOMOS_CORE_URL` → `/wp-content/plugins/momos-core`, admin.css 200 |
| DEF-014 | Wave 2 (Tooling) | Seed CLI | `wp momos seed` not registered (module not in Plugin::MODULES); dev-setup seeding failed silently | S3 | Fixed | SeedModule listed; dev seed ran (26 photos, 8 staff accounts) |
| DEF-015 | Wave 2 (Tooling) | Static analysis | PHPStan low-severity findings: invalid `@phpstan-type` (ReservationError:11), dead `$old_titles` (BranchGuard:28/68), SpiceLevel keys, PHPDoc/return mismatches (BranchRepository:134, SlotGenerator:125, DietaryFields:244, FieldRegistry:371, DemoSeeder:701), `DISALLOW_FILE_MODS` read (Hardening:123) | S4 | Open | Baseline holds them; fix in Wave 3 |
| DEF-016 | Wave 2 (Tooling) | Security hardening | `TranslationEditor::persist()` is public and relies on callers' nonce checks | S3 | Open | Move nonce verification into `persist()` |
| DEF-017 | Wave 2 (Tooling) | Coding standard | 295 PHPCS errors (mostly formatting) | S4 | Partly fixed | `phpcbf` → 103 left, of which 45 are reviewed security-sniff false positives |
