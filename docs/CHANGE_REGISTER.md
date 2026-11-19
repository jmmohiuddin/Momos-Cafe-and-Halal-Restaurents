# Change register

Changes to documented behaviour (scope, interface or rule) made during the build. Each needs the listed approval before release.

| ID | Change | Reason | Docs affected | Approval |
|---|---|---|---|---|
| CHG-001 | No paid plugins; equivalent features in momos-core (IMPL-001) | Purchases outside authorization | TRD §1.2–1.3 | Owner/CTO |
| CHG-002 | "Reservations" is its own top-level admin menu (`momos-reservations`), not a submenu of Momo's | Reservation spec §7 vs ENGINEERING.md; staff reach it in one click | Doc 09 §2.1 | CTO (accepted) |
| CHG-003 | Idempotency replay with a different email/branch returns 400 instead of replaying | Prevents a leaked key disclosing another submitter's status token | API-02 | Security lead |
| CHG-004 | Staff deep link `page=momo-reservations&reservation=N` redirects to `page=momos-reservations&id=N` | Single canonical admin URL | Notification spec | CTO (accepted) |
| CHG-005 | Contact form posts to admin-post (`momos_contact`) instead of the proposed REST route | Works without JS; REST route was only [Proposed] | screens.md §7 | CTO (accepted) |
| CHG-006 | `momos-ui.css` budget raised from 30 KB to **50 KB minified / 10 KB gzip** (measured 46.8 KB / 7.9 KB) | Semantic-token rule + larger component set (forms, allergen table, claim block, statuses); the file is cached site-wide, gzip size is what users download; splitting per template adds requests and complexity for <8 KB | Doc 09 §1.9 U1 | CTO approved (QA-056); Tech lead to ratify |
| CHG-007 | Offer expiry runs every 15 min (idempotent) rather than once at 00:05 JST | Superseded: kernel now has `Schedule::daily_at`; revisit | Branch spec | CTO |
| CHG-008 | Japanese web-font budget: **≤ 650 KB per page** (measured ≈ 600 KB in up to 51 unicode-range slices for Noto Sans JP 400 + 700) instead of the provisional 300 KB; other languages keep 300 KB. Total page weight stays within NFR-PERF-03 (1.5 MB). | The 300 KB figure was marked [TBD → measure in PROTO] (ui-design-system §4.1); Japanese needs kanji coverage; slices load only for glyphs on the page and are cached. Alternatives (400-only with synthetic bold, system fonts) degrade the approved typography. | Doc 08 §2.3.4 | CTO interim approval (QA-054); UX lead to ratify or choose system fonts |
| CHG-009 | Login CAPTCHA (Turnstile) is shown on wp-login only after 3 failed attempts per IP, per SDD §6 / access spec §5.3. Doc 11 SEC-A04 ("no login CAPTCHA required") and SEC-025 ("widget only on C09/C12") read as minimums, not prohibitions. | Defence in depth against credential stuffing with no friction for normal logins | Doc 11 SEC-A04/SEC-025 | Owner/security lead to confirm |
| CHG-010 | Vendor (ROLE-01) accounts are denied six capabilities per user: the four PII caps plus `momo_process_reservations` and `momo_view_notification_log`; break-glass lifts only PII + view. | QA-001 (S1): processing and resend paths returned guest data | access-security-privacy §2.2/§3/§5.6 | CTO (security fix) |
| CHG-011 | Notification rows for reservation transitions are written **inside** the reservation transaction (Reservation `NotificationTrigger`, dedupe keys of notifications.md §4.2); the Notification subscriber only dedupes. | TC-RES-001: a reservation must never commit without its E01/E07 rows (or a not-notified flag) | docs/CONTRACTS.md (hooks fire after commit) | CTO |
