# Screens — build sheets for SCR-C01…C22 (customer) and SCR-A01…A20 (admin)

Implementation-ready, screen-by-screen build sheets for the classic theme `momos` and the admin screens of `momos-core`
(PHP 8.3, namespace `Momos\`). One sheet per screen: purpose, URL, template file, section order (top → bottom) with
the component used and the source field, interactions, states, responsive, RTL, analytics, SEO, acceptance criteria
and tests. Developers should not need the documentation package.

Sources: Doc 08 §4 (screen specs) + §5–6; Doc 07 §5 (screen UX) + §6 (strings); Doc 06 §1.2–1.10, §2.1–2.5, §3;
Doc 04 FR-CORP/BR/MENU/DIET/RES/ADM ACs; Doc 04A user stories; Doc 09 §2.1, §2.8–2.9; Doc 12 test cases; Doc 13
TK-03/04/05/06/09; Doc 17 DEC-029/036/041/042/044/045/047/057/058/060/062/066.

**Ownership.** This file owns *what each screen contains and in which order*. Domain rules and data contracts stay in
the module specs and are only referenced, never redefined:
`branch.md` (branches, hours, notices, offers) · `menu-dietary.md` (menu, dish, halal, allergens) · `reservation.md`
(C09–C11, A08/A09/A17, API-01…16) · `notifications.md` (E01–E11) · `access-security-privacy.md` (A01, A10, A16, A19,
consent) · `i18n-seo-analytics.md` (URLs, hreflang, SEO head, analytics events, A11) · `ui-design-system.md` (tokens,
components, page frame, 404, maintenance, admin frame).

Labels: **M/S/C** = Must/Should/Could · **[Doc]** documented · ⟦TBD → default⟧ open decision, build the default as
configuration · **[Proposed]** added here where the docs are silent · `{PLACEHOLDER}` business fact never hard-coded.

---

## 0. How the documented mechanisms map to this build (IMPL-001/002/005)

| Documented | This build |
|---|---|
| Elementor pages / Theme Builder T01–T14 / Loop Grids | Classic theme templates (table §0.1) + card partials `template-parts/cards/*.php`; loop queries = momos-core query services (`BranchReader`, `OfferQuery`, `MenuQueryService`, …) |
| momos-core Elementor widgets / dynamic tags (`momos-*`) | `Momos\Presentation\Components::render('{slug without momos-}', $args)` → partial `momos-core/templates/widgets/{slug}.php` (same slugs, ui-design-system.md §0) |
| ACF fields / options pages G1–G18 | Code-registered native meta, same keys (`momo_*`); options in `wp_options` |
| WPML translation / "only show translated" | Per-entity `_momos_i18n_{lang}` + `_momos_i18n_status_{lang}`; entity visible in L≠ja only when status `approved` (IMPL-002) |
| Elementor Pro Form (C12) | momos-core `Contact\ContactController` (REST, §7) — no storage (DEC-038) |
| Elementor Pro Gallery (C22) | Theme template + `Components::render('gallery')` over approved attachments (§3.22) |
| Rank Math breadcrumbs/SEO | `Seo\*` (i18n-seo-analytics.md) |
| CE "Elementor content only" mode (A14) | Block editor with a CE block allow-list and template lock (`allowed_block_types_all`) [Proposed] |

### 0.1 Page routing and template files [Proposed]

System pages are normal WP pages (one per entity, translated via i18n meta, same Latin slug in every language). Their
role is stored in option `momo_system_pages` = `{home, about, halal, branches, menu, reservation, submitted, status,
contact, faq, offers, gallery, news}` → page ID (written by the seed command; editable by SA only). Legal pages come from
G1 `momo_privacy_page`, `momo_terms_page`, `momo_reservation_policy_page`, `momo_external_tx_page`,
`momo_accessibility_page`. `Momos\Presentation\Templates\TemplateRouter` (`template_include`) picks the file:

| SCR | Template file (theme `momos`) | Route / object | `page_type` |
|---|---|---|---|
| C01 | `front-page.php` | page `home` (front page) | `home` |
| C02 | `page-templates/about.php` | page `about` | `about` |
| C03 | `page-templates/halal.php` | page `halal` | `halal` |
| C04 | `page-templates/branches.php` | page `branches` | `branch_directory` |
| C05 | `single-momo_branch.php` | CPT `momo_branch` (`/branches/{slug}/`) | `branch` |
| C06 | `page-templates/menu-hub.php` | page `menu` | `menu_hub` |
| C07 | `taxonomy-momo_menu_category.php` | term (`/menu/{cat}/`) | `menu_category` |
| C08 | `single-momo_dish.php` | CPT `momo_dish` (`/menu/{primary-cat}/{dish}/`) | `dish` |
| C09 | `page-templates/reservation.php` | page `reservation` | `reservation` |
| C10 | `page-templates/reservation-submitted.php` | child page `reservation/submitted` | — (no analytics) |
| C11 | `page-templates/reservation-status.php` | rewrite `reservation/status/{REF}/` (momos-core) | — (no analytics) |
| C12 | `page-templates/contact.php` | page `contact` | `contact` |
| C13 | `page-templates/faq.php` | page `faq` | `faq` |
| C14 | `home.php` (list) + `single.php` (item) | posts page `news`; permalink `/news/%postname%/` | `news` |
| C15–C18 | `page-templates/legal.php` | pages with `momo_page_type = legal` | `legal` |
| C19 | `404.php` | any unknown URL | `error_404` |
| C20 | `wp-content/maintenance.php` | maintenance mode | none |
| C21 | `page-templates/offers.php` | page `offers` | `offers` |
| C22 | `page-templates/gallery.php` | page `gallery` | `gallery` |

Every template: `get_header()` → content → `get_footer()`; one H1; `<main id="main" tabindex="-1">`. Facts (prices,
hours, phones, addresses, claims, allergens) only via momos-core components — the CI template scan (ui-design-system.md
§2) fails the build on literals.

---

## 1. Requirements covered

### 1.1 Global requirements implemented by every screen sheet

| ID | Statement | Pri |
|---|---|---|
| FR-CORP-008 / AC-CORP-08-01…06 | Header (logo, Menu · Branches ▾ · [Offers] · Halal · About · Reservation, switcher, "Request a table"); mobile menu dialog; sticky action bar by page type | M |
| FR-CORP-009 / AC-CORP-08-05 | Footer: branch list with `tel:`, Explore (Offers always), 5 legal links + Analytics settings, social, © operator | M |
| DEC-060 / TC-NAV-006 | "Offers" header item only while ≥ 1 active offer is translated in the page language; footer link always | M |
| FR-I18N-001/002/005/006, BR-I18N-02, NFR-I18N-01 | `<html lang dir>`; switcher; bidi isolation; formats; untranslated items hidden (no fallback) | M |
| NFR-ACC-01 / DEC-036 | WCAG 2.2 AA on every customer template; 0 critical/serious axe; targets ≥ 24 px (≥ 44 px primary/bar) | M |
| NFR-PERF-01/03 | LCP ≤ 2.5 s, CLS ≤ 0.1, TBT ≤ 200 ms; ≤ 1.5 MB initial; hero ≤ 200 KB; card images ≤ 80 KB | M |
| NFR-CMP-01 | Widths 360/390/768/1024/1366/1920; latest 2 majors + LINE/IG/FB in-app | M |
| FR-BR-003 | Empty/unknown fields and their labels never rendered (no "please inquire", "N/A", "-") | M |
| DEC-042 / TC-REG-007 | No ordering UI (cart, Add, Order now, checkout, table QR) anywhere | M |
| DEC-045 | No site search | M |
| DEC-047 / BR-CORP-03 | "Image for illustration" caption below flagged dish/offer images only; never on branch, gallery, halal, About | M |
| BR-RES-01 | No booking/confirmed wording before acceptance on C09/C10/C11-pending (Doc 07 §6.6 scan) | M |
| FR-ADM-003 | Every admin screen re-checks capability + branch scope; denial → 403 page + audit `access.denied` | M |

### 1.2 Screen index

| SCR | Name | Pri | URL / admin location | Stories | Key FR / AC | Tests (automate) |
|---|---|---|---|---|---|---|
| C01 | Home | M | `{P}/` | US-CORP-001/002, US-SEO-003 | FR-CORP-001, AC-CORP-01-01…07 | TC-HOME-001…005, TC-CORP-011, TC-NAV-006 |
| C02 | About | M | `{P}/about/` | US-CORP-008 | FR-CORP-002, AC-CORP-02-01/02 | TC-CORP-001 |
| C03 | Halal information | M | `{P}/halal/` | US-CORP-006, US-DIET-003 | FR-CORP-003, AC-CORP-03-01…04 | TC-CORP-002, TC-DIET-003/004/008/020, TC-MENU-013 |
| C04 | Branch directory | M | `{P}/branches/` | US-BR-001/002, US-SEO-001 | FR-BR-001 | TC-BR-001…003 |
| C05 | Branch detail | M | `{P}/branches/{slug}/` | US-BR-002/003/008, US-CORP-002/009, US-RES-002 | FR-BR-002/003/010, AC-CORP-08-01/04, AC-CORP-09-05 | TC-BR-004/005/014/016, TC-DISH-006 |
| C06 | Menu hub | M | `{P}/menu/` | US-MENU-001/002, US-MENU-010(S) | FR-MENU-001 | TC-MENU-001/003 |
| C07 | Menu category | M | `{P}/menu/{cat}/` | US-MENU-001/002/010 | FR-MENU-002, FR-MENU-008(S) | TC-MENU-002/003/007 |
| C08 | Dish detail | M | `{P}/menu/{cat}/{dish}/` | US-MENU-002/003, US-DIET-005, US-RES-002 | FR-MENU-003/009, FR-DIET-006 | TC-DISH-001/005/006, TC-MENU-008/011, TC-DIET-011/012/027 |
| C09 | Reservation form | M | `{P}/reservation/` | US-RES-001…008, 026/027, US-PRV-001, US-I18N-008 | F-RES-01, FR-RES-001…006/027 | TC-RES-001…027/035, TC-ANL-002/003, TC-A11Y-002/006, TC-RTL-003, TC-I18N-018 |
| C10 | Request submitted | M | `{P}/reservation/submitted/` | US-RES-006/009 | FR-RES-006, DEC-066 | TC-RES-020/021, TC-PRV-016 |
| C11 | Status & cancel | S | `{P}/reservation/status/{REF}/#t=` | US-RES-010/011 | FR-RES-019/020, DEC-058 | TC-RES-028/029/030/040/042 |
| C12 | Contact | S | `{P}/contact/` | US-CNT-001 | FR-CNT-001, BR-CNT-01 | TC-CORP-009, TC-PRV-009/015 |
| C13 | FAQ | S | `{P}/faq/` | US-CORP-010 | FR-CORP-004, AC-CORP-04-01/02 | TC-CORP-003 |
| C14 | News list / item | C | `{P}/news/`, `{P}/news/{slug}/` | US-CORP-012 | FR-CORP-005, AC-CORP-05-01 | TC-CORP-004 |
| C15 | Privacy policy | M | `{P}/privacy-policy/` | US-CORP-007, US-PRV-001 | FR-CORP-006, FR-PRV-001 | TC-CORP-005/016, TC-PRV-003 |
| C16 | Terms · Reservation policy | M | `{P}/terms/`, `{P}/reservation-policy/` | US-CORP-007 | FR-CORP-006, AC-CORP-06-03 | TC-CORP-005, TC-RES-031 |
| C17 | External transmission & cookies | M | `{P}/external-transmission/` | US-CORP-007, US-ANL-002 | FR-ANL-002 | TC-ANL-006/007/010 |
| C18 | Accessibility statement | M | `{P}/accessibility/` | US-CORP-007 | DEC-036 | TC-A11Y-010 |
| C19 | 404 | M | any unknown URL | US-SEO-004 | FR-CORP-007, AC-CORP-07-01 | TC-CORP-006 |
| C20 | Maintenance (503) | M | all URLs in maintenance | — | FR-CORP-007, AC-CORP-07-02 | TC-CORP-007 |
| C21 | Offers | M | `{P}/offers/` | US-CORP-002 | FR-CORP-011, AC-CORP-09-03…09 | TC-CORP-011…014 |
| C22 | Gallery | C | `{P}/gallery/` | US-CORP-011 | FR-CORP-012 | TC-CORP-015, TC-MENU-013 |
| A01 | Login + 2FA | M | `wp-login.php` | US-ADM-003 | FR-ADM-006, FR-SEC-001/002 | TC-ADM-004, TC-SEC-001…003 |
| A02 | Dashboard | S | `wp-admin/index.php` | US-ADM-005, US-CORP-004 | FR-ADM-001 | TC-ADM-001, TC-ACL-024 |
| A03 | Branch list / edit | M | Branches (CPT) | US-BR-004/005/009, US-DIET-002, US-RES-024 | FR-BR-004/005 | TC-BR-006…008, TC-RES-038 |
| A04 | Hours & closures | M | Momo's › Hours & closures | US-BR-006/007 | FR-BR-006…008/010 | TC-BR-009…011/013 |
| A05 | Menu categories | M | Dishes › Categories | US-MENU-004 | FR-MENU-005 | TC-MENU-004 |
| A06 | Dish list / edit | M | Dishes (CPT) | US-MENU-004…006/009, US-DIET-004/006 | FR-MENU-004, FR-DIET-005 | TC-DISH-002/003, TC-DIET-009/023 |
| A07 | Branch menu grid | M | Momo's › Branch menu | US-MENU-006…008 | FR-MENU-006/007 | TC-MENU-005/006/009 |
| A08 | Reservation inbox | M | Momo's › Reservations | US-RES-012/019/023/026/028/030, US-NOT-005 | FR-RES-008/021/028 | TC-RSA-001/002/019/024, TC-ACL-003…005, TC-PERF-005 |
| A09 | Reservation detail | M | Reservations › {id} | US-RES-013…021, US-I18N-007 | FR-RES-009…015 | TC-RSA-003…017/025, TC-E2E-006 |
| A10 | Users & branches | M | Users | US-ADM-001/002/007/008 | FR-ADM-002/007/008 | TC-ACL-013, TC-ADM-008/009 |
| A11 | Translations + gaps | M | Momo's › Translation gaps | US-I18N-004/005 | FR-I18N-007/008 | TC-I18N-008 |
| A12 | Halal register | M | Momo's › Claim register (+ CPT edit) | US-DIET-001/007 | FR-DIET-001/002 | TC-DIET-002/017/024, TC-NOT-017 |
| A13 | Notices | M | Notices (CPT) | US-BR-008 | FR-BR-009 | TC-BR-012 |
| A14 | Pages & content | M | Pages | US-CORP-008, US-SEO-002 | FR-CORP-002/006 | TC-ACL-014, AC-CORP-06-02 |
| A15 | Settings | M | Momo's › Settings | US-FND-001/002, US-I18N-006, US-PRV-002 | FR-FND-005, FR-I18N-009, DEC-049 | TC-CORP-008, TC-I18N-019, TC-ADM-010 |
| A16 | Audit log | M | Momo's › Audit log | US-ADM-004 | FR-ADM-004 | TC-ADM-002, TC-ACL-018 |
| A17 | Reservation report | S | Momo's › Reports | US-RES-022 | FR-RES-022 | TC-RSA-018 |
| A18 | Media library | M | Media | US-ADM-006, US-MENU-009 | FR-ADM-005 | TC-ADM-003, TC-ACL-023 |
| A19 | Data-subject requests | S | Momo's › Data requests | US-PRV-003 | FR-PRV-004 | TC-PRV-006, TC-UAT-013 |
| A20 | Offers list / edit | M | Offers (CPT) | US-CORP-003/004/005 | FR-CORP-010 | TC-CORP-010, TC-ACL-021 |

`{P}` = '' (ja) or `/en` `/ar` `/ur` `/hi` `/bn` (enabled languages only). Email screens E01–E11: `notifications.md`.

---

## 2. Shared frame and conventions (detail: ui-design-system.md §8)

| Aspect | Rule for every customer sheet below |
|---|---|
| Region order | skip link → language suggestion bar (JS) → site-wide notice → sticky header → `main` (inner pages: page banner = breadcrumb · eyebrow? · H1 · lead? on the peach-glow canvas) → footer → mobile action bar (≤ 767 px) |
| Mobile action bar | Branch detail: Request · Call · Directions (Call · Directions when not accepting / temporarily closed; Call omitted without phone). C09/C10/C11: none. All other pages incl. legal and 404: Request a table · Branches |
| Breadcrumbs | All inner pages except C09/C10/C11 and Home; trail = BreadcrumbList JSON-LD |
| Loading | Server-rendered; images with width/height; skeletons only for open-now, slots (C09), status card (C11), admin lists; no full-page spinner; no shimmer under reduced motion |
| Empty | Section with no data is not rendered (no heading); page-level empties listed per sheet |
| Error | Failing component renders nothing; static content remains; unknown URL → C19; untranslated singular → 404; planned work → C20 |
| Responsive | ≤ 767: 1 column (dish cards = list variant); 768–1023: 2 columns, collapsed header; ≥ 1024: full nav, 3 columns (4 for category tiles / featured dishes ≥ 1366); container 1200 px; reading width 720 px |
| RTL (ar, ur) | Logical properties only; DOM order = reading order; side panels move to inline-start (left); mirror only directional icons; logo, phone/clock/map icons, numbers not mirrored |
| Bidi | Prices, times, phones, dates, references, Latin names in `<bdi dir="ltr">`; Japanese address `lang="ja"`; user text `dir="auto"` |
| Formats | Doc 07 §6.10 (ICU `-u-ca-gregory-nu-latn`, 24 h, Western digits); "JST" label only on reservation surfaces in non-ja |
| Analytics | Inline `momo_page {page_type, language, …}` + `view_*` per sheet; delegated clicks via `data-momo-event` / `data-momo-cta` / `data-momo-branch`; never on C10, C11, TEST branch, admin (i18n-seo-analytics.md §12) |
| SEO | Title/description/OG per language; canonical self, clean path; query variants `noindex,follow`; hreflang only for visible languages; JSON-LD per sheet |
| Cache | Full-page cache per URL; purge tags `momo-global`, `momo-home`, `momo-branch-{id}`, `momo-dish-{id}`, `momo-cat-{term}`, `momo-halal`, `momo-offers` (TRD §2.9.5). C11 and all `/wp-json/momos/v1/*` except API-01 never cached |

---

## 3. Customer screens

Sheet layout: header line · section table (order is binding; Doc 07 §5 numbering) · bullets.

### 3.1 SCR-C01 Home — M · `{P}/` · `front-page.php` · TK-04-04

| # | Section | Component | Data (source) | Empty rule |
|---|---|---|---|---|
| 1 | Site-wide notice | CMP-NoticeBanner | `NoticeQuery::active('site_wide', L)` (`momo_notice_*`) | hidden |
| 2 | Hero | CMP-Hero | H1 = `momo_tagline` (G1, T) ⟦TBD hero copy — Owner⟧; image = front page featured image (decorative unless it names a dish); `cta.request_table` → `{P}/reservation/`; `cta.find_branch` → `{P}/branches/`; link `offers.see_current` → `{P}/offers/`; category link chips (`momo_menu_category` with ≥ 1 visible dish, `momo_cat_order`) | `see_current` link only while ≥ 1 featured active offer; no tagline → trading name |
| 3 | Offers (directly after hero, DEC-060) | CMP-SectionHeader + CMP-OfferCard ×≤ ⟦3 — Q-414⟧ | `OfferQuery::homeFeatured(L)`: published, active (`start_at ≤ now < end_at`), `momo_offer_homepage_featured=1`, translated, not sample (prod); order `momo_offer_order`, `start_at`; heading `offers.title`, link `offers.see_all` | whole section absent |
| 4 | Branch teaser | CMP-FeatureBand (emerald) + CMP-BranchCard (on-dark) | `BranchReader::directory(L)` (published, non-test, ≠ closed_permanently, translated; `menu_order`); link `cta.see_branches` | hidden when 0 branches |
| 5 | Featured dishes | CMP-DishCard grid ≤ ⟦6 — AC-CORP-01-03⟧ | `momo_featured=1`, published, not retired, not sample (prod), translated; reference price (no branch context); heading `home.featured.title` | hidden |
| 6 | Halal summary | CMP-HalalStatement (brand) | `ClaimResolver::brand(L)` from `momo_brand_halal_statement_key` (approved G2 template) else `brand.neutral`; link `cta.halal_info` | never empty (neutral text) |
| 7 | How reservations work | CMP-StepList (`<ol>`, 3 cards) | strings `home.how.*` (§8); response promise only if `momo_res_show_response_promise` approved (AC-CORP-01-05) | always shown |

- **Interactions:** no carousel/autoplay; cards hover-lift (no-preference only); branch-card actions Request (`?branch={code}`), Directions, Details.
- **States:** open-now skeleton per card; failing open-now hides itself, today's hours line remains.
- **Responsive:** 360 — hero image (≤ 240 px tall) on top, copy on a solid panel below it (never text over the photo), H1 + "Request a table" visible at 360×640 in ja/ar/ur; CTAs full-width stacked; offers/branches 1 col; dishes list cards. 768 — 2 cols. ≥ 1024 — hero split (copy at inline-start), offers 3, branches 3, dishes 3 (4 ≥ 1366).
- **RTL:** hero split mirrors (copy on the right); step numbers Western digits.
- **Analytics:** `momo_page{page_type:home}`; `click_reservation` (`hero`, `home_section`, `offer_card`, `branch_card`, `header`, `sticky_bar`); `click_phone`/`click_directions` (`branch_card`).
- **SEO:** title `seo.title.home`; JSON-LD Organization + WebSite; LCP = hero image `fetchpriority="high"`, others lazy; cache tags `momo-home`, `momo-global`.
- **AC:** AC-CORP-01-01…07; no ordering element, no "100 %"/seal in any language; 0 offers → no Offers section and no hero link; 4 featured offers → 3 cards + `offers.see_all`; teaser = every eligible branch (TEST hidden) in HO order; UI-001 side-by-side at 390/1366.

### 3.2 SCR-C02 About — M · `{P}/about/` · `about.php` · TK-04-05

| # | Section | Component | Data | Empty rule |
|---|---|---|---|---|
| 1 | Page banner | Breadcrumbs (Home › About) + H1 + lead | page title / excerpt (T) | lead hidden |
| 2 | Story / cuisine / team | editorial split (copy + 2×2 photo grid), pillars row (3 CMP-Card), optional `<blockquote>` (accent `border-inline-start`) | page `post_content` (T; CE-editable blocks) | page not published until content exists |
| 3 | Closing band | CMP-FeatureBand + `cta.request_table`; links `cta.view_menu`, `cta.find_branch`, `cta.halal_info` | strings | — |

- Real photos only: `IllustrativeImageGuard` refuses saving the page with any `momo_image_illustrative=1` image; people photos only if flagged real with consent ⟦process — Owner⟧. No "100 %", "certified", seal unless rendered by CMP-HalalStatement from an approved record.
- Not in LCCS: hidden in L until approved → 404 in L (switcher → L home + `?notice=untranslated`).
- Responsive: 360 single column, photos 2×2 below text; ≥ 1024 split 1:1, pillars 3 columns. Text ≤ 720 px.
- Analytics `momo_page{about}`; SEO BreadcrumbList. AC: AC-CORP-02-01/02; quote/pillars mirror in ar/ur.

### 3.3 SCR-C03 Halal information — M · `{P}/halal/` · `halal.php` · TK-04-06

| # | Section | Component | Data | Empty rule |
|---|---|---|---|---|
| 1 | Banner | Breadcrumbs (Home › Halal information) + H1 `cta.halal_info` | — | — |
| 2 | Brand statement | CMP-HalalStatement (brand) | as C01 §6 | `brand.neutral` |
| 3 | What the statements mean | 3 definition cards: `halal.label.certified`, `halal.label.ingredients`, "No statement" | approved strings | — |
| 4 | Per-branch register | CMP-HalalRegister (table ≥ 768 if ≤ 5 cols fit, else cards; 1 card per branch < 768) | per published branch: name link, statement template (`certified.*` / `halal_ingredients` / `no_claim`), certifier (`<bdi>`), scope, cert no., valid until, alcohol lines (`alcohol.*`), `last_verified`; mark image only for active `certified` with mark permission (alt "Halal certification mark of {certifier}") — data contract menu-dietary.md §5.3 | branch without active record → `no_claim` row; unknown alcohol hidden |
| 5 | Ask our staff | box with branch links (C05) + `cta.request_table` | — | — |
| 6 | FAQ excerpt | CMP-Accordion, 3 items from FAQ group "Halal & dietary" + link C13 | C13 content | hidden if FAQ unpublished in L |

- Register query failure → brand neutral text + "Ask our staff" (never stale claims).
- Table: `<caption>`, `scope`; dates written out; certifier names `<bdi>`.
- Hidden in L if any used template lacks an approved translation (AC-CORP-03-03).
- Analytics `view_halal_information{source_page:halal}`; cache tag `momo-halal`.
- AC: AC-CORP-03-01…04; expired record → neutral template next day (CertificateExpiryJob 00:10 JST); no seal image except active certified + permission; TC-DIET-020.

### 3.4 SCR-C04 Branch directory — M · `{P}/branches/` · `branches.php` · TK-05-08

| # | Section | Component | Data | Empty rule |
|---|---|---|---|---|
| 1 | Banner | Breadcrumbs (Home › Branches) + H1 `nav.branches` + intro `branch.dir.intro` [Proposed] | — | intro hidden |
| 2 | Site-wide notice | CMP-NoticeBanner | as C01 | hidden |
| 3 | Branch cards (HO order) | CMP-BranchCard (`<article>`, name = heading) | name, `momo_concept_label`, `momo_area_label`, branch notice chip, CMP-OpenStatus (embedded schedule JSON, JST), today's periods + "L.O.", localised address + `momo_address_ja` + copy, `momo_phone_display` → `tel:{momo_phone_e164}`, actions Request (`?branch={code}`) / Directions / Details | 0 branches → `branch.dir.empty` + contact link |
| 4 | Closing CTA | `cta.request_table` | — | — |

- Temporarily closed: chip `branch.temp_closed`, no Request; Call/Directions/Details remain. Open but not accepting: Request → `cta.call_to_reserve` (tel).
- Copy → `branch.copied` in polite live region 2 s; Clipboard API missing → button hidden, text selectable.
- Responsive 1/2/3 cols; 360 actions: Request full width, Directions + Details half.
- Action names include branch ("Directions to {branch}"); phone LTR isolate.
- Analytics `momo_page{branch_directory}`; `click_reservation`/`click_phone`/`click_directions` (`branch_card`). AC: every published non-test branch, no `closed_permanently`; TC-BR-001…003.

### 3.5 SCR-C05 Branch detail — M · `{P}/branches/{slug}/` · `single-momo_branch.php` · TK-05-09

| # | Section | Component | Data | Empty rule |
|---|---|---|---|---|
| 1 | Branch notices | CMP-NoticeBanner (branch) | `NoticeQuery::forBranch(id, L)` | hidden |
| 2 | H1 + concept | CMP-ConceptTag | `post_title`, `momo_concept_label` | concept hidden |
| 3 | Status line | CMP-OpenStatus | schedule JSON (hours + closures, JST) | temporarily closed → banner `branch.temp_closed` replaces it |
| 4 | Action row | Request (`cta.request_table_branch`) · Call · Directions | `?branch={code}`, `tel:`, `momos-directions-url` (`maps/dir/?api=1&destination={lat},{lng}[&destination_place_id]`) | Request hidden when not accepting |
| 5 | Address + access | CMP-AddressBlock + access list | localised address (T), `momo_address_ja` + copy, `momo_access_directions` "{station} · Exit {exit} · {n} min walk", `momo_access_notes` | access hidden |
| 6 | Map | CMP-MapFacade | `momo_map_preview_image` or theme placeholder; links `cta.open_maps`, `cta.directions`; **no Google JS before click** (DEC-029) | links remain if image fails |
| 7 | Weekly hours | CMP-HoursTable (`<table>`, caption; Day / Opening hours / Last order; "(today)" text) | `ScheduleService` | weekday without period → "Closed" |
| 8 | Closures next 30 days | CMP-ClosureList | `ClosureRepository` | hidden |
| 9 | Halal + alcohol | CMP-HalalStatement (branch) + alcohol lines + link C03 | `ClaimResolver::branch` | `no_claim`; unknown alcohol hidden |
| 10 | Facilities | CMP-FacilityList | `momo_prayer_space`, `momo_smoking_policy`, `momo_step_free_access`, `momo_seating_types`, `momo_payment_methods`, `momo_wifi` (`unknown` omitted) | hidden if all unknown |
| 11 | Offers at this branch | CMP-OfferCard (H3) | `OfferQuery::forBranch` (scope = this branch or all; branch `open`) | hidden |
| 12 | Branch menu link | link `branch.menu_link` → `{P}/menu/?branch={code}` | — | always |
| 13 | Gallery | CMP-Gallery (4:3, real photos only) | `_thumbnail_id`, `momo_gallery` | hidden |
| 14 | Closing CTA | `cta.request_table_branch` | — | hidden when not accepting |

- ≥ 1024: 8 + 4 columns; sticky side panel (open-now, today's hours, Request, Call, Directions) — at inline-start (left) in RTL. In-page anchors `<nav aria-label="On this page">` Hours · Access · Menu · Offers · Halal · Facilities (only rendered sections).
- Not accepting (`momo_res_enabled=0`, global off) or temporarily closed → no Request anywhere; bar Call · Directions.
- `closed_permanently` → 301 `{P}/branches/`; TEST branch → 404 to non-staff (staff: `noindex`, banner "TEST BRANCH", no GTM); untranslated in L → 404.
- Analytics `view_branch{branch_code}`; clicks `branch_page` / `sticky_bar`. SEO: Restaurant + BreadcrumbList JSON-LD; title `seo.title.branch`; tag `momo-branch-{id}`.
- AC: one template renders every branch incl. an n-th (FR-BR-011, TC-BR-015); no Maps JS until activated (TC-BR-016); empty sections absent; bar exactly Request · Call · Directions (≥ 48 px) or Call · Directions; no CMP-IllustrationLabel on the page.

### 3.6 SCR-C06 Menu hub — M · `{P}/menu/` · `menu-hub.php` · TK-06-10

| # | Section | Component | Data | Empty rule |
|---|---|---|---|---|
| 1 | Banner | Breadcrumbs (Home › Menu) + H1 `nav.menu` + intro | page excerpt (T) | intro hidden |
| 2 | Branch selector | CMP-BranchSelector (native `<select>`, label `menu.branch_select.label`) | first option `menu.branch_select.all`; published non-test branches, not closed/temporarily closed | — |
| 3 | Category tiles | CMP-CategoryTile grid | terms with ≥ 1 displayable dish in L (name, image + alt, intro); **no dish counts** (conflict C-3) | category hidden |
| 4 | Closing CTA | `cta.request_table` | — | — |

- Branch change: same URL ± `?branch={code}`, content updated without scroll loss; all menu links keep `?branch=`; unknown code ignored.
- No search field, cart, "Verified" chip, table-QR or halal banner.
- Responsive tiles 2 (360) / 3–4 (≥ 1024). Analytics `view_menu{menu_category:'hub', branch_code?}`. SEO: Menu JSON-LD; `?branch=` → `noindex,follow`, canonical clean.

### 3.7 SCR-C07 Menu category — M · `{P}/menu/{cat}/` · `taxonomy-momo_menu_category.php` · TK-06-11

| # | Section | Component | Data | Empty rule |
|---|---|---|---|---|
| 1 | Banner | Breadcrumbs (Home › Menu › {Category}) + H1 term name + intro + optional image | term (T) | intro/image hidden |
| 2 | Tools row (sticky, collapses to one row on scroll) | category link chips (`<nav>`, `aria-current`) · CMP-BranchSelector · CMP-MenuFilters (S) "Filters ({n})" disclosure < 1024 | sibling terms; tags in use; spice 0–3; (C) allergen exclusion + `menu.filter.allergen_note` | filters hidden if no tags in use |
| 3 | Result count | `role="status"` | client-side count | — |
| 4 | Dish cards | CMP-DishCard (one link = dish name, whole card clickable) | photo (+ `media.illustration` if flagged; no photo → tint circle with initial), eyebrow = category, localised name, `momo_original_name` (`lang`), `momo_short_description` (2-line clamp), price (`PriceResolver`: branch price or reference; "(tax incl.)"), dietary icons + text, spice (3 chilli icons + text), availability (branch context only: `menu.sold_out` / `menu.unavailable`), `menu.advance_order` | 0 matches → `menu.filters.empty` + `menu.filters.clear`; branch offers none → `menu.branch_none` + link to all branches |
| 5 | Closing CTA | `cta.request_table` (`?branch=` kept) | — | — |

- Filters client-side over rendered cards (`data-tags`, `data-spice`, `data-alg`); state in URL (`?diet[]=…&max_spice=…`), `noindex,follow`, canonical clean. Allergen exclusion hides dish when state ∈ contains / may_contain / not_verified or review flag; never "allergen-free".
- Sold-out text at full opacity; no add control, no halal badge.
- Responsive: 360 list cards (image inline-start, mirrored RTL); 768 2 cols; ≥ 1024 3 cols, filters inline.
- Analytics `view_menu{menu_category:slug, branch_code?}`; `click_reservation{menu_page}`. SEO MenuSection JSON-LD. Untranslated term or no visible dish in L (no branch context) → 404.

### 3.8 SCR-C08 Dish detail — M · `{P}/menu/{primary-cat}/{dish}/` · `single-momo_dish.php` · TK-06-12

| # | Section | Component | Data | Empty rule |
|---|---|---|---|---|
| — | Breadcrumbs | Home › Menu › {primary category} › {Dish} | `momo_primary_category` | — |
| 1 | H1 + original name | — | `post_title` (T), `momo_original_name` (`lang`) | original hidden |
| 2 | Gallery | 1–6 images, swipe, no autoplay | `_thumbnail_id`, `momo_gallery`; `media.illustration` below flagged images | featured image required to publish |
| 3 | Price + availability | CMP-PriceBlock + CMP-AvailabilityLabel | branch context: "¥X (tax incl.) at {branch} [Change]"; none: single price or per-branch table ("Prices vary by branch") | offered nowhere → `menu.not_available`, no CTA |
| 4 | Description | — | `post_content` (T) | hidden |
| 5 | Advance order | CMP-AdvanceOrderLabel | `momo_advance_order_lead_hours` → `menu.advance_order` + link to C09 carrying `res.hint.advance_order` | hidden |
| 6 | Dietary tags + spice | CMP-DietaryIcon | tags (approved, T), `momo_spice_level` read-only | tags hidden |
| 7 | Halal basis | CMP-HalalStatement (dish) | `momo_dish_halal_basis`: `branch_default` (default) → `branch_default` template + branch links; record-backed → template; `no_claim` | never omitted |
| 8 | Key ingredients | list | `ingredients.heading` + ingredient terms (T) | hidden |
| 9 | Allergens | CMP-AllergenTable | summary `allergen.summary.*` → banner `allergen.banner.not_verified` (`role="note"`; any not_verified or `momo_allergen_review_required`) → legend → grouped table 9 / 20 / other (`<caption>`, `<th scope="rowgroup">`) → `allergen.last_verified` → `allergen.disclaimer.voluntary` → `allergen.cross_contact` (only if confirmed) → `allergen.tell_staff` | table always shown |
| 10 | Available at | branch links + availability/price when they differ | `BranchMenuItemRepository` | — |
| 11 | Related dishes | CMP-DishCard ≤ 4 (same primary category) | — | hidden |
| 12 | CTA | `cta.request_table` | `?branch=` when branch context or exactly one accepting branch offers it | hidden when offered nowhere |

- ≥ 1024: gallery 7 cols (start) + summary panel 5 cols (end: 3, 5, 6, 12); allergen table full width. 360: single column; mobile toggle "Show only contains / may contain" (`allergen.toggle`, default off).
- Removed UI-001 items: portions, add-ons, quantity, "Add to Order", "Goes well with".
- Retired dish → 301 primary category; non-primary category URL → 301 canonical; sample dish (prod) never public.
- Analytics `view_dish{dish_sku, menu_category}`; `click_reservation{dish_page}`. SEO MenuItem + Offer JSON-LD (`suitableForDiet` only verified).
- AC: own URL, no modal; banner in every language when required; no "allergen-free/safe"; only the dish price block shows prices; TC-DIET-011/012/027.

### 3.9 SCR-C09 Reservation form — M · `{P}/reservation/` · `reservation.php` · TK-09-09

Detail, field table, client/server validation and error mapping: **reservation.md §6.1** (authoritative).

| # | Section | Component | Data |
|---|---|---|---|
| 1–3 | H1 `res.title` · `res.intro` · `res.submitted.promise` (only if configured) | page banner (no breadcrumb) | branch setting |
| 4–12 | Branch · Date (Today/Tomorrow chips + month `<select>`) · Time chips by period · Party chips (≤ 8) / stepper · Name · Email · Phone · Special request · Reply language | CMP-ReservationForm (numbered fieldsets) | API-01 rules for the selected branch |
| 13 | Policy text | link to C16 reservation policy | `momo_res_policy_page` (published only) |
| 14 | "Check your request" summary | CMP-ReservationSummary (live; email large, LTR) | form state |
| 15–16 | `res.notice.collection` + consent `res.label.consent` | CMP-Choice | consent version |
| 17 | `res.no_payment` [Proposed key] | trust line | — |
| 18–19 | Submit `res.submit` · `res.call_alt` | CMP-Button | branch phone |

- States: slots skeleton (≤ 2 s); busy `res.submit.busy` (`aria-busy`); error summary `res.err.summary` (`role="alert"`, focused); SYSTEM banner + phone + `res.err.reference`; no accepting branch → notice + branch phones instead of form; TEST branch banner.
- Success: 201 `duplicate:false` → push `generate_lead` → store response in `sessionStorage.momo_last_request` → navigate to C10 (no PII in URL).
- Responsive: 360 single column, no action bar; 768 name/email/phone 2 cols; ≥ 1024 form 7 + sticky summary 5 (summary at inline-start in RTL).
- Analytics: `momo_page{reservation}`, `reservation_form_start`, `generate_lead`. SEO: indexable; `?branch=` noindex. Turnstile loads on C09 (and C12) only.

### 3.10 SCR-C10 Request submitted — M · `{P}/reservation/submitted/` · `reservation-submitted.php` · TK-09-10

Spec: reservation.md §6.2. Order: hourglass + H1 `res.submitted.heading` (`role="status"`) · `res.submitted.reference` `XXXX-XXXX` + Copy (`res.copied`) · summary (branch, address, date, time + JST non-ja, party, name, `res.submitted.email_to` masked) · `res.submitted.next` + `res.submitted.expiry` + promise (if configured) + `res.submitted.dont_travel` · `res.submitted.status_link` · branch phone · `res.carrier_tip` (if carrier email).
- Empty (no sessionStorage): `res.submitted.generic` + link C04 (never redirect). Duplicate: heading `res.info.duplicate`.
- Headers: `noindex` + `X-Robots-Tag`, `Referrer-Policy: no-referrer`; **no GTM/GA4, Turnstile or other third-party script**; no action bar; reading width at all sizes.
- AC: pending badge = hourglass + amber, never check/green; no §6.6 term; refresh shows same content until tab closes; network log 0 Google requests (TC-PRV-016).

### 3.11 SCR-C11 Status & cancel — S · `{P}/reservation/status/{REF}/#t={token}` · `reservation-status.php` · TK-09-11/12

Spec: reservation.md §6.3 (API-03/04, `X-Momo-Token`). Non-personalised shell (`no-store, private`, `noindex`, no-referrer, no third-party).
- Order: status badge H1 + meaning · reference · details (branch, date, time, party, **given name only** until Q-426) · branch message (`dir="auto"`) · rejected: `decline.reason.*` + `decline.suggested` links "Request another time" (C09 prefilled, "not held") · branch address/phone/directions · cancel section (inline panel `status.cancel.confirm` [Keep it][Yes, cancel], focus managed) · policy text.
- States: skeleton → data; no JS `status.no_js`; 404/410 `status.link_invalid` (no data); 409 → reload + `status.cancel.not_allowed`; success → "Cancelled" announced. completed/no_show → confirmed label + `status.past`.
- Switcher re-attaches token as `#t=` from `sessionStorage` (never query). AC: 7 statuses render label/icon/tokens; cancel absent after cutoff/terminal; TC-RES-040.

### 3.12 SCR-C12 Contact — S · `{P}/contact/` · `contact.php` · TK-04-13

| # | Section | Component | Data / rule |
|---|---|---|---|
| 1 | Banner | Breadcrumbs (Home › Contact) + H1 `nav.contact` | — |
| 2 | Reservation hint | inline info notice `contact.res_hint` + link C09 | — |
| 3 | Form card | CMP-Field ×5 | name (req, 1–100, `dir="auto"`), email (req, ≤ 254, `dir="ltr"`), topic (req; `general`, `group_event`, `feedback`, `media`, `other`), branch (optional select of directory branches), message (req, ≤ 2,000, `dir="auto"`) |
| 4 | Notice + consent | `notice.contact` + checkbox `consent.contact` (req) | access-security-privacy.md §15.2 |
| 5 | Submit | `contact.submit` / busy `contact.submit.busy` | honeypot + Turnstile |
| 6 | Branch phones | list (`tel:`) | directory branches |

- Submit → `POST /wp-json/momos/v1/contact` [Proposed, §7] → mail to HO inbox (`momo_contact_email`); **nothing stored** (BR-CNT-01); email body has no IP/UA (TC-PRV-015).
- States: busy; error summary + inline (same rules as C09, `aria-describedby`); SYSTEM banner with phone; success inline `contact.success` (`role="status"`), form cleared.
- Responsive single column reading width. Analytics `momo_page{contact}` only. AC: no DB row/post/option written (TC-CORP-009); field borders 3:1; success visible in all enabled languages.

### 3.13 SCR-C13 FAQ — S · `{P}/faq/` · `faq.php` · TK-04-07

Banner (Home › FAQ, H1 `nav.faq`) · group anchor chips (Reservations · Halal & dietary · Allergens · Branches & access · Payments ⟦TBD⟧) · per group H2 + CMP-Accordion · contact link.
- Data: page content as FAQ blocks `momo/faq-item` {group, question, answer} (T) [Proposed block]; empty group hidden.
- Accordion: `<button aria-expanded>` per question, multiple open, focus stays; `#q-{slug}` opens matching item; answers in DOM (print); buttons ≥ 44 px; chevron rotates (down = open in LTR and RTL).
- Halal/allergen answers only from approved templates (scan). Analytics: expanding a "Halal & dietary" item pushes `view_halal_information{source_page:faq}` once per expand. SEO: optional FAQPage JSON-LD (C) whose questions equal visible text (AC-CORP-04-02).

### 3.14 SCR-C14 News list / item — C · `{P}/news/`, `{P}/news/{slug}/` · TK-04-08

List: banner (Home › News) · cards (title, `<time>` date per §6.10, excerpt, optional 4:3 image) 1/2/3 cols · 10 per page newest first (AC-CORP-05-01) · pagination. Item: breadcrumb (Home › News › {Title}) · H1 · date · body (reading width) · back link `news.back`.
- Native posts in category `news`, translated via i18n meta; untranslated hidden. Not linked in nav/footer while 0 visible posts; reached directly → `news.empty` "No news yet". Expired news unpublished manually → 301 `{P}/news/`.

### 3.15 SCR-C15–C18 Legal pages — M · `legal.php` · TK-04-09

| Item | C15 Privacy | C16 Terms / Reservation policy | C17 External transmission | C18 Accessibility |
|---|---|---|---|---|
| URL | `/privacy-policy/` | `/terms/`, `/reservation-policy/` | `/external-transmission/` (`#analytics-settings`) | `/accessibility/` |
| Extra block | privacy contact | Reservation policy: supplied text only (DEC-054) else FR-CORP-006(d) system rules incl. "No payment or deposit is required" | tag table (service, provider/country, pages, data, purpose, opt-out) + opt-out toggle `<button aria-pressed>` (i18n-seo-analytics.md §12.5) | target WCAG 2.2 AA (not a legal claim), limitations, phone/email help |

- Common order: breadcrumb (Home › {Policy}) · H1 · `legal.ja_authoritative` (L≠ja) · last updated `momo_legal_last_updated` + version `momo_legal_version` · TOC when > 5 H2 · sections (reading width; tables stack at 360).
- Publish requires `momo_publish_legal_pages` (CE drafts only, AC-CORP-06-02); unpublished until counsel approval (GATE-06); Reservation policy always published and linked from footer + consent label.
- AC: AC-CORP-06-01…04; opt-out applies without reload and persists (TC-ANL-007).

### 3.16 SCR-C19 404 · SCR-C20 Maintenance — M (ui-design-system.md §8.11–8.12)

C19: HTTP 404, `noindex`; banner H1 `404.title` + `404.body` + 4 link cards (Home, Branches, Menu, Request a table — same language; 1 → 4 cols ≥ 1024); bar Request · Branches; path logged (no IP); `click_reservation{error_page}`. C20: static `maintenance.php`, 503 + `Retry-After: 600`, system fonts, message per enabled language with own `lang`/`dir`, HO phone ⟦TBD — FR-FND-005⟧.

### 3.17 SCR-C21 Offers — M · `{P}/offers/` · `offers.php` · TK-04-15

| # | Section | Component | Data | Empty rule |
|---|---|---|---|---|
| 1 | Banner | Breadcrumbs (Home › Offers) + H1 `offers.title` + `offers.intro` | — | — |
| 2 | Branch filter chips | CMP-Chip ("All" + each branch with an active offer) | only when active offers have different scopes | hidden |
| 3 | Offer cards | CMP-OfferCard (`<article id="offer-{id}">`, H2) | image (4:3, alt, + `media.illustration` if flagged) · eyebrow "Offer" · title · `momo_offer_summary` · `offers.valid` (dates written out; + JST non-ja) · scope `offers.scope.all` or branch names · `momo_offer_conditions` (always visible; > 3 lines → `offers.show_all`) · `momo_offer_price_jpy` "(tax incl.)" · CTA by `momo_offer_cta_type` | `offers.empty` + links Menu, Branches; page `noindex`, out of sitemap |

- Chips filter client-side, update `?branch=` (canonical clean; unknown ignored). Request CTA prefills branch for single-branch offers and passes hint `offers.mention_hint`.
- No coupon code, redemption, countdown, "ends soon" animation (BR-CORP-01). Expired offers never rendered (query + job).
- Responsive 1/2/3. Analytics `momo_page{offers}`, `click_reservation{offer_card}`. AC: AC-CORP-09-03…09.

### 3.18 SCR-C22 Gallery — C · `{P}/gallery/` · `gallery.php` · TK-04-16

Banner (Home › Gallery, centred heading) · filter chips (All, per branch, per category; empty chips hidden) · CMP-Gallery grid with **visible captions** (dish/place + branch) · closing CMP-FeatureBand `cta.request_table`.
- Data [Proposed]: attachments with `momo_gallery_include=1`, `momo_media_branch` (branch ID) / `momo_media_category` (term ID), caption + alt in i18n meta; `momo_image_illustrative=1` refused (DEC-047).
- Lightbox only if keyboard-operable (arrows, Esc, focus returns) else link to full image; lazy images with reserved ratio; no prices, Add, hover-only text.
- Responsive 2 (square) / 3 / bento ≥ 1024. Not linked until ≥ 1 approved photo visible in L.

---

## 4. Admin frame, menu and slug registry

Admin = WordPress admin (ja/en per user, DEC-037), WP list tables/notices/meta boxes in `.wrap`; momos-core screens A04,
A07, A08, A09, A11 (gaps), A12, A16, A17, A19 usable at 360 px (cards + sticky action bar, targets ≥ 44 px). Notices
announced (`role="status"`/`role="alert"`). No third-party scripts in admin (TC-PRV-018).

**Menu (TRD §2.1, MUST):** top-level "Momo's" (`momos`, cap `read`, items filtered by capability) + CPT menus.

| Menu item (EN / JA draft) | Screen | Slug / URL [Proposed registry — single source `Momos\Admin\AdminUrls`] | Cap |
|---|---|---|---|
| Momo's › Reservations / 予約リクエスト (badge = pending in scope) | A08 / A09 | `admin.php?page=momos-reservations` / `&id={id}` | `momo_view_reservations` |
| Momo's › Branch menu / 店舗別メニュー | A07 | `admin.php?page=momo-branch-menu` (exists in code) | `momo_edit_branch_availability` |
| Momo's › Hours & closures / 営業時間・休業日 | A04 | `admin.php?page=momos-hours&branch={id}` | `momo_edit_branch_hours` |
| Momo's › Claim register / ハラール登録簿 | A12 (summary) | `admin.php?page=momos-claims` | `read_private_momo_certificates` |
| Momo's › Translation gaps / 未翻訳一覧 | A11 | `admin.php?page=momos-translation-gaps` (+ editor `momos-translate`) | `momo_translate` |
| Momo's › Reports / 予約レポート | A17 | `admin.php?page=momos-reservation-report` | `momo_view_reservation_reports` |
| Momo's › Audit log / 監査ログ | A16 | `admin.php?page=momos-audit` | `momo_view_audit_log` |
| Momo's › Data requests / 個人情報の開示等請求 | A19 | `admin.php?page=momos-dsr` | `momo_manage_dsr` |
| Momo's › Settings / 設定 (tabs Brand, Notifications, Reservations, Languages, Privacy, Monitoring, Security) | A15 | `admin.php?page=momo-settings&tab=…` (+ `momo-brand`, `momo-dietary`) | `momo_manage_brand` / tab caps |
| Branches / 店舗 | A03 | `edit.php?post_type=momo_branch` | `edit_momo_branches` |
| Dishes / 料理 (Categories, Ingredients, Dietary tags, Allergens) | A06, A05 | `edit.php?post_type=momo_dish`, `edit-tags.php?taxonomy=momo_menu_category&post_type=momo_dish` | `edit_momo_dishes`, `momo_manage_menu_categories` |
| Offers / キャンペーン (badge = pending for HO) | A20 | `edit.php?post_type=momo_offer` | `edit_momo_offers` |
| Notices / お知らせ | A13 | `edit.php?post_type=momo_notice` | `edit_momo_notices` |
| Certificates (edit) | A12 (edit) | `post.php?post={id}` (`show_in_menu = momos`) | CAP-DCA + `edit_momo_certificates` |
| Pages · Posts (News) · Media · Users · Dashboard | A14, C14 admin, A18, A10, A02 | native | native caps |

Landing after login: BM/RS → A08 filtered `pending` for their branches; others → A02. List filters/sort/page live in
the URL so "Back" restores the view. Staff email deep links use `AdminUrls::reservation($id)` (ID only, no PII).
Front-end admin bar hidden for TR/BM/RS; WP core dashboard widgets removed except for SA.

---

## 5. Admin screens

### 5.1 SCR-A01 Login + 2FA — M (access-security-privacy.md §5, §13.1)
Centred card on canvas: logo (`momo_logo_primary`) · ja/en selector · username/email (`autocomplete="username"`) · password (`current-password`, paste allowed) · "Log in" · "Lost password" → step 2: 6-digit code (`one-time-code`, `inputmode="numeric"`, paste) · "Use a recovery code". First login → forced enrolment wizard (key + QR → code → recovery codes → "I have saved these codes"). States: busy · generic error "Incorrect username or password" (no enumeration) · lockout with retry time · success → `redirect_to` preserved through 2FA (E07 → A09). 360 px usable. AC: TC-ADM-004, TC-SEC-001…003, deep link lands on A09.

### 5.2 SCR-A02 Dashboard — S
WP dashboard widgets registered only for holders of the capability; counts as text; each links to the filtered list;
load ≤ 2 s with 10,000 reservations; widget error stays inside the widget; empty = positive text ("No overdue requests").
Widgets and audiences: access-security-privacy.md §13.1 table (My pending, Needs action, Attendance to record, Dishes
awaiting review, Translation gaps, Expiring certificates, Allergen not verified, Recent changes, Offers awaiting approval,
Offers ending soon, Failed notifications, Demo content remaining, My translation jobs). AC: only own capabilities and
branches (TC-ACL-024); no "Live Orders"/order metrics.

### 5.3 SCR-A03 Branch list / edit — M (branch.md §7.1)
- List: name, code, operating-status badge, published, `momo_res_enabled`, per-language status, last updated; scoped users see assigned branches only.
- Edit tabs: Identity · Access & facilities · Reservation settings · Dietary · Description & photos (+ SEO box). Lock icon + "Head Office only" / "Dietary Claims Approver only" on read-only fields; BM/CE edit description + photos only; BM sees Reservation settings read-only.
- Publish box: GATE-01 "Facts confirmed in writing by {name, date}" (`momo_facts_confirmed_by/_at`) required to publish.
- `temporarily_closed` reveals reopen date; `closed_permanently` shows future-reservation warning list; cutoff rule message "The decision cutoff must be at least {window} minutes shorter than the minimum lead time."; demo image refusal "Demo images cannot be used here. Upload a real photo."
- AC: identity locked for BM/CE; publish blocked without GATE-01; illustrative image refused; TC-BR-006…008, TC-RES-038.

### 5.4 SCR-A04 Hours & closures — M (branch.md §7.2)
Branch selector (assigned only; foreign `branch` → 403 + audit) · tab Weekly hours (schedule sets; 7 rows/cards; 0–6 periods: label, opens, closes, last order `HH:MM` text `inputmode="numeric"`, crosses-midnight indicator; "Closed" toggle; "Copy Monday to all weekdays") · tab Closures & special hours (upcoming 90 days + add form: date/range ≤ 31, type, periods, public reason, internal note) · 7-day preview (CMP-HoursTable) · affected-requests panel ("This affects {n} pending or confirmed requests" → Save anyway / Cancel). Errors inline per period ("Monday, period 1"), unsaved-changes warning; 360 cards / ≥ 1024 table. AC: closure on a date with pending requests lists them as "Needs action"; 44 px targets; TC-BR-009…011/013.

### 5.5 SCR-A05 Menu categories — M (menu-dietary.md §5.4)
Native term screen + ordering list (drag handle **and** up/down buttons ≥ 24 px → `momo_cat_order`); fields name, slug `^[a-z0-9-]+$`, intro, image (alt required), translation fields per language. Delete blocked: "This category contains {n} dishes. Move them before deleting." AC: order change visible on C06/C07 after purge.

### 5.6 SCR-A06 Dish list / edit — M (menu-dietary.md §5.5)
List: 48 px plate thumbnail, name (+ ja sub-name), `momo_dish_sku`, primary category, status (draft / pending review / published / retired), "{n} not verified", "Review required", "Sample", translation completeness; filter "Pending review". Edit tabs Content · Pricing · Dietary & allergens (29 + custom rows, 4-state radio group with legend, default Not verified, **no "set all"**) · Branches (read-only + link A07) · Translations. Buttons by capability: Save draft · Submit for review · Publish/Update · Retire. Publish warning lists not-verified allergens; sample in production → "Sample dishes cannot be published on the live site". AC: no add-on/seal control; MM without CAP-DCA sees claim fields locked; TC-DISH-002/003, TC-DIET-009/023/028.

### 5.7 SCR-A07 Branch menu grid — M (menu-dietary.md §5.6; API-14/15)
Branch selector · category filter · search (name/SKU) · rows (table ≥ 1024, cards < 1024): thumbnail, dish name (admin language else ja), SKU, offered (MM/HO), reference price, override (MM/HO; locked for BM/RS), displayed price "(tax incl.)", availability segmented control, sold-out-until (JST), reason, row status Saved / error + Retry. Quick action "Sold out today" ≤ 3 taps at 360–390 px. Per-row save only — **no bulk Save**. 409 → row refresh "Updated by {name} at {time}"; 403 per forbidden field. AC: BM cannot change price (UI + API); TC-MENU-005/009.

### 5.8 SCR-A08 Reservation inbox — M (reservation.md §7.1; API-10)
Header: title + scope chip ("{BRANCH_CODE}" / "All branches") · filter chips Pending · Today · Upcoming · Attendance due · More (status, date range, branch, flags, has special request, mine/unclaimed) · search on submit (reference or name) · new-items bar "{n} new requests — Show" (`role="status"`, polling 60 s, no re-sort) · list: `<ul>` of CMP-StaffInboxRow (cards < 1024, table ≥ 1024): date/time largest, party, given name (`dir="auto"`), branch code, status badge (staff label), age, overdue, flags (text), claimed by · pagination.
- States: skeleton; empty "No requests waiting." + links "Upcoming confirmed", "Attendance due"; error banner "Couldn't load requests. Retry."; offline banner pauses polling; 403 `forbidden_branch`.
- HO only (C): "Export CSV" dialog (range ≤ ⟦12 months⟧, reason ≤ 200 required, "Include special requests" off, cap ⟦10,000⟧).
- AC: BM sees assigned branches only (UI, URL, REST — GATE-03); pending sorted by requested start; p95 ≤ 2 s; one-handed at 360; TC-RSA-001/002/024, TC-ACL-003…005.

### 5.9 SCR-A09 Reservation detail — M (reservation.md §7.2; API-11/12/13/16)
Back (keeps filters) · status badge + reference · date/time, party, branch · guest (name, `tel:`, `mailto:`, preferred language native + admin name) · special request (`dir="auto"`) or "No special request" · flags with next step · handled by + Claim · timeline (incl. notification rows, notifications.md §7.1) · internal notes (≤ 1,000) · read-only rules summary · CMP-AdminActionBar (360: sticky bottom Accept · Decline · More; ≥ 1024 two columns details + timeline).
- Sheets (bottom sheet mobile / side panel desktop; focus to heading, Esc, focus return): Accept (message 0/500) · Decline (reason required, message, ≤ 2 suggested times) · Cancel (reason) · Change details (S) · Attended / No-show (window). Each states the consequence ("{Guest} will receive an email in {language}."); buttons disabled in flight; Accept/Decline hidden after cutoff.
- Results: toast "Accepted — email queued to guest"; 409 `version_conflict` / `invalid_transition` (incl. lazy expiry) messages; 400 `reason_required`; 422 `attendance_window`; 403 page. AC: accept once on double tap; decline needs reason; "Guest not notified" shows call step; TC-RSA-003…017/025.

### 5.10 SCR-A10 Users & branch assignments — M (access-security-privacy.md §13.1)
Native Users + columns Role, Branches, 2FA, Status, Last login; filters branch/role/status; **no Delete**. Edit: role (no `administrator` for HO), Branches fieldset (required for BM/RS), admin language, phone, CAP-DCA, disabled; SA vendor/break-glass. Every role/branch change audited and sessions revoked. AC: TC-ACL-013, TC-ADM-008/009.

### 5.11 SCR-A11 Translations + gaps — M (i18n-seo-analytics.md §6, §14.2)
Gaps view: filters (language, content type, LCCS only) · table (item, type, JA published date, age days, six per-language status badges with text Missing / In progress / Published, "Send to translation"/"Translate" link) · LCCS completion % per language as text; active untranslated notices and offers first; sortable headers are buttons with `aria-sort`; 360 horizontal scroll inside labelled container. Empty "No gaps for {language}". Translator sees own languages/jobs only. AC: TC-I18N-008.

### 5.12 SCR-A12 Halal / certificate register — M (menu-dietary.md §5.7)
List: claim type, certifier, scope, linked branches/dishes, valid until + "expires in {n} days" (≤ 30 highlighted with text), status badge (pending verification · active · expired · revoked). Edit (CAP-DCA): all G14 fields, private evidence upload/download, Activate, Revoke (reason). MM: summary read-only; BM: own-branch summary, no evidence. AC: expired/revoked removes wording from C03/C05/C08 after purge; E11 at 30/7/0 days; TC-DIET-002/017/024.

### 5.13 SCR-A13 Notices — M (branch.md §7.3)
List: title, scope, severity, start, end, state badge Scheduled / Active / Expired, translation status. Edit: title, body (counter ⟦300⟧), severity info/warning, scope (BM: own branches only; no site-wide), start/end JST 24 h, link; **live CMP-NoticeBanner preview** (not live-announced; matches front colours and RTL). Validation end > start; BM end ≤ start + 60 days.

### 5.14 SCR-A14 Pages & content — M
Native Pages list/editor. CE: block editor with allow-list (paragraph, heading, list, image, quote, buttons, `momo/faq-item`, columns) and locked page templates [Proposed]; cannot edit theme files or system-page templates. Legal pages (`momo_page_type=legal`): CE drafts, HO/SA publish. Alt text required on inserted images; restaurant facts never typed (CI scan on rendered content: prices, phones, hour ranges, claim words → warning notice for editors [Proposed]). AC: AC-CORP-06-02; TC-ACL-014.

### 5.15 SCR-A15 Settings — M
Tabs: Brand (G1: `momo_trading_name`, `momo_tagline`, `momo_legal_operator_name`, logos, contact, social, policy pages, brand statement key [CAP-DCA]) · Notifications (recipients per branch + "Send test", notifications.md §7.4) · Reservations (`momo_min_decision_window_minutes` ⟦30⟧, `momo_res_global_enabled`) · Languages (`momo_manage_languages`: per-language state, LCCS %, sign-off name/date; gate refusal "Cannot enable {language}: {n} launch-critical items are missing or unreviewed.") · Privacy / Monitoring / Security (access spec). Personal-mail domains → warning chip. AC: logo swap updates header, footer, emails, login (TC-CORP-008); TC-I18N-019; TC-ADM-010.

### 5.16 SCR-A16 Audit log — M (access-security-privacy.md §13.1)
Filters (date range JST default 7 days, actor, action, object type/ID, branch) · table (time, actor, role, action, object, branch, before → after masked, IP truncated, request ID) · 50/page. Read-only — no edit/delete controls. BM/RS/MM/CE/TR → 403 (BM history = A09 timeline). Empty "No entries for these filters".

### 5.17 SCR-A17 Reservation report — S (reservation.md §7.3)
Filters (date range created/requested, branch in scope, language) · KPI tiles (value + label + period; 2-up at 360) · tables by status/branch/language/ISO week; every chart has a table; zero denominator → "Not enough data for this period". No names/emails/phones/notes; TEST excluded. RS no access.

### 5.18 SCR-A18 Media library — M
Native library + attachment fields: alt per language (required before insertion), `momo_image_illustrative` (allowed on dish/offer images only), `momo_image_rights_ref`, gallery fields [Proposed] `momo_gallery_include`, `momo_media_branch`, `momo_media_category`; upload ≤ ⟦5 MB⟧, WebP generated, location metadata stripped; BM upload/own uploads, cannot delete shared brand assets; certification marks hidden from non-CAP-DCA picker (TC-ACL-023).

### 5.19 SCR-A19 Data-subject requests — S (access-security-privacy.md §10.5)
Search (exact email or reference; no partial browsing) · results (reference, branch, date/time, status, language, created) · Export (streamed) / Correct / Anonymise (danger button; dialog requires typing the reference; irreversibility text). Every action audited; empty "No records found."

### 5.20 SCR-A20 Offers list / edit — M (branch.md §7.4)
List: title, scope, start, end, state (Draft · Pending review · Scheduled · Active · Expired, read-only once expired), featured, order, translation completeness; empty "No offers yet" + "Create offer". Edit: G17 fields + helper box (tax-inclusive prices; no coupon codes, online redemption or unsubstantiated "usual price") + **live CMP-OfferCard preview** matching C21. Buttons: CE/BM Save draft · Submit for review; HO Approve and publish · Return to draft (comment required); Duplicate as draft. AC: BM cannot choose "All branches" (403 `forbidden_branch`); TC-CORP-010, TC-ACL-021.

---

## 6. Permissions per screen

Legend: ✓ full · b scoped to assigned branches · r read-only · s summary · d draft/submit only · — no access (menu hidden **and** server 403).

| Screen | SA 01 | HO 02 | MM 03 | CE 04 | TR 05 | BM 06 | RS 07 | CAP-DCA |
|---|---|---|---|---|---|---|---|---|
| C01–C22 (public) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| TEST branch page | ✓ | ✓ | — | — | — | b | b | — |
| A01 Login | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | — |
| A02 Dashboard | ✓ | ✓ | own widgets | own widgets | own jobs | b | b | + certificate/allergen widgets |
| A03 Branches | ✓ | ✓ | r | description & photos (all) | — | b: description & photos; settings r | — (rules summary in A09) | Dietary tab |
| A04 Hours & closures | ✓ | ✓ | — | — | — | b | — | — |
| A05 Categories | ✓ | ✓ | ✓ | — | translate | — | — | — |
| A06 Dishes | ✓ | ✓ | ✓ (publish opt) | — | translate | — | — | claim fields |
| A07 Branch menu | ✓ | ✓ | ✓ | — | — | b availability | b availability ⟦Q-023⟧ | — |
| A08/A09 Reservations | ✓ | ✓ | — | — | — | b | b | — |
| A10 Users | ✓ | ✓ (not SA) | — | — | — | — | — | — |
| A11 Gaps / translate | ✓ | ✓ | ✓ | ✓ | own languages | — | — | statement templates |
| A12 Register | ✓ (with DCA) | ✓ (with DCA) | s r | — | — | b s r | — | edit |
| A13 Notices | ✓ | ✓ | — | ✓ | translate | b | — | — |
| A14 Pages | ✓ | ✓ (publish legal) | — | ✓ (legal: d) | translate | — | — | — |
| A15 Settings | ✓ | ✓ (no technical tab) | — | — | strings | — | — | brand statement key |
| A16 Audit log | ✓ | ✓ | — | — | — | — | — | — |
| A17 Report | ✓ | ✓ | — | — | — | b | — | — |
| A18 Media | ✓ | ✓ | ✓ | ✓ | — | upload, own | — | marks |
| A19 DSR | ✓ | ✓ | — | — | — | — | — | — |
| A20 Offers | ✓ | ✓ approve/publish | — | d | translate | b d | — | — |

Capabilities per cell: access-security-privacy.md §3 (authoritative grant table). Every handler checks capability +
nonce + branch scope server-side; hidden menu items are cosmetic only.

---

## 7. API and handlers used by screens

Base `/wp-json/momos/v1/`. Error envelope and the canonical catalogue (Doc 04 §16.0.4, DEC-063): reservation.md §5.

| Screen | Endpoint | Errors the screen must render |
|---|---|---|
| C09 | API-01 `GET branches/{code}/reservation-rules?lang=` · API-02 `POST reservations` | `invalid_param`, `captcha_failed`, `origin_rejected`, `branch_not_found`, `slot_unavailable`, `pending_limit_reached`, `branch_not_accepting`, `party_size_out_of_range`, `lead_time_not_met`, `outside_booking_window`, `rate_limited`, `payload_too_large`, `internal_error`, `service_unavailable` |
| C11 | API-03 `GET reservations/{public_ref}` · API-04 `POST reservations/{public_ref}/cancel` (header `X-Momo-Token`) | `not_found`, `link_expired`, `cancel_not_allowed`, `invalid_transition`, `origin_rejected`, `rate_limited`, `internal_error` |
| A08 | API-10 `GET staff/reservations` | `rest_forbidden`, `forbidden_branch`, `invalid_param`, `rate_limited` |
| A09 | API-11 `GET staff/reservations/{id}` · API-12 `POST …/{id}/transitions` · API-13 `POST …/{id}/notes` · API-16 `PATCH …/{id}` (S) | `not_found`, `reason_required`, `version_conflict`, `invalid_transition`, `attendance_window`, `forbidden_branch`, `forbidden_field`, `slot_unavailable`, `lead_time_not_met`, `party_size_out_of_range`, `outside_booking_window` |
| A07 | API-14 `GET staff/branches/{code}/menu-items` · API-15 `PATCH staff/branches/{code}/menu-items/{dish_id}` | `forbidden_branch`, `forbidden_field`, `version_conflict`, `invalid_param` |
| C12 | **[Proposed]** `POST contact` — JSON `{name, email, topic, branch_code?, message, consent:true, lang, turnstile, website:""}` → 200 `{"sent":true}`; Origin check, Turnstile, honeypot, rate limit ⟦5 per 10 min per IP hash⟧; mail via notification transport; no persistence | `invalid_param` (field reasons), `captcha_failed`, `origin_rejected`, `unsupported_media_type`, `payload_too_large`, `rate_limited`, `internal_error`, `service_unavailable` |
| A04, A03, A13, A20, A10, A16, A19 | `admin-post.php?action=momos_*` with nonces (module specs) | WP admin notices |

C10 makes no request (sessionStorage only). Public pages read no REST data except API-01 (C09).

---

## 8. Strings (screen-level keys not defined in module specs)

Keys from Doc 07 §6 are reused unchanged (nav, CTA, branch, menu, offers, res, status, allergen, halal — see module
specs). New keys below are **[Proposed]**; every JA value is a draft requiring native review (DEC-027).

| Key | EN | JA (draft) |
|---|---|---|
| `home.featured.title` | Featured dishes | おすすめ料理 |
| `home.how.title` | How reservations work | 予約リクエストの流れ |
| `home.how.step1` | Send a request for a branch, date and time. | 店舗・日付・時間を選んでリクエストを送信します。 |
| `home.how.step2` | The branch reviews your request. | 店舗がリクエストを確認します。 |
| `home.how.step3` | We email you the branch's reply. Your table is set aside only after the branch accepts. | 店舗からの返信をメールでお知らせします。店舗が承認するまでお席は確保されていません。 |
| `branch.dir.intro` | Choose a branch to see its hours, access and halal information. | 店舗を選ぶと、営業時間・アクセス・ハラール情報をご覧いただけます。 |
| `branch.menu_link` | View this branch's menu | この店舗のメニューを見る |
| `branch.on_this_page` | On this page | このページの内容 |
| `menu.branch_select.label` | Show prices for | 価格を表示する店舗 |
| `menu.branch_none` | This branch doesn't offer dishes from this category. | この店舗ではこのカテゴリーの料理を提供していません。 |
| `menu.result_count` | {n} dishes | {n}品 |
| `allergen.toggle` | Show only "contains" and "may contain" | 「含む」「含む可能性あり」のみ表示 |
| `contact.res_hint` | For table requests, please use the reservation form. | お席のご予約はリクエストフォームをご利用ください。 |
| `contact.label.name` / `.email` / `.topic` / `.branch` / `.message` | Your name / Email / Topic / Branch (optional) / Message | お名前／メールアドレス／お問い合わせ内容の種類／店舗（任意）／メッセージ |
| `contact.topic.general` / `.group_event` / `.feedback` / `.media` / `.other` | General question / Group or event / Feedback / Media / Other | 一般的なご質問／団体・イベント／ご意見／取材／その他 |
| `contact.err.message.length` | Your message can be up to 2,000 characters. | メッセージは2,000文字以内で入力してください。 |
| `contact.submit` / `.submit.busy` | Send message / Sending… | 送信する／送信中… |
| `contact.success` [Doc EN] | Thank you — your message has been sent to our head office. | お問い合わせありがとうございます。本部に送信しました。 |
| `404.body` | The page you are looking for does not exist or has moved. | お探しのページは存在しないか、移動しました。 |
| `news.empty` [Doc EN] / `news.back` | No news yet / Back to news | ニュースはまだありません／ニュース一覧に戻る |
| `legal.last_updated` / `legal.toc` | Last updated: {date} / Contents | 最終更新日：{date}／目次 |
| `gallery.filter.all` | All | すべて |
| `faq.group.reservations` / `.halal` / `.allergens` / `.access` / `.payments` | Reservations / Halal & dietary / Allergens / Branches & access / Payments | 予約リクエスト／ハラール・食事制限／アレルゲン／店舗・アクセス／お支払い |
| `admin.res.empty` [Doc EN] | No requests waiting. | 対応待ちのリクエストはありません。 |
| `admin.res.load_error` [Doc EN] | Couldn't load requests. Retry. | リクエストを読み込めませんでした。再試行してください。 |
| `admin.report.no_data` [Doc EN] | Not enough data for this period | この期間のデータが不足しています |
| `admin.audit.empty` [Doc EN] | No entries for these filters | 該当する記録はありません |

All other customer/staff strings: reservation.md §8, branch.md §8, menu-dietary.md §6, i18n-seo-analytics.md §15,
access-security-privacy.md §15, ui-design-system.md §13. Every new key enters the LCCS string set (ar/ur/hi/bn by
native translators; prohibited-term scan applies).

---

## 9. Acceptance criteria and tests to automate (cross-screen)

| # | Criterion (Given/When/Then, abbreviated) | Tests |
|---|---|---|
| 1 | Every customer template at 360/390/768/1024/1366 in ja, en, ar, ur: no horizontal scroll, one H1, landmarks, skip link first | TC-CMP-005, TC-A11Y-001/005, TC-RTL-001 |
| 2 | Section order of C01, C05, C07, C08 equals §3 tables (DOM order check) in ja and ar | TC-HOME-001, TC-BR-004, TC-MENU-002, TC-DISH-001 |
| 3 | Empty data → section and heading absent; no "please inquire"/"N/A"/"-" anywhere | TC-BR-005, TC-WP-007 |
| 4 | No ordering terms, seals, "100 %", request-context terms (Doc 07 §6.6) in any language on any template | TC-REG-007, TC-RES-021, TC-DIET-013 |
| 5 | Untranslated entity in L: absent from lists; singular URL 404; switcher → L home + notice | TC-I18N-013/014, TC-DISH-005, TC-CORP-014 |
| 6 | Mobile action bar items per page type; hidden on C09–C11 and when keyboard open | TC-NAV-002, AC-CORP-08-01/04 |
| 7 | Offers header item appears/disappears with offer activity in that language (cached pages purged); footer link always | TC-NAV-006 |
| 8 | Bidi: phones, prices, times, references render in correct order in ar/ur | TC-RTL-002 |
| 9 | Analytics: each event once with allowed params; none on C10/C11/TEST/admin; `generate_lead` only on 201 new | TC-ANL-001…004, TC-PRV-016 |
| 10 | SEO: canonical clean; `?branch=`/filters noindex; C10/C11 noindex; `/offers/` noindex while empty | TC-SEO-003, TC-CORP-012 |
| 11 | Demo images: caption below dish/offer images only; refused on branch/gallery/halal/About | TC-DISH-006, TC-MENU-013 |
| 12 | Admin isolation: BM/RS see only assigned branches on A02/A04/A07/A08/A09/A13/A17/A20 via UI, URL and REST | TC-ACL-002…009, TC-E2E-012 |
| 13 | Admin custom screens usable at 360 px with ≥ 44 px targets; sheets manage focus | TC-RSA-002, TC-CMP-007, TC-A11Y-011 |
| 14 | Lab CWV on C01, C05, C07, C08, C09 mobile: LCP ≤ 2.5 s, CLS ≤ 0.1, TBT ≤ 200 ms; weight ≤ 1.5 MB | TC-PERF-001/003 |
| 15 | UI-001 side-by-side review at 390/1366 (ja, ar) signed by UX lead (IMPL-005: baseline not attached) | TC-REG-005 (manual) |

Playwright fixtures: seed (`wp momo seed`) with 5 branches + TEST, 3 featured dishes, 1 active featured offer, 1 expired
offer, 1 site notice, 1 branch notice, certificate states (active certified, expired, no_claim), languages all enabled
on staging; test clock for offer/notice/certificate time boundaries.

---

## 10. Out of scope (Phase 1)

Ordering, cart, payments, table QR, customer accounts/login (DEC-041/042) · site search (DEC-045) · offer and notice
detail pages (DEC-044) · dish quick-view modal/sheet · area/station landing pages · consent banner (DEC-030) · calendar
(.ics) files · carousel/autoplay sliders · Elementor/ACF/WPML/Rank Math UIs (IMPL-001) · RTL admin and ar/ur/hi/bn admin
UI · dark mode · E01–E11 visual build (notifications.md) · separate "Menu" item in the mobile action bar (Doc 07 OI-20,
needs FR-CORP-008 change).

## 11. Open TBDs (default built as configuration)

| Item | Default | Owner / ref |
|---|---|---|
| Hero headline/lead | `momo_tagline`; trading name if empty | Owner/Content — FR-CORP-002 |
| Home offers / featured dishes limits | 3 / 6 | Content — Q-414, AC-CORP-01-03 |
| Directory order | `menu_order`, then title | HO |
| FAQ "Payments" group | hidden until content supplied | Owner |
| Status-page name element | given name only | Counsel — Q-426 |
| Customer self-cancel cutoff | 0 (until start) | Owner — DEC-016 |
| Response promise | hidden | Owner — DEC-013 |
| Reservation policy text | system rules only | Owner — DEC-054 |
| `res.submitted.dont_travel` | shown | UX lead — validate V8 |
| Notice body limit | 300 chars | Content lead |
| Export range / row cap | 12 months / 10,000 | HO — F-RES-11 (C) |
| Legal operator name, HO phone, contact email | footer © line hidden; maintenance phone omitted | Owner — FR-FND-005, Q-024 |
| Upload limit | 5 MB | HO — FR-ADM-005 |
| RS availability edits on A07 | yes | HO — Q-023 |
| Illustration caption wording | `media.illustration` | Counsel — DEC-047 |
| Visual identity (logo, colours, fonts) | provisional tokens | Owner — DEC-046 |

## 12. Documentation conflicts found and resolution applied

| # | Conflict | Resolution here |
|---|---|---|
| C-1 | Admin menu: Doc 06 §2.1 separate top-level menus (Reservations, Halal register, Translations, Settings…) vs TRD §2.1 (MUST) top-level "Momo's" + CPT menus; code follows TRD | TRD structure (§4); Doc 06 labels reused |
| C-2 | Admin slugs differ between module specs: `momos-reservations&id=` (reservation.md) vs `momo-reservations` (notifications.md) vs `momos-inbox` (branch.md) vs Doc 06 `momo-reservations&reservation={id}`; code has `momo-branch-menu` | Registry §4 via `Admin\AdminUrls`; module specs/templates must call the helper; register `momo-reservations`/`momos-inbox` as redirect aliases for old links |
| C-3 | C06 tiles: Doc 07 "dish count for context" vs Doc 08 "no counters" | No counts (Doc 08, menu-dietary.md) |
| C-4 | DishCard illustration label "on the photo" (Doc 08 §3.3) vs "directly below the image" (CMP-IllustrationLabel, Doc 07 OI-28, Doc 11) | Below the image |
| C-5 | RS access to A03/A04: Doc 06 §2.1 "b r" vs TRD grant table (no cap) and Doc 07 A09 "ROLE-07 has no SCR-A03 access" | No A03/A04 for RS; rules summary in A09 |
| C-6 | Untranslated notice param `?notice=untranslated` (i18n spec) vs `?momo_notice=missing_translation` (ui-design-system.md §8.4) | `?notice=untranslated` emitted, alias accepted (i18n spec C-1) |
| C-7 | C12 Contact on Elementor Pro Form; no API defined; no contact module spec | momos-core REST `POST /momos/v1/contact` [Proposed] — add to TRD API list as API-18 before build |
| C-8 | US-CORP-012 (News, Could) mapped to SCR-C01 in user_stories.csv | Mapped to SCR-C14 |
| C-9 | C11 personal data: PRD full name + masked email vs Doc 11/TRD given name only (Doc 16 R1-13) | Given name only until Q-426 (reservation.md C-8) |
| C-10 | Doc 08 C10 "Reservation submitted" wording "(if retained) don't travel" vs reservation.md always shown | Shown (Doc 07 §5.9 [Rec]) pending V8 |
| C-11 | Gallery grouping fields mentioned (Doc 08 A18 "branch/category grouping") but no keys in TRD G18 | `momo_gallery_include`, `momo_media_branch`, `momo_media_category` [Proposed] |
| C-12 | Directory "closing CTA": in Doc 06 §1.9, absent from Doc 07 §5.4 | Included (harmless; Doc 06 internal-link rule "every page ends with a reservation CTA") |
| C-13 | Logo format: TRD G1 "no SVG" vs ui-design-system.md "SVG (sanitised)/PNG" | Not decided here — design-system owner to align with TRD (FR-ADM-005) |
