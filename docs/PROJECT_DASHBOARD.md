# Project dashboard — Momo's Cafe & Halal Restaurant platform

Status as of 2026-11-19. Branch `claude/sleepy-planck-sc50wd`. Evidence = commands run in the build environment; nothing
here is claimed without a recorded run. Final summary: `docs/FINAL_REPORT.md`.

## Phase status

| Phase | Scope | Status | Evidence |
|---|---|---|---|
| S0/S1 Foundation | Kernel (migrations, 8 tables + constraints, i18n routing, field layer, jobs, cache, monitoring, CLI), dev env, CI | Done | `wp momos smoke` passes |
| Spec digest | 10 area specs, 206-row requirements registry | Done | `docs/specs/`, `docs/REQUIREMENTS_REGISTRY.md` |
| Wave 1 backend | Branch, Menu, Dietary, Reservation + momos/v1 API, Notification, Access, Audit, Admin | Done | module PHPUnit suites |
| Theme | Design system, fonts per language, frame, customer screens C01–C22, 404, maintenance | Done | Presentation PHPUnit + Playwright |
| Wave 2 | Reservation C09–C11, SEO/analytics, Privacy/DSR, i18n & content admin, demo seed, tooling, ja admin catalog | Done | PHPUnit 395 green at the time |
| Wave 3 QA | 7 QA suites; 81 evidenced defects (3 S1, 21 S2, 45 S3, 12 S4) | Done | `docs/QA_DEFECT_REGISTER.md`: 77 fixed, 4 blocked on decisions |
| Wave 3b closing | Cross-module follow-ups, regression failures, audit gaps, operations docs | Done | fresh-DB release-candidate run (below) |
| Release prep | Operations runbooks, release checklist, final report | Done (code side) | `docs/operations/`, `docs/RELEASE_CHECKLIST.md` |
| Go-live | Owner gates, hosting, data, approvals | **Waiting on owner** | `docs/RELEASE_CHECKLIST.md` |

## Release-candidate gates (fresh database `momos_rc`)

| Gate | Result |
|---|---|
| `php -l` (607 files) | pass |
| PHPStan level 5 | `[OK] No errors`, no unmatched baseline entries |
| PHPCS (WordPress-Extra, whole tree, errors) | 0 errors |
| PHPUnit | 1445 tests, 14513 assertions, 0 failures after the last fixes (1 skipped: production-only guard, 1 risky: output buffer in a perf test) |
| Playwright (one run, one worker, 543 tests) | 539 passed; 3 failures = one keyboard-focus defect at 390 px (fixed; affected specs re-run: 48 passed), 1 TBT flake under CPU load (passes alone) |
| `wp momos smoke` | pass |
| Backup → restore round trip | pass |

## Requirements (206) — re-audit against code and tests

| Status | Count |
|---|---|
| Implemented and tested | 175 |
| Partial | 6 (staging auth now shipped as config; PHPStan level 6 target; scanning runs only in CI; upload pipeline details) |
| Implemented, test only in CI | 2 (cross-browser projects, weekly security scan) |
| Not implemented | 0 |
| Owner dependency (data, approvals, hosting, external services) | 23 |

## Release gates — owner actions (see `docs/RELEASE_CHECKLIST.md`)

| Gate | Needs | Status |
|---|---|---|
| GATE-01 branch data | Verified phone/address/hours per branch | Owner data pending |
| GATE-02 halal claims | Counsel + CAP-DCA approval of statements and certificates | Pending — no halal text renders until approved |
| GATE-04 languages | Native review of ar/ur/hi/bn and approval of en/ja strings (IMPL-007) | Pending — production enables a language only after approval |
| Legal / privacy | Counsel-approved Privacy Policy, Terms, External Transmission page; consent version activation (IMPL-006) | Pending |
| Hosting / domain / SMTP / Turnstile / GTM / WAF / backups / uptime monitor | Owner-provided accounts and secrets | Not purchased or configured (outside build authorization) |
| Decisions | CHG-006/008 ratification, CHG-009 login captcha, QA-070 hero image, QA-079 CSV phone format | Open |

## Registers
- Decisions: `docs/IMPLEMENTATION_DECISIONS.md` (IMPL) + Doc 17 (DEC)
- Changes: `docs/CHANGE_REGISTER.md`
- Requirements: `docs/REQUIREMENTS_REGISTRY.md`
- Defects: `docs/DEFECT_REGISTER.md` (build) and `docs/QA_DEFECT_REGISTER.md` (Wave 3 QA)
- Operations: `docs/operations/` (deployment, rollback, monitoring, backup-restore, incident-response, updates-and-patching)
