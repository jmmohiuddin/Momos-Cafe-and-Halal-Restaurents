# Project dashboard — Momo's Cafe & Halal Restaurant platform

Status as of 2026-11-18. Branch `claude/sleepy-planck-sc50wd`. Evidence = commands run in the build environment; nothing here is claimed without a recorded run.

## Phase status

| Phase | Scope | Status | Evidence |
|---|---|---|---|
| S0/S1 Foundation | Kernel (migrations, 8 tables + 27 constraints, i18n routing, field layer, jobs, cache, monitoring, CLI), dev env, CI skeleton | Done | `wp momos smoke` passes; kernel PHPUnit |
| Spec digest | 10 area specs, 206-row requirements registry | Done | `docs/specs/`, `docs/REQUIREMENTS_REGISTRY.md` |
| Wave 1 backend | Branch, Menu, Dietary, Reservation + momos/v1 API, Notification, Access, Audit, Admin shell | Integrated (`d8bc13f`) | PHPUnit 291 tests / 7760 assertions, green twice on one DB |
| Theme | Design system, fonts per language, frame, customer screens C01–C08, C12–C22, 404, maintenance | Integrated (`d8bc13f`) | Presentation PHPUnit + Playwright frame/smoke specs (team runs); axe 0 violations on 17 URLs (team run) |
| Wave 2 | Reservation screens C09–C11 + cross-module integration, SEO/analytics, Privacy/DSR, i18n & content admin, demo seed, tooling + ja admin catalog + READMEs + admin guide | In progress | — |
| Wave 3 QA | E2E per role, branch isolation (UI/URL/API/IDs), six languages + RTL, a11y, performance, security review, regression | Not started | — |
| Release prep | Deployment checklist, rollback, monitoring, ops guides, final report | Not started | — |

## Release gates (from the documentation) — owner actions

| Gate | Needs | Status |
|---|---|---|
| GATE-01 branch data | Verified phone/address/hours per branch | Owner data pending |
| GATE-02 halal claims | Counsel + CAP-DCA approval of statements and certificates | Pending — no halal text renders until approved |
| GATE-04 languages | Native review of ar/ur/hi/bn (and JA drafts) | Pending — production enables ja+en only (IMPL-003) |
| Hosting / domain / SMTP / Turnstile / GTM | Owner-provided accounts and secrets | Not purchased or configured (outside build authorization) |

## Registers
- Decisions: `docs/IMPLEMENTATION_DECISIONS.md` (IMPL) + Doc 17 (DEC)
- Requirements: `docs/REQUIREMENTS_REGISTRY.md`
- Defects: `docs/DEFECT_REGISTER.md`
- Changes: `docs/CHANGE_REGISTER.md`
