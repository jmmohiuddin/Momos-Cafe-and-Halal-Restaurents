# Go-live checklist

The binding gates are Doc 12 §8.1 GATE-01…GATE-10 (DEC-055): **Go** only when every gate has its evidence; a launch
date never overrides No-Go (Doc 12 §8.3). This list maps each gate to the owner input it needs and the command or
screen in this build that proves it. Run commands on production as the deploy user (`wp --path=<WordPress root>`)
unless "staging" is stated. Operations runbooks: [docs/operations/](operations/README.md).

Legend: **Owner** = Momo's must supply or decide · **Vendor** = build team · ☐ = evidence filed.

## GATE-01 Branch facts — Owner / HO

- ☐ **Owner**: signed, dated fact sheet per branch (official ja + romanised name, address incl. floor, canonical phone —
  Q-004 Shinsaibashi —, weekly hours with last order, closed days, concept, facilities) (Q-002, CON-003).
- ☐ Entered by HO: Branches → edit (identity: HO only), Momo's → Hours (weekly hours, special hours, closures), branch
  settings (G6). The publish validator refuses a branch without hours, last order or an active notification recipient.
- ☐ TC-BR-001/004/005/006 run on staging with the signed sheet as fixture (`tests/e2e/specs/qa-customer-branch.spec.ts`
  + manual 100 % comparison of page, directory, footer and JSON-LD in every enabled language); UAT-06.

## GATE-02 Claims (halal, alcohol, allergens, demo content) — CAP-DCA holder + Owner

- ☐ **Owner/counsel**: approved halal statement templates and certificate records (certifier, scope, number, validity,
  evidence file, mark permission) — Momo's → Dietary statements (templates must be approved; until then no halal text
  renders, QA-006), Momo's → Halal register (CAP-DCA user, two-person rule, evidence upload).
- ☐ Allergen data per dish from kitchen recipe cards (Q-010), approved by a CAP-DCA holder; dishes with `not_verified`
  show the banner. Momo's → Claim-term scan = 0 hits.
- ☐ **Sample content purge** (DEC-047, BR-CORP-03): `wp momos purge-samples --dry-run` (review) →
  `wp momos purge-samples --yes --user=<Super Administrator login>` → `wp momos content sample-check` (must exit 0:
  no published sample item, no demo image outside dish/offer images) → `wp momos smoke` (fails on published samples in
  production). The demo seed is never run in production.
- ☐ TC-MENU-012 crawl for sample names/prices on rendered pages, JSON-LD and sitemaps (the CLI checks the database only).

## GATE-03 Routing & isolation — QA lead + Security lead

- ☐ CI on the RC tag green: PHPUnit (`QaIsolationMatrixTest`, `QaStaffCapabilityMatrixTest`, `AccessMetaCapTest` …) and
  Playwright `qa-isolation-channels`, `qa-staff-access-matrix`, `reservation-lifecycle` reports filed.
- ☐ Branch notification recipients verified per branch (branch settings → notification recipients; one test request
  per branch on staging lands only at that branch's inbox).

## GATE-04 Languages — Owner + native reviewers

- ☐ ja + en must pass; ar/ur/hi/bn only after native review, otherwise they stay hidden (DEC-002, IMPL-003).
- ☐ **en strings approval (IMPL-007)**: a reviewer approves every customer-facing string in Momo's → Interface strings;
  `wp momos i18n strings-missing --lang=en` → "No customer-facing keys missing" and `wp momos i18n gaps --lang=en --lccs`
  lists no LCCS content gap and no "Interface strings …: N keys not approved" line (all shipped strings start as draft).
- ☐ **Native review per language** (Owner names the reviewer, Q-027): content translations approved (Momo's →
  Translation gaps = 0 for LCCS) and strings approved; enable in Settings → Languages (LanguageGate refuses while LCCS
  gaps exist and requires reviewer name + date; audited `language.enabled`).
- ☐ TC-CMP-001 on a real iPhone and Android per enabled language (form → submitted → accept/decline → E01/E02/E03);
  RTL cases for ar/ur.

## GATE-05 Defects — QA lead; Owner for acceptances

- ☐ 0 open Critical; 0 open High in reservation, privacy, security, claims, branch isolation; written Owner risk
  acceptance + fix date for every other open High (`docs/DEFECT_REGISTER.md`, QA triage).

## GATE-06 Legal & privacy — Counsel + Owner

- ☐ **Counsel-approved legal pages** published by HO in every enabled language (Privacy Policy, Reservation Policy,
  External Transmission, and the other legal pages of FR-CORP-006) with last-updated dates; Content Editors can only draft
  (`momo_publish_legal_pages`).
- ☐ **Consent version activation (IMPL-006)**: register the approved version
  (`wp momos privacy consent add --privacy=<x.y> --policy=<x.y> --effective=<YYYY-MM-DD>`), then
  `wp momos privacy consent activate PP<x.y>-RP<x.y>`; `wp momos privacy consent current` shows it. Until then new
  reservations store `unversioned`.
- ☐ Retention values approved (Settings → Privacy and retention); `wp momos privacy retention --dry-run` reviewed;
  TC-PRV-004 time-travel test passed (PHPUnit `PrivacyRetentionTest`).

## GATE-07 Security & backup — Security lead + CTO

- ☐ **Hosting / WAF / CDN** per [operations/deployment.md](operations/deployment.md) §1–§3: PHP 8.3, DB user least
  privilege, `server/*.conf` installed (`curl -sI http://<host>/` → one 301; `/readme.html` → 403), TLS A, WAF blocks
  `xmlrpc.php`, real cron running (`wp momos jobs list` all `ok`).
- ☐ Secrets in the host store (names in deployment.md §2), incl. `MOMOS_MAIL_FROM`; `wp momos smoke` passes in production.
- ☐ **Turnstile** production site key + secret set; a reservation on the TEST branch passes the widget.
- ☐ 2FA enforced for all staff roles (Settings → Security); ≤ 2 Super Administrators; vendor account disabled
  (time-boxed windows only, DEC-035).
- ☐ Scans: weekly workflow green (`composer audit`), ZAP baseline + WPScan with 0 High/Critical (Owner's written
  authorisation for staging scans).
- ☐ **Backups**: cron running ≥ 7 days, off-site copies present (TC-BCK-001); **restore drill** within RTO 8 h / RPO 4 h
  recorded (TC-BCK-002, [operations/backup-restore.md](operations/backup-restore.md) §5); retention ran after restore
  (TC-BCK-005).
- ☐ Headers: HSTS ramp start 300 (Settings → Security), CSP report-only, `no-referrer` + `no-store` on
  `/reservation/submitted/` and status pages (`curl -sI`).

## GATE-08 Performance & accessibility — Tech lead + QA lead

- ☐ Lighthouse/WebPageTest from Japan per template × enabled language; budgets with CHG-006/CHG-008 (if ratified);
  axe 0 critical/serious (`qa-a11y-i18n-axe`); manual AA audit of the reservation journey.

## GATE-09 SEO & analytics — SEO lead

- ☐ **Legacy redirect map** (DEC-039): crawl of the current site → `config/seo/legacy-redirects.csv`
  (`old_url,new_url,status,owner,verified`; not in the repository yet) → `wp momos seo redirects import
  config/seo/legacy-redirects.csv --verify` (non-zero exit on any refused row) → `wp momos seo redirects verify`; every
  legacy URL answers one 301 to a 200 page. Momo's → Redirections shows the map and the 404 log.
- ☐ **GTM id** (`MOMOS_GTM_ID` or Settings → Analytics `GTM-…`), container published by a named publisher; GA4
  DebugView validated (TC-ANL-001…004); no GTM/GA4 on submitted/status pages; Search Console verified; sitemaps submitted.
- ☐ Production indexable (`blog_public` = 1, no `X-Robots-Tag` on public pages); staging still behind basic auth + noindex.

## GATE-10 Operations — HO + QA lead

- ☐ **HO notification list**: Settings → Notifications → "Head Office notification list" (`momo_ho_escalation_recipients`)
  and the daily summary list; branch recipients confirmed in writing (Q-020).
- ☐ **From address, SPF, DKIM, DMARC**: `MOMOS_MAIL_FROM` on the brand domain; DNS records published by the domain owner;
  a test message to Gmail / Yahoo Japan / a carrier address shows SPF, DKIM and DMARC = pass (TC-NOT-008).
- ☐ **Alert recipients** (Settings → Monitoring) and the **uptime monitor** + heartbeat checks
  ([operations/monitoring.md](operations/monitoring.md) §2, §5) — test alert received (TC-SEC-016).
- ☐ Staff accounts per roster with 2FA; ja/en training done (docs/ADMIN_GUIDE_ja_en.md); reservation settings per branch
  equal the approved sheet (screenshot of each branch's settings — there is no `wp momos settings export` in this build).
- ☐ Incident contact sheet filled and tabletop exercise done ([operations/incident-response.md](operations/incident-response.md)).
- ☐ TEST branch hidden, QA inbox its only recipient; cutover smoke (Doc 12 §8.5) passed and logged.

## Release-day sequence (after Go)

1. Backup ([operations/backup-restore.md](operations/backup-restore.md)) → deploy the release tag
   ([operations/deployment.md](operations/deployment.md) §5).
2. `wp momos purge-samples --yes --user=<SA>` (if not done) → `wp momos content sample-check` → `wp momos smoke`.
3. Languages: ja + en (+ reviewed languages) in Settings → Languages; consent version active; caches purged.
4. DNS cut-over (TTL lowered beforehand); legacy redirects verified; TEST-branch smoke ≤ 15 min.
5. Hypercare 30 days (Doc 12 §8.6).

## Open owner decisions (must be closed or explicitly accepted before Go)

| Item | Decision needed | Default if not decided |
|---|---|---|
| CHG-006 | Tech lead ratifies the `momos-ui.css` budget 50 KB min / 10 KB gzip (CTO interim approval, QA-056) | QA-056 stays open against the 30 KB budget |
| CHG-008 | UX lead ratifies the Japanese web-font budget ≤ 650 KB per page, or chooses system fonts | QA-054 stays open |
| QA-070 | Owner supplies the home hero photo (front-page featured image); demo seed shows a placeholder disc | placeholder graphic at launch |
| QA-079 (DEF-008) | HO decides the CSV export phone format: keep the formula-guard apostrophe before `+81…` or export phones as a text column | apostrophe prefix stays (literal spec) |
| CHG-009 | Owner/security lead confirms login Turnstile after 3 failed attempts per IP (beyond Doc 11 SEC-A04/SEC-025 minimums) | behaviour stays as built |
| CHG-001 / IMPL-001 | Owner/CTO ratify "no paid plugins" | as built |
| CHG-003, CHG-010, CHG-011 | security lead / CTO sign-off of the recorded changes | as built |
