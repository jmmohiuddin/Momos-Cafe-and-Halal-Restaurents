# Requirements registry — Momo's Cafe & Halal Restaurant platform (Phase 1)

Master list of every row of the documentation package `csv/requirements.csv` (Doc 04 PRD §15–17 and Appendix A), mapped to the implementation spec(s) in `docs/specs/`. Build constraints: `docs/IMPLEMENTATION_DECISIONS.md` (IMPL-001…005 — no Elementor Pro / ACF Pro / WPML; everything in `momos-core` + theme `momos`). Requirement statements below are shortened; the authoritative wording is in the spec files and Doc 04.

**How to use.** Implementation status ∈ `Not started · In progress · Implemented · Blocked`; test status ∈ `Not run · Pass · Fail · Blocked`. Update the row in the PR that implements/tests the requirement. Spec file column: first file = primary spec; others carry related detail (screens, strings, tests); `qa.md` = test design; `gaps.md` = detail added by the completeness review (§3).

## 1. Summary

| Measure | Count |
|---|---|
| Total requirements (csv rows) | 206 |
| By type | FR 135 · NFR 19 · BR 52 |
| By priority | Must 186 · Should 17 · Could 3 |
| Referenced by ID in ≥ 1 of the 10 area specs | 204 |
| Not referenced by any area spec | 2 (FR-FND-003, FR-FND-006) |
| Spec gaps (Must/Should) — full | 2 |
| Spec gaps (Must/Should) — partial | 3 |

| Type × priority | Must | Should | Could |
|---|---|---|---|
| FR | 115 | 17 | 3 |
| NFR | 19 | 0 | 0 |
| BR | 52 | 0 | 0 |

| Spec file | Requirements referenced |
|---|---|
| `screens.md` | 59 |
| `reservation.md` | 54 |
| `access-security-privacy.md` | 44 |
| `i18n-seo-analytics.md` | 41 |
| `content-strings.md` | 37 |
| `qa.md` | 36 |
| `menu-dietary.md` | 36 |
| `branch.md` | 33 |
| `notifications.md` | 29 |
| `ui-design-system.md` | 16 |
| `gaps.md` | 5 |

## 2. Registry

| ID | Source doc | Description (short) | Feature | Priority | Spec file | Implementation status | Test status |
|---|---|---|---|---|---|---|---|
| FR-FND-001 | Doc 04 §16.1 | One WordPress install; WPML languages ja (default, source), en, ar, ur, hi, bn; per-language enable/hide switch. | F-FND-01 Platform foundation | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-FND-002 | Doc 04 §16.1 | Environments: local, staging (Hostinger allowed; synthetic data only; noindex + HTTP auth), production. Code in Git; deploy … | F-FND-01 Platform foundation | Must | access-security-privacy.md, qa.md, gaps.md | Not started | Not run |
| FR-FND-003 | Doc 04 §16.1 | momos-core loaded via mu-plugin loader; modules Branch, Menu, Dietary, Reservation, Notification, Access, Audit, API … | F-FND-01 Platform foundation | Must | gaps.md | Not started | Not run |
| FR-FND-004 | Doc 04 §16.1 | HTTPS on all URLs with HSTS; HTTP→HTTPS 301. | F-FND-01 Platform foundation | Must | access-security-privacy.md | Not started | Not run |
| FR-FND-005 | Doc 04 §16.1 | Brand settings (ACF options): trading name, legal operator name ⟦TBD⟧, logo variants, default contact email/phone ⟦TBD⟧ … | F-FND-01 Platform foundation | Must | content-strings.md, screens.md, notifications.md, ui-design-system.md | Not started | Not run |
| FR-FND-006 | Doc 04 §16.1 | Plugin inventory capped and documented (target ≤ 15 active plugins incl. momos-core — LC-001). | F-FND-01 Platform foundation | Must | gaps.md | Not started | Not run |
| FR-CORP-001 | Doc 04 §16.2 | Homepage sections in order: notice banner (if active), hero (brand line, Request a table, Find a branch), offers … | F-CORP-01 Homepage | Must | screens.md, content-strings.md | Not started | Not run |
| FR-CORP-002 | Doc 04 §16.2 | About page: editable rich content (story, cuisine, team) by Content Editor; no unverified claims. | F-CORP-02 About | Must | screens.md, content-strings.md | Not started | Not run |
| FR-CORP-003 | Doc 04 §16.2 | Halal page: brand-level approved statement + per-branch table from the register (claim type, certifier, scope, certificate … | F-CORP-03 Halal information | Must | screens.md, content-strings.md | Not started | Not run |
| FR-CORP-004 | Doc 04 §16.2 | FAQ: Q&A items grouped (Reservations, Halal & dietary, Allergens, Branches & access, Payments ⟦TBD⟧); accordion accessible … | F-CORP-04 FAQ | Should | screens.md, content-strings.md, i18n-seo-analytics.md | Not started | Not run |
| FR-CORP-005 | Doc 04 §16.2 | News list/detail using native Posts category "News"; expiring news unpublished manually. | F-CORP-05 News | Could | screens.md, content-strings.md | Not started | Not run |
| FR-CORP-006 | Doc 04 §16.2 | Legal pages in all enabled languages: Privacy Policy, Terms of Use, Reservation Policy, External Transmission & Cookies … | F-CORP-06 Legal pages | Must | screens.md, content-strings.md | Not started | Not run |
| FR-CORP-007 | Doc 04 §16.2 | 404 page (localised, links to home/branches/menu/reservation, logs URL); maintenance page (503 + Retry-After) for planned … | F-CORP-07 Error/maintenance pages | Must | screens.md, i18n-seo-analytics.md, content-strings.md | Not started | Not run |
| FR-CORP-008 | Doc 04 §16.2 | Header: logo, navigation (Menu, Branches, Offers — only while ≥ 1 offer is active in the language (DEC-060) —, Halal, About … | F-CORP-08 Header/footer/nav/mobile bar | Must | screens.md, ui-design-system.md, content-strings.md, branch.md | Not started | Not run |
| FR-CORP-009 | Doc 04 §16.2 | Footer: branch list (name, phone, link), legal links, link to /offers/ (always shown — DEC-060), External Transmission link … | F-CORP-08 Header/footer/nav/mobile bar | Must | screens.md, ui-design-system.md, branch.md | Not started | Not run |
| FR-CORP-010 | Doc 04 §16.2 | Offer management (momo_offer): title, image, summary, conditions, start/end (JST), scope (all or selected branches) … | F-CORP-09 Offers & promotions | Must | screens.md, branch.md, content-strings.md | Not started | Not run |
| FR-CORP-011 | Doc 04 §16.2 | Offer display: homepage section (active featured), branch page (scoped or all-branch), /offers/ list; cards show validity … | F-CORP-09 Offers & promotions | Must | screens.md, branch.md | Not started | Not run |
| FR-CORP-012 | Doc 04 §16.2 | Gallery page: Elementor gallery of real, approved photos grouped by branch/category; demo (illustrative-flagged) images … | F-CORP-10 Gallery | Could | screens.md, content-strings.md | Not started | Not run |
| FR-BR-001 | Doc 04 §16.3 | Directory lists published open/temporarily_closed branches (closed_permanently and TEST hidden). Card: name, concept, area … | F-BR-01 Branch directory | Must | branch.md, screens.md | Not started | Not run |
| FR-BR-002 | Doc 04 §16.3 | Branch page: name, concept, gallery, address (localised + Japanese, copy), access, map facade, phone, weekly hours with … | F-BR-02 Branch detail | Must | branch.md, screens.md | Not started | Not run |
| FR-BR-003 | Doc 04 §16.3 | Empty/unconfirmed fields are not rendered; no "please inquire" placeholders on the public site. | F-BR-02 Branch detail | Must | branch.md, screens.md | Not started | Not run |
| FR-BR-004 | Doc 04 §16.3 | Head Office creates/edits branch identity: name, slug, branch_code, concept, address parts, coordinates, Place ID, phone … | F-BR-03 Branch admin | Must | branch.md, screens.md | Not started | Not run |
| FR-BR-005 | Doc 04 §16.3 | Head Office edits Reservation settings fields (see FR-RES-023). | F-BR-03 Branch admin | Must | branch.md | Not started | Not run |
| FR-BR-006 | Doc 04 §16.3 | Weekly hours: per weekday 0..n periods (label lunch/dinner/all_day; opens; closes; last_order; may cross midnight), validity … | F-BR-04 Hours & closures | Must | branch.md, screens.md | Not started | Not run |
| FR-BR-007 | Doc 04 §16.3 | Closures/special hours: date, type (closed_all_day / special_hours with periods), public_reason enum (public_holiday … | F-BR-04 Hours & closures | Must | branch.md | Not started | Not run |
| FR-BR-008 | Doc 04 §16.3 | Branch Managers create/edit/delete own-branch hours, closures and notices and toggle availability; immediate effect … | F-BR-04 Hours & closures | Must | branch.md, notifications.md | Not started | Not run |
| FR-BR-009 | Doc 04 §16.3 | Notices (momo_notice): title/body (translatable), scope site-wide or selected branches, severity info/warning, start/end … | F-BR-05 Notices | Must | branch.md, screens.md | Not started | Not run |
| FR-BR-010 | Doc 04 §16.3 | Temporary closure or closure dates block new reservation requests server-side and list affected existing pending/confirmed … | F-BR-04 Hours & closures | Must | branch.md | Not started | Not run |
| FR-BR-011 | Doc 04 §16.3 | Adding a sixth (n-th) branch requires no code change (record, settings, hours, staff assignment, branch menu rows). | F-BR-03 Branch admin | Must | branch.md, screens.md | Not started | Not run |
| FR-MENU-001 | Doc 04 §16.4 | Menu hub: ordered categories with image; branch selector (default "All branches — reference prices"); when a branch is … | F-MENU-01 Menu hub & categories | Must | menu-dietary.md, screens.md | Not started | Not run |
| FR-MENU-002 | Doc 04 §16.4 | Category page: dish cards (photo, name, short description, price, dietary icons, spice level, availability label for … | F-MENU-01 Menu hub & categories | Must | menu-dietary.md, screens.md | Not started | Not run |
| FR-MENU-003 | Doc 04 §16.4 | Dish page: name, gallery, description, price (single or per-branch table), branches offering it, dietary tags, spice 0–3 … | F-MENU-02 Dish detail | Must | menu-dietary.md, screens.md | Not started | Not run |
| FR-MENU-004 | Doc 04 §16.4 | Dish administration: title (≤ 100), short description (≤ 160), long description, gallery, assigned categories + one primary … | F-MENU-03 Dish & category admin | Must | menu-dietary.md, content-strings.md, screens.md | Not started | Not run |
| FR-MENU-005 | Doc 04 §16.4 | Category administration: create, edit, order (drag), image, translate; deleting a category with dishes is blocked. | F-MENU-03 Dish & category admin | Must | menu-dietary.md, screens.md | Not started | Not run |
| FR-MENU-006 | Doc 04 §16.4 | Branch menu grid: every published dish with offered, price_override_jpy, availability (available/sold_out/unavailable) … | F-MENU-04 Branch menu configuration | Must | menu-dietary.md, screens.md | Not started | Not run |
| FR-MENU-007 | Doc 04 §16.4 | Sold-out items automatically return to available when sold_out_until passes (job every 15 min). | F-MENU-04 Branch menu configuration | Must | menu-dietary.md | Not started | Not run |
| FR-MENU-008 | Doc 04 §16.4 | Filters on category/hub: dietary tags and max spice level; (C) "hide dishes that contain or may contain X" allergen filter … | F-MENU-05 Menu filters | Should | menu-dietary.md, screens.md | Not started | Not run |
| FR-MENU-009 | Doc 04 §16.4 | Retired/unpublished dishes are hidden; dishes offered by no branch show "not currently available"; old dish URLs 301 to … | F-MENU-02 Dish detail | Must | menu-dietary.md | Not started | Not run |
| FR-DIET-001 | Doc 04 §16.5 | Private register momo_certificate: claim_type, certifier, scope, certificate no., valid_from/until, private evidence file … | F-DIET-01 Halal register & display | Must | menu-dietary.md, screens.md | Not started | Not run |
| FR-DIET-002 | Doc 04 §16.5 | Display rules: certification wording/mark shown only when status active, within validity, approved, and mark permission true … | F-DIET-01 Halal register & display | Must | menu-dietary.md, content-strings.md, notifications.md | Not started | Not run |
| FR-DIET-003 | Doc 04 §16.5 | Per-branch halal statement chosen from approved, translated statement templates per claim type (Doc 11 §8.1 keys; facts only … | F-DIET-01 Halal register & display | Must | menu-dietary.md, content-strings.md | Not started | Not run |
| FR-DIET-004 | Doc 04 §16.5 | Per-branch alcohol policy: served (yes/no/unknown-hidden), used_in_cooking (yes/no/unknown-hidden), notes template. | F-DIET-01 Halal register & display | Must | menu-dietary.md | Not started | Not run |
| FR-DIET-005 | Doc 04 §16.5 | Per-dish state for each of 29 allergens + custom: contains / may_contain / not_contained / not_verified (default); CAP-DCA … | F-DIET-02 Allergen & ingredients | Must | menu-dietary.md, screens.md | Not started | Not run |
| FR-DIET-006 | Doc 04 §16.5 | Allergen display: summary lines, table grouped (9 mandatory-for-packaged, 20 recommended, other) with icon + text + state … | F-DIET-02 Allergen & ingredients | Must | menu-dietary.md, screens.md | Not started | Not run |
| FR-DIET-007 | Doc 04 §16.5 | Ingredients list (taxonomy) shown as "Key ingredients (not a complete list)". | F-DIET-02 Allergen & ingredients | Must | menu-dietary.md | Not started | Not run |
| FR-DIET-008 | Doc 04 §16.5 | Every change to claim fields/records writes an audit entry with before/after values. | F-DIET-01 Halal register & display | Must | menu-dietary.md | Not started | Not run |
| FR-I18N-001 | Doc 04 §16.6 | <html lang> and dir set per language; ar and ur render RTL via CSS logical properties; mirrored layout for directional icons … | F-I18N-01 Six languages + RTL | Must | i18n-seo-analytics.md, ui-design-system.md, screens.md | Not started | Not run |
| FR-I18N-002 | Doc 04 §16.6 | Language switcher in header and mobile menu with native names (日本語 · English · العربية · اردو · हिन्दी · বাংলা); links to … | F-I18N-02 Language switcher | Must | i18n-seo-analytics.md, ui-design-system.md | Not started | Not run |
| FR-I18N-003 | Doc 04 §16.6 | First visit: if Accept-Language matches an enabled language different from current, show dismissible suggestion bar; never … | F-I18N-02 Language switcher | Must | i18n-seo-analytics.md, ui-design-system.md | Not started | Not run |
| FR-I18N-004 | Doc 04 §16.6 | Per-language self-hosted font subsets: Latin = Epilogue (headings) + Plus Jakarta Sans (body) per approved design with Noto … | F-I18N-01 Six languages + RTL | Must | i18n-seo-analytics.md, ui-design-system.md | Not started | Not run |
| FR-I18N-005 | Doc 04 §16.6 | Bidirectional isolation: phone numbers, prices, dates, reference numbers, Latin brand names wrapped in <bdi>/dir="ltr" spans … | F-I18N-01 Six languages + RTL | Must | i18n-seo-analytics.md, ui-design-system.md, content-strings.md, notifications.md | Not started | Not run |
| FR-I18N-006 | Doc 04 §16.6 | 24-hour times, date formats per Doc 07 §6.10 (DEC-062), ¥ JPY with thousands separator and tax label, Western digits on … | F-I18N-01 Six languages + RTL | Must | i18n-seo-analytics.md, content-strings.md, notifications.md, ui-design-system.md | Not started | Not run |
| FR-I18N-007 | Doc 04 §16.6 | Translation workflow (WPML TM): JA source → job per language → translator → reviewer → publish; machine translation only as … | F-I18N-03 Translation workflow | Must | i18n-seo-analytics.md, screens.md | Not started | Not run |
| FR-I18N-008 | Doc 04 §16.6 | "Translation gaps" admin view: published JA items lacking a published translation per enabled language, with age. | F-I18N-03 Translation workflow | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-I18N-009 | Doc 04 §16.6 | Language enablement controlled by Head Office via capability momo_manage_languages (ROLE-01/02); the language gate refuses … | F-I18N-03 Translation workflow | Must | i18n-seo-analytics.md, screens.md | Not started | Not run |
| FR-I18N-010 | Doc 04 §16.6 | Admin UI language per user (ja/en); all momos-core admin strings localised ja/en. | F-I18N-04 Bilingual admin | Must | i18n-seo-analytics.md, notifications.md | Not started | Not run |
| FR-I18N-011 | Doc 04 §16.6 | All customer email templates and form strings exist for all 6 languages and are human-reviewed. | F-I18N-01 Six languages + RTL | Must | i18n-seo-analytics.md, content-strings.md, notifications.md | Not started | Not run |
| FR-I18N-012 | Doc 04 §16.6 | URL slugs are Latin-script (romaji/English) in every language to avoid percent-encoded URLs; titles/content translated. | F-I18N-01 Six languages + RTL | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-RES-001 | Doc 04 §16.7 | Form fields: branch*, date*, time*, party size*, name* (1–100, any script), email*, phone (optional, international, E.164) … | F-RES-01 Reservation form | Must | reservation.md, access-security-privacy.md, screens.md | Not started | Not run |
| FR-RES-002 | Doc 04 §16.7 | Client-side and server-side validation with localised inline errors and an error summary announced to assistive tech; server … | F-RES-01 Reservation form | Must | reservation.md | Not started | Not run |
| FR-RES-003 | Doc 04 §16.7 | Date/time pickers driven by API-01, which returns computed slots per date (hours, closures, lead time, window, slot … | F-RES-01 Reservation form | Must | reservation.md | Not started | Not run |
| FR-RES-004 | Doc 04 §16.7 | Short notice: if requested start < now + lead_time, submission is not allowed; message offers tap-to-call. | F-RES-01 Reservation form | Must | reservation.md | Not started | Not run |
| FR-RES-005 | Doc 04 §16.7 | API-02 creates pending reservation, created event and queued E01/E07 in one transaction; idempotency key per form load; soft … | F-RES-01 Reservation form | Must | reservation.md | Not started | Not run |
| FR-RES-006 | Doc 04 §16.7 | Result screen: 'Request received — not yet confirmed', reference XXXX-XXXX, summary (JST label non-ja only), configured … | F-RES-02 Submission result | Must | reservation.md, screens.md | Not started | Not run |
| FR-RES-007 | Doc 04 §16.7 | Abuse controls: captcha, honeypot, rate limit per IP and per email; clear localised messages for 429/403. | F-RES-01 Reservation form | Must | reservation.md | Not started | Not run |
| FR-RES-008 | Doc 04 §16.7 | Staff inbox: assigned branches only (HO all); default pending sorted by start; filters status/date/branch/flags; search … | F-RES-04 Staff inbox & detail | Must | reservation.md, screens.md | Not started | Not run |
| FR-RES-009 | Doc 04 §16.7 | Detail view: all request fields, status, timeline of events, notification statuses, available actions for current state and … | F-RES-04 Staff inbox & detail | Must | reservation.md, screens.md | Not started | Not run |
| FR-RES-010 | Doc 04 §16.7 | Accept: pending → confirmed with optimistic lock while now < start − decision cutoff (otherwise lazy expiry then 409 … | F-RES-05 Accept/decline/cancel | Must | reservation.md | Not started | Not run |
| FR-RES-011 | Doc 04 §16.7 | Decline: pending → rejected; reason code required (fully_booked, closed_or_private_event, party_size_not_supported … | F-RES-05 Accept/decline/cancel | Must | reservation.md | Not started | Not run |
| FR-RES-012 | Doc 04 §16.7 | Staff cancel: pending/confirmed → cancelled with reason (customer_request_by_phone, branch_unable_to_serve, duplicate … | F-RES-05 Accept/decline/cancel | Must | reservation.md | Not started | Not run |
| FR-RES-013 | Doc 04 §16.7 | Claim: staff sets "handled by" to self; visible to others; event logged. | F-RES-04 Staff inbox & detail | Must | reservation.md | Not started | Not run |
| FR-RES-014 | Doc 04 §16.7 | Internal notes (staff-only, never emailed), event logged. | F-RES-04 Staff inbox & detail | Must | reservation.md | Not started | Not run |
| FR-RES-015 | Doc 04 §16.7 | Modify: date/time/party size of pending/confirmed with reason "agreed with guest"; re-validated against rules (HO with … | F-RES-10 Staff modification | Should | reservation.md | Not started | Not run |
| FR-RES-016 | Doc 04 §16.7 | Expiry job every 5 min per DEC-012; lazy expiry on any staff action or customer status/cancel call past the cutoff (own … | F-RES-07 Automatic expiry | Must | reservation.md | Not started | Not run |
| FR-RES-017 | Doc 04 §16.7 | SLA reminder at response_sla_minutes to branch recipients; escalation email to Head Office at 2× SLA; clock per per-branch … | F-RES-08 SLA reminders/escalation | Should | reservation.md, notifications.md | Not started | Not run |
| FR-RES-018 | Doc 04 §16.7 | Attendance: confirmed → completed or no_show from requested start until start + 72 h; after that HO only. | F-RES-09 Attendance marking | Should | reservation.md | Not started | Not run |
| FR-RES-019 | Doc 04 §16.7 | Customer status page via token link /reservation/status/{ref}/#t={token}: status, details, branch info, cancel button when … | F-RES-03 Status page & self-cancel | Should | reservation.md, screens.md | Not started | Not run |
| FR-RES-020 | Doc 04 §16.7 | Customer cancel via token: allowed for pending/confirmed until requested start ⟦TBD cutoff⟧; staff notified. | F-RES-03 Status page & self-cancel | Should | reservation.md | Not started | Not run |
| FR-RES-021 | Doc 04 §16.7 | CSV export: Head Office only, filtered, date range ≤ ⟦12 months⟧, required reason (≤ 200, audited), row cap ⟦10,000⟧ … | F-RES-11 Reservation CSV export | Could | reservation.md, access-security-privacy.md | Not started | Not run |
| FR-RES-022 | Doc 04 §16.7 | Operations report: counts by status/branch/language/week, median & p90 response time, expiry rate, no-show rate; no PII. | F-RES-12 Operations report | Should | reservation.md, i18n-seo-analytics.md, screens.md | Not started | Not run |
| FR-RES-023 | Doc 04 §16.7 | Branch reservation settings: reservations_enabled, party_min/max, lead_time_minutes, booking_window_days … | F-RES-06 Branch reservation settings | Must | reservation.md, branch.md, notifications.md | Not started | Not run |
| FR-RES-024 | Doc 04 §16.7 | Server enforces branch operating status and closures for every submission and every modification. | F-RES-06 Branch reservation settings | Must | reservation.md, branch.md | Not started | Not run |
| FR-RES-025 | Doc 04 §16.7 | Concurrent staff actions: version mismatch → localised conflict message and refreshed record; no double transitions. | F-RES-05 Accept/decline/cancel | Must | reservation.md | Not started | Not run |
| FR-RES-026 | Doc 04 §16.7 | A hidden "TEST" branch (not in directory/sitemap, staff-only access) supports production smoke tests (DEC-055). | F-RES-06 Branch reservation settings | Must | reservation.md, branch.md, qa.md | Not started | Not run |
| FR-RES-027 | Doc 04 §16.7 | Pre-submit summary above the submit button showing branch, date, time (JST), party size and 'Replies will be sent to … | F-RES-01 Reservation form | Should | reservation.md | Not started | Not run |
| FR-NOT-001 | Doc 04 §16.8 | Customer emails E01–E06 in preferred language (fallback en, ja): brand, reference, branch, address (localised + Japanese) … | F-NOT-01 Customer emails | Must | notifications.md, content-strings.md | Not started | Not run |
| FR-NOT-002 | Doc 04 §16.8 | Staff emails SCR-E07..E10 in recipient admin locale; minimal PII (DEC-017); deep link to record. | F-NOT-02 Staff emails | Must | notifications.md | Not started | Not run |
| FR-NOT-003 | Doc 04 §16.8 | Queue (Action Scheduler) with up to 5 attempts and exponential backoff; final failure → reservation flag "customer not … | F-NOT-03 Notification log/retries | Must | notifications.md | Not started | Not run |
| FR-NOT-004 | Doc 04 §16.8 | Log per message: template key, language, recipient (masked after retention), related reservation, status … | F-NOT-03 Notification log/retries | Must | notifications.md | Not started | Not run |
| FR-NOT-005 | Doc 04 §16.8 | Subjects contain no personal data except the reference. | F-NOT-01 Customer emails | Must | notifications.md | Not started | Not run |
| FR-NOT-006 | Doc 04 §16.8 | Sending identity: brand domain with SPF/DKIM/DMARC; reply-to = branch email ⟦TBD⟧. | F-NOT-03 Notification log/retries | Must | notifications.md | Not started | Not run |
| FR-ADM-001 | Doc 04 §16.9 | Dashboard widgets per role: pending, overdue, needs action, not notified (scoped); HO: expiring certificates, not_verified … | F-ADM-01 Dashboard widgets | Should | access-security-privacy.md, screens.md | Not started | Not run |
| FR-ADM-002 | Doc 04 §16.9 | User management (SA/HO): create, disable (no delete), assign role, assign branches (BM/RS), set admin language; HO cannot … | F-ADM-02 Users, roles, branch assignment | Must | access-security-privacy.md, screens.md | Not started | Not run |
| FR-ADM-003 | Doc 04 §16.9 | Access enforcement: capability + branch scope checked on every admin screen, REST route, AJAX handler and query; denial → … | F-ADM-02 Users, roles, branch assignment | Must | access-security-privacy.md, screens.md | Not started | Not run |
| FR-ADM-004 | Doc 04 §16.9 | Audit log: actor, role, action, object type/ID, branch, before/after (PII values masked), IP (stored truncated ⟦counsel⟧) … | F-ADM-03 Audit log | Must | access-security-privacy.md, screens.md | Not started | Not run |
| FR-ADM-005 | Doc 04 §16.9 | Media: alt text required (per language) for content images; upload size limit ⟦TBD 5 MB⟧; WebP generation; illustrative … | F-ADM-04 Media management | Must | access-security-privacy.md, content-strings.md, screens.md | Not started | Not run |
| FR-ADM-006 | Doc 04 §16.9 | Staff session idle timeout ⟦proposal 8 h⟧; login throttling; 2FA enforced at first login. | F-ADM-02 Users, roles, branch assignment | Must | access-security-privacy.md, screens.md | Not started | Not run |
| FR-CNT-001 | Doc 04 §16.10 | Contact form: name*, email*, topic* (general, group/event, feedback, media, other), branch (optional), message* (≤2,000) … | F-CNT-01 Contact form | Should | content-strings.md, access-security-privacy.md, screens.md, qa.md | Not started | Not run |
| FR-SEO-001 | Doc 04 §16.11 | URL map per Part 3 §URL; trailing slash; lowercase. | F-SEO-01 Technical SEO | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-SEO-002 | Doc 04 §16.11 | Reciprocal hreflang for enabled translated pages (ja, en, ar, ur, hi, bn) + x-default (/en/). | F-SEO-01 Technical SEO | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-SEO-003 | Doc 04 §16.11 | Canonical tags; noindex for reservation result/status pages, test branch, filtered query variants. | F-SEO-01 Technical SEO | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-SEO-004 | Doc 04 §16.11 | XML sitemaps per language (Rank Math + WPML); exclude noindex/hidden languages. | F-SEO-01 Technical SEO | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-SEO-005 | Doc 04 §16.11 | Editable meta title/description/OG per language. | F-SEO-01 Technical SEO | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-SEO-006 | Doc 04 §16.11 | JSON-LD from data: Organization + WebSite; Restaurant per branch (address, geo, telephone, url, openingHoursSpecification … | F-SEO-02 Structured data | Must | i18n-seo-analytics.md, menu-dietary.md, branch.md | Not started | Not run |
| FR-SEO-007 | Doc 04 §16.11 | Visible breadcrumbs on branch, menu, dish, legal pages. | F-SEO-01 Technical SEO | Must | i18n-seo-analytics.md, branch.md | Not started | Not run |
| FR-SEO-008 | Doc 04 §16.11 | 301 redirect map from legacy site; 404 log reviewed weekly for 8 weeks after launch. | F-SEO-03 Legacy redirects | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-SEO-009 | Doc 04 §16.11 | Staging blocked from indexing (noindex + HTTP auth); production robots.txt with sitemap index. | F-SEO-01 Technical SEO | Must | i18n-seo-analytics.md, qa.md | Not started | Not run |
| FR-ANL-001 | Doc 04 §16.12 | GA4 via GTM; events view_branch, view_menu, view_dish, view_halal_information, select_language, click_reservation … | F-ANL-01 Analytics events | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-ANL-002 | Doc 04 §16.12 | External Transmission & Cookies page lists every tag/third party, data sent, purpose, opt-out; analytics opt-out control … | F-ANL-02 Transmission notice & opt-out | Must | i18n-seo-analytics.md, content-strings.md, screens.md | Not started | Not run |
| FR-ANL-003 | Doc 04 §16.12 | Business outcome metrics (accepted, declined, expired, no-show) come from FR-RES-022, not GA4. | F-ANL-01 Analytics events | Must | i18n-seo-analytics.md | Not started | Not run |
| FR-SEC-001 | Doc 04 §16.13 | 2FA for all staff roles | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md, screens.md | Not started | Not run |
| FR-SEC-002 | Doc 04 §16.13 | login throttling & lockout | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-SEC-003 | Doc 04 §16.13 | WAF | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-SEC-004 | Doc 04 §16.13 | DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS true in production; auto-updates off; installs/updates only via staging and the … | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-SEC-005 | Doc 04 §16.13 | security headers (HSTS, CSP per the canonical Doc 11 §6.4 policy — report-only at launch, enforced after ⟦2 weeks⟧ without … | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-SEC-006 | Doc 04 §16.13 | WordPress nonces on all authenticated (staff) state-changing requests; the cached public reservation/contact forms instead … | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-SEC-007 | Doc 04 §16.13 | server-side validation & output escaping | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-SEC-008 | Doc 04 §16.13 | secrets in environment/wp-config outside repo | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-SEC-009 | Doc 04 §16.13 | monthly update cycle via staging and the deployment pipeline; critical security patches ≤ 72 h (DEC-065) | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-SEC-010 | Doc 04 §16.13 | vulnerability scanning (weekly) | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md, qa.md | Not started | Not run |
| FR-SEC-011 | Doc 04 §16.13 | backups per DEC-033 | F-SEC-02 Backup & restore | Must | access-security-privacy.md, qa.md | Not started | Not run |
| FR-SEC-012 | Doc 04 §16.13 | uptime/error/queue monitoring with alerts | F-SEC-03 Monitoring & alerting | Should | access-security-privacy.md | Not started | Not run |
| FR-SEC-013 | Doc 04 §16.13 | incident response runbook & contacts | F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| FR-PRV-001 | Doc 04 §16.14 | Privacy Policy per APPI/LC-001: operator, purposes, items, special-care statement, third parties, entrustment, storage … | F-PRV-01 Privacy notices & consent | Must | access-security-privacy.md, content-strings.md, screens.md, qa.md | Not started | Not run |
| FR-PRV-002 | Doc 04 §16.14 | Notice at collection on reservation and contact forms + required consent checkbox; consent text version stored with … | F-PRV-01 Privacy notices & consent | Must | access-security-privacy.md | Not started | Not run |
| FR-PRV-003 | Doc 04 §16.14 | Retention job: anonymise reservation PII after the retention period (name, email, phone, special request, staff message … | F-PRV-02 Retention job | Must | access-security-privacy.md, notifications.md | Not started | Not run |
| FR-PRV-004 | Doc 04 §16.14 | Data-subject request tooling for HO: find by email/reference, export (WP personal data exporter), correct, anonymise; all … | F-PRV-03 Data-subject request tools | Should | access-security-privacy.md, notifications.md, screens.md | Not started | Not run |
| FR-PRV-005 | Doc 04 §16.14 | No PII in URLs (except opaque token), logs, analytics, email subjects. | F-PRV-01 Privacy notices & consent | Must | access-security-privacy.md, i18n-seo-analytics.md | Not started | Not run |
| NFR-PERF-01 | Doc 04 §15; Doc 09 §6 | Core Web Vitals on key templates (home, branch, menu category, dish, reservation) — mobile: Lab (Lighthouse, Moto G-class … | Cross-cutting NFR | Must | screens.md, ui-design-system.md, qa.md | Not started | Not run |
| NFR-PERF-02 | Doc 04 §15; Doc 09 §6 | Server response from Japan: cached HTML TTFB ≤ 800 ms p75 (gate, measured from Tokyo/Osaka test locations); ⟦target ≤ 600 … | Cross-cutting NFR | Must | qa.md, gaps.md | Not started | Not run |
| NFR-PERF-03 | Doc 04 §15; Doc 09 §6 | Page weight: initial load ≤ 1.5 MB on mobile key templates; hero image ≤ 200 KB; card images ≤ 80 KB WebP/AVIF; fonts only … | Cross-cutting NFR | Must | ui-design-system.md, i18n-seo-analytics.md, qa.md | Not started | Not run |
| NFR-PERF-04 | Doc 04 §15; Doc 09 §6 | Reservation API: API-02 p95 ≤ 2 s at ⟦design load 20 concurrent submissions⟧; staff inbox list p95 ≤ 2 s for ⟦10,000⟧ stored … | Cross-cutting NFR | Must | reservation.md, qa.md | Not started | Not run |
| NFR-PERF-05 | Doc 04 §15; Doc 09 §6 | Notification dispatch: queued email handed to provider ≤ 60 s after the triggering event (p95) under normal operation | Cross-cutting NFR | Must | notifications.md, qa.md | Not started | Not run |
| NFR-AVL-01 | Doc 04 §15; Doc 09 §6 | Availability: ⟦99.5% monthly⟧ excluding announced maintenance; 1-minute external uptime monitoring with alerts | Cross-cutting NFR | Must | access-security-privacy.md, qa.md | Not started | Not run |
| NFR-REL-01 | Doc 04 §15; Doc 09 §6 | No lost requests: submission is atomic; idempotent; email failure never loses or rolls back a reservation | Cross-cutting NFR | Must | notifications.md, reservation.md, qa.md | Not started | Not run |
| NFR-SEC-01 | Doc 04 §15; Doc 09 §6 | Security baseline: per Doc 11 (OWASP Top 10:2025 coverage, 2FA, headers, scanning) | Cross-cutting NFR | Must | access-security-privacy.md, qa.md | Not started | Not run |
| NFR-PRV-01 | Doc 04 §15; Doc 09 §6 | Privacy baseline: per Doc 11 (APPI notices, retention, entrustment disclosure) | Cross-cutting NFR | Must | access-security-privacy.md, qa.md | Not started | Not run |
| NFR-ACC-01 | Doc 04 §15; Doc 09 §6 | Accessibility: WCAG 2.2 AA on all customer templates and reservation journey; 0 automated critical/serious axe violations; 0 … | Cross-cutting NFR | Must | ui-design-system.md, i18n-seo-analytics.md, screens.md, qa.md | Not started | Not run |
| NFR-I18N-01 | Doc 04 §15; Doc 09 §6 | Languages & RTL: 6 languages; RTL correct for ar/ur on all templates; 0 mixed-language pages; 0 hreflang errors | Cross-cutting NFR | Must | i18n-seo-analytics.md, screens.md, ui-design-system.md, qa.md | Not started | Not run |
| NFR-CMP-01 | Doc 04 §15; Doc 09 §6 | Browser/device support: Latest 2 major versions: Chrome (Android, Windows, macOS), Safari (iOS, macOS), Edge, Firefox … | Cross-cutting NFR | Must | ui-design-system.md, screens.md, qa.md | Not started | Not run |
| NFR-MNT-01 | Doc 04 §15; Doc 09 §6 | Maintainability: pinned plugin versions; monthly update cycle via staging; README per momos-core module; coding standards … | Cross-cutting NFR | Must | qa.md, gaps.md | Not started | Not run |
| NFR-SCL-01 | Doc 04 §15; Doc 09 §6 | Scalability: add branches without code; data model valid to ⟦20⟧ branches; no schema change needed for Phase 2 POS IDs … | Cross-cutting NFR | Must | branch.md, qa.md | Not started | Not run |
| NFR-BCK-01 | Doc 04 §15; Doc 09 §6 | Backup & recovery: per DEC-033: daily full + DB every 4 h, 30 days, off-site; RPO ≤ 4 h, RTO ≤ 8 h; restore test pre-launch … | Cross-cutting NFR | Must | access-security-privacy.md, qa.md | Not started | Not run |
| NFR-OBS-01 | Doc 04 §15; Doc 09 §6 | Observability: PHP error log outside web root; notification failures visible in admin; job heartbeat check (expiry/queue) … | Cross-cutting NFR | Must | access-security-privacy.md, notifications.md, reservation.md, qa.md | Not started | Not run |
| NFR-DATA-01 | Doc 04 §15; Doc 09 §6 | Data integrity: UNIQUE constraints (public_ref, idempotency_key, branch+dish); optimistic locking; all times stored UTC + … | Cross-cutting NFR | Must | reservation.md, qa.md | Not started | Not run |
| NFR-SEO-01 | Doc 04 §15; Doc 09 §6 | SEO technical: valid sitemaps; canonical on all indexable pages; JSON-LD validates (Schema.org validator, Rich Results Test) | Cross-cutting NFR | Must | i18n-seo-analytics.md, qa.md | Not started | Not run |
| BR-RES-01 | Doc 04 §17 | A submitted request is never presented as confirmed; before acceptance no screen/email uses 'confirmed', 'booked' … | F-RES-02 Submission result; F-NOT-01 Customer emails; F-CORP-01 Homepage | Must | reservation.md, content-strings.md, notifications.md, screens.md, qa.md | Not started | Not run |
| BR-RES-02 | Doc 04 §17 | Only users assigned to the reservation's branch (ROLE-06/07) or ROLE-02/01 may view or act on it. | F-RES-04 Staff inbox & detail; F-ADM-02 Users, roles, branch assignment; F-NOT-02 Staff emails | Must | reservation.md, access-security-privacy.md, qa.md | Not started | Not run |
| BR-RES-03 | Doc 04 §17 | Request valid only if branch published, open and accepting; date not a closure; time is a generated slot within a period and … | F-RES-01 Reservation form; F-RES-06 Branch reservation settings; F-BR-04 Hours & closures | Must | reservation.md, qa.md | Not started | Not run |
| BR-RES-04 | Doc 04 §17 | Decline and staff cancel require a reason code from the fixed lists. | F-RES-05 Accept/decline/cancel | Must | reservation.md | Not started | Not run |
| BR-RES-05 | Doc 04 §17 | Terminal statuses (rejected, cancelled, expired, completed, no_show) are final; corrections create a new request (staff may … | F-RES-05 Accept/decline/cancel; F-RES-03 Status page & self-cancel | Must | reservation.md | Not started | Not run |
| BR-RES-06 | Doc 04 §17 | Pending requests expire when now ≥ requested start − decision_cutoff. | F-RES-07 Automatic expiry; F-RES-05 Accept/decline/cancel | Must | reservation.md | Not started | Not run |
| BR-RES-07 | Doc 04 §17 | Customers may self-cancel pending/confirmed requests until requested start − ⟦cutoff⟧. | F-RES-03 Status page & self-cancel | Must | reservation.md, content-strings.md | Not started | Not run |
| BR-RES-08 | Doc 04 §17 | Duplicate prevention: idempotency key per form load; soft-duplicate window 10 minutes (same email, branch, date, time); max … | F-RES-01 Reservation form | Must | reservation.md | Not started | Not run |
| BR-RES-09 | Doc 04 §17 | Attendance (completed/no_show) may be recorded only from requested start to start + 72 h by branch staff; Head Office any … | F-RES-09 Attendance marking | Must | reservation.md | Not started | Not run |
| BR-RES-10 | Doc 04 §17 | Parties above the branch maximum cannot be submitted online; the form shows the branch phone/contact route. | F-RES-01 Reservation form; F-RES-06 Branch reservation settings; F-CORP-08 Header/footer/nav/mobile bar | Must | reservation.md, qa.md | Not started | Not run |
| BR-RES-11 | Doc 04 §17 | Staff modification requires the guest's agreement, a reason, re-validation and a customer email. | F-RES-10 Staff modification | Must | reservation.md | Not started | Not run |
| BR-RES-12 | Doc 04 §17 | Email failure never invalidates a reservation; flagged reservations require staff follow-up (phone if provided). | F-NOT-03 Notification log/retries | Must | reservation.md, notifications.md | Not started | Not run |
| BR-RES-13 | Doc 04 §17 | New closures do not auto-cancel existing reservations; affected pending/confirmed requests are flagged "needs action". | F-BR-04 Hours & closures | Must | reservation.md, branch.md | Not started | Not run |
| BR-RES-14 | Doc 04 §17 | No deposits, prepayments or no-show fees are collected or promised in Phase 1. | F-RES-01 Reservation form; F-CORP-06 Legal pages | Must | reservation.md, content-strings.md, notifications.md | Not started | Not run |
| BR-BR-01 | Doc 04 §17 | Only Head Office changes branch identity fields (name, code, concept, address, coordinates, phone, email, operating status) … | F-BR-03 Branch admin; F-RES-06 Branch reservation settings | Must | branch.md | Not started | Not run |
| BR-BR-02 | Doc 04 §17 | Branch Managers change hours, closures, notices, dish availability (and description/photos) for assigned branches only … | F-BR-04 Hours & closures; F-BR-05 Notices; F-MENU-04 Branch menu configuration | Must | branch.md | Not started | Not run |
| BR-BR-03 | Doc 04 §17 | A branch may be published only after its facts are confirmed in writing by the Owner/Head Office (GATE-01). | F-BR-03 Branch admin | Must | branch.md | Not started | Not run |
| BR-BR-04 | Doc 04 §17 | A permanently closed branch is removed from public lists and blocks reservations; its URL 301-redirects to /branches/ … | F-BR-01 Branch directory; F-BR-03 Branch admin | Must | branch.md | Not started | Not run |
| BR-BR-05 | Doc 04 §17 | "Open now" and slot generation are computed in Asia/Tokyo from hours + closures. | F-BR-01 Branch directory; F-RES-01 Reservation form | Must | branch.md | Not started | Not run |
| BR-MENU-01 | Doc 04 §17 | Dish facts (name, description, ingredients, allergens, halal basis) are central; branch rows only control offered, price … | F-MENU-03 Dish & category admin; F-MENU-04 Branch menu configuration | Must | menu-dietary.md | Not started | Not run |
| BR-MENU-02 | Doc 04 §17 | Displayed price = branch override if set, else reference price; JPY, tax-inclusive, labelled "(tax incl.)". | F-MENU-01 Menu hub & categories; F-MENU-04 Branch menu configuration | Must | menu-dietary.md | Not started | Not run |
| BR-MENU-03 | Doc 04 §17 | Only Menu Manager/Head Office change prices; every change is audited. | F-MENU-03 Dish & category admin; F-MENU-04 Branch menu configuration | Must | menu-dietary.md | Not started | Not run |
| BR-MENU-04 | Doc 04 §17 | Sold-out resets automatically at sold_out_until (default end of business day JST). | F-MENU-04 Branch menu configuration | Must | menu-dietary.md | Not started | Not run |
| BR-MENU-05 | Doc 04 §17 | A dish appears in a language only if published and translated in that language. | F-MENU-01 Menu hub & categories; F-CORP-01 Homepage | Must | menu-dietary.md | Not started | Not run |
| BR-MENU-06 | Doc 04 §17 | Advance-order dishes display their lead time and a note to mention them in the reservation special request; they are not … | F-MENU-02 Dish detail | Must | menu-dietary.md | Not started | Not run |
| BR-MENU-07 | Doc 04 §17 | Real recipe differences between branches are modelled as variant dishes. | F-MENU-03 Dish & category admin; F-DIET-02 Allergen & ingredients | Must | menu-dietary.md | Not started | Not run |
| BR-DIET-01 | Doc 04 §17 | Halal wording and marks follow DEC-020; "100% halal" or seal images require an active certified record with mark permission. | F-DIET-01 Halal register & display; F-CORP-03 Halal information | Must | menu-dietary.md, content-strings.md | Not started | Not run |
| BR-DIET-02 | Doc 04 §17 | Expired or revoked certificates hide related claims automatically; HO and CAP-DCA holders are warned 30 and 7 days before … | F-DIET-01 Halal register & display | Must | menu-dietary.md | Not started | Not run |
| BR-DIET-03 | Doc 04 §17 | Allergen display follows DEC-021; never "allergen-free", "safe for allergies" or equivalents. | F-DIET-02 Allergen & ingredients; F-MENU-05 Menu filters | Must | menu-dietary.md, content-strings.md | Not started | Not run |
| BR-DIET-04 | Doc 04 §17 | Only CAP-DCA holders edit claim fields; data sourced from a kitchen document (two-person rule). | F-DIET-01 Halal register & display; F-DIET-02 Allergen & ingredients | Must | menu-dietary.md | Not started | Not run |
| BR-DIET-05 | Doc 04 §17 | Claim text (halal, allergen, alcohol) is never published from unreviewed machine translation. | F-I18N-03 Translation workflow; F-DIET-01 Halal register & display | Must | menu-dietary.md, i18n-seo-analytics.md, content-strings.md | Not started | Not run |
| BR-I18N-01 | Doc 04 §17 | A language is publicly enabled only after its LCCS passes native review (GATE-04). | F-I18N-03 Translation workflow; F-FND-01 Platform foundation | Must | i18n-seo-analytics.md, content-strings.md, qa.md | Not started | Not run |
| BR-I18N-02 | Doc 04 §17 | No mixed-language fallback on the front end; untranslated items are hidden in that language. | F-I18N-02 Language switcher; F-MENU-01 Menu hub & categories; F-BR-05 Notices; F-CORP-09 Offers & promotions | Must | i18n-seo-analytics.md, content-strings.md, branch.md, menu-dietary.md, reservation.md, screens.md, ui-design-system.md, qa.md | Not started | Not run |
| BR-I18N-03 | Doc 04 §17 | Japanese legal text is authoritative; translations state this. | F-CORP-06 Legal pages | Must | i18n-seo-analytics.md, content-strings.md | Not started | Not run |
| BR-ADM-01 | Doc 04 §17 | Staff users are disabled, never deleted; each person has an individual account; shared logins prohibited. | F-ADM-02 Users, roles, branch assignment | Must | access-security-privacy.md | Not started | Not run |
| BR-ADM-02 | Doc 04 §17 | 2FA is mandatory for every staff account before any admin access. | F-ADM-02 Users, roles, branch assignment; F-SEC-01 Security hardening & 2FA | Must | access-security-privacy.md | Not started | Not run |
| BR-ADM-03 | Doc 04 §17 | Role changes and branch assignments only by HO (or SA); audited; HO cannot elevate to SA. | F-ADM-02 Users, roles, branch assignment | Must | access-security-privacy.md | Not started | Not run |
| BR-PRV-01 | Doc 04 §17 | Collect only the fields in FR-RES-001/FR-CNT-001; never religion, nationality, ID, date of birth or payment data. | F-RES-01 Reservation form; F-CNT-01 Contact form | Must | access-security-privacy.md, i18n-seo-analytics.md, reservation.md | Not started | Not run |
| BR-PRV-02 | Doc 04 §17 | Retention per DEC-034 (reservation PII ⟦12 months⟧ after reservation date; notifications 180 days; audit 24 months; backups … | F-PRV-02 Retention job; F-NOT-03 Notification log/retries | Must | access-security-privacy.md, notifications.md | Not started | Not run |
| BR-PRV-03 | Doc 04 §17 | Reservation export only by Head Office (Phase 1), audited; Branch Managers cannot export. | F-RES-11 Reservation CSV export; F-PRV-03 Data-subject request tools | Must | access-security-privacy.md | Not started | Not run |
| BR-PRV-04 | Doc 04 §17 | [Rec — new] No personal data is sent to machine-translation or other AI services. | F-I18N-03 Translation workflow | Must | access-security-privacy.md, i18n-seo-analytics.md, notifications.md, content-strings.md, reservation.md | Not started | Not run |
| BR-NOT-01 | Doc 04 §17 | Staff emails carry minimal PII (DEC-017); customer emails carry only that customer's data. | F-NOT-01 Customer emails; F-NOT-02 Staff emails | Must | notifications.md, reservation.md | Not started | Not run |
| BR-NOT-02 | Doc 04 §17 | [Rec — new] Internal notes and staff internal data are never included in customer-facing emails or pages … | F-RES-04 Staff inbox & detail; F-NOT-01 Customer emails | Must | notifications.md, reservation.md | Not started | Not run |
| BR-CNT-01 | Doc 04 §17 | Contact inquiries are not stored in WordPress. | F-CNT-01 Contact form | Must | content-strings.md, screens.md | Not started | Not run |
| BR-CNT-02 | Doc 04 §17 | Content Editors cannot publish or change claim fields, prices or branch identity. | F-CORP-06 Legal pages; F-MENU-03 Dish & category admin | Must | menu-dietary.md | Not started | Not run |
| BR-SEO-01 | Doc 04 §17 | No review/rating markup about Momo's; structured data must match visible content. | F-SEO-02 Structured data; F-CORP-04 FAQ | Must | i18n-seo-analytics.md, content-strings.md | Not started | Not run |
| BR-CORP-01 | Doc 04 §17 | Offers are informational: no coupon codes, online redemption, payment or automatic price change; every offer shows validity … | F-CORP-09 Offers & promotions | Must | screens.md, branch.md, content-strings.md | Not started | Not run |
| BR-CORP-02 | Doc 04 §17 | Offers publish only after Head Office approval; expired offers are hidden automatically. | F-CORP-09 Offers & promotions | Must | branch.md | Not started | Not run |
| BR-CORP-03 | Doc 04 §17 | Demo/sample content: no sample dish names, descriptions or prices on the public site; demo photos only on dish and offer … | F-MENU-03 Dish & category admin; F-ADM-04 Media management; F-CORP-10 Gallery | Must | screens.md, content-strings.md, branch.md, menu-dietary.md, ui-design-system.md, qa.md | Not started | Not run |
| FR-RES-028 | Doc 04 App. A (Doc 07 §4.7) | Staff inbox refreshes every 60 s with an "n new — Show" bar (no re-sorting under the user's finger) and shows an offline … | F-RES-04 Staff inbox & detail | Should | reservation.md | Not started | Not run |
| FR-RES-029 | Doc 04 App. A (Doc 05/06) | Settings list of Japanese mobile-carrier mail domains (momo_carrier_domains); when a request uses one, the result screen and … | F-RES-01 Reservation form | Should | reservation.md, notifications.md | Not started | Not run |
| FR-RES-030 | Doc 04 App. A (Doc 12; DEC-055) | Reservations at the hidden TEST branch are test data: notifications go only to the QA inbox; excluded from reports, KPIs and … | F-RES-01 Reservation form | Must | reservation.md, branch.md, notifications.md, qa.md | Not started | Not run |
| FR-ADM-007 | Doc 04 App. A (Doc 11 SEC-008) | Vendor/offshore accounts are named, time-limited (momo_access_expires_at enforced at authentication), 2FA, and logged … | F-ADM-02 Users, roles, branch assignment | Must | access-security-privacy.md | Not started | Not run |
| FR-ADM-008 | Doc 04 App. A (Doc 05 WF-ADM) | Changing a user's role or branch assignments, or disabling the user, revokes all of that user's active sessions immediately … | F-ADM-02 Users, roles, branch assignment | Must | access-security-privacy.md | Not started | Not run |
| FR-I18N-013 | Doc 04 App. A (Doc 07 §4) | Switching language on the reservation form restores entered values (except consent) in the target language; if storage is … | F-I18N-02 Language switcher | Should | i18n-seo-analytics.md | Not started | Not run |
| FR-DIET-009 | Doc 04 App. A (Doc 11 §8) | Admin claim-term scan (ROLE-01/02, CAP-DCA) lists published content containing restricted terms (e.g., certified, 100% … | F-DIET-01 Halal register & display | Should | menu-dietary.md, content-strings.md | Not started | Not run |
| BR-RES-15 | Doc 04 App. A (Doc 05 §4.5) | Branch reservation settings must satisfy decision_cutoff_minutes ≤ lead_time_minutes − momo_min_decision_window_minutes ⟦30⟧ … | F-RES-06 Branch reservation settings | Must | reservation.md, branch.md, qa.md | Not started | Not run |
| BR-DIET-06 | Doc 04 App. A (Doc 05 WF-EXC) | A reported error in a published halal/alcohol/allergen claim is contained (claim withdrawn or set to not verified) within … | F-DIET-01 Halal register & display | Must | menu-dietary.md, qa.md | Not started | Not run |
| BR-DIET-07 | Doc 04 App. A (Doc 05 WF-ADM) | Changing a dish's ingredients or a reported recipe/supplier change sets momo_allergen_review_required; the dish shows the … | F-DIET-02 Allergen & ingredients | Must | menu-dietary.md | Not started | Not run |
| NFR-MNT-02 | Doc 04 App. A (Doc 12 §1) | Non-production environments provide seed/reset commands (wp momo seed/reset) and a test clock for time-dependent jobs; both … | F-FND-01 Platform foundation | Must | qa.md | Not started | Not run |

### 2.1 Appendix A items that are not requirement rows

Doc 04 Appendix A also registers one screen and one API that `requirements.csv` does not contain. They are tracked here so they are not lost.

| ID | Source doc | Description (short) | Feature | Priority | Spec file | Implementation status | Test status |
|---|---|---|---|---|---|---|---|
| SCR-E11 | Doc 04 App. A; Doc 07 §5.36 | Staff email: certificate expiry warning (cert_30 / cert_7 / cert_expired), ja/en, to HO list + CAP-DCA holders; CertificateExpiryJob daily 00:10 JST, independent of E10 | F-DIET-01 Halal register & display | Must | notifications.md, menu-dietary.md, screens.md | Not started | Not run |
| API-17 | Doc 04 App. A; Doc 11 §6 | POST /momos/v1/csp-report — receives CSP violation reports (rate-limited, no PII stored) | F-SEC-01 Security hardening & 2FA | Could | access-security-privacy.md, qa.md | Not started | Not run |

### 2.2 Feature cross-check (Doc 03 §2.7 — 58 features: 44 Must, 11 Should, 3 Could)

Every feature has at least one requirement in the registry and at least one spec. Specs listed are those that reference the feature's requirements by ID.

| Feature | Priority | Requirements | Specs |
|---|---|---|---|
| F-FND-01 Platform foundation | Must | FR-FND-001, FR-FND-002, FR-FND-003, FR-FND-004, FR-FND-005, FR-FND-006, BR-I18N-01, NFR-MNT-02 | access-security-privacy.md, content-strings.md, i18n-seo-analytics.md, notifications.md, screens.md, ui-design-system.md, qa.md |
| F-CORP-01 Homepage | Must | FR-CORP-001, BR-RES-01, BR-MENU-05 | content-strings.md, menu-dietary.md, notifications.md, reservation.md, screens.md, qa.md |
| F-CORP-02 About | Must | FR-CORP-002 | content-strings.md, screens.md |
| F-CORP-03 Halal information | Must | FR-CORP-003, BR-DIET-01 | content-strings.md, menu-dietary.md, screens.md |
| F-CORP-04 FAQ | Should | FR-CORP-004, BR-SEO-01 | content-strings.md, i18n-seo-analytics.md, screens.md |
| F-CORP-05 News | Could | FR-CORP-005 | content-strings.md, screens.md |
| F-CORP-06 Legal pages | Must | FR-CORP-006, BR-RES-14, BR-I18N-03, BR-CNT-02 | content-strings.md, i18n-seo-analytics.md, menu-dietary.md, notifications.md, reservation.md, screens.md |
| F-CORP-07 Error/maintenance pages | Must | FR-CORP-007 | content-strings.md, i18n-seo-analytics.md, screens.md |
| F-CORP-08 Header/footer/nav/mobile bar | Must | FR-CORP-008, FR-CORP-009, BR-RES-10 | branch.md, content-strings.md, reservation.md, screens.md, ui-design-system.md, qa.md |
| F-CORP-09 Offers & promotions | Must | FR-CORP-010, FR-CORP-011, BR-I18N-02, BR-CORP-01, BR-CORP-02 | branch.md, content-strings.md, i18n-seo-analytics.md, menu-dietary.md, reservation.md, screens.md, ui-design-system.md, qa.md |
| F-CORP-10 Gallery | Could | FR-CORP-012, BR-CORP-03 | branch.md, content-strings.md, menu-dietary.md, screens.md, ui-design-system.md, qa.md |
| F-BR-01 Branch directory | Must | FR-BR-001, BR-BR-04, BR-BR-05 | branch.md, screens.md |
| F-BR-02 Branch detail | Must | FR-BR-002, FR-BR-003 | branch.md, screens.md |
| F-BR-03 Branch admin | Must | FR-BR-004, FR-BR-005, FR-BR-011, BR-BR-01, BR-BR-03, BR-BR-04 | branch.md, screens.md |
| F-BR-04 Hours & closures | Must | FR-BR-006, FR-BR-007, FR-BR-008, FR-BR-010, BR-RES-03, BR-RES-13, BR-BR-02 | branch.md, notifications.md, reservation.md, screens.md, qa.md |
| F-BR-05 Notices | Must | FR-BR-009, BR-BR-02, BR-I18N-02 | branch.md, content-strings.md, i18n-seo-analytics.md, menu-dietary.md, reservation.md, screens.md, ui-design-system.md, qa.md |
| F-MENU-01 Menu hub & categories | Must | FR-MENU-001, FR-MENU-002, BR-MENU-02, BR-MENU-05, BR-I18N-02 | branch.md, content-strings.md, i18n-seo-analytics.md, menu-dietary.md, reservation.md, screens.md, ui-design-system.md, qa.md |
| F-MENU-02 Dish detail | Must | FR-MENU-003, FR-MENU-009, BR-MENU-06 | menu-dietary.md, screens.md |
| F-MENU-03 Dish & category admin | Must | FR-MENU-004, FR-MENU-005, BR-MENU-01, BR-MENU-03, BR-MENU-07, BR-CNT-02, BR-CORP-03 | branch.md, content-strings.md, menu-dietary.md, screens.md, ui-design-system.md, qa.md |
| F-MENU-04 Branch menu configuration | Must | FR-MENU-006, FR-MENU-007, BR-BR-02, BR-MENU-01, BR-MENU-02, BR-MENU-03, BR-MENU-04 | branch.md, menu-dietary.md, screens.md |
| F-MENU-05 Menu filters | Should | FR-MENU-008, BR-DIET-03 | content-strings.md, menu-dietary.md, screens.md |
| F-DIET-01 Halal register & display | Must | FR-DIET-001, FR-DIET-002, FR-DIET-003, FR-DIET-004, FR-DIET-008, BR-DIET-01, BR-DIET-02, BR-DIET-04, BR-DIET-05, FR-DIET-009, BR-DIET-06 | content-strings.md, i18n-seo-analytics.md, menu-dietary.md, notifications.md, screens.md, qa.md |
| F-DIET-02 Allergen & ingredients | Must | FR-DIET-005, FR-DIET-006, FR-DIET-007, BR-MENU-07, BR-DIET-03, BR-DIET-04, BR-DIET-07 | content-strings.md, menu-dietary.md, screens.md |
| F-I18N-01 Six languages + RTL | Must | FR-I18N-001, FR-I18N-004, FR-I18N-005, FR-I18N-006, FR-I18N-011, FR-I18N-012 | content-strings.md, i18n-seo-analytics.md, notifications.md, screens.md, ui-design-system.md |
| F-I18N-02 Language switcher | Must | FR-I18N-002, FR-I18N-003, BR-I18N-02, FR-I18N-013 | branch.md, content-strings.md, i18n-seo-analytics.md, menu-dietary.md, reservation.md, screens.md, ui-design-system.md, qa.md |
| F-I18N-03 Translation workflow | Must | FR-I18N-007, FR-I18N-008, FR-I18N-009, BR-DIET-05, BR-I18N-01, BR-PRV-04 | access-security-privacy.md, content-strings.md, i18n-seo-analytics.md, menu-dietary.md, notifications.md, reservation.md, screens.md, qa.md |
| F-I18N-04 Bilingual admin | Must | FR-I18N-010 | i18n-seo-analytics.md, notifications.md |
| F-RES-01 Reservation form | Must | FR-RES-001, FR-RES-002, FR-RES-003, FR-RES-004, FR-RES-005, FR-RES-007, FR-RES-027, BR-RES-03, BR-RES-08, BR-RES-10, BR-RES-14, BR-BR-05, BR-PRV-01, FR-RES-029, FR-RES-030 | access-security-privacy.md, branch.md, content-strings.md, i18n-seo-analytics.md, notifications.md, reservation.md, screens.md, qa.md |
| F-RES-02 Submission result | Must | FR-RES-006, BR-RES-01 | content-strings.md, notifications.md, reservation.md, screens.md, qa.md |
| F-RES-03 Status page & self-cancel | Should | FR-RES-019, FR-RES-020, BR-RES-05, BR-RES-07 | content-strings.md, reservation.md, screens.md |
| F-RES-04 Staff inbox & detail | Must | FR-RES-008, FR-RES-009, FR-RES-013, FR-RES-014, BR-RES-02, BR-NOT-02, FR-RES-028 | access-security-privacy.md, notifications.md, reservation.md, screens.md, qa.md |
| F-RES-05 Accept/decline/cancel | Must | FR-RES-010, FR-RES-011, FR-RES-012, FR-RES-025, BR-RES-04, BR-RES-05, BR-RES-06 | reservation.md |
| F-RES-06 Branch reservation settings | Must | FR-RES-023, FR-RES-024, FR-RES-026, BR-RES-03, BR-RES-10, BR-BR-01, BR-RES-15 | branch.md, notifications.md, reservation.md, qa.md |
| F-RES-07 Automatic expiry | Must | FR-RES-016, BR-RES-06 | reservation.md |
| F-RES-08 SLA reminders/escalation | Should | FR-RES-017 | notifications.md, reservation.md |
| F-RES-09 Attendance marking | Should | FR-RES-018, BR-RES-09 | reservation.md |
| F-RES-10 Staff modification | Should | FR-RES-015, BR-RES-11 | reservation.md |
| F-RES-11 Reservation CSV export | Could | FR-RES-021, BR-PRV-03 | access-security-privacy.md, reservation.md |
| F-RES-12 Operations report | Should | FR-RES-022 | i18n-seo-analytics.md, reservation.md, screens.md |
| F-NOT-01 Customer emails | Must | FR-NOT-001, FR-NOT-005, BR-RES-01, BR-NOT-01, BR-NOT-02 | content-strings.md, notifications.md, reservation.md, screens.md, qa.md |
| F-NOT-02 Staff emails | Must | FR-NOT-002, BR-RES-02, BR-NOT-01 | access-security-privacy.md, notifications.md, reservation.md, qa.md |
| F-NOT-03 Notification log/retries | Must | FR-NOT-003, FR-NOT-004, FR-NOT-006, BR-RES-12, BR-PRV-02 | access-security-privacy.md, notifications.md, reservation.md |
| F-ADM-01 Dashboard widgets | Should | FR-ADM-001 | access-security-privacy.md, screens.md |
| F-ADM-02 Users, roles, branch assignment | Must | FR-ADM-002, FR-ADM-003, FR-ADM-006, BR-RES-02, BR-ADM-01, BR-ADM-02, BR-ADM-03, FR-ADM-007, FR-ADM-008 | access-security-privacy.md, reservation.md, screens.md, qa.md |
| F-ADM-03 Audit log | Must | FR-ADM-004 | access-security-privacy.md, screens.md |
| F-ADM-04 Media management | Must | FR-ADM-005, BR-CORP-03 | access-security-privacy.md, branch.md, content-strings.md, menu-dietary.md, screens.md, ui-design-system.md, qa.md |
| F-CNT-01 Contact form | Should | FR-CNT-001, BR-PRV-01, BR-CNT-01 | access-security-privacy.md, content-strings.md, i18n-seo-analytics.md, reservation.md, screens.md, qa.md |
| F-SEO-01 Technical SEO | Must | FR-SEO-001, FR-SEO-002, FR-SEO-003, FR-SEO-004, FR-SEO-005, FR-SEO-007, FR-SEO-009 | branch.md, i18n-seo-analytics.md, qa.md |
| F-SEO-02 Structured data | Must | FR-SEO-006, BR-SEO-01 | branch.md, content-strings.md, i18n-seo-analytics.md, menu-dietary.md |
| F-SEO-03 Legacy redirects | Must | FR-SEO-008 | i18n-seo-analytics.md |
| F-ANL-01 Analytics events | Must | FR-ANL-001, FR-ANL-003 | i18n-seo-analytics.md |
| F-ANL-02 Transmission notice & opt-out | Must | FR-ANL-002 | content-strings.md, i18n-seo-analytics.md, screens.md |
| F-SEC-01 Security hardening & 2FA | Must | FR-SEC-001, FR-SEC-002, FR-SEC-003, FR-SEC-004, FR-SEC-005, FR-SEC-006, FR-SEC-007, FR-SEC-008, FR-SEC-009, FR-SEC-010, FR-SEC-013, BR-ADM-02 | access-security-privacy.md, screens.md, qa.md |
| F-SEC-02 Backup & restore | Must | FR-SEC-011 | access-security-privacy.md, qa.md |
| F-SEC-03 Monitoring & alerting | Should | FR-SEC-012 | access-security-privacy.md |
| F-PRV-01 Privacy notices & consent | Must | FR-PRV-001, FR-PRV-002, FR-PRV-005 | access-security-privacy.md, content-strings.md, i18n-seo-analytics.md, screens.md, qa.md |
| F-PRV-02 Retention job | Must | FR-PRV-003, BR-PRV-02 | access-security-privacy.md, notifications.md |
| F-PRV-03 Data-subject request tools | Should | FR-PRV-004, BR-PRV-03 | access-security-privacy.md, notifications.md, screens.md |

## 3. Spec gaps

Method: every requirement ID was searched in the ten area specs (`branch`, `menu-dietary`, `reservation`,
`notifications`, `access-security-privacy`, `i18n-seo-analytics`, `ui-design-system`, `screens`, `content-strings`,
`qa`); rows with few or test-only references were then read in context to check that a developer gets implementable
detail (data, rules, mechanism), not just a traceability line. Features of Doc 03 §2.7 and the Appendix A items were
cross-checked the same way (§2.1–2.2). Only Must/Should rows are listed. The missing detail is written in
[`docs/specs/gaps.md`](specs/gaps.md).

| ID | Priority | Gap | Why | Covered now in |
|---|---|---|---|---|
| FR-FND-003 | Must | Full | Not referenced by any spec: mu-loader, module set + README, theme-independence, migration runner, job registry/cron, heartbeats (AC-FND-01-04/05/06) | gaps.md §1 |
| FR-FND-006 | Must | Full | Not referenced by any spec: plugin register, ≤ 15 cap, SCR-A15 read-only list, approval rule (AC-FND-01-09) | gaps.md §2 |
| FR-FND-002 | Must | Partial | Staging rules covered (access-security-privacy §9.3); environment matrix, deploy-by-tag steps and the production→staging sanitisation script (AC-FND-01-08) missing | gaps.md §3 |
| NFR-PERF-02 | Must | Partial | Only measured in qa.md; page-cache rules, cache tags, `Kernel\Cache\CacheInvalidator` and purge adapters undefined — and the TRD mechanism (LiteSpeed Cache plugin) is removed by IMPL-004 | gaps.md §4 |
| NFR-MNT-01 | Must | Partial | Only tested in qa.md; README-per-module, version pinning, PHPCS/PHPStan config and the 70 % coverage gate not specified for implementers | gaps.md §5 |

No Should-priority gaps were found. Could rows (FR-CORP-005 News, FR-CORP-012 Gallery, FR-RES-021 CSV export) and the
Could API-17 are covered by screens/content-strings/reservation/access-security-privacy specs.

## 4. Documentation conflicts noted during the review

Conflicts already recorded inside the area specs (each spec ends with its own conflict list) are not repeated. New ones:

| # | Conflict | Resolution used |
|---|---|---|
| 1 | `momo_trading_name` length: TRD G1 / content-strings ≤ 100 vs Doc 06 / ui-design-system ≤ 80 | Store ≤ 100; layout must cope |
| 2 | Logo upload: Doc 04 FR-FND-005 + TRD (no SVG uploads) vs ui-design-system (SVG sanitised) — also screens C-13 | No SVG uploads; theme wordmark SVG ships as code |
| 3 | Page cache via LiteSpeed Cache plugin (TRD §2.9.5, Doc 11 SEC-060) vs IMPL-004 | Adapter design in gaps.md §4 |
| 4 | Test clock: `momos_test_clock` (SDD/TRD) vs `momos_frozen_now` / `MOMOS_FROZEN_NOW` (kernel, qa.md) | Kernel names |
| 5 | Hash pepper constant `MOMOS_HASH_PEPPER` (docs) vs `MOMOS_HMAC_KEY` (production config sample) | Kernel name; alias documented |
| 6 | Plugin inventory of 14 incl. premium (TRD §1.3, Doc 11 SEC-060) vs IMPL-001/004 | Inventory = momos-core + theme (gaps.md §2) |
