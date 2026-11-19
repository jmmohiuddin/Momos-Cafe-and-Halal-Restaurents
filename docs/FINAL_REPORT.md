# Final build report — Momo's Cafe & Halal Restaurant platform (Phase 1)

Date: 2026-11-19 · Branch: `claude/sleepy-planck-sc50wd` · Every result below comes from a recorded run in the build
environment; items that were not run are named as such.

## 1. What was built

A WordPress 7.1 site (PHP 8.3, MariaDB 10.11) with **no paid plugins** (IMPL-001):

- **`momos-core` plugin** (350 PHP files, ≈ 47 k lines, loaded as a must-use plugin) with 14 modules: Kernel
  (migrations, 8 custom tables + constraints, field layer, multilingual layer, jobs, cache tags, monitoring, CLI),
  Access (roles, branch scope, meta capabilities, TOTP 2FA, login throttling, sessions, security headers), Audit
  (hash-chained audit log + viewer), Admin (settings, language gate, dashboard, content editors), Branch (branches,
  hours, closures, notices, offers), Menu, Dietary (allergens, halal claims two-person rule, certificates + evidence),
  Reservation (request workflow, staff inbox, report/export, SLA jobs), Api (momos/v1 REST, Turnstile, rate limits),
  Notification (queue, worker, 11 email templates in six languages, digest), Privacy (retention, DSR tool, consent
  versions), Seo (titles, canonical, hreflang, sitemaps, JSON-LD, redirects, 404 log, GTM + dataLayer with opt-out),
  Presentation (view models for the theme), Seed (fictional demo content, sample-content guard).
- **`momos` theme**: design system from Doc 08 and the UI-001 prototype, self-hosted fonts per language, every
  customer screen SCR-C01…C22 incl. the reservation request/submitted/status screens, 404 and static maintenance page,
  RTL for Arabic and Urdu, Elementor-compatible.
- **Six customer languages** (ja source at `/`, `/en/ /ar/ /ur/ /hi/ /bn/`), bilingual ja/en admin (1,471-string
  Japanese catalog), per-language publication gate.

## 2. How it was verified

| Check | Result (fresh database) |
|---|---|
| PHPUnit (78 test classes) | 1445 tests, 0 failures (§6) |
| Playwright (28 specs, 543 tests, one worker, one run) | 539 passed before the last fixes; the 3 keyboard-focus failures were fixed and the affected specs re-run: 48 passed; the remaining 1 is a TBT measurement that passes when run alone (CPU contention) |
| PHPStan level 5 / PHPCS WordPress-Extra | no errors / 0 errors |
| `wp momos smoke` | pass |
| Backup → restore round trip | pass (`bin/backup.sh` / `bin/restore.sh`, automated in `OpsBackupRestoreTest` and `tests/ops/`) |
| Requirements re-audit (206 rows, against code and tests) | 175 implemented + tested · 6 partial · 2 tested only in CI · 0 missing · 23 owner-dependent |

QA covered customer journeys in six languages at 390/1280 px, every staff role and the full capability matrix,
branch isolation through screens, URLs, REST, admin-post, exports, notifications and ID enumeration, the reservation
lifecycle with concurrency and all email templates, accessibility (axe WCAG 2.2 AA, keyboard), performance budgets
(query counts, page weight, 10,000-reservation inbox) and a security review. 81 evidenced defects were found
(3 S1, 21 S2, 45 S3, 12 S4): 77 fixed with regression tests, 4 blocked on decisions — `docs/QA_DEFECT_REGISTER.md`.

Not run here (need the real environment): live SMTP delivery and SPF/DKIM/DMARC, live Turnstile, real hosting
TLS/WAF/CDN, Lighthouse on a real device and field Core Web Vitals, the weekly ZAP/WPScan scans and the
Firefox/WebKit projects (defined in CI, not run locally), GitHub Actions itself (no run yet).

## 3. What the owner must do before go-live

`docs/RELEASE_CHECKLIST.md` lists every gate with the screen or command. In short:

1. **Data and approvals**: verified branch facts (GATE-01); counsel-approved halal statements and certificates
   (GATE-02 — no halal wording is shown until approved); Privacy Policy, Terms and External-transmission page;
   activation of the consent version (IMPL-006); native review and approval of strings and content per language
   (GATE-04, IMPL-007 — production enables a language only after approval); Head Office notification list.
2. **Accounts and infrastructure** (not purchased — outside the build authorization): hosting with PHP 8.3 and real
   cron, domain/DNS, SMTP with SPF/DKIM/DMARC, Turnstile keys, GTM container, WAF/CDN, off-site backup storage, uptime
   monitor; secrets per `docs/operations/deployment.md`.
3. **Decisions**: ratify CHG-006 (CSS budget) and CHG-008 (Japanese font budget), confirm CHG-009 (login CAPTCHA after
   3 failures), choose the home hero image (QA-070) and the CSV phone format (QA-079), canonical host (apex or www).
4. **Launch steps**: `wp momos purge-samples`, `wp momos content sample-check`, `wp momos smoke`, legacy redirect
   import, restore drill, uptime monitor.

## 4. Where things are

| Need | Document |
|---|---|
| Conventions, kernel APIs | `docs/ENGINEERING.md`, `docs/CONTRACTS.md`, module `README.md` files |
| Decisions and changes to the documented scope | `docs/IMPLEMENTATION_DECISIONS.md`, `docs/CHANGE_REGISTER.md` |
| Requirements and status | `docs/REQUIREMENTS_REGISTRY.md`, `docs/PROJECT_DASHBOARD.md` |
| Defects | `docs/DEFECT_REGISTER.md`, `docs/QA_DEFECT_REGISTER.md` |
| Operations | `docs/operations/` (deployment, rollback, monitoring, backup-restore, incident-response, updates-and-patching) |
| Staff guide (ja/en) | `docs/ADMIN_GUIDE_ja_en.md` |
| Local development | `bin/dev-setup.sh`, `bin/serve.sh`, `tests/e2e/fixtures/seed.sh` |

## 5. Known limits

- ar/ur/hi/bn texts (and Japanese drafts) are drafts awaiting native review; they cannot go live until approved.
- Final logo, photos and hero image are owner assets; the theme uses a provisional wordmark and the demo uses
  local prototype photos that are never committed (the UI-001 repository is private).
- PHPStan runs at level 5 (gaps.md targets level 6); the coverage gate is report-only.
- The public JavaScript budget (10 KB gzip) has ≈ 0.6 KB headroom.

## 6. Final verification run (after the last fixes)

Run on 2026-11-19 after the last code change (fresh seeded database `momos_qa_verify`, fresh PHPUnit database):

| Check | Command | Result |
|---|---|---|
| PHPUnit, full suite | `vendor/bin/phpunit` | `OK, but incomplete, skipped, or risky tests!` — Tests: 1445, Assertions: 14513, Skipped: 1, Risky: 1 (0 failures, 0 errors) |
| Keyboard focus + mouse chip selection | `npx playwright test specs/qa-a11y-i18n-keyboard.spec.ts specs/theme-pointer-focus.spec.ts` | 48 passed (incl. the three 390 px Tab-walk cases that failed in the release-candidate run) |
| CSS / JS / font-CSS budgets | `npx playwright test specs/qa-performance-budgets.spec.ts -g "JS|U1"` | 3 passed |
| PHPStan level 5 | `vendor/bin/phpstan analyse` | `[OK] No errors` |
| PHPCS (project ruleset, whole tree) | `vendor/bin/phpcs -n` | exit 0, 0 errors |

The skipped test is the production-only sample guard (the test install's environment is a constant); the risky test is
the template query-budget test, which passes but leaves an output buffer open from the core template loader.
The full Playwright suite was last run as one pass in the release-candidate run (539/543; the failing cases are
covered by the re-runs above).
