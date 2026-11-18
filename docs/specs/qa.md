# Spec: QA, acceptance and release (test strategy, GATE-01..10, E2E, P0 oracle)

This is the build specification for QA on the Momo's platform (`momos-core` plugin + `momos` classic theme). It tells automation engineers, developers and the QA lead what to test, which oracle to use, and when a release may go live. You can write PHPUnit and Playwright tests from this file without reading the documentation package.

**Sources.** Doc 12 (all of it: §1 strategy, §2 coverage, §3 + `csv/test_cases.csv` with 391 cases, §5 E2E-01..17, §6 automation, §7 UAT, §8 gates/Go-No-Go/smoke/hypercare, §9 harmonisation, open items). Doc 04 §16.0.2–16.0.4 (shared behaviour, status labels, error catalogue) and §35 (release scope). Doc 07 §6.3/6.4/6.6/6.10 (microcopy, claim strings, prohibited terms, localisation). Doc 09 §3.15 and §4.3 (test support, API routes). Doc 10 §2 (CLI, clock). Doc 11 (security values, through `access-security-privacy.md`). Doc 13 §7 (DoR/DoD). Doc 17: DEC-002, 019, 033, 036, 055 (binding), 058, 062, 063, 066. CSV `requirements.csv` (NFR rows). Sibling specs in `docs/specs/` hold the functional detail; this spec references them.

**Labels.**
- **[Doc]**: taken from the documentation.
- **[TBD → x]**: an open decision. Tests read `x` from settings and never hard-code it.
- **[Proposed]**: this spec's addition. Review it before release.
- **[IMPL]**: an adaptation required by the no-paid-plugin build (IMPL-001..005).
- ⟦…⟧: a placeholder for a business fact or name. Never invent one.

All test data is synthetic. Branch codes `QAA…QAF`, `QAY`, `QAZ`, `TST`, phone `090-0000-0101` and `example.test` addresses are fixtures, not business facts.

---

## 0. Build adaptation (IMPL-001..005 → QA)

| Documented mechanism (Doc 12) | This build | Test impact |
|---|---|---|
| wp-env + Docker for CI and PHPUnit | `bin/dev-setup.sh` (MariaDB 10.11 + WordPress in `.wp/`), `bin/serve.sh` (PHP built-in server on :8080), GitHub Actions `.github/workflows/ci.yml` (jobs `php`, `e2e`) | Same suites. The CI DB engine must equal production (MariaDB 10.11 / MySQL 8). |
| Mailpit capture and API | Dev/CI: `MOMOS_MAIL_TRANSPORT=log` writes each message to the notification log and to `MOMOS_MAIL_CATCHER_DIR` (`wp-content/mail-catcher`). Staging: SMTP to the provider with `MOMOS_MAIL_SANDBOX` rerouting to Mailpit or the QA inbox. | `tests/e2e/helpers/mailbox.ts` reads the catcher dir on dev/CI and the Mailpit API on staging. **[Proposed]** catcher format: one RFC 822 `.eml` per message named `{utcYmdHis}-{notification_id}.eml`, so headers (To/Cc/Bcc/Reply-To/Subject) and MIME parts can be asserted. |
| Test clock option `momos_test_clock` (TRD NFR-MNT-02) | Kernel `Clock` with option `momos_frozen_now` or constant `MOMOS_FROZEN_NOW` (env var in `wp-config.dev.php`). PHPUnit: `TestCase::freeze('2026-12-24 18:00')` (JST). Ignored when `wp_get_environment_type() === 'production'`. | Playwright helper `clock.ts` → **[Proposed]** `wp momos clock set "<JST Y-m-d H:i>"` / `--offset=+90m` / `reset`, which writes `momos_frozen_now`. |
| Turnstile test keys | `MOMOS_TURNSTILE_MODE=test` (always passes) on dev/CI. Staging uses Cloudflare's always-pass, always-fail and token-spent test keys. | TC-RES-022 step 2 needs the always-fail key or the **[Proposed]** mode `MOMOS_TURNSTILE_MODE=fail`. |
| WPML language hide / switcher | Per-language enable setting behind `LanguageGate` (`momo_manage_languages`, SA/HO). Translations live in `_momos_i18n_{lang}` and `_momos_i18n_status_{lang}` ∈ `missing, draft, needs_review, approved`. Shown only when `approved`. | TC-I18N-009/015/019 test the setting and gate. "Untranslated" in a test means status ≠ `approved`. |
| WPML String Translation export | String catalogs `momos-core/languages/strings/{ja,en,ar,ur,hi,bn}/{module}.php` (ar/ur/hi/bn headed "DRAFT – needs native review") + admin `.po` (ja/en). | TC-I18N-011 diffs the key sets of the six catalogs. 0 missing keys. Review status per language comes from the reviewer sign-off record, not from the file. |
| Elementor Theme Builder templates, Loop Grid, dynamic tags, Role Manager | Classic theme `momos` templates (`front-page.php`, `single-momo_branch.php`, …, see `screens.md`). Fields render from code. | TC-WP-006/007 are re-scoped to "correct theme template per CPT/language; empty field hides its block and label". TC-WP-008 = RTL visual suite after a theme or core update. TC-ACL-019 = no role except SA can edit theme/plugin files (DISALLOW_FILE_EDIT/MODS). |
| ACF field groups | `Kernel\Fields\FieldRegistry` meta with the documented `momo_*` keys | Assert on the same meta keys. |
| Plugin inventory ≤ 15 incl. premium | Active: `momos-core` (mu-loaded) + theme `momos`. No premium packages. | TC-SEC-009 asserts ≤ 15 active and every active plugin is in the inventory. TC-WP-010 and the premium-licence part of TC-WP-014 are N/A. Core checksums still apply. |
| Wordfence / Simple History / UpdraftPlus / Rank Math | `Access\Login\*`, `momo_audit_log`, `bin/backup.sh` + `bin/restore.sh` + host backups, `Seo` module | The tests are unchanged and run against the momos-core implementation. |
| `tests-e2e/` folder | `tests/e2e/` (Playwright 1.63.0, @axe-core/playwright 4.10.2, already in `package.json`) | Structure in §3.3. |

---

## 1. Requirements covered

| ID | Statement (one line) | Pri |
|---|---|---|
| DEC-055 | GATE-01..10 are binding. No Critical defect is ever accepted. A High defect in reservation, privacy, security, claims or branch isolation is never accepted. Any other High needs written Owner risk acceptance with a fix date. Hypercare is 30 days. Production test bookings go only to the hidden TEST branch. | M |
| FR-RES-026 | A hidden TEST branch exists for production smoke tests. It is not in the directory or sitemap and only staff can access it. | M |
| FR-RES-030 | TEST-branch reservations are test data. Notifications go only to the QA inbox. They are excluded from reports, KPIs, digests and GA4 outcomes, and the branch is absent from every public output. | M |
| NFR-MNT-01 | Pinned versions; monthly update cycle via staging; WPCS/PSR-4; ≥ 70 % line coverage for the Reservation and Access modules **[TBD → 70 %]** | M |
| NFR-MNT-02 | Seed/reset WP-CLI commands and a test clock exist on non-production and are disabled in production | M |
| NFR-PERF-01..05, NFR-AVL-01, NFR-REL-01, NFR-SEC-01, NFR-PRV-01, NFR-ACC-01, NFR-I18N-01, NFR-CMP-01, NFR-SCL-01, NFR-BCK-01, NFR-OBS-01, NFR-DATA-01, NFR-SEO-01 | Measurable targets in §6 | M |
| FR-FND-002 / FR-SEO-009 | Local, staging and production environments. Staging holds synthetic data only and sends noindex + HTTP auth. | M |
| FR-SEC-010 / FR-SEC-011 | Weekly vulnerability scanning; backups per DEC-033 | M |
| BR-RES-01 | A request is never presented as confirmed before acceptance (prohibited-term scan, UAT-03) | M |
| BR-RES-02 | Only users assigned to the branch (ROLE-06/07) or ROLE-01/02 view or act on a reservation (GATE-03) | M |
| BR-I18N-01 / BR-I18N-02 | A language goes public only after its native-review LCCS sign-off. No mixed-language fallback. | M |
| BR-CORP-03 | No sample dish content is public. Labelled demo photos may appear only on dish and offer images. | M |
| DEC-002, 036, 033, 019 | Language gate; WCAG 2.2 AA (no JIS conformance claim); backup RPO/RTO; TTFB gate from Japan | M |
| TC-* | All 391 designed cases (160 P0, 196 P1, 33 P2, 2 P3). P0 cases are in full in §9 and P1 cases are indexed in §10. | — |

Priority words map as follows. Requirements use MoSCoW (Must/Should/Could). Tests use the risk priority **P0–P3** (§2.2). Defects use severity **Critical/High/Medium/Low** (§4) plus an urgency word. "Must/High" in this spec means P0, and P1 where a gate depends on it.

---

## 2. Test strategy summary

### 2.1 Objectives (Phase 1 is ready when the evidence shows …)
| # | Objective | Measured by |
|---|---|---|
| O1 | Each valid request is stored once, at the selected branch, and reaches a final state through authorised actions only | RES/RSA suites, E2E-01..09, GATE-03, GATE-05 |
| O2 | No customer is told a request is confirmed before staff accept it | Prohibited-term scan (TC-RES-021, TC-DIET-013), UAT-03 |
| O3 | Staff see and act on their own branch only, at every layer: UI, URL, REST, AJAX, export, query, notification | ACL suite (20 ordered pairs), GATE-03 |
| O4 | Halal, alcohol and allergen information shown on the site equals the approved records; unverified data is labelled | DIET suite, UAT-08, GATE-02 |
| O5 | Every enabled language, incl. ar/ur RTL, completes the full journey on real phones, with native sign-off | I18N/RTL/CMP suites, GATE-04 |
| O6 | Employees maintain data without touching layouts or other branches | WP/ACL suites, TC-WP-009, UAT-06/07 |
| O7 | Secure, private, fast from Japan, indexable and accessible | SEC/PRV/PERF/SEO/A11Y suites, GATE-06..09 |
| O8 | Operations ready: staff trained, recipients verified, settings equal the sheet, TEST-branch smoke passed | GATE-10, TC-SMK-001 |

**Cross-cutting rule:** a reservation test passes only when the customer screen, the database row, the staff view and the inbox all agree. A "thank you" page alone proves nothing.

### 2.2 Test priority (risk) — not defect priority
| Pri | Definition | Exit rule |
|---|---|---|
| **P0** | A failure could lose, duplicate, misroute or falsely confirm a request; expose PII or cross branches; publish an unverified halal/allergen claim; allow an exploitable vulnerability; or break the reservation journey in an enabled language or on a Tier-1 device | Exhaustive testing, automated where possible, run on every RC: **100 % executed and passed** for Go |
| P1 | Core customer/staff function, legal display, multilingual quality, hardening, SEO migration, ops readiness | 100 % executed; failures follow the severity rules |
| P2 | Secondary feature, admin convenience, optimisation beyond the gate | ≥ 95 % executed |
| P3 | Cosmetic, Could features | Run when time allows, or deferred in the report |

### 2.3 Test levels and tools
| Level | Tool | Where | When |
|---|---|---|---|
| Static | PHPCS (WPCS + security sniffs), `php -l`, stylelint (logical properties only: physical `left/right/margin-left…` is an error) | CI | Every PR |
| Unit/integration | PHPUnit (WP test suite, `tests/phpunit`, base `Momos\Tests\TestCase`, InnoDB rollback per test) | CI | Every commit |
| API | Playwright `APIRequestContext` + PHPUnit REST tests (`rest_do_request`) | CI, staging | Every PR / staging deploy |
| E2E | Playwright TypeScript; one browser context per role for concurrency and isolation | CI (smoke subset), staging | Deploy, nightly |
| Visual | Playwright `toHaveScreenshot()` in a pinned Linux container | CI vs staging | Nightly, before release, after updates |
| A11y | @axe-core/playwright, tags `wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa`; NVDA, VoiceOver, TalkBack | staging | Nightly (axe); per RC (manual) |
| Performance | Lighthouse CI (mobile, Moto G-class), WebPageTest (Tokyo/Osaka agents **[TBD — DEC-019]**), k6 | Production-candidate stack | Per RC, before launch |
| Security | OWASP ZAP baseline (passive, authenticated + unauthenticated), WPScan, manual WSTG, secret scan, TLS scan | staging (written Owner authorisation) | Per RC |
| Manual | Exploratory charters A–F, human E2E on real devices, UAT | staging | Per RC |
| Production | Read-only smoke TC-SMK-002; TEST-branch smoke TC-SMK-001 | production | Each deploy / launch |

### 2.4 Environments
| Env | Data | Email | Allowed tests |
|---|---|---|---|
| Local / CI | Synthetic seed, reset per run | log transport + catcher dir | All non-destructive |
| Staging | **Synthetic only**, reset per cycle | Sandbox → Mailpit / allow-listed QA inboxes | All, incl. ZAP baseline (written authorisation) and k6 (host permission). HTTP auth + noindex. |
| Production-candidate | Synthetic, removed by script before go-live | Disabled or QA-only | TC-PERF-001..004 only |
| Recovery env | Restored backup copy, destroyed after the test | Outbound disabled | TC-BCK-002/003/005 |
| Production | Real | Real | Read-only smoke, TEST-branch smoke, passive header/TLS checks. **Never:** load tests, active scans, destructive tests, or test bookings at real branches. |

**Parity checklist** (every staging build; a failure blocks Stage 1): WordPress/PHP/DB versions equal the release manifest; cache/CDN configuration; language enable settings; time zone `Asia/Tokyo`; system cron with `DISABLE_WP_CRON`; WAF rules; email transport (sandbox); separate staging keys (Turnstile, email, GA4); permalinks.

### 2.5 Stages, entry/exit, suspension
| Stage | Exit criteria |
|---|---|
| 0 Requirements ready | Each P0 requirement has acceptance criteria. Each open value is decided or parameterised. Test-data spec approved. |
| 1 Build testable (each build) | Parity checklist passed; TC-SMK-003 green (≤ 5 min); CI unit/API suites green |
| 2 System test complete | 100 % of P0 and P1 executed; ≥ 95 % of P2; 0 open Critical; 0 open High in protected areas; each failure has a defect; each fix retested; native review done for each language to be enabled |
| 3 UAT sign-off | All UAT scripts Approved, or Approved with written conditions (§12) |
| 4 Go/No-Go | Decision per §5.3, signed by the Owner |
| 5 Hypercare exit | 30 days stable (§5.6) |

**Suspend** testing when staging is unavailable for > 4 working hours, the smoke suite fails, a Critical defect blocks > 30 % of planned cases, or the seed is corrupted. **Resume** when a new build passes TC-SMK-003 and the parity checklist.

### 2.6 Exploratory charters (60–90 min per RC; two testers on A, B and F)
A Reservation integrity: refresh/back after submit, two tabs, offline/slow 3G, expired session, language switch mid-form, wrong device clock, submit at 23:59 JST.
B Staff concurrency: two staff on one record, role revoked mid-edit, logout in another tab, notification link opened by another user.
C Content safety: long names, pasted Word text, huge images, emoji, deleting categories that hold dishes, duplicate branch.
D Language seams: switcher on every template and error state, missing translations, mixed RTL/LTR, emails.
E Third-party failure: maps, email provider, captcha, CDN or fonts blocked.
F Caching: edit hours/menu/allergens, then view anonymously in each language; logged-in vs logged-out; draft leakage through cache or REST.

---

## 3. Test infrastructure

### 3.1 Fixtures (seed `wp momos seed --profile=standard`; `--profile=perf` adds 10,000 reservations)
| Code | Purpose |
|---|---|
| QAA | Open; full confirmed facts; certified (active) halal record; lunch (LO 14:30) + dinner (from 17:30); lead 120, window 60, interval 30 |
| QAB | Open; split hours; a closure-today variant; `halal_ingredients` claim; variant dish D9011; party_max ≥ 6 |
| QAC | Open; Friday 18:00–01:00 (LO 00:30), crossing midnight; `no_claim` |
| QAD | Open; minimal fields (no facilities, access text or gallery); expired certificate |
| QAE | Open; revoked certificate; used for the 20-pair isolation tests |
| QAY / QAZ | `temporarily_closed` with a reopen date / `closed_permanently` |
| QAF | Created during TC-BR-015 / E2E-13 (sixth branch) |
| TST | Hidden TEST branch; the QA inbox is its only recipient. Code **[TBD → TST — Tech lead, DEC-052]** |

**Menu fixtures:** 8 categories (one empty, one draft); about 60 dishes. Named dishes: D9001 (featured, ja only); D9002 (reference price ¥1,200; QAA override ¥1,350; not offered at QAC); D9003 (sold out); D9004 (retired); D9005 (offered by no branch); D9006 (advance order 48 h); D9010/D9011 (base/variant); D9012 (ja + en only); D9013 (created in E2E-11). Names are 80 characters and descriptions 1,000 characters per language.

**Allergen coverage:** each of the 29 items appears as `contains`, `may_contain` and `not_contained` in at least one dish, and ≥ 5 dishes keep the default `not_verified`.

**Certificate fixtures:** active approved with mark permission; active approved without; `pending_verification`; expiring in 30 days; expiring in 7 days; expired; revoked; future `valid_from`.

**Offer fixtures:** O1 all branches, featured, active; O2 QAB, active; O3 QAC, active, not featured; O4 future; O5 expired; O6 QAB draft by qa_bm_b awaiting approval; O7 QAY, active.

**Reservation fixtures:** 200 rows across all 7 statuses, 6 languages and 5 branches, incl. `needs_action` and `customer_notified_flag = 0`.

**Hostile and multilingual fixture files** (`tests/e2e/fixtures/data/*.json`, mirrored for PHPUnit):
| Fixture | Content |
|---|---|
| F-NAMES | 山田 太郎 · ヤマダ タロウ · محمد علي · عائشہ خان · अमित शर्मा · রহিম উদ্দিন · O'Connor-Smith · Nguyễn Thị Hương · names of 100 and 101 characters |
| F-BIDI | ar «طاولة لأربعة أشخاص، نريد Chicken Biryani، رقم الهاتف +81 90-0000-0101، المرجع ABCD-2345، السعر ¥1,200» · ur «براہ کرم high chair رکھیں، ریفرنس ABCD-2345، وقت 19:00 JST» |
| F-DIG | ٤ ۴ ४ ৪ and full-width ４; phone `+٨١ ٩٠ ٠٠٠٠ ٠١٠١` |
| F-LONG | 80-char dish names; 1,000/1,001-char special requests; the longest translated button labels |
| F-PHONE | `090-0000-0101`, `03-0000-0101`, `+81 (0)90 0000 0101`, `+880 1700-000000`, `+92 300 0000000`, `+971 50 000 0000`, `+44 7700 900123`, `abc-123`, empty |
| F-EMAIL | `qa.diner01+ar@example.test`, `Mixed.Case@Example.Test`, a long subdomain; invalid `a@`, `a b@x.jp` |
| F-HOSTILE | `<script>`, `"><img src=x onerror=…>`, `<svg onload=…>`, `' OR 1=1--`, `=HYPERLINK(…)`, `+SUM(1,1)`, `@cmd`, CRLF + `Bcc:` in a name, a 5,000-char string, 🎂 |
| Markers | Unique PII marker strings (e.g., `ZZNOTE7731`) for the sweeps that check emails, logs, analytics and URLs |

### 3.2 Accounts (one person per account; 2FA via test TOTP seeds held in the QA vault)
| Account | Role slug (DEC-024) | Scope |
|---|---|---|
| qa_sa | `administrator` (ROLE-01) | all |
| qa_ho / qa_ho_dca | `momo_head_office` (ROLE-02) without / with user cap `momo_edit_dietary_claims` (CAP-DCA) | all |
| qa_mm / qa_mm_pub | `momo_menu_manager` (ROLE-03) without / with `publish_momo_dishes` | central |
| qa_ce | `momo_content_editor` (ROLE-04) | central |
| qa_tr_{en,ar,ur,hi,bn}, qa_rev_{lang} | `momo_translator` (ROLE-05), translator / reviewer (`momo_approve_translations`) | assigned language |
| qa_bm_a … qa_bm_e | `momo_branch_manager` (ROLE-06) | QAA … QAE |
| qa_rs_a … qa_rs_e | `momo_reservation_staff` (ROLE-07) | QAA … QAE |
| qa_multi_bc | ROLE-06 | QAB + QAC |
| qa_tst | ROLE-07 | TST only (also exists in production) |
| qa_vendor | ROLE-01 + usermeta `momo_vendor`, `momo_access_expires_at` | TC-ACL-022, TC-ADM-008 |
| qa_sub | `subscriber` (negative control) | none |
| anonymous | ROLE-00 public visitor | — |

### 3.3 Test-support commands (refuse to run and change nothing when `wp_get_environment_type() === 'production'`, per TC-WP-011/012)
| Command | Status | Purpose |
|---|---|---|
| `wp momos seed --profile=standard\|perf` / `wp momos reset` | [Doc] | Load/remove the fixtures in §3.1. Reset truncates the custom tables and removes seeded posts. |
| `wp momos clock set "<JST Y-m-d H:i[:s]>"` · `--offset=±Nm` · `reset` | [Proposed] wrapper over `momos_frozen_now` | Time travel for expiry, SLA, attendance, certificates, offers, retention |
| `wp momos jobs run <hook>` (e.g., `momos_job_expiry`) | [Proposed] | Run one job synchronously under the current clock |
| `wp momos test:reservation <REF>` → JSON row + events + notifications (no token) | [Doc §6.2 helper] | DB reconciliation in `dbAssert.ts` |
| `wp momos smoke`, `wp momos content sample-check`, `wp momos settings export` | [Doc] (also allowed in production; read-only) | Deploy smoke, demo-content gate, settings evidence |
| Fault injection: option `momos_test_fault` ∈ `db_unavailable_on_submit`, `mail_provider_fail` (`n` attempts or `all`) | [Proposed] | TC-RES-039, TC-SEC-020, TC-NOT-005, E2E-09 |

### 3.4 Playwright layout (`tests/e2e/`)
```text
playwright.config.ts   projects: chromium-desktop, msedge-desktop, firefox-desktop, webkit-desktop,
                       webkit-iphone (iPhone 13), chromium-android (Pixel 7); baseURL + HTTP auth from env;
                       trace on first retry; retries 1 in CI (a test that passes only on retry is flagged flaky)
setup/auth.setup.ts    logs in every §3.2 account with TOTP → storageState (gitignored)
setup/seed.setup.ts    wp momos reset && wp momos seed --profile=standard; clock reset
fixtures/accounts.ts, fixtures/data/*.json, fixtures/prohibited-terms/{lang}.json, fixtures/branchRules.ts (reads API-01)
helpers/api.ts (REST nonce per role) · mailbox.ts · clock.ts · dbAssert.ts · cli.ts (runs bin/wp or SSH wp)
pages/                 page objects using getByRole/getByLabel only
specs/ smoke/ reservation/ staff/ acl/ i18n-rtl/ dietary/ offers/ seo/ analytics/ a11y/ visual/ scope/ perf/
```
**Conventions.** Each test title starts with its TC ID (`TC-RES-017 idempotent submission`), so results write back to the CSV. Tags: `@P0 @GATE-03 @ar`. Each run uses unique identities (`qa.<tc>.<run>@example.test`). No fixed sleeps. Parallel workers are partitioned by branch. Traces, videos and HAR files contain no personal data.

**Boundary values come from API-01** (`GET /momos/v1/branches/{code}/reservation-rules`) and are never hard-coded.

### 3.5 PHPUnit scope (`tests/phpunit/{unit,integration}`)
- **State machine:** each valid transition (T1–T8, N1–N5) with each permitted actor. Each invalid pair returns 409 `invalid_transition`. Assert actor checks (403), timestamp columns, and that the event row is written in the same transaction.
- **Validation:** all BR-RES-03 rules, field lengths, E.164 normalisation, native-digit normalisation, and the validation order of SDD §5.2 (content type → origin → rate limit → idempotent replay → captcha → schema → business rules → soft duplicate → pending limit).
- **Slot generation:** periods, last order, gaps, cross-midnight, closures, special hours, JST independence.
- **Jobs with FrozenClock:** expiry (every 5 min), SLA reminder/escalation, attendance window, sold-out reset (every 15 min), certificate expiry (daily 00:10 JST), offer expiry, retention (daily 03:15 JST), log purge.
- **Access:**
  - `map_meta_cap` for every role × resource (oracle in §7.4).
  - An unscoped repository list query throws.
  - Route audit: every `momos/v1` route has an explicit `permission_callback`, and none returns true for staff data.
- **Integrity:** UNIQUE constraints (`public_ref`, `idempotency_key`, `branch_id+dish_id`), optimistic locking (`version`), append-only audit, idempotent migrations.
- **Notification queue:** retries, backoff, final-failure flag, single To.
- **Coverage:** ≥ 70 % line coverage for `src/Reservation` and `src/Access` **[TBD → 70 %]**.

### 3.6 Visual regression
About 40 snapshots:
- home, directory, branch, category, dish, reservation form (error state), submitted page and offers × {ja 390, ar 390, ja 1366, ar 1366};
- plus ur 390 (branch, dish, form) and bn 390 (category, dish).

Stabilise by disabling animations, masking the map facade, dates and notices, awaiting web fonts, and using fixed seed content. Comparison settings: `threshold: 0.2`, `maxDiffPixelRatio: 0.01`. Baselines are the first implementation the designer accepts, and they are updated only with QA-lead approval in the PR.

### 3.7 CI placement
| Trigger | Runs | Budget | Blocks |
|---|---|---|---|
| Pull request | `php -l`, PHPCS, stylelint, PHPUnit, REST route audit, Playwright smoke + ACL subset (Chromium) | ≤ 15 min | Merge |
| Staging deploy | Smoke in 6 languages; reservation happy path + key negatives; ACL 20 pairs; notification routing | ≈ 20 min | Test cycle start |
| Nightly (staging) | Full Playwright on all projects; visual; axe; SEO crawl; prohibited-wording, mixed-language, demo-content and scope scans | 60–90 min | An RC needs **3 consecutive green nights** (TC-REG-004) |
| Core/PHP/theme update | TC-WP-005 pack + manual ja/ar journey | ≈ 30 min + manual | Production update |
| Release candidate | All of the above + Lighthouse CI, WPScan, ZAP baseline, manual suites | per RC | Stage 2 exit |
| Production deploy | TC-SMK-002 read-only smoke | ≤ 5 min | Rollback decision |
| Launch | TC-SMK-001 on TST | ≤ 15 min | Go-live confirmation |

Flaky tests are quarantined with a defect. They are never silently retried away.

---

## 4. Defect policy (DEC-055 — binding)

**Protected areas:** reservation, privacy, security, claims (halal/alcohol/allergen) and branch isolation. The tester sets severity, and triage confirms it. Severity is never lowered because a fix is hard.

| Severity | Definition (any one condition) | Launch rule |
|---|---|---|
| **Critical** | (a) Customer/staff PII readable, exportable or emailed to anyone not authorised (another branch, the public, third-party analytics). (b) A valid request is not stored, is stored for another branch, is duplicated, changes status without an authorised actor, or is shown to the customer as confirmed when it is not. (c) Certification wording, a mark or "100 % halal" is shown without an active approved record; an expired or revoked claim is still shown; or an allergen is shown as not contained/absent when approved data says `contains`, `may_contain` or `not_verified`. (d) Exploitable auth bypass, privilege escalation, code execution, SQL injection, or stored XSS executing in admin. (e) Submission or the public site unavailable for all users of an enabled language with no workaround. (f) Data loss not recoverable within RPO. | **Never accepted → No-Go** |
| **High** | (a) Core function (find branch, read menu/dish, submit, receive email, accept/decline/cancel, edit branch data) fails or gives a wrong result for an enabled language, Tier-1 device or branch, with no reasonable workaround. (b) **Any** halal/alcohol/allergen defect that is not Critical, e.g., a missing disclaimer or banner, or a mistranslated claim (floor High). (c) A notification template/language is not sent or has wrong content. (d) A hardening control is missing (2FA not enforced, missing header, ineffective rate limit, missing nonce). (e) A privacy control is missing (notice, consent version, retention job, PII in URL/log/subject). (f) An automated critical/serious or manual AA failure on the reservation journey. (g) An unusable RTL layout, or a mixed-language page in an LCCS item. (h) A GATE-08/09 threshold failed. | Protected-area High: **never accepted**. Other High: only with written Owner risk acceptance + fix date. |
| **Medium** | Important content wrong, or a secondary function failing with a workaround; AA failure outside the reservation journey; non-safety translation error; SEO issue outside GATE-09; visual deviation that affects usability | Fix before launch, or accept in writing with an owner and date (Conditional Go) |
| **Low** | Cosmetic issue, minor wording, alignment in a non-Tier-1 browser | Backlog |

**Urgency** (set by QA lead + PO): Immediate · This cycle · Before launch · After launch (scheduled) · Backlog. Defaults: Critical → Immediate. Protected-area High → Immediate or This cycle. Other High → This cycle or Before launch.

**Workflow:** New → Triaged → (Rejected / Duplicate / CannotReproduce / Deferred) → Assigned → InProgress → Fixed → ReadyForRetest (deployed to staging) → Verified (by a tester other than the fixer) → Closed. Reopened → Assigned.
- Only the QA lead closes Critical/High defects.
- A defect reopened twice escalates to the QA and dev leads.
- Deferred is impossible for Critical and for protected-area High.
- Security defects go to a restricted tracker.

**Bug report fields:**
- `MOMO-QA-####` and a title in the form "[Area][Lang/Role] what breaks, where"
- TC and requirement IDs; environment, build tag and manifest
- Browser/device/OS/viewport, language, role and branch
- Preconditions; numbered steps; expected result (with requirement); actual result (HTTP status + `code`)
- Evidence: screenshot or video, console, HAR, `trace.zip`
- Severity, urgency, reproducibility, owner, status
- Tags: `reservation, isolation, claims, rtl, translation, security, privacy, a11y, perf, design`

**No personal data in bug reports.**

**Mandatory regression before Verified:**
| Defect area | Regression to run |
|---|---|
| Reservation logic | Full RES + RSA suites + PHPUnit state machine + E2E-01 (ar) and E2E-03 (ja) |
| Roles/isolation | Full ACL suite (20 pairs) + TC-SEC-006 |
| Claims | Full DIET suite + prohibited-wording scan + TC-DIET-019 cache check |
| Translation/RTL | I18N/RTL checks for the affected template in all enabled languages + native spot check |
| Template/CSS | Visual regression of every template that shares it (LTR + RTL) + Tier-1 devices |
| Notifications | NOT suite for the affected template × 6 languages |
| Core/PHP update | TC-WP-005 pack |
| Any Critical | All of the above for the area + full smoke |

---

## 5. Release gates, Go/No-Go, launch, hypercare

### 5.1 Gates — exact pass criteria
| Gate | Exact pass criteria | Evidence | Approver |
|---|---|---|---|
| **GATE-01 Branch facts** | For each branch to be published, the following are confirmed in a signed fact sheet: name, address (Japanese + localised), phone, weekly hours incl. last order, closed days and concept. TC-BR-006 shows a **100 % match** on the branch page, directory, footer and JSON-LD in every enabled language. Unconfirmed fields are absent (TC-BR-005). UAT-06 confirmation. | Signed, dated fact sheet per branch; TC-BR-001/004/005/006 report; screenshots | Owner / HO |
| **GATE-02 Claims** | A named CAP-DCA holder approved every published certificate record, branch halal statement, alcohol policy and dish allergen data set from a kitchen/certificate source (UAT-08). All GATE-02 cases pass. Prohibited-wording scan: **0 hits**. No seal image exists without an active record. Demo-content scan: **0 sample items public**. Every dish with `not_verified` data shows the banner. | Register export with `verified_by/verified_at/source_document`; scan reports; UAT-08 forms | CAP-DCA holder + Owner |
| **GATE-03 Routing & isolation** | **100 % pass** of all GATE-03 cases on the RC build: 20 ordered branch pairs × channels (UI, URL, REST, AJAX, export, list queries, notifications), routing per branch, concurrency, offer scoping | Playwright + PHPUnit JUnit/HTML reports for the RC tag; mail routing log | QA lead + Security lead |
| **GATE-04 Languages** | For each language to be enabled: TC-CMP-001 on a real iPhone and Android device (form → submitted → accept and decline → E01/E02/E03); RTL cases for ar/ur; LCCS complete with a named native-reviewer sign-off (TC-I18N-015, UAT-09); UAT-03 comprehension met; enablement goes through the LanguageGate (TC-I18N-019). **ja and en must pass.** Other languages that fail stay hidden, and the gate is assessed on the enabled set. | Screen recordings per language/device; reviewer sign-off forms; inbox screenshots | Owner + native reviewers |
| **GATE-05 Defects** | TC-REG-003 on the RC: **0 open Critical; 0 open High** in the protected areas; every other open High has written Owner risk acceptance with a fix date | Tracker export at RC; signed acceptances | QA lead; Owner |
| **GATE-06 Legal & privacy** | Counsel approval (versions recorded) of the Privacy Policy, Reservation Policy, External Transmission page, retention settings and cross-border disclosures; retention job passed the time-travel test (TC-PRV-004); all GATE-06 cases pass | Counsel approval records; PRV report | Counsel + Owner |
| **GATE-07 Security & backup** | 2FA enforced for all staff roles (TC-SEC-001, TC-ADM-004); WPScan + ZAP baseline with **0 High/Critical**; headers and TLS verified; backups running for **≥ 7 days** (TC-BCK-001); full restore within **RTO ≤ 8 h / RPO ≤ 4 h** (TC-BCK-002) | Scan reports; header captures; backup logs; restore record | Security lead + CTO |
| **GATE-08 Performance & a11y** | NFR-PERF-01 lab medians, NFR-PERF-02 TTFB p75 from Japan and NFR-PERF-03 budgets met on all key templates in all enabled languages; axe **0 critical/serious**; **0 manual AA failures** on the reservation journey | Lighthouse JSON/HTML per template × language; WebPageTest; axe reports; signed manual audit | Tech lead + QA lead |
| **GATE-09 SEO & analytics** | Every legacy URL from the DEC-039 crawl returns a **single 301 to a 200** page; 0 broken top URLs (top = URLs with Search Console clicks or external links ⟦list from crawl⟧); GA4 events validated in DebugView (TC-ANL-001..004); Search Console verified; sitemaps submitted | Redirect CSV; DebugView exports; Search Console screenshots | SEO lead |
| **GATE-10 Operations** | Staff accounts match the roster, with 2FA and ja/en training done; recipients verified per branch; SLA, decision cutoff and all reservation settings equal the approved sheet; monitoring and incident drills done; KPI sources live (F-RES-12 report or the documented read-only SQL fallback; alerts per TC-SEC-016); **TEST-branch smoke passed at cutover** | Roster vs user export; training attendance; recipient confirmations; `wp momos settings export`; drill records; smoke log | HO + QA lead |

### 5.2 Blocking test cases per gate (from the `gate` column)
| Gate | Blocking TCs |
|---|---|
| GATE-01 | BR-001, BR-004, BR-005, BR-006, UAT-006 |
| GATE-02 | HOME-003, CORP-002, MENU-012, MENU-013, DIET-001..004, DIET-006..014, DIET-017..024, DIET-025, DIET-027, DIET-028, ACL-023, E2E-010, E2E-015, UAT-008 |
| GATE-03 | RSA-001, RSA-013, RSA-019, NOT-003, NOT-004, NOT-014, ACL-001..012, ACL-021, ACL-024, ADM-009, E2E-006, E2E-012, UAT-010, REG-001 |
| GATE-04 | HOME-004, I18N-001, I18N-002, I18N-007, I18N-009, I18N-011, I18N-013, I18N-015, I18N-019, RTL-001..004, RES-020, RES-021, NOT-001, NOT-002, CMP-001, E2E-001, E2E-002, E2E-003, E2E-005, E2E-011, E2E-014, UAT-003, UAT-009 |
| GATE-05 | REG-003 |
| GATE-06 | CORP-005, CORP-016, I18N-016, RES-003, RES-027, ANL-006, ANL-010, SEC-022, PRV-001..005, PRV-010, PRV-013, PRV-014, PRV-015, PRV-017, PRV-018, NOT-015, NOT-016, UAT-013 |
| GATE-07 | ADM-004, ADM-008, ADM-011, ACL-022, SEC-001..005, SEC-009, SEC-010, SEC-012..014, SEC-023..026, SEC-029, WP-012, WP-013, WP-014 (checksum part only [IMPL]), PRV-016, BCK-001, BCK-002, BCK-005 |
| GATE-08 | PERF-001..003, A11Y-001..009, A11Y-012 |
| GATE-09 | SEO-004, SEO-008, SEO-009, SEO-010, ANL-001..004, ANL-011 |
| GATE-10 | RSA-023, RES-037, NOT-008, ADM-005, ADM-006, ADM-007, ADM-010, SEC-016, SEC-017, UAT-004, UAT-011, SMK-001 |

(All IDs carry the prefix `TC-`. The GATE-02 list includes TC-DIET-021/022/023 and TC-DIET-025 per the Doc 12 §8.2 list. TC-DIET-005, 015, 016 and 026 are not gate-blocking.)

### 5.3 Go / Conditional Go / No-Go
| Decision | Rule |
|---|---|
| **Go** | GATE-01..10 passed with evidence; UAT signed; 0 Critical; 0 High in any area; open Medium/Low listed with owner + date |
| **Conditional Go** | All gates passed, and the only exceptions are: (a) High defects **outside** the protected areas, each with written Owner acceptance + fix date; (b) languages other than ja/en that failed GATE-04 stay hidden (TC-I18N-009 proves no switcher entry, hreflang or sitemap for them); (c) a Should/Could feature switched off cleanly with no dead links, with Owner approval. Conditions are tracked daily in hypercare. |
| **No-Go** | Any Critical; any High in a protected area; any failed gate other than path (b); missing CAP-DCA or counsel approval; restore not tested; TEST-branch smoke failed. **A launch date never overrides No-Go.** |

The QA lead and tech lead recommend, and the Owner decides and signs. A Go is provisional until TC-SMK-001 passes in the cutover window. If the smoke fails, the QA lead, tech lead and Owner decide on rollback: within the cutover window, revert DNS within the TTL; after it, show the maintenance page or redeploy the previous release.

### 5.4 Launch checklist (each item cites its evidence)
| Area | Checklist items (evidence) |
|---|---|
| Testing and UAT | Stage 2 exit and the gate evidence pack; UAT forms for all areas and all enabled languages |
| Content and claims | Fact sheets signed; claim approvals; no seal, demo or sample content (`wp momos content sample-check` = 0); offers approved; LCCS complete per enabled language and hidden languages confirmed |
| Legal | Counsel approvals; legal pages published with dates |
| Technical: config | Production versions equal the manifest. Debug off. `DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS` true; core, plugin and theme auto-updates off (DEC-065). TZ Asia/Tokyo. System cron running. |
| Technical: security | HTTPS + HSTS ramp (SEC-050); headers incl. the canonical Doc 11 §6.4 CSP as Report-Only; WAF; 2FA; clean scans |
| Technical: backups | Backups running, off-site, restore tested |
| Technical: email | SPF/DKIM/DMARC pass to real inboxes; staff recipients verified |
| Technical: go-live switches | DNS TTL lowered; redirects live; staging still blocked; production noindex removed |
| Technical: monitoring | Uptime, job heartbeats, queue and errors alert both HO and the vendor |
| Analytics/SEO | GTM published by a named publisher; no GTM/GA4 on `/reservation/submitted/` or the status page; DebugView validated; Search Console verified; sitemaps submitted |
| Operations | Staff trained (ja/en guides); escalation contacts; the 6-language "online reservations unavailable — please call" notice ready; settings equal the approved sheet; TST configured with the QA inbox only |
| Accounts | Test accounts disabled except qa_tst; Super Administrators ≤ ⟦2⟧; vendor access time-boxed |

### 5.5 Production smoke on the hidden TEST branch (TC-SMK-001, ≤ 15 min)
**Preconditions:**
- The Go decision is taken and the release is deployed.
- qa_tst (ROLE-07 for TST only, with 2FA) and the QA inbox are ready.
- TST is hidden and has the QA inbox as its only recipient.
- No real branch is touched and no real customer data is read.

| # | Min | Action | Pass |
|---|---|---|---|
| 1 | 0–2 | Read-only: home, every branch page, menu hub, one dish, `/offers/` in ja, en, ar | 200; no console errors; HSTS + security headers; hreflang present |
| 2 | 2–3 | robots.txt, sitemap index, staging leftovers, TST visibility | No noindex on public pages; no staging hosts; TST absent from the directory, sitemap and JSON-LD |
| 3 | 3–5 | As qa_tst, submit a TST request (tomorrow, valid slot; name "QA SMOKE TEST — DO NOT PREPARE"; QA inbox email) | 201; reference shown; "not yet confirmed" |
| 4 | 5–7 | QA inbox | E01 from the brand domain with SPF/DKIM/DMARC pass; E07 only to the QA inbox |
| 5 | 7–9 | qa_tst inbox → Accept | Only TST visible; status confirmed; E02 received |
| 6 | 9–11 | Status link → Cancel (if F-RES-03 is released; otherwise qa_tst cancels with a reason) | E04 to the customer (E09 to the QA inbox for a customer cancel); status cancelled |
| 7 | 11–12 | GA4 Realtime/DebugView | Page views present; test lead suppressed/tagged (FR-RES-030); no PII |
| 8 | 12–13 | Login page; anonymous `/wp-json/wp/v2/users` | Login loads; users endpoint 401 |
| 9 | 13–15 | Monitoring dashboard; record the smoke log | Uptime, heartbeats and queue green; the TST record is flagged as test and excluded from reports |

Any failure stops the smoke, and the team decides between fix-forward and rollback.

The read-only post-deploy smoke **TC-SMK-002** (≤ 5 min) covers:
- key pages return 200 in all 6 languages;
- headers, robots, sitemap and the login page;
- TST is not public;
- nothing is written.

### 5.6 Hypercare (30 days)
Review cadence: 15 minutes daily on days 1–14, then three times a week to day 30 **[TBD → maintenance contract]**.

**Exit at day 30:**
- no open Critical/High;
- Conditional-Go conditions closed, or rescheduled with Owner approval;
- browser tiers re-checked against GA4;
- hypercare report signed.

| Monitor | Frequency | Alert threshold |
|---|---|---|
| External uptime (NFR-AVL-01) | 1 min | 2 consecutive failures |
| TC-SMK-002 | After each deploy + daily; hourly for the first **[TBD → 7 days]** | Any failure |
| CSP report-only | Daily triage; enforce after **[TBD → 2 weeks]** clean (TC-SEC-030) | Any violation from an approved source |
| Job heartbeats (expiry, queue, SLA, certificates, offers, retention) | Continuous | No run for 30 min |
| Notification queue | Continuous / daily | Any final failure; failure rate > **[TBD → 5 %/h]** |
| Reservation reconciliation TC-SMK-004 (created vs E07 vs decided, per branch) | Daily | 0 requests on a normal-traffic day; any pending past 2× SLA; a "customer not notified" flag open beyond the same day |
| Deliverability (bounce, DMARC aggregates, seed test to Gmail/Yahoo Japan/carriers) | Daily / weekly | Bounce spike; DMARC failures |
| PHP fatals, 5xx, JS errors (log outside web root) | Continuous | Any fatal |
| Security (WAF, failed logins, vuln feed, new admins) | Daily | Failed logins > **[TBD → 20/h]**; new vulnerability; unexpected admin |
| SEO (404 log weekly for 8 weeks; Search Console) | Weekly | New internal 404; hreflang/coverage errors |
| RUM CWV (TC-PERF-008) | Monthly | A template group leaves "good" |

**Incident response:**
- **Critical** (site down, reservations failing, data exposure): phone the tech lead and Owner immediately, publish the 6-language "please call the branch" notice, and respond within **[TBD → 1 h]**.
- **High:** respond the same business day.
- **Medium/Low:** fix in the next release.

---

## 6. NFR measurable targets (oracle for GATE-07/08 and hypercare)

| NFR | Target | Method | TC |
|---|---|---|---|
| NFR-PERF-01 lab | Per **median of 3 runs**: LCP ≤ 2.5 s, CLS ≤ 0.1, TBT ≤ 200 ms. Templates: home, branch, menu category, dish, reservation × 6 languages. | Lighthouse mobile preset (Moto G-class throttling) on production-equivalent cache/CDN | TC-PERF-001 |
| NFR-PERF-01 field | p75 LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1 | CrUX / GA4 RUM after launch | TC-PERF-008 |
| NFR-PERF-02 | **Cached HTML TTFB p75 ≤ 800 ms** (gate; aspirational target ≤ 600 ms); uncached p75 ≤ 1.8 s | WebPageTest from Tokyo + Osaka, 9 runs per template per location after priming, then after purge | TC-PERF-002 |
| NFR-PERF-03 | Initial load ≤ 1.5 MB; hero ≤ 200 KB; card images ≤ 80 KB WebP/AVIF; only current-language fonts | Transfer-size breakdown of the PERF-001 runs | TC-PERF-003 |
| NFR-PERF-04 (API-02) | p95 ≤ 2 s at **20 concurrent VUs** for 10 min across 5 branches; unexpected errors < 1 %; DB rows = 201 responses; 0 duplicates; 2 notifications per row | k6. Turnstile test keys. Load IP exempt from the IP limit (tested separately). Host written permission. | TC-PERF-004 |
| NFR-PERF-04 (inbox) | API-10 p95 ≤ 2 s with **10,000** rows; indexes used; no full scans on filters | k6 (5 VUs, 5 min) + EXPLAIN | TC-PERF-005 |
| NFR-PERF-05 | Event → provider acceptance p95 ≤ 60 s; nothing stuck queued > 5 min | 100 notifications | TC-NOT-010 |
| Spike/stress | ×2 load, slow DB, provider outage: informational, but **no lost request** | k6 | TC-PERF-007 |
| NFR-AVL-01 | **[TBD → 99.5 %]** monthly excl. announced maintenance; 1-min external monitoring | Uptime monitor | SMK |
| NFR-REL-01 | Submission atomic and idempotent; email failure never loses or rolls back a request | — | RES-001/017/025/039, NOT-005 |
| NFR-DATA-01 | UNIQUE `public_ref`, `idempotency_key`, `branch+dish`; optimistic locking; UTC stored + JST displayed | PHPUnit | RSA-013, RES-015 |
| NFR-ACC-01 | WCAG 2.2 AA on all customer templates + the reservation journey; axe 0 critical/serious; 0 manual AA failures on the journey | §6.1 | A11Y-001..012 |
| NFR-I18N-01 | 6 languages; RTL correct for ar/ur on all templates; **0 mixed-language pages; 0 hreflang errors** | Crawl + script detection | I18N-001/013, SEO |
| NFR-CMP-01 | Latest 2 majors of Chrome (Android/Win/mac), Safari (iOS/mac), Edge, Firefox, Samsung Internet; smoke in LINE/Instagram/Facebook in-app; viewports 360, 390, 768, 1024, 1366, 1920 | Tier table below | CMP-001..007 |
| NFR-SCL-01 | Adding a branch needs no code; model valid to **[TBD → 20]** branches | E2E-13 | BR-015 |
| NFR-BCK-01 | Daily full + DB every 4 h; 30 days; off-site Momo's-owned storage; RPO ≤ 4 h; RTO ≤ 8 h; restore test before launch + quarterly | Restore drill | BCK-001/002/005 |
| NFR-OBS-01 | PHP log outside web root; notification failures visible in admin; heartbeat alert if no run for 30 min; uptime alerts to HO + vendor | Drill | SEC-016, WP-004 |
| NFR-SEO-01 | Valid sitemaps; canonical on every indexable page; JSON-LD valid (Schema.org validator, Rich Results Test); no Review/AggregateRating/ReserveAction | Crawler + validators | SEO-* |
| NFR-MNT-01 | ≥ 70 % line coverage Reservation/Access **[TBD]**; PHPCS clean | CI | WP-011 |
| Cache purge | A data save purges the affected URLs in all languages within **60 s** (Doc 04 §16.0.2); claim change ≤ **[TBD → 5 min]** | Playwright | DIET-019 |
| Open-now | Correct within ±1 min of a period boundary in JST, whatever the device time zone | Playwright clock | BR |

**Browser/device tiers:**

| Tier | Browsers/devices | Scope | Method |
|---|---|---|---|
| Tier 1 | Safari iOS (375 and 390–393 px); Chrome Android (360–412 px) | Full journey in every enabled language; staff inbox | **Real devices mandatory** + Playwright WebKit/Chromium |
| Tier 2 | Chrome/Edge/Firefox on Windows (1366/1920); Safari/Chrome on macOS (1440); iPadOS (768/820/1024); LINE in-app browser | Smoke + reservation | Playwright + manual |
| Tier 3 | Instagram/Facebook in-app; Samsung Internet | Smoke + submit | Manual |

**Responsive check:** assert `document.documentElement.scrollWidth <= innerWidth` on key templates in ja and ar.

### 6.1 Accessibility checks (WCAG 2.2 AA, DEC-036)
**Automated.** Run axe with the tags in §2.3 on every template × language × state:
- form empty, form with errors, submitted, status, cancel panel;
- language switcher, mobile menu, dialogs, 404;
- `/offers/` and `/gallery/`;
- the admin reservation inbox and detail.

Pass = **0 critical + 0 serious**.

**Manual checks:**

| Check | Pass |
|---|---|
| Keyboard-only journey (ja, en, ar): form, errors, submit, status, cancel | All reachable; logical order in LTR and RTL; visible focus; no traps; picker operable; focus moves to the error summary |
| Screen readers: NVDA (Windows) and VoiceOver (iOS/macOS) full journey in ja, en, ar; spot-check ur, hi, bn; TalkBack spot check | Names, roles and values announced; errors and the "request received" status announced (4.1.3, `role="alert"` / live region); the language of parts switches |
| 2.4.11 Focus Not Obscured | Sticky header, mobile bar and suggestion bar never hide the focused element |
| 2.5.7 Dragging; 2.5.8 Target Size | A non-drag alternative exists; targets ≥ **24 × 24 CSS px**, or are spaced |
| 3.2.6 Consistent Help; 3.3.7 Redundant Entry | Help sits in the same place; no re-entry of data already given |
| 3.3.8 Accessible Authentication | Staff login and 2FA allow paste and password managers; invisible captcha |
| Contrast, resize, reflow | Text ≥ 4.5:1 (large text 3:1), UI ≥ 3:1, incl. text over photos; 200 % text, 320 px reflow and 400 % zoom with no loss of content and no 2-D scroll; reduced motion respected |
| Form semantics (TC-A11Y-006) | Programmatic labels per language; required not shown by colour only; errors in text, linked by `aria-describedby`; `autocomplete` name/email/tel; `novalidate` with localised messages |
| Accommodation channel (TC-A11Y-012) | A phone/email alternative is visible **before** the form fields in every language (Head Office contact before branch selection, branch `tel:` after it), and the captcha failure message repeats it |

An automated score is never evidence of conformance. No JIS X 8341-3 conformance claim is made.

---

## 7. Oracles for automation

### 7.1 Reservation boundary table (values read from API-01; defaults are proposals)
| Rule | Setting [TBD → default] | Probe | Expected |
|---|---|---|---|
| Party size | `party_min/party_max` [1–8, DEC-015] | min−1, min, max, max+1 | min..max → 201. Out of range → 422 `party_size_out_of_range`. The UI routes max+1 to the phone. |
| Lead time | `lead_time_minutes` [120] | now+L−1 min, now+L, now+L+1 | now+L−1 → 422 `lead_time_not_met` (message with branch phone); now+L and later → 201 |
| Booking window | `booking_window_days` [60] | today+W and today+W+1, with the clock at 23:59 and at 00:00 JST | today+W → 201; today+W+1 → 422 `outside_booking_window`; "today" is computed in JST |
| Slot grid | `slot_interval_minutes` [30] | 19:00 vs 19:10 | Off-grid → 409 `slot_unavailable`, `fields.time = not_a_slot` |
| Last order / gaps | per period | QAA 14:30, 15:00, 16:00 | 14:30 → 201; 15:00 and 16:00 → 409 `slot_unavailable` (`after_last_order` / `outside_opening_hours`) |
| Cross-midnight | QAC Fri 18:00–01:00, LO 00:30 | Sat 00:00, 00:30; Friday closure | Slots belong to Friday's period; a Friday closure removes them |
| Closures | `closed_all_day`, special hours | closed date X; special Y 12:00–15:00 at 19:00 and 13:00 | X → 409 `branch_closed_on_date`; Y 19:00 → 409 `outside_opening_hours`; 13:00 → 201 |
| Branch status | `status`, `reservations_enabled`, published | 4 blocking variants | Absent from the selector; 422 `branch_not_accepting` |
| Idempotency | `idempotency_key` | double click, replay, F5, back + resubmit | Exactly 1 row; replays → **200** with the original `public_ref`; exactly 1 E01 + 1 E07 |
| Soft duplicate | `momo_soft_duplicate_minutes` [10] | +9:59 and +10:01 (same email + branch + date + time) | +9:59 → existing ref (pending **or** confirmed), no row; +10:01 → new row |
| Pending limit | `max_active_pending_per_email` [3] | N+1 | 409 `pending_limit_reached`. The soft duplicate is checked **before** the pending limit. |
| Abuse | IP limit [5/hour, DEC-028]; Turnstile; honeypot | 6th request; bad token; honeypot filled | 429 `rate_limited` + `Retry-After`; 403 `captcha_failed`; honeypot → no row, no hint |
| Lengths | name 1–100; special request ≤ 1,000; staff message ≤ 500 | 0/100/101; 1,000/1,001; 500/501 | Over the limit → 400 `invalid_param` with `fields` |
| Time zone | Asia/Tokyo | browser TZ America/New_York, Asia/Dhaka, Asia/Dubai, Pacific/Kiritimati | `requested_date` X, `requested_time` 19:00, `requested_start_utc` = X 10:00Z; "JST" label in non-ja only |
| Settings consistency | `decision_cutoff ≤ lead_time − momo_min_decision_window_minutes` [30] | lead 60/cutoff 60; lead 120/cutoff 60 | The first save is refused; the second is saved |
| Dependency failure | DB unavailable | fault injection | 503 `service_unavailable`; nothing stored; the form keeps its values; a retry with the same key → 201 once |
| Status token (DEC-058/064) | Token only in the `X-Momo-Token` header; the link carries it in the fragment `#t=` | header / `?t=` / `t` in body / unknown / expired | 200 / 400 `invalid_param` / 400 `invalid_param` / 404 `not_found` / 410 `link_expired` (token valid until start + 24 h) |
| Origin / media type | Origin header = site host; JSON only | foreign Origin; form-encoded or text/plain | 403 `origin_rejected`; 415 `unsupported_media_type` (content type is checked first) |
| Public ref | `XXXX-XXXX` Crockford base32, no I/L/O/U | regex `^[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$` | Every 201 |

### 7.2 State machine (PHPUnit exhaustive; API-12 for staff, API-04 for customer)
- **T1** ∅ → pending (customer)
- **T2** pending → confirmed (BM/RS of the branch, HO, SA)
- **T3** pending → rejected (reason required)
- **T4a** pending → cancelled (customer token)
- **T4b** pending → cancelled (staff, reason required)
- **T5** pending → expired (system only)
- **T6a / T6b** confirmed → cancelled (customer before start / staff with reason)
- **T7** confirmed → completed and **T8** confirmed → no_show: from start until start + 72 h for BM/RS; HO may act later
- **N1** claim · **N2** note · **N3** modify (E06) · **N4** notification failed · **N5** reminder/escalation

Each transition asserts the status, timestamp column, event type and email.

| From \ To | pending | confirmed | rejected | cancelled | expired | completed | no_show |
|---|---|---|---|---|---|---|---|
| pending | — | ✓ T2 | ✓ T3 | ✓ T4a/T4b | ✓ T5 system | 409 | 409 |
| confirmed | 409 | — | 409 | ✓ T6a/T6b | 409 (job skips) | ✓ T7 in window | ✓ T8 in window |
| rejected, cancelled, expired, completed, no_show | 409 | 409 | 409 | 409 | 409 | 409 | 409 |

| Probe | Expected |
|---|---|
| Unassigned staff, any action | 403 `forbidden_branch` |
| Customer cancel after start or on a terminal status | 409 `cancel_not_allowed` |
| Attendance outside the window (BM/RS) | 422 `attendance_window`; HO allowed |
| Decline/cancel without a reason | 400 `reason_required` |
| More than 2 suggested times | 400 `invalid_param` (`fields.suggested_times`) |
| Stale `version` | 409 `version_conflict` + refreshed record; exactly one transition + one email |
| Lazy expiry | A staff action or customer status/cancel call on a pending request past start − cutoff first applies T5 in its own transaction (E05 once). The call then returns 409 `invalid_transition`, and Accept/Decline are hidden. |
| Expiry job | Runs every 5 min. A request expires at the first run ≥ start − `decision_cutoff` **[TBD → 60 min, DEC-012]**. |
| SLA | E08 reminder at `response_sla_minutes` **[TBD → 120; wall clock vs business hours, DEC-013]**; HO escalation at 2× |
| Suspected spam | Cancel with reason `suspected_spam` sends no customer email |

### 7.3 Error catalogue (Doc 04 §16.0.4, DEC-063 — the only codes clients may receive)
Body: `{ "code": "...", "message": "<localised>", "data": { "status": <http>, "fields": { "<field>": "required|invalid_format|too_long|out_of_range|not_allowed" } } }`. WordPress core codes are re-coded: `rest_invalid_param` and `rest_missing_callback_param` → `invalid_param`; `rest_invalid_json` → `invalid_json`; `rest_not_logged_in` and `rest_cookie_invalid_nonce` → `rest_forbidden`. **Test: no other `code` value ever appears** in any response captured by the suites.

| code | HTTP | Endpoints |
|---|---|---|
| `invalid_param` | 400 | all |
| `invalid_json` | 400 | POST/PATCH |
| `reason_required` | 400 | API-12, API-16 |
| `rest_forbidden` | 401/403 | API-10..16 |
| `forbidden_branch` | 403 | API-10..16 |
| `forbidden_field` | 403 | API-15 (+ API-16 per TRD) |
| `captcha_failed` | 403 | API-02, contact |
| `origin_rejected` | 403 | API-02, API-04 |
| `branch_not_found` | 404 | API-01, API-02 (+ API-14/15 per TRD) |
| `not_found` | 404 | API-03, API-04, API-11..16 |
| `method_not_allowed` | 405 | core `wp/v2/momo_*` |
| `slot_unavailable` | 409 | API-02, API-16 |
| `pending_limit_reached` | 409 | API-02 |
| `cancel_not_allowed` | 409 | API-04 |
| `invalid_transition` | 409 | API-04, API-12, API-16 |
| `version_conflict` | 409 | API-12, API-13 (claim), API-15, API-16 |
| `link_expired` | 410 | API-03, API-04 |
| `payload_too_large` | 413 | POST/PATCH (8 KB public) |
| `unsupported_media_type` | 415 | POST/PATCH |
| `branch_not_accepting`, `lead_time_not_met`, `outside_booking_window`, `party_size_out_of_range` | 422 | API-01/02/16 (`branch_not_accepting` also API-01) |
| `attendance_window` | 422 | API-12 |
| `rate_limited` | 429 | API-02/03/04/10..16 |
| `internal_error` / `service_unavailable` | 500 / 503 | all; nothing saved; no stack trace or path |

**Routes under test** (namespace `/momos/v1`):

| ID | Method and path | Note |
|---|---|---|
| API-01 | `GET /branches/{code}/reservation-rules?lang=` | Public |
| API-02 | `POST /reservations` | Public |
| API-03 | `GET /reservations/{public_ref}` | Header `X-Momo-Token` |
| API-04 | `POST /reservations/{public_ref}/cancel` | |
| API-10 | `GET /staff/reservations` | |
| API-11 | `GET /staff/reservations/{id}` | |
| API-12 | `POST /staff/reservations/{id}/transitions` | |
| API-13 | `POST /staff/reservations/{id}/notes` | |
| API-14 | `GET /staff/branches/{code}/menu-items` | |
| API-15 | `PATCH /staff/branches/{code}/menu-items/{dish_id}` | |
| API-16 | `PATCH /staff/reservations/{id}` | Should |
| API-17 | `POST /csp-report` | Could |

Request and response schemas are in `reservation.md` and `menu-dietary.md`.

### 7.4 Role × resource oracle (TC-ACL-001 capability level; TC-ACL-002 UI/REST)
Legend: C create · R read · U update · D delete/archive · P publish · E export · (b) assigned branches only · — denied (403 + `access.denied` audit row).

| Resource | SA | HO | MM | CE | TR | BM | RS |
|---|---|---|---|---|---|---|---|
| Plugins, theme, security settings, code | CRUD | — | — | — | — | — | — |
| Brand settings | CRUD | RU | R | R | translate | R | — |
| Pages (corporate/legal) | CRUDP | CRUDP | R | CRUDP (legal: draft only) | translate | — | — |
| News (C) | CRUDP | CRUDP | — | CRUDP | translate | — | — |
| Offers | CRUDP | CRUDP (approve) | R | CRU (submit) | translate | CRU (b, submit for HO approval) | R (b) |
| Branch identity fields | CRUDP | CRUDP | R | R | translate text | R (b) | R (b) |
| Branch description & photos | CRUDP | CRUDP | — | U | translate | U (b) | — |
| Branch reservation settings | CRUD | CRUD | — | — | — | R (b) | R (b) |
| Hours, closures, special hours | CRUD | CRUD | R | R | — | CRUD (b) | R (b) |
| Notices | CRUDP | CRUDP | — | CRUDP | translate | CRUDP (b) | R (b) |
| Menu categories | CRUD | CRUD | CRUD | R | translate | R | R |
| Dishes (non-claim) | CRUDP | CRUDP | CRU + submit (P if granted) | R | translate | R | R |
| Dish claim fields | U with CAP-DCA | U with CAP-DCA | R | R | translate approved text | R | R |
| Halal/certificate register | CRUD with CAP-DCA | CRUD with CAP-DCA | R | — | — | R (b, `momo_cert_branches`) | — |
| Branch menu: offered, price | CRUD | CRUD | CRUD | — | — | R (b) (price/offered PATCH → 403 `forbidden_field`) | R (b) |
| Branch menu: availability | RU | RU | RU | — | — | RU (b) | RU (b) [TBD → yes] |
| Reservations view / actions / PII | R/U/R | R/U/R | — | — | — | (b) | (b) |
| Reservation export (C) | E | E | — | — | — | — | — |
| Reservation report (no PII) | R | R | — | — | — | R (b) | — |
| Users, roles, assignments | CRUD | CRUD (not SA) | — | — | — | R (b staff list) | — |
| Translation jobs | all | assign/manage | send | send | U own jobs | — | — |
| Audit log (SCR-A16) | R | R | — | — | — | — (own-branch history only in the SCR-A09 timeline) | — |
| GA4 access | R | R | R | R | — | — | — |
| DSR tools; language enablement (`momo_manage_languages`) | U | U | — | — | — | — | — |

Users are disabled, never deleted. A role change, branch-assignment change or disable destroys all of that user's sessions immediately (TC-ADM-009, TC-ACL-011).

Vendor ROLE-01 accounts are denied reservation PII except during an audited break-glass grant of ≤ **[TBD → 8 h]**. Export and DSR stay denied even during a grant (TC-ACL-022).

### 7.5 Branch isolation channels (20 ordered pairs X→Y over QAA..QAE; run on every staging deploy)
| Channel | Probe as staff of X | Expected |
|---|---|---|
| Admin lists, counts, filters, search | list/count; filter branch=Y; search Y's ref | Only X data and totals; filter 403; ref not found |
| Direct admin URL | Detail of Y's reservation id | 403; no data in the body; `access.denied` |
| REST API-10..16 | Y code/id with a valid session + nonce | 403 `forbidden_branch`; 0 DB changes |
| admin-ajax / admin-post | Replay every momos-core action with Y ids | Denied. A nonce never replaces the capability + branch check. |
| Export | Any export call | BM/RS 403; HO export of QAB contains only QAB rows |
| Repository | Scoped repository without an access context (PHPUnit) | Throws; SQL filters `branch_id IN (assigned)` |
| Notifications/digests | Submit at X | Only X recipients get E07/E08/E09; digests contain own-branch items only |
| Branch data | Edit Y hours/closures/notices/availability | 403; Y unchanged |
| Dashboard / certificate register | Widgets; Y certificate URL | Counts include assigned branches only; Y record 403 |
| Public | WP search, feeds, sitemaps, `/wp-json/wp/v2/*`, theme loops; anonymous API-10/11 | No reservation data anywhere; staff routes 401 |
| Session changes | Unassign/demote/disable mid-session | Next request denied |

### 7.6 Claims display oracle (GATE-02)
| Record state | Branch page / Halal page | Dish | JSON-LD |
|---|---|---|---|
| `certified`, active, within validity, approved, mark permission true | `certified.{scope}` template (certifier, scope, cert no., valid until) + mark from the register upload | approved halal basis if linked | `suitableForDiet` only where the active scope covers the dish **[TBD — SEO lead + counsel]** |
| Same, mark permission false | Certified wording, **no mark** | as above | as above |
| `halal_ingredients` approved | `halal_ingredients` template; no certification wording or seal | ingredients statement if approved | No HalalDiet |
| `no_claim`, `pending_verification`, expired, revoked, future `valid_from` | `no_claim` neutral statement | No halal claim | none |

| Allergen state | Label key (EN / JA draft) |
|---|---|
| contains | `allergen.state.contains` Contains / 含む |
| may_contain | `allergen.state.may_contain` May contain (cross-contact) / 含む可能性あり（混入の可能性） |
| not_contained | `allergen.state.not_contained` Not contained (checked) / 含まない（確認済み） — never "free" or "safe" |
| not_verified (default) | `allergen.state.not_verified` Not verified / 未確認 + banner `allergen.banner.not_verified` "Allergen information not verified — please ask staff." / アレルゲン情報は未確認です。スタッフへお尋ねください。 The banner shows when any item is `not_verified` **or** `momo_allergen_review_required = 1`. |

**Shown on every dish page:**
- `allergen.disclaimer.voluntary`;
- `allergen.tell_staff`;
- `allergen.last_verified` ("Information last verified on {date}." / 最終確認日：{date});
- `allergen.cross_contact`, **only** when `momo_allergen_cross_contact_confirmed.confirmed = true`.

**Allergen seed: exactly 29 items.**
- Mandatory (9): shrimp えび, cashew_nut カシューナッツ, crab かに, walnut くるみ, wheat 小麦, buckwheat そば, egg 卵, milk 乳, peanut 落花生.
- Recommended (20): almond アーモンド, abalone あわび, squid いか, salmon_roe いくら, orange オレンジ, kiwifruit キウイフルーツ, beef 牛肉, sesame ごま, salmon さけ, mackerel さば, soybean 大豆, chicken 鶏肉, banana バナナ, pistachio ピスタチオ, pork 豚肉, macadamia_nut マカダミアナッツ, peach もも, yam やまいも, apple りんご, gelatin ゼラチン.
- No Western-list items such as `gluten`, `shellfish` or `nuts`.

**Approval and change rules:**
- Claim edits need CAP-DCA plus a `source_document`, and stamp `verified_by` and `verified_at`.
- There is no bulk "set all to not contained" action.
- Certificate expiry is applied by the job at 00:10 JST. E11 warnings go out at 30 days, at 7 days and on the expiry day, once per variant.
- Revocation applies immediately on save.
- Claim text renders in a language only after human review; unreviewed MT never renders.

### 7.7 Microcopy oracle (EN / JA draft; keys from Doc 07 §6; JA requires native review)
| Key | EN | JA |
|---|---|---|
| `status.pending` | Request received — not yet confirmed | リクエスト受付・未確定 |
| `status.confirmed` | Confirmed by the branch | 店舗にて確定済み |
| `status.rejected` | Declined | お受けできませんでした |
| `status.cancelled` | Cancelled | キャンセル済み |
| `status.expired` | Expired — not confirmed in time | 期限切れ（時間内に確定できませんでした） |
| `status.past` (completed/no_show; customers never see those codes) | The date of this reservation has passed. | このご予約の日時は過ぎています。 |
| `res.submitted.heading` | Request received — not yet confirmed | リクエストを受け付けました（まだ確定していません） |
| `res.submitted.reference` | Your reference: {ref} | 受付番号：{ref} |
| `res.submitted.next` | The branch will review your request and reply by email. A table is not set aside for you until the branch accepts your request. | 店舗がリクエストを確認し、メールでご連絡します。店舗が承認するまで、お席は確保されていません。 |
| `res.submitted.expiry` | If the branch has not replied by {time}, your request expires and we will email you. | {time}までに店舗から返信がない場合、リクエストは期限切れとなり、メールでお知らせします。 |
| `res.submitted.generic` (direct visit, no session data) | If you sent a request, check your email for the reference number. If you did not receive an email, contact the branch. | リクエストを送信された方は、メールに記載の受付番号をご確認ください。メールが届いていない場合は店舗へお問い合わせください。 |
| `res.submitted.status_link` | View request status | リクエストの状況を確認する |
| `res.carrier_tip` | Not receiving our emails? If you use a Japanese mobile carrier address, allow mail from @{domain}. | メールが届かない場合：携帯キャリアのメールアドレスをご利用の方は「@{domain}」からのメールを受信できるよう設定してください。 |
| `status.link_invalid` | This link is no longer valid. If you need help, contact the branch. | このリンクは無効です。お問い合わせは店舗までご連絡ください。 |
| `status.cancel.not_allowed` | This request can no longer be cancelled online. Please call {phone}. | このリクエストはオンラインでは取り消しできなくなりました。{phone}へお電話ください。 |
| `status.no_js` | Please contact the branch: {phone} | 店舗へお問い合わせください：{phone} |
| Staff labels | Pending · Accepted · Declined · Cancelled · Expired · Attended · No-show; actions Accept · Decline · Cancel request · Claim | 未対応 · 承認済み · お断り済み · キャンセル済み · 期限切れ · 来店済み · 無断キャンセル; 承認する · お断りする · キャンセルする · 担当する |

**Prohibited-term scan** (TC-RES-021, TC-DIET-013; lists in `fixtures/prohibited-terms/{lang}.json`):
- **Request context.** Scope: C09, C10, C11 while pending, E01, and E04/E06 while pending.
  - en: booked, you're booked, reservation confirmed, booking confirmed, confirm table reservation, guarantee your seating, instant seating, your table is reserved, reservation complete, your reservation.
  - ja: 予約完了, 予約確定, 予約が確定, 予約を確定, ご予約を承りました, このご予約, 席を予約 (as a status).
  - ar: تأكيد الحجز, تم تأكيد الحجز.
  - Allow-list: only the negated `status.pending` / `res.submitted.heading`.
- **Claim terms** (everywhere, unless rendered from an approved `certified.*` template). Examples:
  - en: "100% halal", "fully halal", "halal certified" outside a template, "allergen-free", "free from {allergen}", "gluten-free", "safe for allergies", "no risk";
  - ja: 100%ハラール, 完全ハラール, アレルゲンフリー, 完全除去, アレルギーの方も安心, グルテンフリー.
  - No seal image is allowed unless a `certified` record with mark permission is active.
- **Ordering terms** (everywhere). en: order now, add to cart, checkout, place order, takeout, delivery, coupon code, redeem. ja: 今すぐ注文, カートに追加, 注文を確定. Ordering routes `/cart/`, `/checkout/` and `/order/` return 404 (TC-REG-007).
- ur/hi/bn and the full ar lists: **[TBD — native reviewers, GATE-04]**.

**Localisation (TC-I18N-005/006):**
- Digits are always Western on output (`-u-nu-latn`; bn needs this override).
- ja long date `2026年12月3日（木）`; en `Thu 3 Dec 2026`; time `19:00` (24 h).
- "JST" label only in non-ja languages.
- Price `¥1,500（税込）` / `¥1,500 (tax incl.)`.
- Inputs normalise ٠–٩, ۰–۹, ०–९, ০–৯ and ０–９ before validation.
- In ar/ur, phones, prices, dates and references sit inside LTR isolates (`<bdi>` or `dir="ltr"`). User text uses `dir="auto"`.

### 7.8 Analytics events (GATE-09)
| Event | Trigger | Params (no PII) | Fires |
|---|---|---|---|
| view_branch | Branch page load | branch_code, language | Once per load |
| view_menu | Menu hub/category load | menu_category, branch_code (if selected), language | Once per load |
| view_dish | Dish page load | dish_sku, menu_category, language | Once per load |
| view_halal_information | Halal page load or halal FAQ expanded | language, cta_location | Once per load/expand |
| select_language | Switcher option chosen | language (target), cta_location | Once per click |
| click_reservation | Any "Request a table" CTA | cta_location, branch_code (if known), language | Once per click |
| reservation_form_start | First field interaction per form load | branch_code (if selected), language | Once per form load |
| generate_lead | **Only** when API-02 returns 201; pushed on the form page before navigation | branch_code, language, party_size_band `1–2 / 3–6 / 7+` | Never on validation error, 4xx/5xx, refresh or idempotent replay (200) |
| click_phone | `tel:` link | branch_code, cta_location, language | Once per click |
| click_directions | Directions/map link | branch_code, cta_location, language | Once per click |

**C10 `/reservation/submitted/` and C11 status** load **no** third-party request. They send `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`, and repeat requests show `MISS`/`BYPASS` (TC-PRV-016). No advertising pixels.

### 7.9 Notification matrix (delivery evidence = a message in an inbox, never a log line)
| Tpl | Trigger | To | Langs | Key assertions |
|---|---|---|---|---|
| E01 | T1 | customer | 6 | "not yet confirmed"; reference; expiry time; status link; branch phone; carrier tip; JST label in non-ja only; plain-text part |
| E02 | T2 | customer | 6 | Details + optional staff message only; never an internal note or staff name |
| E03 | T3 | customer | 6 | Reason label; ≤ 2 suggested times (informational) |
| E04 | T4a/T6a, T4b/T6b | customer | 6 | "by you" / "by branch" variant; none for `suspected_spam` |
| E05 | T5 | customer | 6 | Apology + branch phone |
| E06 | N3 | customer | 6 | Before/after; while pending says "details of your request were updated" |
| E07 | T1 | branch recipients | ja/en (recipient locale) | Ref, branch, date/time, party, **given name only**, "Special request: yes/no", admin link; no email/phone/note |
| E08 | N4/N5 | branch recipients; HO for escalation | ja/en | Reminder / escalation / failure variant |
| E09 | T4a/T6a | branch recipients | ja/en | Minimal PII |
| E10 | daily | branch + HO | ja/en | Expired, failed, attendance missing, needs action; own branch only |
| E11 | Certificate job | HO list + CAP-DCA holders | ja/en | No customer data; once per variant (cert_30 / cert_7 / cert_expired) |

**All messages:**
- exactly one address in To, with no Cc/Bcc;
- Reply-To = the branch address only;
- the subject contains at most the reference;
- links point to the production domain in the preferred language, with no shorteners;
- E01–E06 show only "We have received your note" for a special request; E07–E10 show only "Has a note: yes".

**Failure handling:** 5 attempts with backoff → flag "customer not notified" + E08 alert; the reservation is unchanged.

**Inbox set:** Gmail, Outlook.com, iCloud, Yahoo Japan, docomo/au/SoftBank ⟦carrier test addresses — QA lead⟧.

---

## 8. Human-style E2E scenarios (staging, standard seed, screen-recorded, real devices)
Rules for every run:
- Testers act as the persona, use only what the UI shows, and never use admin shortcuts.
- Staff steps are done by a second tester using the named account.
- "Tomorrow 19:00" means a slot that is valid for the branch's rules.
- Each run records: tester, build, device, start/end time, defects, and a persona-confidence score from 1 to 5.

| ID | Setup | Journey | Pass when |
|---|---|---|---|
| **E2E-01** ar tourist, iPhone Safari (P0, GATE-04) | QAA certified, "alcohol not served"; phone +971 50 000 0000; Gmail app | /ar/ from search → Halal page → directory → QAA (today's hours, copy the Japanese address) → "Request a table" (QAA prefilled) → tomorrow 19:00, party 3, name محمد علي, F-BIDI request → wrong email once, then corrected → submit → read E01 → qa_rs_a (Android) accepts with a message → open E02 and the status link | Completed unaided in RTL. Ref on screen = ref in E01. Asked "is your table booked?" the tester answers "not yet". E02 in Arabic: QAA, date, 19:00 + JST label, party 3. Status = "Confirmed by the branch". No reversed digits, no LTR fields inside RTL, the sticky bar never hides submit, no ja/en leakage. |
| **E2E-02** bn family of 6, Android Chrome (P0) | QAB party_max ≥ 6; name রহিম উদ্দিন; party typed as ৬ | /bn/ → menu → biryani category with QAB selected (QAB prices, sold-out labels) → dish → request QAB Saturday lunch, party ৬, "high chair ×1" → qa_bm_b accepts | Party stored as 6; Bengali conjuncts render in the UI and emails; branch price with tax label; staff see the high-chair note; E02 in Bengali; no English validation strings |
| **E2E-03** ja host for Muslim colleagues, desktop → iPhone (P0) | QAA certified; QAC no_claim | Halal page compares QAA and QAC → QAA alcohol policy and facilities (prayer space only if confirmed) → request 5 people 12:00 "同僚がハラール料理を希望" → later checks status on iPhone → accept | Host can explain the QAA claim (certifier, scope, validity) and that QAC makes no claim; no 予約完了/予約確定 before acceptance; natural Japanese E01/E02; Japanese date format, 24 h |
| **E2E-04** short notice, Android en (P1) | Clock 18:00 JST; QAA lead 120 | Wants a table "in 30 min" → slots before 20:00 not offered → reads the message → taps call | No slot inside the lead time; English "please call" message with a working `tel:+81…` link; direct API-02 → 422 `lead_time_not_met`; no row |
| **E2E-05** staff on phone declines; ur customer rebooks (P0) | qa_rs_b on Android during service; customer ur | Submit QAB tomorrow 19:00 ×4 → staff opens from the E07 link, logs in with 2FA, declines `fully_booked` with 18:00 + 20:30 → Urdu E03 → new request 20:30 → accepted | Decline takes ≤ 3 screens with no zoom; reason mandatory; E03 shows both times in correct RTL order; a 3rd suggestion is refused; first request stays `rejected` |
| **E2E-06** concurrency (P0, GATE-03) | qa_rs_a (Android) + qa_bm_a (desktop) both open the same pending QAA request | Manager accepts; staff declines without refreshing | Staff sees the localised conflict message and the refreshed `confirmed` record; exactly 1 status change, 1 event pair, 1 E02 |
| **E2E-07** private-event closure (P0) | QAC: 2 pending + 2 confirmed on date X | qa_bm_c adds a `private_event` closure on X → customers try X → staff in "needs action": decline pending (`closed_or_private_event`), phone and cancel one confirmed, modify the other to X+1 ("agreed with guest") | X unbookable immediately (picker + API); 4 items flagged, **none auto-cancelled**; customers get E03/E04/E06 only after each staff action; translated closure reason on the public page; no cached page still offers X |
| **E2E-08** expiry (P0) | Clock; QAD cutoff 60; request today 20:00 left untouched | Time passes the SLA → 19:00 JST → job → customer opens E05 + status → next morning HO reads E10 | `expired` at the first job run ≥ 19:00 (≤ 5 min late); E05 apologises with the branch phone; status shows "Expired — not confirmed in time"; Accept/Decline hidden; API-12 → 409 `invalid_transition` |
| **E2E-09** carrier email failure (P0) | docomo address filtering the domain; run 2 with the provider fault stub failing all attempts | Submit with phone → result shows ref + whitelisting tip → no E01 → follow tip → open the status link saved from the result screen. Run 2: after 5 failed attempts, staff see "customer not notified" and call | Request never lost or rolled back; ref and status usable without email; later emails arrive after whitelisting; run 2 flag + E08 alert visible on a phone |
| **E2E-10** allergy diner, Android hi (P0, GATE-02) | Dishes with `contains` wheat, `may_contain` peanut, and default `not_verified` | Browse category → 3 dishes → allergen tables, legend, disclaimer → filter (if built) → mentions peanut allergy in the special request | Correctly tells contains / may contain / not verified; banner on the unverified dish; disclaimer + tell-staff in Hindi; no "allergen-free"/"safe"; staff see the note verbatim |
| **E2E-11** Urdu translation missing (P1, GATE-04) | D9013 published ja + en only | /ur/ menu lacks D9013 → open D9013 ja link → switch to اردو → Urdu home with notice → HO sees it in Translation gaps → qa_tr_ur translates, qa_rev_ur approves → appears | No ja/en text ever on Urdu pages; gap listed with its age; published only after review |
| **E2E-12** BM URL tampering (P0, GATE-03) | qa_bm_a knows a QAB id and ref | Edit the admin URL id; API-11 from DevTools; filter the inbox by QAB; search the QAB ref; export; edit QAB hours | Every attempt denied with no data in the response; each denial audited; QAA work unaffected |
| **E2E-13** sixth branch, staging (P1) | qa_ho; no deploy | Create QAF: identity, facts, hours, settings, recipients, staff, branch menu rows; translate; publish; customer books; QAF staff accept | QAF appears in the directory, selectors, footer, sitemap, hreflang and JSON-LD without code changes; routes only to QAF; QAA–QAE unchanged; nothing hard-coded to 5 |
| **E2E-14** translator publishes ar (P1, GATE-04) | qa_tr_ar + qa_rev_ar; dish with an approved allergen statement | Update the Arabic description; try to change claim text and the ja source; submit → reviewer approves → check mobile | Only the ar description changes; claim text editable only as a translation of approved text; ja source untouched; RTL intact; job history shows both people |
| **E2E-15** certificate expires overnight (P0, GATE-02) | Clock; QAA `valid_until` = today; E11 cert_30/cert_7 already sent | 23:59 claim shows → past midnight → job at 00:10 → at 00:30 view QAA, Halal page and a dish in ja/ar/ur → HO checks register + audit | No certified wording, mark or `suitableForDiet` anywhere (pages, JSON-LD, cache); `no_claim` fallback (or a separately approved `halal_ingredients`); `expired` audited; E11 cert_expired sent once |
| **E2E-16** cancel in LINE in-app browser (P1) | Status link from E01 shared to self in LINE; iPhone | Open inside LINE → status → cancel → reopen → try the link with another ref | Works in LINE; `cancelled_by = customer`; E04 + E09; reopen shows "Cancelled"; altered ref gives a generic not-found |
| **E2E-17** SLA reminder + escalation (P1) | Clock; QAE SLA 120 [DEC-013], cutoff 60; request 6 h later | Nobody acts → at SLA: E08 + "overdue" badge → at 2×SLA: HO escalation → HO phones → staff accept before cutoff | Reminder and escalation fire once each at the right times; accepting stops reminders; no expiry |

Each scenario maps to TC-E2E-0NN (same number). Automate the deterministic parts of E2E-06, 08, 12, 15 and 17 in Playwright, using the clock and two contexts. The rest stay manual with recordings.

---

## 9. P0 test cases by area (from `csv/test_cases.csv`; all must pass for Go)

Automation keys:
- **Playwright:** UI tests.
- **Playwright API:** `APIRequestContext`.
- **PHPUnit:** WP test suite.
- **manual:** human execution with evidence.

Preconditions use the shorthands of §3: SEED, CLOCK (= `momos_frozen_now`), MAIL (= catcher/Mailpit) and ACC (accounts). Values in ⟦⟧ are read from settings at run time. E2E and UAT P0 cases are in §8 and §12.

#### RES — Reservation — customer (24 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-RES-001 | Valid submission creates one pending request atomically · _PHPUnit + Playwright_ | SEED; MAIL; QAA open | 1) Submit valid request via UI. 2) Inspect API response and DB rows. | 201 with public_ref XXXX-XXXX (Crockford, no I/L/O/U); status pending; 1 created event; E01 and E07 queued in same transaction; consent_version, consent_at, preferred_lang, source_lang stored. |
| TC-RES-002 | Form fields and required markers · _Playwright_ | SEED | 1) Inspect form in 6 languages. | Branch*, date*, time*, party size*, name*, email*, phone (optional), special request (guidance text), preferred language (defaults to UI language), consent* with same-language policy links, invisible captcha. |
| TC-RES-003 | Data minimisation · _Playwright API_ · **GATE-06** | SEED | 1) Inspect DOM and API-02 schema. 2) POST extra fields religion, nationality, dob, marketing_opt_in and tampered status=confirmed. | No such fields in UI; API rejects unknown or tampered fields with 400 invalid_param and stores nothing extra; status is always pending on creation. |
| TC-RES-004 | Client and server validation with error summary · _Playwright + axe_ | SEED | 1) Submit empty form in ja and ar. 2) Send same empty payload directly to API-02. | All required errors inline and in a summary announced to AT; focus to summary; data retained; API 400 invalid_param with fields. |
| TC-RES-005 | Invalid formats and hostile payloads · _PHPUnit + Playwright API_ | Fixture F-HOSTILE | 1) API-02 with emails 'a@', 'a b@x.jp'; party -1, 0, 'abc', 2.5; name 101 chars; request 1,001 chars; name with CRLF 'Bcc:'. 2) Valid request with <script>, "><img onerror>, ' OR 1=1--, =HYPERLINK() in text fields. | Step 1: 400 invalid_param with fields, no row. Step 2: stored as inert text; escaped in admin, emails, CSV; no header injection. |
| TC-RES-006 | Picker driven by API-01 rules · _Playwright API_ | SEED: QAA lunch/dinner periods, closure on date X, lead 120 min, window 60 days, interval 30 min | 1) Open picker for QAA. 2) Call API-01 directly. | Closed/out-of-window dates disabled; times listed as 30-min slots within periods up to last order; API-01 contains no staff or customer data. |
| TC-RES-007 | Short-notice request redirected to phone · _Playwright API_ | CLOCK; QAA lead_time 120 min | 1) At 18:00 JST pick 19:00 today. 2) POST 19:00 directly to API-02. | Slot not offered; message with tap-to-call branch phone in UI language; API returns 422 lead_time_not_met (localised message with the branch phone), no row. |
| TC-RES-008 | Boundary: party size · _PHPUnit + Playwright_ | QAA party_min/party_max from settings (proposal 1-8 ⟦DEC-015⟧) | 1) Test min-1, min, max, max+1 via UI and API-02 (parameterised from settings). | min and max accepted; min-1 and max+1 rejected by API-02 with 422 party_size_out_of_range, no row; in the UI max+1 cannot be submitted and shows the branch phone/contact route (BR-RES-10). |
| TC-RES-009 | Boundary: lead time · _PHPUnit_ | CLOCK frozen; lead_time L from settings | 1) Submit start = now+L-1 min, now+L, now+L+1 (slot-aligned variants). | now+L-1 rejected with 422 lead_time_not_met (no row); now+L and later accepted (201). |
| TC-RES-010 | Boundary: booking window · _PHPUnit_ | CLOCK at 23:59 JST and at 00:00 JST; window W | 1) Submit today+W and today+W+1 at both clock times. | today+W accepted (201), today+W+1 rejected with 422 outside_booking_window (no row); 'today' always computed in JST. |
| TC-RES-011 | Boundary: last order, gaps, slot grid · _PHPUnit_ | QAA lunch LO 14:30, dinner from 17:30, interval 30 | 1) Submit 14:30, 15:00, 16:00 (gap), 19:10 (off-grid). | 14:30 accepted; 15:00, 16:00, 19:10 rejected 409 slot_unavailable with field 'time'. |
| TC-RES-013 | Closures and special hours enforced server-side · _PHPUnit + Playwright API_ | QAA closed_all_day X; special_hours Y (12:00-15:00 only) | 1) Try X in picker and via API. 2) Try Y at 19:00 and 13:00. | X not selectable and API 409 slot_unavailable (fields.time = branch_closed_on_date); Y 19:00 rejected with 409 slot_unavailable (outside_opening_hours), 13:00 accepted. |
| TC-RES-014 | Branch status blocks submissions · _PHPUnit + Playwright API_ | Variants: temporarily_closed; reservations_enabled=false; unpublished; closed_permanently | 1) For each variant open form and POST API-02. | Branch absent from selector; API 422 branch_not_accepting with localised message and phone route; no row. |
| TC-RES-015 | Times independent of device time zone · _Playwright_ | Browser time zones America/New_York, Asia/Dhaka, Asia/Dubai, Pacific/Kiritimati | 1) Book 19:00 on date X from each zone. 2) Inspect DB and E01. | requested_date X, requested_time 19:00, requested_start_utc = X 10:00Z; UI and emails show 19:00 (with the JST label in non-ja languages; none in ja — DEC-062). |
| TC-RES-017 | Idempotent submission · _Playwright + PHPUnit_ | MAIL | 1) Double-click submit. 2) Replay POST with same idempotency_key. 3) F5 on /reservation/submitted/. 4) Back and resubmit. | Exactly 1 row; repeats return 200 with original public_ref; exactly one E01 and one E07. |
| TC-RES-018 | Soft-duplicate window 10 minutes · _PHPUnit_ | CLOCK | 1) Submit request R1. 2) New form load, same email+branch+date+time at +9 min 59 s. 3) Repeat at +10 min 1 s. 4) Staff accepts R4 and the guest resubmits R4 within 10 min. 5) At the pending limit, resubmit an existing pending request within 10 min. | Step 2 returns R1's reference, no new row; step 3 creates new pending (subject to pending limit); step 4 returns R4's reference (soft duplicate covers pending or confirmed); step 5 returns the existing reference, not 409 (soft-duplicate check precedes the pending limit, SDD §5.2). |
| TC-RES-020 | Result screen content · _Playwright_ · **GATE-04** | SEED; response promise configured in variant B only | 1) Submit in 6 languages. 2) Inspect page and HTTP headers. | Reference XXXX-XXXX, summary (masked email; times with the JST label shown in non-ja languages only; ja shows none (DEC-062); 'expires if undecided by {start − cutoff}'), 'Request received — not yet confirmed', promise only in variant B, next steps, status link, branch phone, carrier-whitelisting tip; no .ics; noindex; content comes from sessionStorage written by the form: refresh shows the same content until the tab closes; a direct visit without data shows the generic message (no redirect, no PII). |
| TC-RES-021 | No confirmation wording before acceptance · _Playwright_ · **GATE-04** | Prohibited-term lists per language | 1) Scan result screen, E01, pending status page in 6 languages. | 0 terms from the Doc 07 §6.6 request-context list (incl. confirmed, booked, reserved, 'your reservation', 予約完了, 予約確定, 予約が確定, ご予約を承りました, このご予約 and the per-language equivalents); the negated DEC-011 label ('not yet confirmed', 未確定) is the only permitted use of 'confirmed'/確定 (BR-RES-01). |
| TC-RES-022 | Abuse controls: honeypot, captcha, rate limit · _Playwright API_ | Staging with Turnstile test keys; IP limit proposal 5/hour ⟦DEC-028⟧ | 1) POST with honeypot filled. 2) POST with failing captcha token. 3) POST valid requests from one IP until the per-IP limit of TRD §4.3.6 ⟦proposal 5/hour⟧ is exceeded. | 1) no row, no hint which check failed; 2) 403 captcha_failed localised; 3) first request over the limit 429 rate_limited with Retry-After, localised with phone route. |
| TC-RES-025 | Network failure during submit · _Playwright_ | Playwright offline toggle | 1) Go offline at submit. 2) Observe. 3) Go online and retry. | Localised error, fields retained; retry with same key yields exactly 1 row and 1 E01. |
| TC-RES-030 | Token cannot reach other reservations · _Playwright API_ | Reservations A and B | 1) Use A's token with B's public_ref. 2) Enumerate 200 random references. | 404 not_found for all; no data; rate limit triggers; no timing difference revealing existence ⟦best effort⟧. |
| TC-RES-037 | TEST-branch requests are isolated test data · _Playwright API + manual_ · **GATE-10** | TEST branch configured; QA inbox recipient | 1) Submit at TEST branch in production. 2) Check recipients, report, GA4, sitemap, directory. | Only QA inbox notified; request excluded from F-RES-12 report and KPIs; generate_lead suppressed/tagged test; TEST absent from directory, sitemap, JSON-LD and menus. |
| TC-RES-039 | Database failure during submit returns 503 service_unavailable · _PHPUnit + Playwright API_ | SEED; fault injection on the DB connection for API-02 (staging/CI only) | 1) Fill the form in ja and ar and submit while the database is unavailable. 2) Inspect the API response, DB rows, queued notifications and the form. 3) Restore the DB and resubmit with the same idempotency key. | Step 1: 503 service_unavailable with the localised message and branch phone; nothing stored (no reservation, event or notification row); the form keeps all entered values (AC-RES-01-14). Step 3: 201, exactly one row, one E01 and one E07. |
| TC-RES-040 | Status token travels only in the URL fragment and the X-Momo-Token header · _Playwright + Playwright API + log grep_ | SEED; MAIL; pending request R with its E01 status link; web-server and CDN access logs readable on staging | 1) Open the E01 link /{lang}/reservation/status/{ref}/#t={token} with network capture. 2) Call API-03 with ?t={token} and no header. 3) Call API-04 with "t" in the JSON body and no header. 4) Call API-03 and API-04 with the X-Momo-Token header. 5) Open the link with JavaScript disabled. 6) On the status page switch language to ar. | Step 1: the page script removes the fragment from the address bar (history.replaceState) and calls API-03 with the X-Momo-Token header; no request URL, access-log or CDN-log line and no Referer contains the token. Steps 2-3: 400 invalid_param (token accepted only in the header). Step 4: 200 (API-04 body carries only lang). Step 5: no-JS message telling the guest to contact the branch. Step 6: the token is re-attached from sessionStorage and the status loads in Arabic (no 'link no longer valid'). |

#### RSA — Reservation — staff actions & state machine (14 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-RSA-001 | Inbox scope, sort, filters, search · _Playwright_ · **GATE-03** | ACC qa_rs_a; 40 reservations across QAA-QAE | 1) Open inbox. 2) Filter status/date/branch. 3) Search by reference and by name. | Only QAA; default pending sorted by requested start; filters and search correct; SLA-overdue badge on overdue items. |
| TC-RSA-003 | Detail view and state-aware actions · _Playwright_ | Reservations in each status | 1) Open detail for pending, confirmed (before and after start), each terminal status. | All fields, status label, event timeline, notification statuses; only actions valid for state and role shown. |
| TC-RSA-004 | Accept (T2) · _Playwright + PHPUnit_ | MAIL; pending R | 1) Accept with 500-char message. 2) Retry another with 501-char message. | R confirmed, confirmed_at set, status_changed event, E02 in preferred language, no staff email; 501 chars rejected with 400 invalid_param (fields.message_to_customer). |
| TC-RSA-005 | Decline (T3) with reason and suggestions · _PHPUnit + Playwright_ | MAIL | 1) Decline without reason. 2) Decline fully_booked with 3 suggested times. 3) Decline with 2 suggestions. | 1) 400 reason_required; 2) 400 invalid_param (fields.suggested_times, max 2); 3) rejected status, E03 lists 2 times as informational (suggested times are not validated as slots — a non-slot time is accepted with a warning). |
| TC-RSA-006 | Staff cancel (T4b/T6b) · _PHPUnit + Playwright_ | MAIL; pending and confirmed requests | 1) Cancel without reason. 2) Cancel with branch_unable_to_serve. 3) Cancel with suspected_spam. | 1) 400 reason_required; 2) cancelled_by=staff + E04 (by branch); 3) cancelled, no customer email. |
| TC-RSA-011 | Valid transition matrix T1-T8, N1-N5 · _PHPUnit_ | PHPUnit with test DB | 1) Execute each allowed transition with each permitted actor. | Status, timestamps, event type and email per CANONICAL_3 §3 table for every row. |
| TC-RSA-012 | Invalid transitions return 409 · _PHPUnit + Playwright API_ | PHPUnit + API | 1) Attempt every terminal -> any; pending -> completed/no_show; confirmed -> rejected/expired/pending; customer cancel after start. | Staff transitions (API-12): 409 invalid_transition; customer cancel after start or on a terminal status (API-04): 409 cancel_not_allowed; no state change; no email; attempt logged. |
| TC-RSA-013 | Concurrent staff actions (optimistic lock) · _Playwright + PHPUnit_ · **GATE-03** | Two browser contexts as qa_rs_a and qa_bm_a | 1) Both open pending R. 2) A accepts. 3) B declines with stale version. | B gets 409 version_conflict, localised message and refreshed record; one transition; one customer email. |
| TC-RSA-014 | Repeated action is safe · _Playwright API_ | MAIL | 1) Double-click Accept. 2) Replay accept POST. | Second call 409 version_conflict; exactly one E02. |
| TC-RSA-016 | Closure flags affected reservations · _Playwright_ | Existing pending/confirmed on date X | 1) qa_bm_a adds closure X. 2) Open inbox 'needs action' filter. | Affected items flagged; statuses unchanged; staff must act (decline/cancel/contact). |
| TC-RSA-017 | 'Customer not notified' follow-up · _Playwright_ | Reservation whose email failed finally | 1) Open inbox and detail. | Flag visible in list and detail with phone (if provided) and follow-up guidance; status unaffected. |
| TC-RSA-022 | Automatic expiry timing · _PHPUnit_ | CLOCK; decision_cutoff C (proposal 60 ⟦DEC-012⟧); pending R start S | 1) Run job at S-C-1 min. 2) Run job at S-C and S-C+5 min. 3) Check confirmed R2 at same times. | Still pending at S-C-1; expired at first run ≥ S-C (≤ 5 min late); E05 with branch phone; E10 digest; R2 unaffected. |
| TC-RSA-023 | Hidden TEST branch behaviour · _Playwright API_ · **GATE-10** | TST configured; QA inbox as only recipient | 1) Anonymous API-02 to TST. 2) QA account submits to TST. 3) Check directory, sitemap, JSON-LD, hreflang, report. | Anonymous refused without disclosure; QA submission accepted and routed only to QA inbox; TST absent from all public outputs and reports ⟦mechanism per SDD; FR-RES-030⟧. |
| TC-RSA-025 | Lazy expiry when staff act after the decision cutoff · _PHPUnit + Playwright_ | CLOCK; MAIL; ACC qa_rs_a; pending R at QAA with start S; decision_cutoff C; expiry job paused | 1) Open R in SCR-A09 before S-C. 2) Advance the clock to S-C+2 min without running the job; press Accept (stale page). 3) Reload the detail and the inbox. 4) Run the expiry job. | Step 2: request becomes expired (actor system), E05 queued once, staff see 'This request has expired' and API-12 returns 409 invalid_transition with no E02 (AC-RES-05-07); step 3: Accept/Decline hidden after the cutoff; step 4: no second transition or E05. |

#### ACL — Permissions & branch isolation (14 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-ACL-001 | Role x resource oracle (capability level) · _PHPUnit_ · **GATE-03** | PHPUnit; oracle = §2.10 matrix | 1) For every role (incl. HO with/without CAP-DCA) and resource row assert user_can() for each action. | 0 mismatches against the oracle. |
| TC-ACL-002 | Role x resource oracle (UI and REST) · _Playwright API_ · **GATE-03** | ACC all roles | 1) For each oracle cell call the corresponding admin screen and REST/AJAX route. | Allowed -> success; denied -> 403, no data, access.denied audit row. |
| TC-ACL-003 | Isolation: admin lists, counts, search (20 pairs) · _Playwright API_ · **GATE-03** | ACC qa_bm_x, qa_rs_x for QAA-QAE | 1) For each ordered pair (X,Y) list, count, filter branch=Y, search Y's reference. | Only X data and totals; branch=Y filter 403; Y reference not found. |
| TC-ACL-004 | Isolation: direct admin URLs · _Playwright_ · **GATE-03** | Reservation id of QAB | 1) As qa_rs_a open admin detail URL of QAB reservation. | 403 with no reservation data in body; access.denied audit. |
| TC-ACL-005 | Isolation: REST endpoints · _Playwright API_ · **GATE-03** | API-10..16 | 1) As qa_rs_a call API-10 branch_code=QAB, API-11/12/13/16 with QAB id, API-14/15 with QAB code. | 403 forbidden_branch; 0 DB changes; audit rows. |
| TC-ACL-006 | Isolation: AJAX and replayed form posts · _Playwright API_ · **GATE-03** | List of momos-core admin-ajax/admin-post actions | 1) Replay each with QAB ids using qa_rs_a cookie and valid nonce. | All denied; valid nonce never substitutes for capability + branch check. |
| TC-ACL-007 | Isolation: export · _Playwright API_ · **GATE-03** | ACC qa_bm_a, qa_rs_a, qa_ho | 1) BM/RS call export with any filter. 2) qa_ho exports QAB only. | BM/RS 403; qa_ho gets only QAB rows; audit. |
| TC-ACL-008 | Isolation: notifications and digests · _Playwright_ · **GATE-03** | MAIL; 20 ordered pairs | 1) Submit at X; inspect recipients. 2) Run digests. | Only X recipients get E07/E08/E09; each branch digest contains only own-branch items. |
| TC-ACL-009 | Isolation: branch data editing · _Playwright API_ · **GATE-03** | ACC qa_bm_a | 1) Edit QAB hours, closures, notices, availability via UI, URL and REST. | All denied 403; QAB unchanged; audit. |
| TC-ACL-011 | Role change or disablement during session · _Playwright_ · **GATE-03** | Active session qa_rs_a | 1) qa_ho demotes / disables / unassigns qa_rs_a. 2) qa_rs_a performs an action. | Next request refused; session ended for disabled account (momo_account_disabled = 1); no action applied; re-enabling restores the kept branch assignment rows. |
| TC-ACL-012 | Reservations never exposed publicly · _Playwright API_ · **GATE-03** | SEED | 1) Check WP search, feeds, sitemaps, /wp-json/wp/v2/*, theme template loops. 2) Anonymous call API-10/11. | No reservation data anywhere; staff routes 401. |
| TC-ACL-013 | User and role management rules · _Playwright API_ | ACC qa_ho, qa_bm_a | 1) qa_ho creates, assigns, disables users. 2) qa_ho tries to create/edit/elevate SA. 3) qa_bm_a changes own role/branch. 4) Try deleting a staff user. | 1) works, audited; 2) denied; 3) denied; 4) delete not offered (disable only). |
| TC-ACL-022 | Vendor accounts denied reservation PII; break-glass grant and revoke audited · _Playwright_ · **GATE-07** | Named vendor ROLE-01 account with momo_access_expires_at = now + 4 h and per-user denies; ACC qa_sa (Momo's); Owner approval reference | 1) As vendor open A08, A09 for a QAA reservation, API-10, API-11, the export handler and A19. 2) qa_sa grants break-glass for 1 h citing the approval reference; vendor reopens A09. 3) qa_sa tries a 9 h grant ⟦limit 8 h⟧. 4) qa_sa revokes; vendor retries A09. 5) Open the audit log. | Step 1: every call 403 with no reservation fields, access.denied rows; step 2: detail readable during the grant, export and DSR still 403; step 3 rejected; step 4: 403 on the next request; vendor.breakglass_granted/revoked rows with approver reference and reservation.detail_viewed rows for the vendor's views. |
| TC-ACL-024 | Dashboard widgets and certificate register reads are branch-scoped · _PHPUnit + Playwright_ · **GATE-03** | SEED; ACC qa_bm_a, qa_rs_a, qa_multi_bc; certificates for QAA and QAB; pending and overdue requests at all branches | 1) Open the dashboard (SCR-A02) as each account and compare widget counts with the seeded data. 2) As qa_bm_a open the QAB certificate record by URL and via admin-ajax/REST. 3) As qa_rs_a open SCR-A12. | Step 1: every widget count includes only assigned branches; step 2: QAA record readable, QAB record 403 + access.denied (branch must be in momo_cert_branches); step 3: 403. |

#### ADM — Administration (7 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-ADM-002 | Audit log content and append-only · _PHPUnit + Playwright_ | SEED | 1) Perform price change, role change, reservation decision, export, denied access. 2) Try editing/deleting audit rows via UI/REST. | Rows with actor, role, action, object, branch, before/after (PII masked), truncated IP ⟦counsel⟧, timestamp; filterable; no update/delete path. |
| TC-ADM-004 | Session timeout, throttling and forced 2FA enrolment · _Playwright_ · **GATE-07** | CLOCK; new staff account | 1) First login. 2) Exceed the session limits of Doc 11 SEC-001/004/005 ⟦values — Security lead⟧. 3) Wrong passwords repeatedly. | 2FA enrolment forced before any admin page; re-auth after idle; throttling engages. |
| TC-ADM-005 | Staff account readiness · _manual_ · **GATE-10** | Production user list; staff roster ⟦TBD — Owner — DEC-024⟧ | 1) Compare users with roster. 2) Check 2FA status, role, branches, language, naming. | 1 named account per person; 2FA enrolled; no shared or 'admin' account; SA count within limit ⟦TBD⟧; test accounts disabled except TST QA account. |
| TC-ADM-006 | Notification recipients verified per branch · _manual_ · **GATE-10** | Production config pre-launch | 1) Send test message to each branch's recipients. 2) Each branch confirms receipt. | Every address business-controlled and receiving; confirmation recorded per branch. |
| TC-ADM-007 | Reservation settings match approved values · _manual_ · **GATE-10** | Approved configuration sheet ⟦TBD values⟧ | 1) Compare each branch's settings with sheet. | All values equal the sheet (incl. SLA and decision cutoff). |
| TC-ADM-008 | Vendor account expiry enforced · _Playwright + test clock_ · **GATE-07** | Vendor user with momo_access_expires_at = now + 2 min | 1) Log in. 2) Wait past expiry. 3) Reload admin; try new login. | Session ends at expiry; login refused with generic message; event audited. |
| TC-ADM-009 | Role/branch change revokes sessions · _Playwright_ · **GATE-03** | BM logged in on two browsers | 1) HO removes branch assignment. 2) BM reloads both sessions. | Both sessions are logged out immediately; audit records before/after; BM cannot access former branch data. |

#### I18N — Multilingual (6 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-I18N-001 | lang and dir attributes per language · _Playwright_ · **GATE-04** | SEED; all templates | 1) Load every customer template in ja, en, ar, ur, hi, bn. 2) Read <html lang dir>. | lang = ja/en/ar/ur/hi/bn; dir = rtl for ar and ur, ltr otherwise; 0 exceptions. |
| TC-I18N-009 | Hidden language is not public · _Playwright_ · **GATE-04** | qa_ho hides hi | 1) Request /hi/ and /hi/menu/ anonymously. 2) Inspect switcher, hreflang, sitemaps. | /hi/ URLs not reachable by visitors (404); no hi in switcher, hreflang or sitemap; staff preview still possible. |
| TC-I18N-011 | All customer email and form strings reviewed in 6 languages · _manual + PHPUnit_ · **GATE-04** | String inventory export (momos-core string catalogs languages/strings/{lang}/*.php + admin .po) | 1) Compare inventory against 6 languages. 2) Check review status flags. | 0 missing strings; 0 strings whose only translation is machine output; reviewer recorded per language. |
| TC-I18N-013 | No mixed-language pages · _Playwright_ · **GATE-04** | Allow-list: brand names, romanised dish names, Japanese address original, language names | 1) Crawl each enabled language. 2) Run script detection per text node. | 0 pages with out-of-language text outside the allow-list. |
| TC-I18N-015 | LCCS complete and native-reviewed before enablement · _manual_ · **GATE-04** | LCCS checklist (CANONICAL_3 §6) per language; reviewer sign-off form | 1) Native reviewer walks LCCS in staging. 2) Sign-off recorded with name/date. 3) qa_ho tries enabling a language without sign-off. | Enablement only with recorded sign-off and LCCS completeness 100 % (LanguageGate, TC-I18N-019); missing item keeps language hidden. |
| TC-I18N-019 | Language enablement gate: capability, LCCS completeness and audit · _PHPUnit + Playwright_ · **GATE-04** | ACC qa_sa, qa_ho, qa_mm, qa_ce, qa_tr_hi; hi LCCS at 98 % with sign-off recorded; bn LCCS 100 % without sign-off | 1) As qa_mm, qa_ce and qa_tr_hi open the Languages tab and POST an enable request. 2) As qa_ho enable hi. 3) As qa_ho enable bn. 4) Complete hi LCCS to 100 % and enable it. 5) Disable hi again. 6) Open the audit log. | Step 1: 403 (momo_manage_languages held by SA/HO only); step 2: refused with the list of missing LCCS items (LanguageGate, LCCS < 100 %); step 3: refused (no native-review sign-off); step 4: hi enabled — switcher, hreflang and sitemap updated after purge; step 5: hi hidden again; language.enabled and language.disabled audit rows with actor and sign-off reference. |

#### RTL — RTL & BiDi (4 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-RTL-001 | Mirrored layout on every template (ar, ur) · _Playwright + manual_ · **GATE-04** | SEED; viewports 360, 768, 1366 | 1) Screenshot all customer templates in ar and ur. 2) Compare with approved RTL baselines. 3) Check icons. | Header, nav, cards, footer, breadcrumbs, forms mirrored; directional icons mirrored; phone, map pin, logo not mirrored; drawer opens from inline-start. |
| TC-RTL-002 | Bidirectional isolation of LTR fragments · _Playwright + manual_ · **GATE-04** | Fixture F-BIDI (Arabic/Urdu sentences with +81 90-0000-0101, ¥1,200, 2026-12-24 19:00, ABCD-2345, 'Chicken Biryani') | 1) Render branch page, dish page, result screen, status page in ar/ur. 2) Inspect DOM for bdi/dir=ltr. 3) Visual check of order. | Digits and Latin fragments keep LTR order (no '81+', no reversed reference); user text uses dir=auto. |
| TC-RTL-003 | RTL reservation form usability · _manual_ · **GATE-04** | SEED | 1) Complete and fail the form in ar and ur on iPhone and Android. | Labels/inputs right-aligned; email and phone inputs LTR; errors next to fields; error summary order logical; date/time selection works. |
| TC-RTL-004 | RTL emails render correctly · _manual_ · **GATE-04** | E01-E06 rendered in ar and ur | 1) Deliver to Gmail web, Outlook.com, iOS Mail, Gmail Android test inboxes. | dir=rtl body; LTR fragments isolated; no broken layout; plain-text part readable. |

#### DIET — Halal & allergen claims (16 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-DIET-001 | Allergen reference list seeded exactly (9+20) · _PHPUnit_ · **GATE-02** | Fresh install with momos-core seed | 1) List momo_allergen terms with code, regulatory_class, ja/en names. | Exactly 29 terms equal to CANONICAL_1 §2 — regulatory_class mandatory (9): shrimp/えび, cashew_nut/カシューナッツ, crab/かに, walnut/くるみ, wheat/小麦, buckwheat/そば, egg/卵, milk/乳, peanut/落花生; regulatory_class recommended (20): almond/アーモンド, abalone/あわび, squid/いか, salmon_roe/いくら, orange/オレンジ, kiwifruit/キウイフルーツ, beef/牛肉, sesame/ごま, salmon/さけ, mackerel/さば, soybean/大豆, chicken/鶏肉, banana/バナナ, pistachio/ピスタチオ, pork/豚肉, macadamia_nut/マカダミアナッツ, peach/もも, yam/やまいも, apple/りんご, gelatin/ゼラチン (codes and JA names exact); no Western-list items (e.g., 'gluten', 'shellfish', 'nuts'). |
| TC-DIET-002 | Certificate register fields and private evidence · _Playwright_ · **GATE-02** | ACC qa_ho_dca | 1) Create certificate with all fields and evidence PDF. 2) Request evidence file URL anonymously and as qa_bm_a. | All fields saved; evidence not publicly reachable (403/404); register not in REST/sitemap/search. |
| TC-DIET-003 | Certification display rule matrix · _PHPUnit + Playwright_ · **GATE-02** | CLOCK; certificate variants: active+approved+mark; active+no mark permission; pending_verification; expired; revoked; future valid_from | 1) For each variant view branch page, /halal/, dish page and JSON-LD in ja/en/ar. | Certification wording only for active, in-validity, approved records; mark image only when mark permission true; all other variants show no certification wording, mark or suitableForDiet. |
| TC-DIET-004 | Certificate expiry auto-hide · _PHPUnit + Playwright_ · **GATE-02** | CLOCK; QAA certificate valid_until = today | 1) Advance clock past 00:00 JST. 2) Run daily job. 3) View QAA page, /halal/, JSON-LD in all languages (cache purged by job). | Status = expired; all claims and mark hidden everywhere; audit entry; no stale cached page. |
| TC-DIET-006 | Revoked certificate hides claims immediately · _Playwright_ · **GATE-02** | Active certified QAA | 1) qa_ho_dca sets status revoked. 2) View public pages. | Claims and mark hidden on save (after automatic cache purge); audit entry. |
| TC-DIET-009 | Claim fields editable only by CAP-DCA · _PHPUnit + Playwright API_ · **GATE-02** | ACC qa_mm, qa_ce, qa_bm_a, qa_tr_ar, qa_ho (no CAP-DCA), qa_ho_dca | 1) Each account tries to change an allergen state and halal basis via UI and REST. | Only qa_ho_dca succeeds; others 403 + access.denied audit; qa_ho_dca save stamps verified_by and verified_at. |
| TC-DIET-010 | Two-person evidence recorded · _PHPUnit_ · **GATE-02** | ACC qa_ho_dca | 1) Save allergen change without source_document. 2) Save with source_document 'Recipe card RC-0042 (Head Chef)'. | Step 1 rejected; step 2 saved with source_document + verified_by (CAP-DCA user) visible in audit; no bulk action such as 'set all remaining to not contained' exists (per-item verification, DEC-021). |
| TC-DIET-011 | Allergen panel grouping and disclaimers · _Playwright + manual_ · **GATE-02** | Dish with contains wheat, may_contain peanut, not_contained milk, all else verified | 1) View allergen table in 6 languages, LTR and RTL. | 'Contains: …' and 'May contain (cross-contact): …' summary at the top; groups 9 mandatory / 20 recommended / other; icon + text per state using the Doc 07 §6.4 labels; legend; 'Information last verified on {date}'; voluntary-information disclaimer and 'tell staff' instruction always; cross-contact note only where the branch's kitchen practice is confirmed (DEC-021). |
| TC-DIET-012 | Not-verified banner · _Playwright_ · **GATE-02** | Dish A all default not_verified; dish B one not_verified item; dish C fully verified with momo_allergen_review_required = 1 | 1) View both dishes in 6 languages. | A, B and C show 'Allergen information not verified — please ask staff' (banner trigger: any not_verified OR momo_allergen_review_required); no state shown as 'not contained' for unverified items. |
| TC-DIET-013 | Prohibited wording scan · _Playwright_ · **GATE-02** | Master claim-term list Doc 11 §8.6 + §9.5 plus the Doc 07 §6.6 request-context and ordering terms, per language approved by native reviewers ⟦TBD — DEC-027⟧ | 1) Crawl all public pages and rendered emails E01-E06 in every enabled language. 2) Match prohibited terms and seal images. | 0 hits for allergen-free / safe for allergies / 100% halal / unbacked 'certified' / seal; pre-acceptance texts free of request-context terms (confirmed, booked, reserved, 'your reservation', 予約完了, 予約確定, 予約が確定, ご予約を承りました and per-language equivalents; only the negated DEC-011 label allow-listed). |
| TC-DIET-014 | Claim text never from unreviewed machine translation · _Playwright + manual_ · **GATE-02** | Claim templates with MT draft in hi | 1) Leave hi template as MT draft and publish hi dish. 2) Mark human-reviewed and recheck. | While unreviewed the claim text does not render in hi (dish shows not-verified/neutral state per rule); after review it renders. |
| TC-DIET-017 | Certificate register access control · _Playwright API_ · **GATE-02** | ACC qa_ho (no CAP-DCA), qa_ho_dca, qa_mm, qa_bm_a, qa_ce | 1) Each opens register and tries create/edit via UI and REST. | Only qa_ho_dca (and SA with CAP-DCA) create/edit; qa_bm_a read own branch only; qa_mm read; qa_ce no access. |
| TC-DIET-019 | No stale claim data from caches · _Playwright_ · **GATE-02** | Page cache and CDN enabled on staging | 1) Warm cache for a dish in 6 languages. 2) qa_ho_dca changes milk not_contained -> contains. 3) Reload anonymously. | All 6 language pages show 'contains milk' within the agreed purge time ⟦TBD ≤ 5 min⟧; no stale variant served. |
| TC-DIET-020 | No unapproved halal seal image anywhere · _Playwright + manual_ · **GATE-02** | Build artefacts (theme, child theme, theme templates, media library, email templates); UI-001 seal asset hash list (CON-033) | 1) Hash-scan build and media library for seal assets. 2) Crawl rendered pages, emails and OG images in all languages for seal images/alt text. 3) Activate a certified record with mark permission and re-scan. | Before step 3: 0 seal images (incl. the UI-001 unnamed seal and legacy '100% Halal Prepared' seal); after: the mark appears only where the active record allows, sourced from the register upload. |
| TC-DIET-024 | Claim activation blocked until evidence is complete · _PHPUnit + Playwright_ · **GATE-02** | ACC qa_ho_dca; certified record for QAD and halal_ingredients record for QAB in pending_verification | 1) Try to set each record to active with one item missing in turn: evidence file, certifier, certificate number, valid_from/valid_until, scope, source_document; for a record with mark permission the permission document; for halal_ingredients the supplier evidence file; also enter a non-https public register URL; then revoke an active record without a reason. 2) Repeat via direct REST/admin-post bypassing the form. 3) Complete all fields and activate. | Every incomplete attempt rejected with a field-specific message (non-https register URL rejected; revocation without a reason ≤ 255 chars rejected); status stays pending_verification and no certified or halal_ingredients wording renders publicly; step 3 active with verified_by, verified_at and source_document stamped and an audit row. |
| TC-DIET-027 | Approved allergen disclaimer and tell-staff text on every dish page · _Playwright_ · **GATE-02** | SEED; approved allergen.disclaimer and allergen.severe strings per enabled language | 1) Crawl every published dish URL (incl. variants) in every enabled language. 2) Match the disclaimer and tell-staff texts (and, where the branch practice is confirmed, the cross-contact text) against the approved string table for the page language. | 100% of dish pages contain both approved texts verbatim in the page language (no other-language fallback); dishes with all 29 states not_contained still show the voluntary disclaimer and tell-staff note; the cross-contact note appears only where confirmed (DEC-021) and never as an unverified statement of kitchen practice; failure list empty. |

#### HOME — Homepage (1 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-HOME-003 | Halal summary shows approved statement only · _Playwright_ · **GATE-02** | SEED; variant A: no active certified record; variant B: brand statement approved | 1) Load / in 6 languages for each variant. 2) Search DOM for seal images and certification words. | Variant A: neutral approved text, no seal, no 'certified'/'100%'. Variant B: approved statement text exactly as approved template. |

#### CORP — Corporate/halal page (1 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-CORP-002 | Halal page table driven by register · _Playwright_ · **GATE-02** | SEED certificates: QAA active certified; QAB halal_ingredients; QAC no_claim; QAD expired; QAE revoked | 1) Open /halal/ in 6 languages. 2) Compare each row with register. | Rows only for active approved records (claim type, certifier, scope, certificate no., valid until, alcohol policy, last verified). QAC shows neutral statement; QAD/QAE show no certification wording. |

#### BR — Branches (3 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-BR-001 | Directory lists only eligible branches · _Playwright_ · **GATE-01** | SEED: QAA-QAE open, QAY temporarily_closed (reopen date), QAZ closed_permanently, TST hidden | 1) Open /branches/ in 6 languages. | QAA-QAE and QAY shown; QAY labelled temporarily closed with reopen date; QAZ and TST absent. |
| TC-BR-006 | Branch facts equal the signed fact sheet · _Playwright + manual_ · **GATE-01** | Owner-signed branch fact sheet (GATE-01) loaded as test fixture | 1) For every published branch compare name, address, phone, weekly hours, last order, closed days, concept on page, footer and JSON-LD in each language. | 100% field match; any mismatch is a defect blocking GATE-01. |
| TC-BR-013 | Closure blocks new requests and flags existing ones · _PHPUnit + Playwright API_ | QAA has 2 pending + 1 confirmed on date X | 1) Add closed_all_day on X. 2) Submit new request for X via UI and API-02. 3) Open inbox. | New request refused (409 slot_unavailable); 3 existing reservations flagged 'needs action', status unchanged, no customer email sent automatically. |

#### MENU — Menu (1 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-MENU-012 | Pre-launch demo/sample content scan · _Playwright + manual_ · **GATE-02** | Production-candidate content; list of UI-001 sample dish names/prices and demo seed names | 1) Crawl all public pages, JSON-LD, sitemaps and OG tags in every enabled language. 2) Match against the sample list. 3) List images flagged as demo in the media library. | 0 sample dish names, descriptions or prices public; demo photos remain only on real dish and offer images and each shows 'image for illustration' (写真はイメージです) in each language; none on branch, gallery or halal pages; no unlabelled demo photo (DEC-047). |

#### NOT — Notifications (10 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-NOT-001 | E01 'Request received' content in 6 languages · _Playwright_ · **GATE-04** | MAIL; one request per language | 1) Render E01 HTML and plain-text parts per language. | Brand, reference, branch name, localised + Japanese address, date/time (JST label shown in non-ja languages only; ja shows none (DEC-062)), party size, 'not yet confirmed' statement, expiry explanation ('expires if undecided by {start − cutoff}'), next steps, status link, branch phone, whitelisting guidance; plain-text part present. |
| TC-NOT-002 | E02-E06 content and variants · _Playwright_ · **GATE-04** | MAIL | 1) Trigger confirmed, declined (with 2 times), cancelled by customer, cancelled by branch, expired, details updated in 6 languages. | Correct template and variant, language, branch data, JST times, next steps; E03 shows suggestions; E05 shows branch phone; E06 for a pending request says 'details of your request were updated' with the button 'View request status' (never 'reservation' before acceptance, BR-RES-01); plain-text parts. |
| TC-NOT-003 | Staff emails carry minimal PII · _Playwright_ · **GATE-03** | MAIL; recipients with ja and en locale | 1) Trigger E07-E10. 2) Scan bodies and headers. | Reference, branch, date/time, party size, given name only + admin link (login required); E07 shows 'Special request: yes/no' only; no email, phone, special request or notes; language = recipient locale. |
| TC-NOT-004 | Staff notification routing per branch · _Playwright_ · **GATE-03** | MAIL; distinct recipient lists for QAA-QAE | 1) Submit one request per branch. 2) Inspect Mailpit recipients. | Each E07 reaches only its branch's recipients; 0 cross-branch deliveries. |
| TC-NOT-005 | Queue retries and final failure · _PHPUnit_ | Provider stub returns 5xx/timeout | 1) Submit request. 2) Let queue run 5 attempts. 3) Restore provider for a second request after 2 failures. | Request 1: 5 attempts with backoff, flag 'customer not notified', notification_failed event, E08 staff alert, reservation still pending. Request 2: sent on attempt 3. |
| TC-NOT-007 | Subjects contain no personal data · _PHPUnit_ | All templates x languages rendered with fixture names/emails | 1) Extract subjects. 2) Search for name, email, phone fixtures. | Subjects contain at most the reference; 0 PII hits. |
| TC-NOT-008 | Sending identity and authentication · _manual_ · **GATE-10** | Production-like provider on staging subdomain or production pre-launch | 1) Send E01 to Gmail, Outlook.com, Yahoo Japan. 2) Read Authentication-Results headers. | From = brand domain; SPF pass, DKIM pass aligned, DMARC pass; reply-to = branch email ⟦TBD⟧. |
| TC-NOT-012 | Customer emails are self-contained and safe · _Playwright_ | MAIL | 1) Inspect E01-E06 links and data. | Only that customer's data; links point to production domain in preferred language; no staging hosts; no shorteners; no notes. |
| TC-NOT-014 | Internal notes never reach customers · _Playwright + mail capture_ · **GATE-03** | Reservation with internal note "VIP — check ID" | 1) Accept with message. 2) Inspect E02 HTML and text parts. | Customer email contains only the customer message; no internal note or staff name. |
| TC-NOT-015 | Customer email envelope: single recipient, no other customer's data · _Playwright_ · **GATE-06** | MAIL; two requests R1 and R2 at QAA within the same minute, distinct emails and names, R1 with a special request | 1) Trigger E01-E06 for R1 (accept, decline, cancel by customer and by branch, expire, modify across fixtures). 2) Open the raw MIME of each message in Mailpit. | Exactly one address in To (R1's email); no Cc or Bcc header; Reply-To = branch address only; no R2 name, reference or data; no internal note; special request shown only as 'We have received your note'. |

#### PRV — Privacy (9 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-PRV-001 | Notice at collection · _Playwright_ · **GATE-06** | SEED | 1) Open reservation and contact forms in 6 languages. | Purpose notice visible before submit with link to same-language privacy policy. |
| TC-PRV-002 | Consent required and version stored · _PHPUnit + Playwright_ · **GATE-06** | SEED; consent_version v1 then v2 | 1) Submit without consent (UI and API). 2) Submit with consent under v1, change to v2, submit again. | Unticked by default; blocked without consent (400); rows store consent_version v1/v2 and consent_at. |
| TC-PRV-003 | Privacy policy completeness and counsel approval · _manual_ · **GATE-06** | Draft policy in ja + 5 languages | 1) Check against FR-PRV-001 items (operator, purposes, items, entrustment, foreign countries, safety measures, retention, request procedure, contact). | All items present; countries of hosting, email provider, analytics, captcha and vendor listed; counsel approval recorded. |
| TC-PRV-004 | Retention job anonymises on schedule · _PHPUnit_ · **GATE-06** | CLOCK; reservations dated retention-1 day, retention, retention+1 day ⟦proposal 12 months⟧ | 1) Run retention job. 2) Inspect rows, events, notifications, logs. 3) Simulate job failure. | RetentionJob (daily 03:15 JST): only rows past retention anonymised — name, email, phone, special request, token hash and abuse hashes set to NULL (SDD §5.10), pii_purged_at set, recipients purged, events kept without PII, counts logged; failure raises alert. |
| TC-PRV-007 | No PII in URLs · _Playwright_ | Fixture PII markers | 1) Record every URL and redirect across customer flows and emails. | Only the opaque token on the status URL; no email, name, phone in any URL or Referer. |
| TC-PRV-008 | No PII in logs · _manual_ | Fixture PII markers after a full regression run | 1) Grep PHP error log, WAF/app logs, notification log, audit log, Action Scheduler logs. | Markers absent except masked values in audit/notification logs. |
| TC-PRV-010 | Non-production environments hold no production PII · _manual_ · **GATE-06** | Staging, dev, local | 1) Check seed provenance and scan for real domains/phones. | Synthetic only; outbound email captured; noindex + HTTP auth on staging. |
| TC-PRV-014 | Special request excluded from emails, exports and analytics; separate-consent switch · _Playwright + PHPUnit_ · **GATE-06** | SEED; MAIL; ACC qa_ho, qa_rs_a; special_request_separate_consent = off; marker string ZZNOTE7731 | 1) Submit at QAA with special request 'high chair ZZNOTE7731' while capturing dataLayer and GA4 hits; accept, then submit and decline/cancel/expire further requests so E01-E10 are all sent. 2) qa_ho exports the period with default columns. 3) Set special_request_separate_consent = on; POST API-02 with a non-empty special_request and no special_request_consent; repeat with special_request_consent = true and with an empty special_request. | Marker absent from every E01-E10 subject, header, HTML and text part, from the default CSV (no special-request column) and from all dataLayer/GA4 hits; E01-E06 show only 'We have received your note', E07-E10 only 'Has a note: yes'; staff detail shows the escaped text. Step 3: 400 invalid_param with fields.special_request_consent and no row; consented and empty-note requests return 201. |
| TC-PRV-016 | Result and status pages load no third-party resources and send privacy headers · _Playwright_ · **GATE-07** | SEED; MAIL; staging behind production CDN/cache configuration | 1) Submit a request and record a HAR of /reservation/submitted/ in 6 languages. 2) Open the status link from E01 in 6 languages with HAR recording. 3) Read response headers of both routes at origin and through the CDN. 4) Request each route twice anonymously and read the cache status header. | 0 requests to any origin other than the site on both pages (no GTM/GA4, Turnstile, fonts or maps — DEC-066; generate_lead was already pushed on the form page, TC-ANL-002); both routes return Referrer-Policy: no-referrer, Cache-Control: no-store, private and X-Robots-Tag: noindex, nofollow; repeat requests are never served from cache (MISS/BYPASS); pages show no email, phone or note text. |

#### SEC — Security (14 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-SEC-001 | 2FA enforced for every staff role · _Playwright_ · **GATE-07** | ACC one user per ROLE-01..07 | 1) Log in each user without completing 2FA. 2) Try REST/AJAX with cookie from step 1. | No admin page or staff API reachable before second factor; enrolment forced at first login; recovery only via SA. |
| TC-SEC-002 | Login throttling, lockout, generic errors · _Playwright_ · **GATE-07** | Staging | 1) 20 rapid wrong passwords for a valid user. 2) Wrong password for unknown user. 3) Use reset token twice and after expiry. | Throttle/lockout at configured threshold ⟦LC-001 proposal 10⟧ with alert; identical generic messages; reset token single-use and expiring. |
| TC-SEC-003 | User enumeration blocked · _Playwright API_ · **GATE-07** | Anonymous | 1) GET /?author=1. 2) GET /wp-json/wp/v2/users. 3) Check author sitemaps and oEmbed. | No username disclosed; users endpoint 401; no author archives in sitemaps. |
| TC-SEC-004 | Security headers · _Playwright API + ZAP_ · **GATE-07** | Staging with production config; production pre-launch | 1) Fetch public page, admin page, REST response; inspect headers. | HSTS per the Doc 11 SEC-050 ramp (max-age=300 at go-live → 86,400 → 31,536,000; includeSubDomains only after every subdomain is HTTPS-only); the canonical Doc 11 §6.4 CSP string sent as Content-Security-Policy-Report-Only at launch (enforced after ⟦2 weeks⟧ with no unresolved violations — TC-SEC-030) with frame-src incl. https://www.google.com and https://challenges.cloudflare.com; frame-ancestors 'self', Referrer-Policy, Permissions-Policy, X-Content-Type-Options nosniff on all. |
| TC-SEC-005 | HTTPS everywhere · _Playwright_ · **GATE-07** | Production pre-launch | 1) Request http:// and www variants. 2) TLS scan. 3) Mixed-content scan. | Single 301 to https canonical host; TLS ≥ 1.2; HSTS present with the max-age of the current SEC-050 ramp step; 0 mixed content. |
| TC-SEC-006 | Nonces / CSRF on state changes · _Playwright API_ | ACC qa_rs_a | 1) Replay accept, decline, settings, menu-grid changes without nonce and with forged nonce. 2) Cross-site auto-POST page. | All rejected 403 with no change. |
| TC-SEC-007 | Stored and reflected XSS · _Playwright + ZAP_ | Fixture F-HOSTILE | 1) Inject payloads into reservation fields, branch/dish/notice fields, translation strings, URL params. 2) Render public pages, admin list/detail, emails, CSV. | All rendered inert; no script execution in any context. |
| TC-SEC-008 | SQL injection · _Playwright API + PHPUnit_ | Fixture F-HOSTILE | 1) Send payloads to API-10 q/status/date, branch_code path, API-01. 2) Code grep for unprepared queries. | No SQL errors or data leak; all custom queries use $wpdb->prepare. |
| TC-SEC-010 | Configuration exposure · _Playwright API + ZAP_ · **GATE-07** | Staging + production pre-launch | 1) Request wp-config.php~, .bak, .sql, .zip, .git/, debug.log, readme.html, /wp-content/uploads/ listing, uploaded .php, /xmlrpc.php POST. | All 403/404/blocked; no directory listing; PHP not executable in uploads; WP_DEBUG_DISPLAY false. |
| TC-SEC-012 | WPScan / vulnerability feed clean · _WPScan_ · **GATE-07** | Release candidate versions | 1) Run WPScan (API token) against staging. 2) Check vulnerability feed for every installed version. | 0 known High/Critical vulnerabilities; weekly scheduled scan configured. |
| TC-SEC-013 | OWASP ZAP baseline scan · _ZAP_ · **GATE-07** | Written authorisation from Owner; staging only | 1) ZAP baseline (passive) unauthenticated. 2) Authenticated context as qa_rs_a. | 0 High findings; Medium triaged with defects or documented acceptance. |
| TC-SEC-020 | Graceful errors without leaks or half-saves · _PHPUnit + Playwright_ | Fault injection: DB timeout during API-02 | 1) Submit during injected failure. | Localised generic error; no stack trace/path; no partial rows (transaction rolled back); retry succeeds once. |
| TC-SEC-023 | Password policy and reset-link expiry · _Playwright_ · **GATE-07** | Staging; one test account per staff role; server clock shift available | 1) For each role set passwords of 11, 12 and 129 characters and a known-breached password (e.g., 'Password1234'). 2) Request two reset links; use one at 59 min and the other at 61 min. 3) Reuse the consumed link. | 11-char, 129-char and breached passwords rejected with a localised message; 12-char accepted; 59-min link works once; 61-min and reused links fail with a generic message. |
| TC-SEC-027 | Public write endpoints reject cross-origin and non-JSON requests · _Playwright_ | SEED; valid Turnstile test token; a pending reservation with a valid status token | 1) POST API-02 with valid token and JSON body but Origin https://attacker.example. 2) POST API-02 without Origin and with a foreign Referer. 3) POST API-04 cancel with a valid status token and a foreign Origin. 4) Auto-submit API-02 from a cross-site page as form-encoded and text/plain. 5) Repeat step 1 with the site Origin. | Steps 1-3 rejected with 403 origin_rejected and step 4 with 415 unsupported_media_type (content type is checked first, SDD §5.2; Doc 04 §16.0.4), each with no row, no state change and no email; step 5 returns 201; no response widens Access-Control-Allow-Origin. |

#### SEO — SEO (2 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-SEO-008 | Legacy redirect map · _Playwright_ · **GATE-09** | Crawl list of momoscafehalal.com URLs (DEC-039) + redirect map | 1) Request every legacy URL (http/https, www/non-www). | Each resolves with a single 301 to the mapped URL returning 200; 0 broken top URLs; 0 chains/loops. |
| TC-SEO-009 | Staging blocked, production indexable · _Playwright_ · **GATE-09** | Staging and production pre-launch | 1) Request staging without auth. 2) Check staging robots/headers. 3) Check production for leftover noindex and robots rules. | Staging 401 + noindex; production public pages indexable, robots.txt does not block CSS/JS, sitemap listed. |

#### ANL — Analytics (2 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-ANL-002 | generate_lead only on server-confirmed success · _Playwright_ · **GATE-09** | GTM Preview | 1) Click submit with validation errors. 2) Submit getting 429, 403 captcha, 409, 5xx (fault injection). 3) Successful submit. 4) Refresh result page; idempotent replay. | Fires only in step 3, once, pushed on the form page when API-02 returns 201 and before navigation to /reservation/submitted/ (DEC-066); party_size_band correct; never on result-page refresh (the result page loads no GTM/GA4) or idempotent replay (200). |
| TC-ANL-004 | No PII in analytics · _Playwright_ · **GATE-09** | Fixture PII marker strings | 1) Complete journeys incl. status page. 2) Capture dataLayer and GA4 collect requests. | No name, email, phone, special request, token or public_ref+token combination in any hit, page_location or title; /reservation/submitted/ and the status page send no GTM/GA4 request at all (DEC-066). |

#### PERF — Performance (1 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-PERF-002 | TTFB from Japan · _WebPageTest_ · **GATE-08** | Production-candidate hosting; Japan test agents (Tokyo, Osaka ⟦availability to confirm⟧) | 1) 9 runs per key template per location, warm cache. 2) Repeat after cache purge for uncached. | Cached HTML TTFB p75 ≤ 800 ms; uncached p75 ≤ 1.8 s. |

#### A11Y — Accessibility (5 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-A11Y-001 | Automated axe scan (WCAG 2.2 tags) · _axe_ · **GATE-08** | All customer templates and reservation states in 6 languages | 1) Run @axe-core/playwright with tags wcag2a, wcag2aa, wcag21a, wcag21aa, wcag22aa. | 0 critical and 0 serious violations. |
| TC-A11Y-002 | Keyboard-only reservation journey · _manual_ · **GATE-08** | Desktop Chrome/Firefox; ja, en, ar | 1) Complete form, trigger errors, submit, open status page, cancel — keyboard only. | All reachable; logical order in LTR and RTL; visible focus; no traps; picker operable; focus moves to error summary. |
| TC-A11Y-003 | Screen readers incl. Arabic and Urdu · _manual_ · **GATE-08** | NVDA (Windows), VoiceOver (iOS Safari, macOS); voices for ar/ur ⟦verify availability⟧ | 1) Journey in ja, en, ar with NVDA and VoiceOver. 2) Spot-check ur, hi, bn: page language, labels, errors, status message. | Names/roles/values announced; errors and 'request received' status announced (4.1.3); language switches correctly. |
| TC-A11Y-004 | WCAG 2.2 new success criteria · _manual_ · **GATE-08** | Key templates | 1) 2.4.11 sticky header/bar/suggestion bar vs focus. 2) 2.5.7 dragging alternatives. 3) 2.5.8 target size measure. 4) 3.2.6 help location. 5) 3.3.7 redundant entry. 6) 3.3.8 staff login (paste, password manager, 2FA field). | All pass; target ≥ 24×24 CSS px or spaced. |
| TC-A11Y-006 | Accessible form semantics · _axe + manual_ · **GATE-08** | Reservation and contact forms in 6 languages | 1) Inspect labels, required indication, error text and linkage, autocomplete. | Programmatic labels per language; required not by colour only; errors in text, linked; autocomplete name/email/tel. |

#### CMP — Browser/device (1 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-CMP-001 | Tier-1 real devices: language journey for GATE-04 · _manual_ · **GATE-04** | Real iPhone (current and previous iOS) Safari; mid-range Android Chrome; MAIL + real inboxes | 1) For each enabled language on each device submit two requests. 2) Staff accept one and decline one. 3) Open E01, E02, E03 on the device. | Form -> submitted -> accept/decline -> emails complete in that language and direction; no layout, input or picker defects; screen recordings stored as GATE-04 evidence. |

#### WP — WordPress platform (1 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-WP-004 | Scheduled jobs run without traffic · _PHPUnit + manual_ | DISABLE_WP_CRON true; system cron every minute | 1) Block visitor traffic for 60 min. 2) Inspect Action Scheduler and job heartbeats. | Expiry (5 min), sold-out (15 min), queue, SLA, certificate and retention jobs ran on schedule; heartbeats fresh. |

#### BCK — Backup & restore (2 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-BCK-001 | Backups run as specified · _manual_ · **GATE-07** | Production backup configuration | 1) Inspect 7 days of backup logs. 2) Check off-site copy and encryption. | Daily full + DB every 4 h; 30-day retention; off-site copy in Momo's-owned storage; encrypted. |
| TC-BCK-002 | Full restore test within RTO/RPO · _manual_ · **GATE-07** | Isolated recovery environment (not shared staging), outbound email disabled | 1) Restore latest backup; time it. 2) Verify table row counts, sample checksums, audit continuity, 6-language rendering, staff login + 2FA. | Restore ≤ 8 h; data loss window ≤ 4 h; all checks pass; environment destroyed and logged afterwards. |

#### SMK — Smoke (1 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-SMK-001 | Production smoke on hidden TEST branch (≤ 15 min) · _manual + Playwright_ · **GATE-10** | Go decision taken; QA account for TST; QA inbox; maintenance mode off | Run §8.5 checklist steps 1-9 within 15 minutes. | All steps pass; TST request created, accepted, cancelled, emails received in QA inbox only; no real branch touched. |

#### REG — Regression (2 P0)

| TC | Title · auto · gate | Preconditions | Steps | Expected (oracle) |
|---|---|---|---|---|
| TC-REG-001 | Cross-branch regression after branch edits · _Playwright_ · **GATE-03** | SEED | 1) Edit QAA address (HO), hours and availability (BM). 2) Compare QAB-QAE pages, slots, JSON-LD, map pins. | Only QAA changed. |
| TC-REG-003 | Release-candidate defect audit · _manual_ · **GATE-05** | Defect tracker export at RC | 1) Count open defects by severity and area. 2) Check risk acceptances. | 0 Critical; 0 High in reservation, privacy, security, claims, isolation; other High only with signed Owner acceptance and fix date. |

---

## 10. P1 index (100 % executed; failures follow §4)

| Area | # | Cases (ID without the TC- prefix, title, [gate]) |
|---|---|---|
| RES | 15 | RES-012 Cross-midnight service period; RES-019 Active pending limit per email per branch; RES-023 Captcha outage degrades to stricter rate limit; RES-026 Branch prefill and hidden branches; RES-027 Special request guidance and handling [GATE-06]; RES-028 Status page via token; RES-029 Customer cancellation via token; RES-031 No payments, deposits or invented policy; RES-032 Phone normalisation; RES-033 Six scripts and emoji persist; RES-034 Preferred language drives emails; RES-035 Pre-submit summary shows exact request details; RES-038 Settings validation lead time vs cutoff; RES-041 Derived status token is stable across later emails and stored only as a hash; RES-042 Lazy expiry on customer status and cancel calls |
| RSA | 8 | RSA-002 Inbox usable on phone and tablet; RSA-007 Claim (N1); RSA-008 Internal notes never leave admin; RSA-009 Modify details (N3); RSA-010 Attendance window (T7/T8); RSA-019 CSV export (Could) controls [GATE-03]; RSA-020 Action and status labels; RSA-021 SLA reminder and Head Office escalation |
| ACL | 10 | ACL-010 Multi-branch assignment [GATE-03]; ACL-014 Content Editor boundaries; ACL-015 Translator boundaries; ACL-016 Menu Manager boundaries; ACL-017 Reservation Staff boundaries; ACL-018 Audit log visibility; ACL-019 No file/theme editing except SA [IMPL re-scoped]; ACL-020 No public accounts; ACL-021 Offer scope isolation for Branch Managers [GATE-03]; ACL-023 Certification mark images hidden from the media picker for non-CAP-DCA users [GATE-02] |
| ADM | 3 | ADM-003 Media rules; ADM-010 Notification recipients restricted to allowed business domains [GATE-10]; ADM-011 Every Doc 11 §6.9 event type writes a complete audit row [GATE-07] |
| I18N | 11 | I18N-002 Language switcher behaviour [GATE-04]; I18N-003 Language suggestion bar, never redirect; I18N-004 Per-language self-hosted fonts; I18N-005 Date, time, number and price formatting; I18N-006 Native digits accepted and normalised; I18N-007 Translation workflow with human review [GATE-04]; I18N-010 Bilingual admin per user; I18N-012 Latin-script slugs in every language; I18N-014 Language switch preserves context; I18N-016 Translated legal pages state Japanese is authoritative [GATE-06]; I18N-018 Form values persist across language switch |
| RTL | 3 | RTL-005 Urdu Nastaliq legibility; RTL-006 Direction toggling and logical CSS; RTL-007 Staff view of RTL customer data |
| DIET | 10 | DIET-005 Expiry warnings at 30 and 7 days (E11); DIET-007 Halal statement only from approved templates [GATE-02]; DIET-008 Alcohol policy display [GATE-02]; DIET-015 Claim changes write before/after audit; DIET-018 Dish halal basis and suitableForDiet only when approved [GATE-02]; DIET-021 Claim-term scan finds restricted wording [GATE-02]; DIET-022 Urgent claim correction procedure [GATE-02]; DIET-023 Ingredient change triggers allergen review [GATE-02]; DIET-025 Expiry-day alert and no_claim fallback after certificate expiry [GATE-02]; DIET-028 Claim-type dietary tags assignable only by CAP-DCA [GATE-02] |
| HOME | 4 | HOME-001 Homepage section order and content; HOME-002 Featured dishes respect publish + translation state; HOME-004 'How reservations work' explains the request model [GATE-04]; HOME-005 Hero CTAs route correctly per language |
| CORP | 9 | CORP-001 About page editable by Content Editor without claims; CORP-005 Legal pages present, dated and governed [GATE-06]; CORP-006 404 page localised and logged; CORP-008 Brand settings drive footer and contact; CORP-010 Offer management and approval workflow; CORP-011 Offer display on home, branch pages and /offers/; CORP-012 Offer start/end enforced and never indexed after expiry; CORP-013 Offers are informational only; CORP-016 Legal pages: version parity across languages, consent-registry link and footer links [GATE-06] |
| BR | 12 | BR-002 Directory card fields; BR-003 Open-now status computed in JST; BR-004 Branch detail page content [GATE-01]; BR-005 Unconfirmed fields are not rendered [GATE-01]; BR-007 Head Office edits branch identity with validation and audit; BR-008 Reservation settings editable by Head Office only; BR-009 Weekly hours editor incl. cross-midnight; BR-010 Closures and special hours; BR-011 Branch Manager edits are immediate, audited, digested; BR-012 Notices: scope, severity, auto-expiry; BR-014 Permanently closed branch; BR-015 Sixth branch added without code change |
| MENU | 11 | MENU-001 Menu hub with branch selector; MENU-002 Category page dish cards; MENU-003 Price display rule and tax label; MENU-004 Category administration; MENU-005 Branch menu grid permissions; MENU-006 Sold-out auto-reset; MENU-008 Retired and unoffered dishes; MENU-009 Central dish edit preserves branch rows; MENU-010 Price changes are audited; MENU-011 Advance-order dish messaging; MENU-013 Demo images absent from branch, gallery and halal pages; sample items cannot be published [GATE-02] |
| NOT | 6 | NOT-006 Notification log fields; NOT-009 Inbox placement incl. Japanese carrier mail; NOT-010 Dispatch latency p95 ≤ 60 s; NOT-011 Expiry email and staff digest; NOT-016 Transactional emails contain no promotional content or tracking [GATE-06]; NOT-017 E11 certificate expiry email: variants, recipients, content and independence from E10 |
| PRV | 7 | PRV-005 Log retention periods [GATE-06]; PRV-006 Data-subject request tooling (Should); PRV-009 Contact inquiries not stored; PRV-013 No personal data sent to MT/AI services [GATE-06]; PRV-015 Contact form collects only FR-CNT-001 fields; inquiry email has no IP or user-agent metadata [GATE-06]; PRV-017 Abuse signals stored only as keyed hashes, cleared after the period; audit IPs truncated [GATE-06]; PRV-018 wp-admin screens load no third-party origins [GATE-06] |
| SEC | 14 | SEC-009 File editing disabled, plugin inventory approved [GATE-07]; SEC-011 Secrets outside the repository; SEC-014 WAF active without false positives [GATE-07]; SEC-015 Update cycle and critical patch path; SEC-016 Monitoring and alerting drill [GATE-10]; SEC-017 Incident response readiness [GATE-10]; SEC-019 Session cookie flags and logout; SEC-022 Vendor access and non-production data [GATE-06]; SEC-024 Absolute session lifetime, no Remember-me, sessions destroyed on credential change [GATE-07]; SEC-025 Application Passwords, comments, pingbacks and version disclosure disabled [GATE-07]; SEC-026 Security alert drill: denials, failed logins, plugin changes, integrity, captcha, TLS, backup [GATE-07]; SEC-028 CI security gates block merges; SEC-029 CSP console audit: approved tags cause no violations; unapproved script blocked [GATE-07]; SEC-030 CSP enforcement switch after the report-only period |
| SEO | 8 | SEO-001 URL map, trailing slash, lowercase; SEO-002 Reciprocal hreflang with x-default; SEO-003 Canonicals and noindex; SEO-004 Per-language sitemaps [GATE-09]; SEO-005 Meta titles, descriptions, OG per language; SEO-006 JSON-LD validity and parity; SEO-007 No prohibited markup; SEO-010 Search Console and sitemap submission [GATE-09] |
| ANL | 8 | ANL-001 Each GA4 event fires once with correct parameters [GATE-09]; ANL-003 reservation_form_start once per form load [GATE-09]; ANL-005 No advertising pixels; no tags in admin; ANL-006 External Transmission page matches observed tags [GATE-06]; ANL-007 Analytics opt-out works; ANL-008 Business outcomes not tracked in GA4; ANL-010 GPC and analytics opt-out stop all Google tag requests; Consent Mode defaults [GATE-06]; ANL-011 GTM blocklist blocks Custom HTML/JS; publisher and GA4 linkage settings [GATE-09] |
| PERF | 5 | PERF-001 Lab Core Web Vitals on key templates [GATE-08]; PERF-003 Page weight budgets [GATE-08]; PERF-004 API-02 at design load; PERF-005 Staff inbox with 10,000 reservations; PERF-006 Cache correctness |
| A11Y | 5 | A11Y-005 Contrast, resize, reflow, zoom [GATE-08]; A11Y-007 Language of page and parts [GATE-08]; A11Y-008 Text alternatives for images and icons [GATE-08]; A11Y-010 Accessibility statement and accommodation channel; A11Y-012 Accommodation channel on the reservation page [GATE-08] |
| CMP | 5 | CMP-002 Desktop browsers latest 2 majors; CMP-004 In-app browsers (LINE, Instagram, Facebook); CMP-005 Viewport matrix without overflow; CMP-006 Native inputs and device intents; CMP-007 Staff inbox on phone and tablet browsers |
| WP | 11 | WP-001 Version parity and pinning; WP-002 momos-core loader and theme independence; WP-003 Language configuration; WP-005 Update regression pack; WP-006 Theme templates per CPT/language [IMPL re-scoped]; WP-007 Templates render the current record and hide empty fields [IMPL re-scoped]; WP-008 RTL visual regression after theme/core update [IMPL re-scoped]; WP-009 Employee tasks without layout editing; WP-011 Testability hooks and unit coverage; WP-012 Seed/reset and test clock disabled in production [GATE-07]; WP-013 Production hardening: wp-config constants, unfiltered_html, install caps, file permissions [GATE-07] |
| BCK | 2 | BCK-003 No duplicate emails after DB restore; BCK-005 Retention job re-run after restore before the site reopens [GATE-07] |
| SMK | 3 | SMK-002 Post-deploy read-only production smoke; SMK-003 Staging build smoke (build testable); SMK-004 Hypercare daily reservation reconciliation |
| REG | 4 | REG-002 Regression after each fix; REG-004 Nightly regression stability; REG-006 Disabled branch manager does not affect reservations; REG-007 Scope compliance: no ordering UI from the UI-001 baseline |
| DISH | 5 | DISH-001 Dish detail page content; DISH-002 Dish lifecycle and publish capability; DISH-004 Variant dish for branch recipe difference; DISH-005 No language fallback for dishes; DISH-006 Demo photo label on dish and offer images; none on branch, gallery or halal pages |
| NAV | 6 | NAV-001 Header navigation desktop; NAV-002 Mobile menu and sticky action bar; NAV-003 Footer branch list and links; NAV-004 Breadcrumbs on branch, menu, dish, legal pages; NAV-005 Site crawl: no broken internal links; NAV-006 Conditional 'Offers' navigation item and permanent footer link (DEC-060) |

P2/P3 cases (35) are in the CSV: ≥ 95 % of P2 must be executed; P3 runs when time allows.

---

## 11. Definition of Done

**Task (development; Doc 13 §7.2 + ENGINEERING.md §7):**

| # | Criterion |
|---|---|
| D1 | Merged by reviewed PR. PHPCS (WPCS + security sniffs), `php -l` and stylelint are clean. No new warnings on staging. |
| D2 | Unit/integration tests are written and green. Reservation and Access line coverage ≥ 70 % [TBD]. |
| D3 | Every new REST route has an explicit `permission_callback`, JSON-schema args and catalogue error codes only. The route audit passes. |
| D4 | Capability + branch scope are checked server-side on every screen, route and AJAX/admin-post handler. A denial returns 403 and writes `access.denied`. |
| D5 | Input sanitised, output escaped, `$wpdb->prepare`, nonces on state changes |
| D6 | No hard-coded user-facing strings: customer strings via `momos_t()` in all six catalogs; admin strings via `__()` with ja/en |
| D7 | Business facts come from data (meta, tables, options). None are typed into theme templates. |
| D8 | Any front-end change is RTL-checked in ar and ur, using logical CSS only. |
| D9 | Keyboard operable, labelled, visible focus; axe shows 0 critical/serious on the changed view. |
| D10 | Every audited action writes its audit row. No PII in logs, URLs, analytics or email subjects. |
| D11 | Migrations are idempotent; module README and deploy notes are updated. |
| D12 | The task's acceptance criteria are verified on staging, with evidence attached and the TC IDs named in the test titles. |

**Story:** all its tasks are Done; Given/When/Then pass; translations of new strings requested (ar/ur/hi/bn drafts flagged).

**Stage:** the §2.5 exit criteria are met; gate evidence is filed; open defects are triaged; demo/sample content is still flagged.

**Release:** GATE-01..10 are met; `wp momos content sample-check` = 0; the Go/No-Go record is signed; the rollback has been rehearsed.

**Business/content task:** the signed evidence exists, is versioned and dated, and is referenced from the decision log.

---

## 12. UAT (one week, frozen staging snapshot, synthetic identities)

| ID | Goal | Who | Acceptance | TC |
|---|---|---|---|---|
| UAT-01 | Find a branch, today's hours, directions, phone | Customer reps | ≥ 90 % unaided **[TBD]** | TC-UAT-001 |
| UAT-02 | Understand halal basis and allergen states for 3 dishes | Customer reps (incl. one with an allergy concern) | Correct explanations; nobody reads "not verified" as "safe" | TC-UAT-002 |
| UAT-03 (P0, GATE-04) | Submit, then answer "Is your table booked?" | 5 per enabled language group | **5/5** answer "not yet / waiting for the restaurant". A smaller sample needs every participant correct + Owner acceptance. | TC-UAT-003 |
| UAT-04 (P0, GATE-10) | Process 5 seeded pending requests on own phone | Reservation Staff per branch | Correct; customer email verified; no help needed | TC-UAT-004 |
| UAT-05 | Decline with reason/suggestions; cancel a confirmed request by phone agreement | BM/RS | Reasons right; customer messages acceptable to the Owner | TC-UAT-005 |
| UAT-06 (P0, GATE-01) | Hours, closure, notice, sold-out, draft branch offer; confirm own facts | Branch Managers | Changes correct and own-branch only; facts confirmed | TC-UAT-006 |
| UAT-07 | Edit a dish, change a price, submit, publish | MM + publisher | Correct publication through review | TC-UAT-007 |
| UAT-08 (P0, GATE-02) | Compare register/dish data with certificates and recipe cards; approve | CAP-DCA holder + Head Chef | **100 % match**; signed per branch and dish set | TC-UAT-008 |
| UAT-09 (P0, GATE-04) | Walk the LCCS on mobile incl. emails; approve safety-critical strings | Translator + reviewer per language | Signed LCCS per enabled language | TC-UAT-009 |
| UAT-10 (P0, GATE-03) | Create users and assign branches; branch users try another branch | HO + 2 branch accounts | Correct setup; denials observed | TC-UAT-010 |
| UAT-11 (P0, GATE-10) | 20 seeded requests over a simulated shift incl. reminders | All branch staff | All within the configured SLA; no misrouting; confidence ≥ 4/5 | TC-UAT-011 |
| UAT-12 | Report, audit viewer, DSR tool, notices, offer approval | HO | Tasks completed correctly | TC-UAT-012 |

UAT passes when every script is Approved, or Approved with conditions (only for Medium/Low defects, each with an owner and date). No Critical or protected-area High defect may be open. The UAT-08 and UAT-09 sign-offs must exist.

The sign-off form records: script, participant role/name ⟦⟧, build and date, per-step result, defects, decision, conditions and signature.

Nimikh facilitates but never approves for the business.

---

## 13. Out of scope (Phase 1 QA)

- Load tests, active scans, destructive tests, or test bookings at real branches in production.
- Copying production PII to any non-production environment.
- Site search (DEC-045).
- Customer accounts, online ordering, payments, POS and LINE/SMS notifications.
- A JIS X 8341-3 conformance claim.
- Paid snapshot services.
- Cypress and Selenium.
- Elementor/WPML/ACF-specific checks that have no counterpart in this build (§0).
- QA never approves claims, translations or business behaviour. Approvals belong to the CAP-DCA holder, the native reviewers and the Owner.

---

## 14. Open TBDs (tests stay parameterised; owner — reference)

1. Decision cutoff [60 min] DEC-012; SLA [120 min] and its clock rule (wall clock vs business hours, global vs per-branch) DEC-013/Q-402; party/lead/window/interval/pending limit [1–8/120/60/30/3] DEC-015; customer cancel cutoff DEC-016; IP limit [5/h] and captcha-outage limit DEC-028; min decision window [30] BR-RES-15.
2. Session/idle/lockout/2FA-grace values. Current build proposals: lockout after 10 failures/IP/15 min for 60 min; idle 8 h; password 12–128 chars; reset link 3,600 s. Owner: Security lead, R1-30.
3. Claim cache purge [≤ 5 min]; CSP report-only period [2 weeks]; hourly post-launch smoke [7 days]; homepage offers [3]; export row cap [10,000]; re-verification [92/183 days]; break-glass [8 h]; evidence retention [12 months]; hypercare cadence and incident targets [1 h].
4. Per-language prohibited-term lists (ar complete, ur/hi/bn) — native reviewers, DEC-027.
5. Carrier test addresses (docomo/au/SoftBank) — QA lead/HO, DEC-018.
6. Tokyo/Osaka WebPageTest agents — Tech lead, DEC-019.
7. Recovery environment for restore tests with real backups (location, access, destruction) — CTO + counsel, DEC-033/035.
8. UAT participants and acceptance of a reduced UAT-03 sample — Owner, DEC-024.
9. `suitableForDiet` rules for a certified scope vs `halal_ingredients` — SEO lead + counsel.
10. TEST branch code (TST) — Tech lead, DEC-052.
11. Status-page content (full guest name + masked email vs given name only, no email) — counsel, Q-426. TC-PRV-016 asserts no email, phone or note text.
12. BR-DIET-06 containment target (1 h vs 2 h); certificate evidence retention (5 years after expiry vs validity + 3 years); offer image required/optional and conditions length (500 vs 600).
13. Japanese carrier `Reply-To` = branch email ⟦TBD⟧ (TC-NOT-008); Super Administrator limit ⟦2⟧.
14. `select_language` parameters (from/to vs the canonical list) — Analytics owner.
15. **[Proposed here, needs CTO/Tech lead approval]:**
    - `wp momos clock`, `wp momos jobs run`, the `momos_test_fault` fault-injection option, the `.eml` catcher format and `MOMOS_TURNSTILE_MODE=fail`. All are non-production only.

## 15. Documentation conflicts found (and the ruling used here)

| # | Conflict | Ruling |
|---|---|---|
| 1 | Test clock: TRD/SDD/Doc 12 `momos_test_clock` vs build kernel `momos_frozen_now` / `MOMOS_FROZEN_NOW` | Use the build names. A CLI wrapper hides the difference. |
| 2 | Doc 12 §2.19 allergen literal "Not used in this dish (verified)" vs Doc 07 §6.4 `allergen.state.not_contained` "Not contained (checked)" | Doc 07/Doc 11 keys govern; Doc 12 calls its literals illustrative. Assert on string keys. |
| 3 | DEC-058 "cacheable shell" vs TC-PRV-016 / Doc 11 PRV-007 `Cache-Control: no-store` | Test `no-store, private` (open item R3-30). |
| 4 | Doc 12 §3 sub-headings (e.g., "RES 34 cases", "ACL 21") vs the CSV totals (RES 42, ACL 24) after the addenda | The CSV (391 cases) is authoritative. |
| 5 | Doc 12 assumes wp-env, Mailpit, `tests-e2e/`, Elementor/WPML/ACF checks and a 14-plugin inventory incl. premium packages | Re-scoped per §0 (IMPL-001). TC-WP-010 and the premium-licence part of TC-WP-014 are N/A. TC-WP-006/007/008 and TC-ACL-019 are re-interpreted. |
| 6 | Error-code catalogue (Doc 04) lacks `forbidden_field` on API-16 and `branch_not_found` on API-14/15, which TRD uses | Accept the TRD extensions as pending catalogue additions. The "no other code" test allow-lists exactly these two. |
| 7 | Status page PII (PRD: full name + masked email; Doc 11: given name only) | Counsel decision pending (§14 item 11). |
| 8 | PRD §16.0.2 cache purge "within 60 s" vs claim purge "≤ 5 min" proposal (TC-DIET-019) | Test the stricter 60 s for all data. Report claim purge timing separately against ≤ 5 min. |
