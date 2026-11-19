# Multilingual, SEO & Analytics — implementation spec (i18n · URLs · SEO · JSON-LD · redirects · GA4/GTM · opt-out)

Status: build spec v1.0 for `momos-core` (PHP 8.3, namespaces `Momos\Kernel\I18n`, `Momos\Seo`, `Momos\Presentation`,
`Momos\Privacy\Analytics`) and theme `momos`. Audience: developers who will not read the source package. Every rule is
traceable to an ID of the documentation package (Doc 04 PRD, 04A, 05, 06, 07, 08, 09 TRD, 10 SDD, 11, 12, 13, 17).
Values in ⟦…⟧ are TBD with the documented proposal used as default. `[IMPL]` marks a build-time design choice that
replaces a documented plugin mechanism (IMPL-001/002/003); `[Prop]` marks a value the docs leave open and that this spec
proposes (change via the owner named in §21). Business facts are never hard-coded: `{BRANCH}`, `{AREA}`, `SHN`, `{host}`
are placeholders.

## 0. How the documented mechanisms map to this build (IMPL-001…004)

| Documented mechanism | Build equivalent |
|---|---|
| WPML languages, directories, hidden languages | `Kernel\I18n\Lang` (codes, locale, dir, prefix), `Router` (prefix detection/stripping, `language_attributes`, 404 guard), option `momos_languages_enabled` (public set) |
| WPML "Translatable — only show translated items" | Entity visible in L≠ja only if `_momos_i18n_status_L = approved` **and** L enabled (`Translations::post_visible/term_visible`, `meta_query`) — BR-I18N-02 |
| WPML copy fields | Stored once on the JA post (language-independent); never in `_momos_i18n_*` |
| WPML TM jobs / ATE / review step | Translation editor (`Kernel\I18n\Admin\TranslationEditor`), statuses `missing → draft → needs_review → approved`, approve cap `momo_approve_translations`; optional job request meta `_momos_i18n_job_{lang}` |
| WPML "needs update" | `approved` + `_momos_i18n_srchash_{lang}` ≠ current source hash (`Translations::is_outdated`) |
| WPML String Translation / `.po` for customer strings | Key catalog `languages/strings/{lang}/{module}.php` + reviewed overrides `momos_strings_overrides` (`Kernel\I18n\Strings`, `momos_t()`) |
| WPML hidden-language state toggled by `LanguageGate` | `Kernel\I18n\LanguageGate` writes `momos_languages_enabled` + `momos_language_gate` record |
| WPML switcher widget / suggestion bar | Theme template parts `template-parts/i18n/switcher.php`, `…/suggestion-bar.php` + `assets/js/lang-suggest.js` (`Presentation\LanguageSuggestionBar`) |
| Rank Math meta box, titles, OG | Field group "SEO" (meta `momo_seo_*`, `momo_og_*`) + `Seo\Head` printer in `wp_head` |
| Rank Math canonical/robots/sitemaps/breadcrumbs/redirections/404 monitor | `Seo\Canonical`, `Seo\RobotsFilter`, `Seo\SitemapController`, `Seo\Breadcrumbs`, `Seo\Redirects`, `Seo\NotFoundLog` (no custom tables; options only — DEC-009 keeps 8 tables) |
| Rank Math `@graph` + momos JSON-LD merge | `Presentation\Schema\JsonLdBuilder` prints the only JSON-LD block (filter `momos/schema/graph`) |
| GTM via child theme | `Presentation\Analytics\TagLoader` (first-party loader) + `DataLayer` + `assets/js/datalayer.js`, `assets/js/analytics-optout.js` |
| Elementor widget `momos-analytics-optout` | Shortcode/template part `[momos_analytics_optout]` rendered on page `external-transmission` |
| Fonts in `momos-child/fonts/` | `wp-content/themes/momos/fonts/` (theme `momos`, IMPL-001) |

## 1. Requirements covered

Priority: M = Must, S = Should, C = Could.

### 1.1 Features, FRs, BRs, NFRs

| ID | Statement (one line) | Pri |
|---|---|---|
| F-I18N-01 | Six-language front end ja/en/ar/ur/hi/bn incl. RTL, fonts, bidi, formats | M |
| FR-I18N-001 | `<html lang dir>` per language; RTL for ar/ur via CSS logical properties; mirror directional icons only; DOM order = reading order | M |
| FR-I18N-004 | Self-hosted per-language font subsets (DEC-061); ≤ 3 families / ≤ 6 files per page; no font CDN; Urdu line-height ↑ | M |
| FR-I18N-005 | `<bdi>`/`dir="ltr"` isolation for phones, prices, dates, times, refs, emails, URLs, Latin names; user text `dir="auto"` | M |
| FR-I18N-006 | 24 h times; dates per Doc 07 §6.10; `¥1,500` + tax label; Western digits out; multi-script digits in; "JST" label non-ja only (DEC-062) | M |
| FR-I18N-011 | All customer email templates/form/validation strings in 6 languages, human-reviewed, reviewer recorded per language | M |
| FR-I18N-012 | Latin slugs in every language; same slug across languages; no percent-encoded paths | M |
| F-I18N-02 / FR-I18N-002 | Switcher (header, mobile menu, footer list): native names, enabled only, equivalent page or language home + notice; `select_language` | M |
| FR-I18N-003 | First-visit suggestion bar from `navigator.languages`; never redirect; `momo_lang_choice` 1 year | M |
| FR-I18N-013 | Reservation-form values survive a language switch (not consent); warn only if storage unavailable | S |
| F-I18N-03 / FR-I18N-007 | JA source → translate → native review → publish; MT drafts only; translators cannot touch source/copy fields | M |
| FR-I18N-008 | Translation gaps view (SCR-A11): missing / in progress / needs update, age, LCCS flag, completeness % | M |
| FR-I18N-009 | Language gate: only `momo_manage_languages`; refuse enable while LCCS < 100 % or no sign-off; disable hides immediately; audited | M |
| F-I18N-04 / FR-I18N-010 | Admin language per user ja/en (DEC-037); all momos-core admin strings ja+en; JST; staff emails in recipient locale | M |
| FR-FND-001(b)(c)(d) | ja root, `/en/ /ar/ /ur/ /hi/ /bn/`; enabled/hidden state; hidden → 404 + noindex to anonymous | M |
| F-SEO-01 / FR-SEO-001 | URL map (§8), lowercase, trailing slash, 301 to canonical form | M |
| FR-SEO-002 | Reciprocal hreflang for enabled+translated versions + `x-default` → en | M |
| FR-SEO-003 | Self canonical per language; `noindex` rules (§9.3) | M |
| FR-SEO-004 | XML sitemap index + per-language sitemaps of indexable URLs only | M |
| FR-SEO-005 | Editable title, description, OG per page per language; length warnings; default templates | M |
| FR-SEO-007 | Visible breadcrumbs (branch, menu category, dish, legal …), RTL-mirrored, = BreadcrumbList | M |
| FR-SEO-009 | Staging noindex + HTTP auth; production robots.txt; every indexable page ≤ 3 clicks | M |
| F-SEO-02 / FR-SEO-006 | JSON-LD Organization, WebSite, Restaurant, Menu/MenuSection/MenuItem/Offer, BreadcrumbList, FAQPage (C) from data | M |
| F-SEO-03 / FR-SEO-008 | Legacy 301 map (single hop, CSV in Git), 404 log reviewed weekly × 8 weeks | M |
| FR-CORP-007(a)(b) | Localised 404 (noindex) + 404 log without IP/UA (consumed here) | M |
| F-ANL-01 / FR-ANL-001 | GA4 via GTM; 10 events; no PII; no tags on submitted/status pages (DEC-066), wp-admin, TEST branch | M |
| FR-ANL-002 | External Transmission & Cookies page + "Allow analytics" switch (`momo_analytics_optout`) | M |
| FR-ANL-003 | Business outcomes from reservation report (FR-RES-022), never GA4; no Measurement Protocol | M |
| FR-PRV-005 | No PII in URLs, analytics, logs (analytics + 404 log part) | M |
| BR-I18N-01 | Language public only after LCCS native review (GATE-04) | M |
| BR-I18N-02 | No mixed-language fallback; untranslated items hidden in that language | M |
| BR-I18N-03 | Japanese legal text authoritative; translations say so (wording ⟦counsel⟧) | M |
| BR-DIET-05 | Claim text never published from unreviewed MT | M |
| BR-PRV-04 | No personal data to MT/AI services | M |
| BR-SEO-01 | No rating/review markup about Momo's; structured data = visible content | M |
| BR-PRV-01 | Data minimisation (analytics carries none) | M |
| NFR-I18N-01 | 6 languages; RTL correct; 0 mixed-language pages; 0 hreflang errors | M |
| NFR-SEO-01 | Valid sitemaps; canonical on all indexable pages; JSON-LD validates | M |
| NFR-ACC-01 | WCAG 2.2 AA (switcher, bar, toggle, breadcrumbs) | M |
| NFR-PERF-03 | ≤ 1.5 MB initial load incl. fonts | M |
| Doc 11 PRV-006/007/021/022/023/024 | No PII in analytics; no 3rd-party on C10/C11; C17 = live tags; opt-out + GPC block GTM; GA4/GTM settings; no tags in admin | M |

### 1.2 User stories, screens, decisions

| Type | IDs |
|---|---|
| US | US-I18N-001…008, US-SEO-001…004, US-ANL-001, US-ANL-002 |
| Customer screens | Global header/footer/mobile menu (switcher), language-suggestion bar, SCR-C17 External transmission, SCR-C19 404, every SCR-C (head/meta/JSON-LD/dataLayer) |
| Admin screens | SCR-A11 Translation editor + gaps view (+ translator queue), SCR-A15 Settings → Languages, SEO → Redirects & 404 log `[IMPL]`, SEO meta box on SCR-A03/A05/A06/A14 |
| Decisions | DEC-002, 003, 005, 027, 030, 031, 037, 039, 040, 045, 060, 061, 062, 066; IMPL-001…004 |
| Backlog | TK-08-01…13, TK-13-01…11, TK-12-15, TK-03-04/05, TK-04-10/12 |

## 2. Language model

| Code | Locale | `<html lang>` | Native name | dir | URL prefix | Role | Font text face |
|---|---|---|---|---|---|---|---|
| ja | ja | ja | 日本語 | ltr | (root) | source, default, always public | Noto Sans JP |
| en | en_US | en | English | ltr | `/en/` | translation, **x-default** | Plus Jakarta Sans + Epilogue |
| ar | ar | ar | العربية | rtl | `/ar/` | translation | Noto Naskh Arabic |
| ur | ur | ur | اردو | rtl | `/ur/` | translation | Noto Nastaliq Urdu |
| hi | hi_IN | hi | हिन्दी | ltr | `/hi/` | translation | Noto Sans Devanagari |
| bn | bn_BD | bn | বাংলা | ltr | `/bn/` | translation | Noto Sans Bengali |

- Order everywhere (switcher, footer list, admin tabs): ja, en, ar, ur, hi, bn.
- State per target language: `enabled` (public) or `hidden`. Defaults (IMPL-003): dev/staging all six enabled for QA; production `ja`+`en` until sign-off. `ja` cannot be hidden.
- Fallback launch sequence if a language misses GATE-04: ja+en → ar → ur/hi/bn (DEC-002, PRD §35).
- Hidden language, anonymous request to any `/{lang}/…` URL → **404** + `noindex`, no hidden-language text in HTML (FR-FND-001(d), AC-FND-01-03). Users with `momo_preview_languages` see the page with `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`, and a staff banner `[Prop]` "Preview — {language} is hidden from the public."
- REST: language passed as `lang` (enum of the six codes, default `ja`); any other value → 400 `invalid_param`, `fields.lang = "not_allowed"`.

## 3. Data model

### 3.1 Per-entity translation meta (posts and terms; IMPL-002)

| Key | Type | Notes |
|---|---|---|
| `_momos_i18n_{lang}` | JSON object `{field: value}` | Translatable fields only, incl. SEO keys `seo_title`, `seo_description`, `og_title`, `og_description` |
| `_momos_i18n_status_{lang}` | enum `missing` \| `draft` \| `needs_review` \| `approved` | default `missing`; public only when `approved` |
| `_momos_i18n_updated_{lang}` | datetime UTC | last save |
| `_momos_i18n_srchash_{lang}` | sha256 | source hash at approval → "needs update" when it differs |
| `_momos_i18n_approved_by_{lang}` | user ID | reviewer |
| `_momos_i18n_translated_by_{lang}` `[IMPL]` | user ID | last user who changed content (translator ≠ reviewer rule, §6.2) |
| `_momos_i18n_job_{lang}` `[IMPL]` | JSON `{priority: normal\|urgent, requested_by, requested_at}` | "Send to translation" request (replaces WPML job) |

Status mapping to documented WPML states: `missing` = not translated; `draft`/`needs_review` = in progress (needs_review = translator done, awaiting reviewer); `approved` = complete/published; `approved` + outdated = needs update (previous reviewed translation stays public — PRD F-I18N-03 A1).

### 3.2 SEO source fields (JA; field group "SEO", on `page`, `momo_branch`, `momo_dish`, `momo_menu_category` term, `post` (news, C))

| Meta key | Type | Req | Translatable | Validation | Default |
|---|---|---|---|---|---|
| `momo_seo_title` | text | — | T (`seo_title`) | hard max 120 chars; warning > 32 full-width (ja) / > 60 chars (other languages) (FR-SEO-005) | '' → template §9.1 |
| `momo_seo_description` | textarea | — | T (`seo_description`) | hard max 300; warning > 120 full-width (ja) / > 160 chars `[Prop]`; no prices/promotions (Doc 06 §1.3) | '' → excerpt fallback §9.1 |
| `momo_og_title` | text | — | T (`og_title`) | max 120 | = resolved title |
| `momo_og_description` | textarea | — | T (`og_description`) | max 300 | = resolved description |
| `momo_og_image` | attachment ID | — | C | JPEG/PNG/WebP; not `momo_image_illustrative` on branch/About (DEC-047) | featured image → brand `momo_logo_primary` |
| `momo_lccs` (pages) `[IMPL]` | bool | — | C | set by migration M0007 for LCCS pages (§6.4); SA-only edit | 0 |
| `momo_old_slugs` (category terms) `[IMPL]` | JSON string[] | — | C | system-maintained on slug change → 301 | [] |

Posts use core `_wp_old_slug` for slug-change 301s (kept language prefix).

### 3.3 Options (wp_options)

| Option | Shape | Autoload | Owner |
|---|---|---|---|
| `momos_languages_enabled` | `list<code>` (ja implied) | yes | LanguageGate only |
| `momos_language_gate` | `{lang: {state: enabled\|hidden, lccs_signoff_by: string≤100, lccs_signoff_at: Y-m-d, lccs_signoff_ref: string≤200, changed_by: user ID, changed_at: UTC}}` (Doc 06 ENT-01 names) | yes | LanguageGate |
| `momos_strings_overrides` | `{lang: {key: string}}` reviewed string values | yes | translators (SCR-A11) |
| `momos_strings_review` `[IMPL]` | `{lang: {group: {status: needs_review\|approved, by, at, hash}}}` group = catalog file (`nav`, `res`, `email`, …); hash change → `needs_review` | yes | `momo_approve_translations` |
| `momos_option_i18n` | brand/option translations `{page: {lang: {field…, _status}}}` | yes | existing |
| `momos_redirects` `[IMPL]` | `list<{from, to, status:301, owner, verified:bool, source: legacy\|manual\|auto, created_at}>` | no | `momo_manage_redirects` |
| `momos_404_log` `[IMPL]` | ring buffer ≤ ⟦1,000⟧ rows `{path, lang, referrer_host, hits, first_seen_utc, last_seen_utc}` | no | system |
| `momos_404_reviews` `[IMPL]` | `list<{week_of: Y-m-d, reviewed_by, reviewed_at, actions: string≤500}>` | no | SA/HO |

### 3.4 User meta, cookies, browser storage, constants

| Item | Spec |
|---|---|
| `locale` (user meta, core) | `ja` (default) or `en_US` only (DEC-037); other locales hidden in the profile select |
| `momo_translator_langs` `[IMPL]` | `list<code>` of target languages for ROLE-05 (replaces WPML language pairs ja→xx) |
| Cookie `momo_lang_choice` | value = language code; `Path=/; Max-Age=31536000; SameSite=Lax; Secure`; functional; listed on C17 |
| Cookie `momo_analytics_optout` | value `1`; `Path=/; Max-Age=31536000; SameSite=Lax; Secure`; functional; listed on C17 |
| sessionStorage `momo_form_{idempotency_key}` + `momo_form_current` (= key) | reservation-form values except consent and captcha token; deleted after API-02 201 (TRD FR-I18N-013) |
| Constant `MOMOS_GTM_ID` `[IMPL]` | `^GTM-[A-Z0-9]{4,10}$`, per environment (wp-config/env); absent ⇒ no GTM at all (dev default) |
| Constant/env `MOMOS_ENV` | `Env::is_production()`; non-production ⇒ staging SEO block (§9.6) |

No WPML cookie exists in this build — C17 inventory row T5 lists `momo_lang_choice` + `momo_analytics_optout` instead of `wp-wpml_current_language` (Doc 11 §4.5 to update).

### 3.5 Enumerations

| Enum | Values |
|---|---|
| `Lang` | `ja, en, ar, ur, hi, bn` |
| Translation status | `missing, draft, needs_review, approved` |
| Gap state (A11) | `missing` (no approved translation, status missing), `in_progress` (draft / needs_review), `needs_update` (approved + outdated) |
| `page_type` (dataLayer) | `home, about, halal, branch_directory, branch, menu_hub, menu_category, dish, offers, reservation, contact, faq, news, legal, gallery, error_404` (none on submitted/status) |
| `cta_location` (resolved, §12.3) | `header, hero, branch_card, branch_page, sticky_bar, menu_page, dish_page, offer_card, home_section, halal_page, footer, reservation_page, error_page` |
| `party_size_band` | `1-2, 3-6, 7+` |
| `lead_source` | `reservation_form` |

## 4. Front-end i18n rendering rules (F-I18N-01)

### 4.1 Direction, language, layout

1. `<html lang="{html}" dir="{ltr|rtl}">` via `language_attributes` (existing `Router`); `<body class="momo-lang-{code} momo-dir-{dir}">` (TK-08-02). Direction changes only by full page load; no cached opposite-direction layout (AC: ar → en → ar flips each time).
2. CSS: logical properties only (`margin-inline-*`, `padding-inline-*`, `inset-inline-*`, `border-inline-start`, `text-align: start`); no `float:left/right`, no `row-reverse`; stylelint CI rule bans `margin-left/right`, `padding-left/right`, `left/right` positioning, `text-align: left/right` (TRD §3.6).
3. Mirrored in RTL: chevrons, arrows, back/next, directions icon, breadcrumb separator, carousel start, panel slide side, sticky side column. **Not mirrored:** logo, phone/clock/map-pin/check icons, media controls, numbers, allergen shapes, photos (Doc 08 §6.1).
4. Foreign fragments carry their language: Japanese address on non-ja pages `<span lang="ja" dir="ltr">…</span>`; Latin brand/dish original names `<bdi>`; proper nouns may stay in source script with `lang` (Doc 08 §6.3).
5. Uppercase/letter-spacing only for Latin: `:lang(ja,ar,ur,hi,bn)` removes `text-transform` and `letter-spacing`; no italics; ja `line-break: strict`; no manual `<br>` in translated strings; design for +40 % string length (Doc 08 §6.4).

### 4.2 Bidi isolation (FR-I18N-005; helper `Presentation\Helpers\Bdi::ltr()` / `momos_bdi()`)

| Content | Markup |
|---|---|
| Phone | `<a href="tel:+81…"><bdi dir="ltr">{display}</bdi></a>` |
| Price | `<bdi dir="ltr">¥1,500</bdi> <span>{tax label}</span>`; signed amounts keep the sign inside: `<bdi dir="ltr">+¥300</bdi>` |
| Time / range | `<bdi dir="ltr">12:00–15:00</bdi>`; periods joined with " · " |
| Date with digits, reference `XXXX-XXXX`, email, URL | `<bdi dir="ltr">…</bdi>`; reference class `momo-ref` |
| User-entered text (names, special requests, notes) | container `dir="auto"` in customer pages, admin and emails |
| RTL form inputs for email/phone/URL | `dir="ltr"` input, label aligned start (right) |

Placeholders containing numbers/Latin text are wrapped by code, never by translators (Doc 07 §6.1).

### 4.3 Fonts (FR-I18N-004, DEC-061; theme `momos`)

| Page language | Families loaded (WOFF2, `font-display: swap`) | Max files |
|---|---|---|
| en | Epilogue 700/800 (headings) + Plus Jakarta Sans 400/700 (body) | 4 |
| ja | Noto Sans JP 400/700 (unicode-range sliced subsets; Latin from its own glyphs) | see §21 C-9 |
| ar | Noto Naskh Arabic 400/700 + Plus Jakarta Sans 400/700 | 4 |
| ur | Noto Nastaliq Urdu 400/700 + Plus Jakarta Sans 400/700 | 4 |
| hi | Noto Sans Devanagari 400/700 + Plus Jakarta Sans 400/700 | 4 |
| bn | Noto Sans Bengali 400/700 + Plus Jakarta Sans 400/700 | 4 |

- Enqueue only the current language's families (≤ 3 families, ≤ 6 files per page; Epilogue never on non-Latin pages — wordmark is SVG). Preload only the current language text face at 400. No Google Fonts/CDN/icon fonts. OFL licence files shipped in `fonts/`.
- Fallback stacks: en `Epilogue, "Plus Jakarta Sans", "Noto Sans", system-ui, sans-serif`; ja `"Noto Sans JP", "Hiragino Sans", "Yu Gothic", sans-serif`; ar `"Noto Naskh Arabic", "Segoe UI", Tahoma, serif`; ur `"Noto Nastaliq Urdu", "Noto Naskh Arabic", serif`; hi `"Noto Sans Devanagari", "Nirmala UI", sans-serif`; bn `"Noto Sans Bengali", "Nirmala UI", sans-serif`.
- Per-script tokens via `:lang()` (`--momo-font-scale`, `--momo-lh-body`): ja 1.0 / 1.8; ar 1.1 / 1.8 (headings 700, no letter-spacing); **ur 1.15 / 2.0** (min 16 px, controls ≥ 48–52 px, no `overflow:hidden` pills, no line clamps on dish titles); hi 1.05 / 1.7; bn 1.05 / 1.7; en 1.0 / 1.6 (Doc 08 §2.3.3).
- Budgets per page view ⟦verify in PROTO⟧: en ≤ 140 KB; ja ≤ 300 KB; ar ≤ 120 + 70 KB; ur ≤ 450 + 70 KB; hi ≤ 160 + 70 KB; bn ≤ 180 + 70 KB. Naskh fallback for Urdu only if the performance gate fails (CON-022).

### 4.4 Formatting (FR-I18N-006, DEC-062; `Presentation\Format\DateFormatter`, `PriceFormatter`; requires PHP `intl`)

ICU locale tags with `-u-ca-gregory-nu-latn`; time zone always `Asia/Tokyo`; output digits always Western.

| Lang | ICU locale | Long date | Short date | Time | Zone label (reservation screens & emails) | Price | Branch phone |
|---|---|---|---|---|---|---|---|
| ja | `ja-JP-u-ca-gregory-nu-latn` | 2026年12月3日（木） | 12/3（木） | 19:00 (`H:mm`) | **none** | ¥1,500（税込） | national `06-xxxx-xxxx` |
| en | `en-GB-u-ca-gregory-nu-latn` | Thu 3 Dec 2026 | Thu 3 Dec | 19:00 | `19:00 JST` | ¥1,500 (tax incl.) | `+81 6-xxxx-xxxx` |
| ar | `ar-u-ca-gregory-nu-latn` | skeleton `EEEEdMMMMy` | `EEEdMMM` | 19:00 | translated label ⟦native review⟧ | `¥1,500` isolated LTR + translated tax label | international, isolated |
| ur | `ur-u-ca-gregory-nu-latn` | `EEEEdMMMMy` | `EEEdMMM` | 19:00 | translated label | as ar | international, isolated |
| hi | `hi-u-ca-gregory-nu-latn` | `EEEEdMMMMy` | `EEEdMMM` | 19:00 | translated label | ¥1,500 + label | international |
| bn | `bn-u-ca-gregory-nu-latn` (**override**: CLDR default digits are Bengali) | `EEEEdMMMMy` | `EEEdMMM` | 19:00 | translated label | ¥1,500 + label | international |
| admin ja/en | as ja/en | long form in detail views | ISO `2026-12-03` in tables | 19:00 | — | ¥1,500 | guest phone as entered + E.164 |

- Skeletons resolved with `IntlDatePatternGenerator::getBestPattern()`; ja/en use the literal patterns above. Weekday always shown on reservation surfaces. Offer date ranges: en "1 Dec – 31 Dec 2026", ja 「2026年12月1日〜12月31日」, others ICU interval inside `<bdi>`. Relative times ("2 h ago") only in admin, with absolute time visible/`title`.
- Zone label string key `common.tz_label` `[Prop key]`: en "JST"; ja unused; ar/ur/hi/bn ⟦native reviewer⟧.
- Party size: ja `4名`, en `4 people`; others gettext plural forms (Arabic 6 forms) ⟦native review⟧.
- **Input digit normalisation** (`Kernel\Support\Digits::normalize()`, client + server, before validation; phone, party size, typed dates/times, admin times): U+0660–0669, U+06F0–06F9, U+0966–096F, U+09E6–09EF, U+FF10–FF19 → ASCII. Field shows the normalised value on blur. `tel:` always E.164.

### 4.5 No mixed language (BR-I18N-02, DEC-027)

- Every list/query for language L adds `Translations::meta_query(L)`; single views of an entity not visible in L → 404 (not Japanese). Cards, options, labels, terms (ingredients, tags) not approved in L are omitted.
- UI strings: `Strings::t()` falls back to `en` only as a defect safety net; on public pages a fallback logs `WARNING i18n.string_missing {key, lang}` and counts as an LCCS gap, so an enabled language cannot have one (§6.4). Customer email missing a string at send time → English + defect alert (WF-EXC-09 #7).
- Legal pages (C15–C18) in L≠ja show at top `legal.ja_authoritative` (BR-I18N-03, wording ⟦counsel⟧).

## 5. Language switcher, suggestion bar, form carry-over (F-I18N-02)

### 5.1 Switcher (FR-I18N-002; `template-parts/i18n/switcher.php`)

| Aspect | Rule |
|---|---|
| Placement | Header (desktop/tablet/mobile header: globe + current native name + chevron), mobile menu panel (2-column grid of 48 px buttons), footer plain list |
| Options | Enabled languages only, fixed order, native names, no flags; each `<a lang="{code}" hreflang="{code}" dir="{dir}">`; current `aria-current="true"` (emerald fill + check) |
| Markup | `<button aria-expanded aria-controls>` + `<ul>` of links; Enter/Space open, Esc closes and returns focus to the button; target ≥ 44 px; list aligned `inset-inline-end` |
| Target resolution (server-side, per option) | 1) current singular entity (page/branch/dish/category/news) visible in target → its URL with target prefix; 2) else target-language home + `?notice=untranslated`; 3) on 404 → target home (no notice) `[Prop]` |
| Query kept | `branch`, `diet`, `spice` only (meaningful selection/filter params); every other param dropped |
| Status page C11 | Client JS re-attaches the token from sessionStorage as `#t=` fragment of the target URL (never a query parameter — DEC-058) |
| Reservation form C09 | §5.3 |
| Click | push `select_language {from_language, to_language}` (beacon), set `momo_lang_choice={to}`, then navigate |
| Notice | Target home with `?notice=untranslated` (accept legacy `?momo_notice=missing_translation` as alias) shows dismissible `lang.not_available` with `{language}` = target native name; URL is `noindex`, not cached separately from canonical (query variant) |

### 5.2 Suggestion bar (FR-I18N-003; `assets/js/lang-suggest.js`, cache-safe)

Algorithm (client-side only; server never redirects on Accept-Language, IP or device — AC-I18N-02-03, crawler test SEO-M07):
1. If cookie `momo_lang_choice` exists → stop.
2. For each tag in `navigator.languages` (in order): primary subtag lower-cased (`ar-EG`→`ar`, `en-US`→`en`, `ur-PK`→`ur`); the first one contained in the embedded enabled-language list is the **best match**; none → stop.
3. If best match = page language → stop (no cookie written).
4. Render the bar above the header: `role="region"`, `aria-label` = `lang.suggest` text, own `lang`/`dir` = suggested language; text `lang.suggest` in the suggested language; button `lang.switch`; close button (×) with accessible name `lang.dismiss`. Announced politely (PRD FR-I18N-003(d); see §21 C-6). It never obscures focus, the header CTA, form error summaries or the sticky bar.
5. Switch → `momo_lang_choice={suggested}`; navigate to that language's equivalent URL (same resolution as the switcher; URL embedded per language in a `data-momo-alternates` JSON built server-side). Dismiss → `momo_lang_choice={page language}`; hide bar.
Data embedded in every page (cached HTML): `{code: {url, suggest, switch, dismiss, native, dir}}` for enabled languages only.

### 5.3 Reservation form carry-over (FR-I18N-013, S; `reservation-form.js`)

- On every field change: write `momo_form_{idempotency_key}` = {branch, date, time, party_size, name, email, phone, special_request, preferred_lang (+ flag `preferred_lang_manual`)} and `momo_form_current` = key. Never consent, never captcha token.
- On load of the form in any language: if `momo_form_current` exists → restore values, keep the same `idempotency_key`; consent unticked; `preferred_lang` follows the new UI language unless `preferred_lang_manual`.
- After API-02 201: delete both keys (no restore after submission — US-I18N-008 AC3).
- sessionStorage unavailable (probe write throws) → clicking a switcher option opens confirm dialog `lang.form_clear.confirm` with `lang.form_clear.continue` / `lang.form_clear.stay` (Stay keeps focus on the switcher).

## 6. Translation workflow, gaps view, LCCS, language gate (F-I18N-03)

### 6.1 Workflow (FR-I18N-007, DEC-027)

1. JA source published/updated → item appears in gaps view for every target language lacking an approved, current translation (age 0).
2. Sender (HO/MM/CE) clicks "Send to translation" (sets `_momos_i18n_job_{lang}` priority normal/urgent) — optional; translators may work directly from their queue.
3. Translator (ROLE-05, languages in `momo_translator_langs`) opens the stand-alone editor `admin.php?page=momos-translate&type=post|term|option|strings&id=…&lang=…`: JA source shown read-only beside each translatable field; copy fields (prices, SKU, codes, allergen states, coordinates, hours) are **not editable** (read-only display at most). Saves as `draft`; "Submit for review" → `needs_review`.
4. Reviewer (`momo_approve_translations`) approves → `approved` (+ srchash, approved_by) → public immediately (cache purge of the entity's URLs in that language) or returns → `draft` with a comment (stored in `_momos_i18n_job_{lang}.comment` `[Prop]`).
5. JA source changes later → `needs_update` in gaps; the approved translation stays public until re-approved (copy fields are already current).

Machine translation: **not integrated in Phase 1 build** (out of scope §20). If ever added, it may only fill `draft`, receives content only (never reservation/notification/user/contact data — BR-PRV-04, AC-I18N-03-06), and claim/legal/reservation/email text requires human translation or native review (BR-DIET-05).

### 6.2 Review rules

| Content class | Rule |
|---|---|
| Claim text (halal statement & alcohol templates, allergen/dietary labels, disclaimers), legal pages (`momo_page_type=legal`), reservation strings (`res.*`, `status.*`), email strings (`email.*`) | Reviewer must differ from `_momos_i18n_translated_by_{lang}` (translator ≠ reviewer) → else refuse: "You cannot approve your own translation of this content." `[Prop copy]`; statement templates additionally need CAP-DCA meaning check before activation (Dietary spec) |
| Other content | Any `momo_approve_translations` holder (reviewer ≠ translator recommended) |
| String groups (`momos_strings_review`) | Approving a group stamps hash of the reviewed values; any later change to the catalog file or overrides → group `needs_review` |

### 6.3 Gaps view SCR-A11 (FR-I18N-008; `Kernel\I18n\TranslationGaps`)

- Items: published JA pages; published non-test `momo_branch` (not `closed_permanently`); published, non-retired, non-sample `momo_dish`; `momo_menu_category`, `momo_dietary_tag`, `momo_allergen`, `momo_ingredient` terms; published `momo_notice` not yet ended; `momo_offer` publish/pending not yet ended; news posts (if enabled); brand option texts; active halal statement/alcohol templates; each string group (catalog file).
- Languages: every target language, **enabled and hidden**.
- Columns: type · Japanese title (link to translate for that language) · language · state (`missing` / `in_progress` / `needs_update`) · age in days (JST calendar days since JA publish for missing/in progress; since JA last change for needs update) · LCCS (yes/no) · job priority.
- Filters: language, type, LCCS only; default sort: active untranslated notices and offers first, then age desc. Counts per language; **LCCS completeness % per language** = `100 × (LCCS items with approved & current translation) / (LCCS items)`, rounded down to an integer (100 only when 0 gaps).
- Header summary per language: "{native} — LCCS {p} % ({n} gaps)"; empty → `No gaps for {language}`.
- CSV download (HO, SA): same columns, no PII. Dashboard widget: gaps per language (MM, CE, HO). Translators see the same view filtered to their languages ("My queue").
- Performance target: ≤ 2 s for 500 items × 5 languages `[Prop]` (computed from meta with one query per entity type; request-cached).

### 6.4 LCCS definition (Doc 06 §4.6; `lccs = true`)

| # | LCCS item | Source in this build |
|---|---|---|
| 1 | Header/footer/nav/CTA/breadcrumb labels | string groups `nav`, `cta`, `ftr`, `bc`, `lang`, `skip`, `common` |
| 2 | Home page | page with `momo_lccs=1` (slug `home`) |
| 3 | Branch directory + every published non-test branch (title, description, area, address lines, access) | page `branches` + `momo_branch` |
| 4 | Menu hub + every category | page `menu` + `momo_menu_category` |
| 5 | Every published dish (title, short/long description) | `momo_dish` |
| 6 | Dietary tag + allergen labels, legend, disclaimer | `momo_dietary_tag`, `momo_allergen` terms + `diet` strings |
| 7 | Halal page + all active statement/alcohol templates | page `halal` + option templates |
| 8 | Reservation form/submitted/status pages, validation & error messages, status/reason labels | pages `reservation`, `submitted`, `status` + `res`, `status` strings |
| 9 | Customer emails E01–E06 (subjects + bodies, HTML + text) | `email` strings |
| 10 | Privacy Policy, Reservation Policy, External Transmission page | pages `privacy-policy`, `reservation-policy`, `external-transmission` |
| 11 | 404 page | `404` strings |
| 12 | Enum labels (closure/availability/decline/cancel reasons, facility values) | `branch`, `menu`, `res` enum strings |
| 13 | "Image for illustration" label (if any illustrative image is live) | `media` string |

Not LCCS (but hidden per language until approved): offers, notices, About, FAQ, Contact, Terms, Accessibility, Gallery captions, News, ingredients.

### 6.5 Language gate (FR-I18N-009; `Kernel\I18n\LanguageGate`)

| Step | Rule |
|---|---|
| Who | `momo_manage_languages` (SA, HO). Others → 403 + audit `access.denied` (TC-I18N-019 step 1) |
| Enable L | Refuse if `TranslationGaps::lccsGaps(L) > 0` → `Cannot enable {language}: {n} launch-critical items are missing or unreviewed.` + list of missing items; refuse if `lccs_signoff_by` or `lccs_signoff_at` empty → `Record the native-review sign-off (name and date) before enabling {language}.` `[Prop copy]` |
| On enable | add L to `momos_languages_enabled`; update `momos_language_gate[L]` (state, changed_by/at); purge all cached URLs (`momo-global`); audit `language.enabled` `{lang, lccs_percent, signoff_by, signoff_at, signoff_ref}` |
| Disable L | Always allowed; confirmation "Hiding a live language removes it from search results and links. Continue?" `[Prop copy]` (WF-ADM-19 A2: Owner decision); remove from enabled; purge `momo-global`; audit `language.disabled`. Effect: switcher, hreflang, sitemaps, public access gone; `/{L}/…` → 404 within 5 min (AC-I18N-03-04) |
| Concurrency | Read-modify-write under named lock `momos_language_gate`; one language per request |
| Not via settings import | `momos_languages_enabled` is never written by any other code path (CI grep test) |

GATE-04 evidence outside the system: reviewer sign-off sheet, mobile journey recordings, RTL checks (ar/ur), hreflang/sitemap checks.

## 7. Bilingual admin (F-I18N-04, DEC-037)

- Profile "Language" offers only 日本語 (`ja`, default) and English (`en_US`); HO sets it at user creation. Every momos-core admin string uses `__()` / `_x()` / `_n()` with text domain `momos-core`, English msgids, complete `languages/momos-core-ja.po/.mo` (CI: 0 empty msgstr — AC-I18N-04-02). Includes menus, titles, labels, help, notices, errors, status/reason labels, CSV headers.
- Admin dates/times in JST, ISO dates in tables; detail views long form in user's language.
- Staff emails rendered with `Locale::with()` in the recipient's language: WP users → user locale (ja/en); non-user recipients → recipient row `language` (default `ja`) ⟦Q-416⟧.
- Admin is always LTR (admin languages are LTR); RTL user data rendered `dir="auto"`. Third-party/core screens fall back to vendor translations (accepted).

## 8. Complete customer URL map (DEC-003, Doc 06 §1.2, TRD §2.4)

Every row exists in each language: `{P}` = '' (ja) or `/en` `/ar` `/ur` `/hi` `/bn` (enabled only). Same Latin slug in every language.

| # | Page (SCR) | Path | Index | Sitemap | Breadcrumb | JSON-LD | `page_type` / GTM |
|---|---|---|---|---|---|---|---|
| 1 | Home (C01) | `{P}/` | yes | yes | — | Organization, WebSite | `home` |
| 2 | About (C02) | `{P}/about/` | yes | yes | Home › About | BreadcrumbList | `about` |
| 3 | Halal information (C03) | `{P}/halal/` | yes | yes | Home › Halal information | BreadcrumbList | `halal` |
| 4 | Branch directory (C04) | `{P}/branches/` | yes | yes | Home › Branches | BreadcrumbList | `branch_directory` |
| 5 | Branch detail (C05) | `{P}/branches/{branch-slug}/` | yes (not TEST) | published, non-test, not closed | Home › Branches › {Branch} | Restaurant, BreadcrumbList | `branch` |
| 6 | Menu hub (C06) | `{P}/menu/` (`?branch=`, `?diet=`, `?spice=` → noindex, canonical clean) | yes | yes | Home › Menu | Menu, BreadcrumbList | `menu_hub` |
| 7 | Menu category (C07) | `{P}/menu/{category-slug}/` | yes | yes | Home › Menu › {Category} | MenuSection, BreadcrumbList | `menu_category` |
| 8 | Dish (C08) | `{P}/menu/{primary-category}/{dish-slug}/` (other category → 301 here) | yes | yes (published, not retired/sample) | Home › Menu › {Category} › {Dish} | MenuItem+Offer, BreadcrumbList | `dish` |
| 9 | Reservation form (C09) | `{P}/reservation/` (`?branch={code}` prefill → noindex) | yes | yes | — | — | `reservation` |
| 10 | Request submitted (C10) | `{P}/reservation/submitted/` | **no** (`noindex` + `X-Robots-Tag`) | no | — | — | **no GTM**, `Referrer-Policy: no-referrer` |
| 11 | Request status (C11) | `{P}/reservation/status/{REF}/#t={token}` (REF `^[0-9A-HJKMNP-TV-Z]{8}$`) | **no**, `no-store` | no | — | — | **no GTM**, no-referrer |
| 12 | Contact (C12, S) | `{P}/contact/` | yes | yes | Home › Contact | BreadcrumbList | `contact` |
| 13 | FAQ (C13, S) | `{P}/faq/` | yes | yes | Home › FAQ | BreadcrumbList (+FAQPage C) | `faq` |
| 14 | Offers (C21) | `{P}/offers/` | yes while ≥ 1 active offer in that language; else `noindex` | only while active | Home › Offers | BreadcrumbList | `offers` |
| 15 | Gallery (C22, C) | `{P}/gallery/` | yes | yes | Home › Gallery | BreadcrumbList | `gallery` |
| 16 | News list / item (C14, C) | `{P}/news/`, `{P}/news/{slug}/` | yes while live | yes | Home › News › {Title} | BreadcrumbList | `news` |
| 17 | Privacy Policy (C15) | `{P}/privacy-policy/` | yes | yes | Home › Privacy Policy | BreadcrumbList | `legal` |
| 18 | Terms of Use (C16) | `{P}/terms/` | yes | yes | Home › Terms of Use | BreadcrumbList | `legal` |
| 19 | Reservation Policy (C16) | `{P}/reservation-policy/` | yes | yes | Home › Reservation Policy | BreadcrumbList | `legal` |
| 20 | External Transmission & Cookies (C17) | `{P}/external-transmission/` (`#analytics-settings` anchor) | yes | yes | Home › External Transmission & Cookies | BreadcrumbList | `legal` |
| 21 | Accessibility (C18) | `{P}/accessibility/` | yes | yes | Home › Accessibility | BreadcrumbList | `legal` |
| 22 | 404 (C19) | any unknown URL (HTTP 404) | no | no | — | — | `error_404` |
| 23 | Maintenance (C20) | all URLs during planned work: static HTML, 503 + `Retry-After`, all enabled languages each with own `lang`/`dir` | no | no | — | — | none |
| — | TEST branch | `/branches/{test-slug}/` | 404 to non-staff; staff view `noindex` | never | — | never | **no GTM**, no events |
| — | Untranslated-notice home | `{P}/?notice=untranslated` | noindex | no | — | — | `home` |

System URLs: `/sitemap.xml` (index), `/sitemaps/{lang}-{type}.xml` (`type` ∈ `pages, branches, menu-categories, dishes, news`), `/robots.txt`, `/wp-sitemap.xml` → 301 `/sitemap.xml` (core sitemaps disabled), `/wp-json/momos/v1/*` (never linked publicly), `/wp-admin/`, `/wp-login.php` (not linked). Not existing (404 or 301): `/ja/…` (no ja prefix), offer detail pages, taxonomy archives of ingredients/tags/allergens, search `?s=` (→ 301 home, DEC-045), author archives & `?author=N` (→ 301 home), attachment pages (→ 301 parent or home), date/tag archives (→ 404 `[Prop]`), core feeds (→ 404 `[Prop]`).

### 8.1 URL rules (FR-SEO-001, Doc 06 §1.5)

1. ASCII `[a-z0-9-]` slugs, lowercase, trailing slash, HTTPS. `SlugPolicy` copies the JA slug (shared post/term) and rejects non-ASCII slugs on save (FR-I18N-012): `Kernel\I18n\SlugPolicy` for dishes, offers, notices, pages and news posts: accents are transliterated (WordPress `remove_accents`); a typed non-Latin slug is rejected with an admin error / REST 400 (previous slug kept, or a first publication saved as draft); a title with no Latin letters gets a neutral unique slug (`dish`, `page-2`, `offer-3` …) plus an admin notice asking for a descriptive one. Categories, branches and FAQ ids keep their module guards.
2. Normalisation 301 (single hop, combined): uppercase path or missing trailing slash → lowercase + slash, language prefix preserved (`/Branches` → `/branches/`; `/EN/menu` → `/en/menu/`; `/en` → `/en/`). Exempt: files (`*.xml`, `*.txt`, assets), `/wp-json/`, `/wp-admin/`, `wp-login.php`, query strings (never lowercased), and `/reservation/status/{REF}/` (REF is upper-case Crockford; path accepted case-insensitively and normalised client-side — §21 C-8).
3. Max 3 path segments below the language prefix; every indexable page ≤ 3 clicks from its language home (FR-SEO-009).
4. Query parameters: allowed `branch`, `diet`, `spice` (+ `notice`); all canonicalised away; never PII (tokens only in `#t=`).
5. Automatic 301s: retired dish → its primary category; dish under non-primary category → canonical dish URL; `closed_permanently` branch → `/{P}/branches/`; post slug change → new slug (core `_wp_old_slug`); category slug change → new slug (`momo_old_slugs`); expired news → `/{P}/news/`. Language prefix always kept.

## 9. SEO head output (F-SEO-01; `Seo\Head` at `wp_head` priority 1; core `<title>` via `pre_get_document_title`)

### 9.1 Title, description, Open Graph (FR-SEO-005)

Resolution in page language L (never another language's text):
1. Title: `momo_seo_title` (ja) / `_momos_i18n_L.seo_title` if approved and non-empty → else template by page type:

| Page type | Template key | EN | JA (draft ⟦native/SEO review⟧) |
|---|---|---|---|
| default | `seo.title.default` | `{title} \| {brand}` | `{title}｜{brand}` |
| home | `seo.title.home` | `{brand}` | `{brand}` |
| branch (area set) | `seo.title.branch` | `{branch} – Halal restaurant near {area} \| {brand}` (PRD FR-SEO-005) | `{branch}｜{area}のハラールレストラン｜{brand}` |
| branch (no area) | `seo.title.branch_no_area` | `{branch} \| {brand}` | `{branch}｜{brand}` |
| dish | `seo.title.dish` | `{dish} – {category} \| {brand}` `[Prop]` | `{dish}（{category}）｜{brand}` |

`{brand}` = string `seo.brand_short` (EN "Momo's" per PRD template ⟦brand confirm⟧). Titles unique per page and language (TK-13-11).
2. Description: SEO field → else entity excerpt/short description in L (tags stripped, ≤ 160 chars, ≤ 80 full-width ja, cut at word/sentence boundary) → else omit the tag (never empty, never JA on non-ja pages).
3. OG: `og:title`, `og:description`, `og:image` (+ `og:image:alt` from translated alt text), `og:url` (= canonical), `og:type` `website`, `og:site_name` = trading name in L, `og:locale` (`ja_JP, en_US, ar_AR, ur_PK, hi_IN, bn_BD` `[Prop]`) and `og:locale:alternate` for each hreflang alternate. No Twitter-specific tags required.
4. Admin length guidance shown live in the SEO box: "Title is longer than recommended ({n}/{max})" (warning, not blocking).

### 9.2 Canonical (FR-SEO-003(a))

`<link rel="canonical">` on every indexable page = absolute URL of the page in **its own language**, clean path, no query, lowercase, trailing slash (never cross-language, never to ja). Query variants canonicalise to the clean URL. No canonical on 404, C10, C11, hidden-language previews.

### 9.3 Robots (FR-SEO-003(b), Doc 11 PRV-007; `<meta name="robots">` + header where noted)

| Condition | Directive |
|---|---|
| `/reservation/submitted/`, `/reservation/status/*` | `noindex, follow` meta **and** `X-Robots-Tag: noindex, nofollow` (one header; Doc 11 §6.4, SDD §6.11, access-security-privacy §8, IMPL-008); C10 and C11 also `Cache-Control: no-store, private` |
| Any URL with a query string (`?branch=`, `?diet=`, `?notice=`, UTM …) | `noindex, follow` |
| TEST branch page (staff only; others 404) | `noindex, nofollow` |
| `/offers/` with no active offer in L | `noindex, follow` |
| Hidden-language preview (staff) | `noindex, nofollow` meta + header |
| 404 | `noindex` |
| Staging (any non-production env) | `X-Robots-Tag: noindex, nofollow` on every response, `blog_public=0`, HTTP Basic auth at server (401 unauthenticated) |
| All other public pages | no robots meta (indexable) |

### 9.4 hreflang (FR-SEO-002, DEC-003; `Seo\HreflangFilter`)

For an **indexable** page in language L: `alternates = [(code, canonical URL in code) for code in enabled languages (order §2) if the entity is visible in code]`.
- Output only if `count(alternates) ≥ 2`: `<link rel="alternate" hreflang="{code}" href="{abs URL}">` for each (incl. self), codes exactly `ja, en, ar, ur, hi, bn`.
- `x-default` → the `en` alternate; if `en` not among alternates, omit `x-default` ⟦Q-424 — default "omit"⟧.
- Never list hidden languages, untranslated versions, query URLs or noindex pages; noindex pages print no hreflang. Reciprocity follows automatically from the shared rule (AC-SEO-01-01). HTML head only — no hreflang in sitemaps (one method, Doc 06 §1.7).
- Archive-like pages (`/branches/`, `/menu/`, `/offers/`) are Pages → their own translation status; menu category = term status; `/offers/` additionally needs ≥ 1 active offer in that language.

### 9.5 XML sitemaps (FR-SEO-004; `Seo\SitemapController`)

- `/sitemap.xml`: `<sitemapindex>` of `/sitemaps/{lang}-{type}.xml` for each **enabled** language and each non-empty type. Each child ≤ 1,000 URLs (split `-2` if exceeded). `<url><loc>` absolute + `<lastmod>` (W3C UTC: max of JA modified and `_momos_i18n_updated_L`). `Content-Type: application/xml; charset=UTF-8`; `X-Robots-Tag: noindex`; cached, purged with content changes.
- Included: indexable published URLs visible in L: pages (except submitted, status, offers-while-empty), branches (published, non-test, status ≠ `closed_permanently`), categories, dishes (published, not retired, `momo_is_sample = 0`), news (if enabled).
- Excluded: hidden languages, noindex URLs, TEST branch, `momo_offer`/`momo_notice`/`momo_certificate`, retired dishes, permanently closed branches, untranslated items, attachments, non-public taxonomies, drafts, reservations.
- All listed URLs must return 200 (AC-SEO-01-03).

### 9.6 robots.txt (FR-SEO-009; `robots_txt` filter)

Production:
```
User-agent: *
Disallow: /wp-admin/
Allow: /wp-admin/admin-ajax.php

Sitemap: https://{host}/sitemap.xml
```
Never block CSS/JS/fonts/uploads. Non-production: `User-agent: *` / `Disallow: /` (plus header + Basic auth above).

### 9.7 Breadcrumbs (FR-SEO-007; `Seo\Breadcrumbs::items(): list<{name,url}>` — single source for HTML and JSON-LD)

- Shown on branch directory/detail, menu hub/category, dish, halal, legal pages, and (Rec) FAQ, About, Contact, offers, gallery, news; never on home or C09/C10/C11.
- Markup: `<nav aria-label="{a11y.breadcrumb}"><ol>…</ol></nav>`; current item not a link, `aria-current="page"`; 14 px muted, current accent; separator by CSS (`›` LTR, mirrored in RTL).
- Names in page language (`bc.home`, `nav.*`, entity titles); dish uses its primary category; URLs localised.

## 10. Structured data (FR-SEO-006, BR-SEO-01; `Presentation\Schema\JsonLdBuilder`)

One `<script type="application/ld+json">` per page with `{"@context":"https://schema.org","@graph":[…]}`; filter `momos/schema/graph (array $graph, string $context)`. All text in page language and **identical to visible content**. Never emitted: `aggregateRating`, `review`, offer/promotion markup for `momo_offer`, anything for TEST/hidden/closed_permanently branches, placeholders for unknown values (omit the property).

| Node (where) | Properties (exact) |
|---|---|
| Organization (home) | `@type: Organization`, `@id: https://{host}/#organization` (same in all languages), `name` = trading name (L), `legalName` = `momo_legal_operator_name` (only when supplied), `url` = home URL in L, `logo` = `ImageObject{url, width, height}` from `momo_logo_primary`, `sameAs` = brand social URLs (https) |
| WebSite (home) | `@type: WebSite`, `@id: https://{host}/#website`, `url` (home in L), `name`, `inLanguage` (L), `publisher: {@id …#organization}`; no `SearchAction` (DEC-045) |
| Restaurant (branch page) | `@type: Restaurant`, `@id: {branch URL in L}#restaurant`, `name`, `address: PostalAddress{streetAddress, addressLocality, addressRegion, postalCode, addressCountry: "JP"}` (ja: Japanese parts `momo_address_line1`+`_line2`, `momo_city`, `momo_prefecture`; other L: approved translated lines), `geo: GeoCoordinates{latitude, longitude}` (≥ 5 decimals as stored), `telephone` (E.164 `momo_phone_e164`), `url` (branch URL in L), `image` (featured + gallery URLs), `menu` (menu hub URL in L), `acceptsReservations: true` only when `momo_res_enabled = 1` and status `open` (else omit), `servesCuisine` ⟦Q-415 — omit until supplied⟧, `priceRange` ⟦Q-415, < 100 chars — omit until supplied⟧, `parentOrganization: {@id …#organization}`, `openingHoursSpecification` (below) |
| Menu (menu hub) | `@type: Menu`, `@id: {menu URL}#menu`, `name`, `url`, `inLanguage`, `hasMenuSection: [{@type: MenuSection, name, url}]` (visible categories) |
| MenuSection (category) | `@type: MenuSection`, `name`, `description` (if shown), `url`, `hasMenuItem: [{@type: MenuItem, name, url}]` |
| MenuItem (dish) | `@type: MenuItem`, `name`, `description` (as shown), `image`, `url`, `offers: {@type: Offer, price: "{reference price integer}", priceCurrency: "JPY"}` (reference price only; branch prices not marked up), `suitableForDiet: "https://schema.org/HalalDiet"` **only** with an active `certified_item` claim (vegetarian/vegan never in Phase 1) |
| BreadcrumbList (pages with breadcrumbs) | `itemListElement: [{@type: ListItem, position: 1…n, name, item: abs URL}]` = §9.7 items in order |
| FAQPage (C, FAQ page) | `mainEntity: [{@type: Question, name, acceptedAnswer: {@type: Answer, text}}]` — only when every marked-up Q&A is visible verbatim (FR-CORP-004(c)); off by default |

`openingHoursSpecification` (Doc 04 FR-SEO-006(b), AC-SEO-02-02) — built from `ScheduleProvider` (branch spec), JST:
1. Regular: one entry per weekly period `{@type: OpeningHoursSpecification, dayOfWeek: "https://schema.org/{Monday…Sunday}", opens: "HH:MM", closes: "HH:MM"}`; weekday 1…7 per the branch spec mapping (ISO, 1 = Monday ⟦confirm in branch spec⟧); cross-midnight (`closes_next_day = 1`) → `closes` = next-day time on the opening day's entry (e.g. opens 18:00, closes 02:00); periods with `valid_from/valid_until` add `validFrom/validThrough`.
2. Special dates in the next **60 days**: `closed_all_day` → `{dayOfWeek, opens: "00:00", closes: "00:00", validFrom: date, validThrough: date}`; `special_hours` → one entry per special period with `validFrom = validThrough = date`.
3. `temporarily_closed`: regular entries kept; each date up to `momo_reopen_date` within the 60-day window emitted as closed (step 2) `[Prop]`.
4. Values must equal the visible hours list; re-generated after cache purge (AC-SEO-02-04).

## 11. Legacy redirects & 404 log (F-SEO-03, DEC-039; `Seo\Redirects`, `Seo\NotFoundLog`)

| Topic | Rule |
|---|---|
| Source of truth | Versioned CSV in Git `config/seo/legacy-redirects.csv`: `old_url,new_url,status,owner,verified` (status always 301). Built from the pre-launch crawl of the legacy site (HTML pages, externally linked images, PDFs); unmatched URLs map to nearest parent (home, `/menu/`, `/branches/`) — FR-SEO-008(a) |
| Import | `wp momos seo redirects import <csv>` → `momos_redirects` (source `legacy`); validation refuses: target = source, target that is itself a source (chain), loops, targets outside the site, targets that do not return 200 (`--verify` makes HTTP HEAD checks) |
| Matching | `parse_request` priority 0, anonymous + logged-in: normalised request path (+ query when the entry has one) matched case-insensitively with/without trailing slash → `wp_redirect(target, 301)`; single hop; `Cache-Control: public, max-age=3600`. http↔https and www↔non-www handled at server/CDN in the same single hop |
| Manual redirects | SEO → Redirects screen (§14.3) for SA/HO; same validation; source `manual` |
| 404 logging | On every 404 (public): upsert `{path (query stripped except `branch`), lang, referrer_host, hits++, first_seen_utc, last_seen_utc}`; **no IP, no UA, no full referrer, never `email=` etc.** (FR-CORP-007(b)); ring buffer drops least-recently-seen rows beyond ⟦1,000⟧ |
| Weekly review | For 8 weeks after launch HO/SA record a review (`momos_404_reviews`: week, reviewer, actions) from the 404 screen; Search Console domain property verified and sitemaps submitted (GATE-09, ops) |

## 12. Analytics (F-ANL-01/02, DEC-030, DEC-066; Doc 11 §4.6)

### 12.1 Where tags load (`Presentation\Analytics\TagLoader`)

Server decides whether the page may carry analytics at all (cache-safe, same for every anonymous visitor):
- **Yes:** public front-end pages in enabled languages when `MOMOS_GTM_ID` is defined.
- **Never:** wp-admin, wp-login, `/reservation/submitted/`, `/reservation/status/*` (DEC-066 — no third-party script at all), TEST branch pages and any page with test-branch context, hidden-language previews, logged-in users `[Prop]`, non-production without an explicit container ID, maintenance page. No `<noscript>` GTM iframe anywhere (it would bypass opt-out/GPC).

Client decides per visitor (inline first-party loader, before GTM):
```js
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
var optedOut = /(?:^|; )momo_analytics_optout=1/.test(document.cookie) || navigator.globalPrivacyControl === true;
gtag('consent','default',{analytics_storage: optedOut?'denied':'granted', ad_storage:'denied',
  ad_user_data:'denied', ad_personalization:'denied', functionality_storage:'granted', security_storage:'granted'});
dataLayer.push({'gtm.blocklist':['customScripts','nonGoogleScripts','nonGooglePixels','nonGoogleIframes']});
dataLayer.push({event:'momo_page', page_type:'dish', language:'ar', branch_code:null, menu_category:'biryani', dish_sku:'D0018'});
// server-known view_* event push here (§12.2)
if (!optedOut) { /* inject https://www.googletagmanager.com/gtm.js?id=MOMOS_GTM_ID async */ }
```
GPC header `Sec-GPC: 1` is honoured through `navigator.globalPrivacyControl` (page cache prevents server-side branching). CSP allow-list for Google tag domains is owned by the security spec (Doc 11 §6.4).

### 12.2 Event contract (dataLayer; exact names; **no other parameters allowed**)

| Event | Trigger | Pushed by | Parameters |
|---|---|---|---|
| `view_branch` | branch page load | inline (server) | `branch_code`, `language` |
| `view_menu` | menu hub or category load | inline | `menu_category` (category slug, or `hub` on the hub), `branch_code` (if `?branch=` selected, else omitted), `language` |
| `view_dish` | dish page load | inline | `dish_sku`, `menu_category` (primary), `language` |
| `view_halal_information` | halal page load, or halal FAQ item expanded (once per expand) | inline / JS | `language`, `source_page` (`halal` \| `faq`) — see §21 C-3 |
| `select_language` | switcher option activated (header, mobile menu, footer list) | `datalayer.js`, beacon, before navigation | `from_language`, `to_language` |
| `click_reservation` | any "Request a table" CTA incl. offer CTA | delegated click | `cta_location`, `branch_code` (if known), `language` |
| `reservation_form_start` | first interaction with any form field, once per page load | form JS | `branch_code` (if chosen), `language` |
| `generate_lead` (**key event**) | API-02 returned **201 with `duplicate:false`** — never on click, validation error, 4xx/5xx, 200 replay, soft duplicate; pushed on the **form page** before navigating to C10 | form JS; `eventCallback` → navigate, or after ≤ 500 ms timeout | `branch_code`, `language`, `party_size_band`, `lead_source` = `reservation_form` |
| `click_phone` (**key event**) | click on `tel:` link | delegated | `branch_code`, `cta_location`, `language` |
| `click_directions` (**key event**) | Directions / "Open in Google Maps" / "Show map" | delegated | `branch_code`, `cta_location`, `language` |

Parameter dictionary: `language` ∈ six codes · `branch_code` `^[A-Z]{3}$` (never the test branch) · `menu_category` `^[a-z0-9-]{1,60}$` or `hub` · `dish_sku` `^D\d{4}$` · `cta_location` enum §3.5 · `party_size_band` `1-2`/`3-6`/`7+` · `from_language`/`to_language` codes · `source_page` page_type value · `lead_source` constant · `page_type` (only in `momo_page`).

Implementation: tracked elements carry `data-momo-event`, `data-momo-cta`, `data-momo-branch` (rendered by templates/widgets; never on test-branch content). `assets/js/datalayer.js` (≤ 3 KB, no deps) attaches one delegated listener and exposes `momoPush(event, params)` which drops unknown keys and any value failing its regex (allow-list test, TK-13-08). **Forbidden in any push:** names, emails, phones, references, tokens, free text, IP, URLs with query. Event names `[a-z0-9_]` ≤ 40 chars. Business outcomes (accepted/declined/expired/no-show) are never sent to GA4; Measurement Protocol not used (FR-ANL-003).

### 12.3 cta_location values (resolved, see §21 C-2)

| Value | Where |
|---|---|
| `header` | header "Request a table" button / header phone |
| `hero` | home hero CTA |
| `branch_card` | branch cards (home teaser, directory) — Call / Directions / Request |
| `branch_page` | branch detail in-page CTAs, map facade |
| `sticky_bar` | mobile sticky action bar |
| `menu_page` / `dish_page` | menu hub/category CTA, dish page CTA |
| `offer_card` | offer card CTA leading to the form |
| `home_section` | other home sections ("How reservations work" etc.) |
| `halal_page` | halal page CTAs |
| `footer` | footer branch phones / links |
| `reservation_page` | reservation form branch info (phone, directions) |
| `error_page` | 404 page links |

### 12.4 GA4 / GTM configuration (operations checklist; evidence screenshots, GATE-09)

Data retention **14 months** ⟦Owner + counsel; 2 months if minimisation preferred⟧ · reset on new activity off · Google Signals off · ads personalisation off (all regions) · no Ads/product links · data sharing off · User-ID and user-provided data off · redaction: email on; query params `t, token, email, phone, name, ref` · enhanced measurement: page views, scrolls, outbound clicks on; site search, form interactions, video off · key events `generate_lead`, `click_phone`, `click_directions` · GTM: one container, ≤ 2 named publishers with 2-step verification, second-publisher review, no advertising pixels (Meta, LINE Tag, Google Ads), GA4 tags require `analytics_storage`. GA4 read access ROLE-01…04 via admin menu link "Analytics ↗" (external, new tab).

### 12.5 External Transmission & Cookies page + opt-out (FR-ANL-002, SCR-C17)

- Page `external-transmission` (legal page, LCCS, footer link on every page `ftr.ext_trans`; footer also `ftr.analytics` → `#analytics-settings`). Content is editorial but must equal the quarterly tag audit (PRV-021); table columns: service · provider (country) · pages · information sent · purpose · how to opt out. Expected Phase 1 rows (Doc 11 §4.5): GTM (Google LLC, US); GA4 (`_ga`, `_ga_<ID>`); Cloudflare Turnstile (C09, C12 only); CDN/WAF ⟦provider TBD⟧; first-party cookies `momo_lang_choice`, `momo_analytics_optout` (+ staff login cookies); Google Maps — nothing on load (link/facade, after click only); "not used": Meta Pixel, LINE Tag, Google Ads, YouTube/social embeds, Google Fonts. Any new tag requires this page updated before go-live (6 languages).
- Opt-out control `[momos_analytics_optout]` inside `<section id="analytics-settings">`: `<button aria-pressed="true|false">` labelled `anl.optout.label` with visible text state; status message `role="status"`.

| Action | Behaviour |
|---|---|
| Load | pressed = !(`momo_analytics_optout=1` or GPC); with GPC on: shows off + note `anl.optout.gpc`; button still toggles cookie but GTM stays off while GPC = true |
| Turn off | set `momo_analytics_optout=1` (1 y, Secure, Lax); delete `_ga`, `_ga_*` for host and parent domain (Path=/); `gtag('consent','update',{analytics_storage:'denied'})`; status `anl.optout.off_done`; no reload, no console errors; next pages: GTM not injected (0 requests to `*.googletagmanager.com`, `*.google-analytics.com`, `*.analytics.google.com`) |
| Turn on | delete `momo_analytics_optout`; `gtag('consent','update',{analytics_storage:'granted'})`; inject GTM if not loaded; status `anl.optout.on_done` |
| No JS | text explains that analytics is blocked by disabling JavaScript (`anl.optout.nojs` `[Prop]`) |

No consent banner in Phase 1 (DEC-030; EEA/UK regional banner ⟦counsel Q-037⟧).

## 13. Permissions (ROLE-01 SA … ROLE-07 RS + CAP-DCA)

| Capability / action | SA (01) | HO (02) | MM (03) | CE (04) | TR (05) | BM (06) | RS (07) | CAP-DCA |
|---|---|---|---|---|---|---|---|---|
| `momo_manage_languages` (enable/disable, sign-off) | Y | Y | — | — | — | — | — | — |
| `momo_translate` (translation editor, gaps view; TR filtered to own languages) | Y | Y | Y | Y | Y | — | — | — |
| `momo_approve_translations` `[IMPL]` (approve; strings groups) | Y | Y | — | — | Y (designated reviewers) | — | — | statement-template meaning check |
| `momo_preview_languages` `[IMPL]` (view hidden languages) | Y | Y | — | — | Y | — | — | — |
| Send translation request / CSV gaps export | Y | Y | send | send | — | — | — | — |
| Edit JA source / copy fields | per module caps | per module | per module | per module | **never** | per module | — | claim fields |
| SEO meta (JA) — `momo_edit_seo_meta` `[IMPL]` + edit right on the object | Y | Y | Y (dishes, categories) | Y (pages, branch description/SEO) | translated SEO keys via editor | — | — | — |
| `momo_manage_redirects` `[IMPL]` (redirects, 404 log, weekly review) | Y | Y | — | — | — | — | — | — |
| SEO technical settings (robots/sitemap rules, GTM ID) | code/env (SA) | — | — | — | — | — | — | — |
| Publish External Transmission/legal pages (`momo_publish_legal_pages`) | Y | Y | — | draft | — | — | — | — |
| GA4 read (external) / GTM publish | owner / ≤ 2 named publishers | read | read | read | — | — | — | — |
| Own admin language (profile) | Y | Y (+ set for others at creation) | Y | Y | Y | Y | Y | — |

Every handler checks capability server-side + nonce; denial → 403 + `access.denied` audit (menus hidden is cosmetic only).

## 14. Screens

### 14.1 Customer components

| Component | Shows | States / interactions |
|---|---|---|
| Header switcher | globe + current native name | closed/open; current marked; hidden languages absent; keyboard + SR operable; RTL list aligned to inline-end |
| Mobile menu language list | all enabled native names (48 px grid) | inside modal panel (focus trap, Esc closes, focus returns) |
| Footer language list | plain links, enabled only | — |
| Suggestion bar | `lang.suggest` + Switch + × in suggested language | hidden (cookie / no match / same language); shown; dismissed |
| Untranslated notice | `lang.not_available` dismissible notice on target home | only with `?notice=untranslated` |
| Breadcrumbs | §9.7 | — |
| C17 opt-out block | toggle + state text + status | on / off / GPC-off / no-JS text; applies without reload |
| C19 404 | H1 `404.title`, one sentence `404.body`, links Home · Branches · Menu · Request a table | HTTP 404, `noindex`, language from URL prefix (hidden-language prefix → ja 404 `[Prop]`), path logged |
| Hidden-language preview banner (staff) | "Preview — {language} is hidden from the public." | only for `momo_preview_languages` |

### 14.2 SCR-A11 Translation (admin "Momo's → Translations")

| Element | Spec |
|---|---|
| Gaps view | §6.3; WP list table; sortable headers are buttons with `aria-sort`; per-language status badges with text (Missing / In progress / Needs update / Published); "Send to translation" and "Translate" links; usable at 360 px (horizontal scroll inside table container) |
| Translator queue | same table, filtered to `momo_translator_langs`, statuses missing/draft/needs_update first |
| Editor | §6.1; JA source read-only beside each field; RTL target fields `dir` per language; buttons "Save draft", "Submit for review", reviewer "Approve" / "Return to translator"; outdated banner "Source changed since approval" |
| String groups | per language & group: key, EN reference, JA, target value (override), status; group "Approve" |
| States | loading (server-rendered); empty `No gaps for {language}` + LCCS 100 %; success notices `role="status"`; errors `role="alert"` |

### 14.3 SCR-A15 Settings → Languages tab (`momo_manage_languages`)

Table per target language: native + English name · state (Enabled / Hidden) · LCCS completeness % and gap count (link to filtered gaps view) · `lccs_signoff_by` (name), `lccs_signoff_at` (date picker, ≤ today), `lccs_signoff_ref` · last change (who/when) · button Enable / Hide. Validation messages §6.5. Japanese row read-only "Source language — always public". Success: "{language} is now public." / "{language} is now hidden." `[Prop copy]`.

### 14.4 SEO → Redirects & 404 log `[IMPL]` (SA/HO)

Redirects list (from, to, source, owner, verified, created) with add/edit/delete + "Verify all" (HEAD checks; marks chains/loops/non-200 in red); 404 log (path, lang, referrer host, hits, first/last seen) sorted by hits with "Create redirect" prefilled; "Record weekly review" form (week, actions). Empty states: "No redirects yet." / "No 404 errors recorded." `[Prop copy]`.

### 14.5 SEO meta box (SCR-A03 branch, A05 category, A06 dish, A14 pages)

Fields §3.2 with live length counters/warnings and a search-snippet preview in the admin language; translated SEO values edited in the Translations box/editor per language.

## 15. Strings (keys from Doc 07 §6 / Doc 06 §3.8; JA = draft, native review required)

| Key | EN | JA |
|---|---|---|
| `lang.switch` | Switch | 切り替える |
| `lang.suggest` | This page is available in {language}. (written in the suggested language) | このページは日本語でもご覧いただけます。 |
| `lang.not_available` | This page is not yet available in {language}. | このページは{language}ではまだご覧いただけません。 |
| `lang.dismiss` `[Prop]` | Dismiss | 閉じる |
| `lang.switcher.label` `[Prop]` | Language | 言語 |
| `lang.form_clear.confirm` (PRD) | Changing language will clear the form. Continue? | 言語を切り替えると入力内容が消去されます。続けますか？ `[Prop]` |
| `lang.form_clear.continue` / `.stay` (PRD) | Continue / Stay | 続ける／このページに留まる `[Prop]` |
| `bc.home` | Home | ホーム |
| `nav.menu` / `nav.branches` / `nav.halal` / `nav.about` / `nav.reservation` | Menu / Branches / Halal / About / Reservation | メニュー／店舗一覧／ハラール／私たちについて／予約リクエスト |
| `nav.offers` / `nav.faq` / `nav.contact` / `nav.gallery` / `nav.news` | Offers / FAQ / Contact / Gallery / News | お得な情報／よくある質問／お問い合わせ／ギャラリー／ニュース |
| `ftr.privacy` / `ftr.terms` / `ftr.res_policy` | Privacy Policy / Terms of Use / Reservation Policy | プライバシーポリシー／利用規約／予約ポリシー |
| `ftr.ext_trans` / `ftr.accessibility` / `ftr.analytics` | External Transmission & Cookies / Accessibility / Analytics settings | 外部送信・Cookieについて／アクセシビリティ／アクセス解析の設定 |
| `skip.main` | Skip to main content | メインコンテンツへ移動 |
| `404.title` | Page not found | ページが見つかりません |
| `404.body` `[Prop]` | The page you were looking for does not exist or has moved. | お探しのページは存在しないか、移動しました。 |
| `price.tax_incl` | (tax incl.) | （税込） |
| `common.tz_label` `[Prop key]` | JST | (not shown) |
| `legal.ja_authoritative` (BR-I18N-03) | The Japanese version of this document is authoritative. ⟦counsel⟧ | (ja page: not shown) |
| `anl.optout.label` (FR-ANL-002) | Allow analytics | アクセス解析を許可する `[Prop]` |
| `anl.optout.on` / `.off` `[Prop]` | On / Off | オン／オフ |
| `anl.optout.off_done` `[Prop]` | Analytics is now off for this browser. | このブラウザでのアクセス解析をオフにしました。 |
| `anl.optout.on_done` `[Prop]` | Analytics is now on for this browser. | このブラウザでのアクセス解析をオンにしました。 |
| `anl.optout.gpc` `[Prop]` | Your browser sends a Global Privacy Control signal, so analytics stays off. | お使いのブラウザがGlobal Privacy Control信号を送信しているため、アクセス解析はオフのままです。 |
| `a11y.breadcrumb` `[Prop]` | Breadcrumb | パンくずリスト |
| `seo.brand_short` | Momo's ⟦brand confirm⟧ | Momo's |

Admin strings (ja .po; JA draft): "Translation gaps" 未翻訳一覧 · "Languages & launch gate" 言語の公開管理 · "Redirections" リダイレクト · "No gaps for {language}" 「{language}の未翻訳はありません」 · "Cannot enable {language}: {n} launch-critical items are missing or unreviewed." 「{language}を公開できません：公開必須コンテンツのうち{n}件が未翻訳または未レビューです。」 `[Prop JA]`.
ar/ur/hi/bn values: Doc 06 §3.8 drafts for `nav.*`, `bc.home`, `cta.*`, `ftr.*`, `lang.switch`; all others produced by translators — every non-EN value is draft until native review (DEC-027; catalog file header "DRAFT – needs native review").

## 16. Endpoints and handlers

No new `momos/v1` REST routes are required for this area. Public routes accept `lang` (§2); error bodies follow the Doc 04 §16.0.4 catalogue (`invalid_param` 400 for a bad `lang`; `rest_forbidden` 401/403; `internal_error` 500). Admin actions use `admin-post.php` with nonce + capability:

| Handler | Method / action | Cap | Input | Result / errors |
|---|---|---|---|---|
| Language gate | POST `momos_language_gate` | `momo_manage_languages` | `lang`, `op` = enable\|disable, `signoff_by`, `signoff_at`, `signoff_ref`, nonce | redirect back with notice; refusals §6.5; 403 page + `access.denied` |
| Save translation | POST `momos_save_translation` (existing) | `momo_translate` (+ approve cap for `approved`) | type, id, lang, fields, status | refuse approve by translator for review-class content |
| Strings group save/approve | POST `momos_save_strings` `[IMPL]` | `momo_translate` / `momo_approve_translations` | lang, group, values | — |
| Translation request | POST `momos_request_translation` `[IMPL]` | `momo_translate` (not TR) | type, id, langs[], priority | sets `_momos_i18n_job_{lang}` |
| Gaps CSV | GET `momos_gaps_csv` `[IMPL]` | `momo_manage_languages` or HO | filters | `text/csv`, formula-escaped cells |
| Redirect CRUD / verify / 404 review | POST `momos_redirect_*`, `momos_404_review` `[IMPL]` | `momo_manage_redirects` | from, to, owner … | validation messages §11 |
| Sitemaps | GET `/sitemap.xml`, `/sitemaps/{lang}-{type}.xml` | public | — | 200 XML; hidden/unknown lang or type → 404 |
| robots | GET `/robots.txt` | public | — | §9.6 |
| WP-CLI | `wp momos seo redirects import|verify|export`, `wp momos i18n gaps [--lang=]`, `wp momos i18n strings-missing` | SA shell | — | non-zero exit on failure (CI) |

## 17. Caching and purge (Doc 04 §16.0.2; TRD §2.9.5)

- Public pages cached per URL incl. prefix; never cached: logged-in, hidden-language previews, `/reservation/status/*`, query-variant handling per host config. Purge within 60 s after any save of shown data, in **all languages** of the entity.
- Translation approved/unapproved → purge that entity's URLs in that language + lists containing it (`momo-home`, directory, category) + sitemap `{lang}-*`.
- Language enabled/disabled → purge everything (`momo-global`) + sitemap index.
- SEO meta / redirect change → purge the entity URL / nothing (redirects evaluated before cache where the host allows, else purge the source URL).
- Suggestion bar, opt-out state, GPC, form carry-over: client-side only (cache-safe).

## 18. Audit (SDD §2.4.7 catalogue)

`language.enabled`, `language.disabled` (payload: lang, lccs_percent, signoff_by/at/ref; no PII), `access.denied`, `settings.updated` (SEO/analytics settings, redirects bulk import — object `redirects`). `[IMPL]` Catalogue additions `translation.approved`, `translation.returned`, `redirect.created/updated/deleted` are proposed under SDD change control; until added, use `settings.updated`.

## 19. Acceptance criteria and tests to automate

| AC / TC | Check | Auto |
|---|---|---|
| AC-I18N-01-01 / TC-I18N-001 (P0), TC-I18N-004 | `/ur/menu/` → `lang="ur" dir="rtl"`; only Plus Jakarta Sans + Noto Nastaliq Urdu files requested; ≤ 3 families, ≤ 6 files, own domain | Playwright |
| AC-I18N-01-02 / TC-RTL-002 | ar dish card ¥1,500 and phone not reversed; ¥ adjacent correctly | Playwright screenshot + DOM |
| AC-I18N-01-03 / TC-I18N-006 | "٤" → party_size 4; Urdu/Bengali phone digits → E.164 | PHPUnit + Playwright |
| AC-I18N-01-04 / TC-I18N-013 (P0), TC-I18N-011 (P0) | crawl per enabled language: 0 untranslated strings, 0 mixed blocks; 0 missing catalog keys; reviewer recorded | Playwright + PHPUnit |
| AC-I18N-01-05 / TC-I18N-012 | no percent-encoded paths; slugs Latin | Playwright crawl |
| AC-I18N-01-06 / TC-RTL-005 | Urdu 200 % zoom: no clipped marks | manual + screenshot |
| AC-I18N-01-07 / TC-I18N-005 | en "19:00 JST", ja "19:00"; dates per §4.4 | Playwright |
| AC-I18N-02-01/02 / TC-I18N-002, TC-I18N-014 | en branch C → اردو → Urdu branch C; dish without hi → `/hi/` + notice; query `branch` kept | Playwright |
| AC-I18N-02-03 / TC-I18N-003 | Accept-Language ar, no cookie: 200 ja, bar in Arabic; dismiss + reload → no bar; no 3xx ever (also crawler UA) | Playwright |
| AC-I18N-02-04/05 | hidden bn not listed; Enter opens, Esc closes, focus returns | Playwright + axe |
| TC-I18N-018 / US-I18N-008 | values restored except consent; storage blocked → warning; none after submit | Playwright |
| AC-I18N-03-01/05 / TC-I18N-007 | translator save not public until approval; translator → JA source edit denied; copy fields not editable | Playwright + manual |
| AC-I18N-03-02 / TC-I18N-008 | JA dish updated → "needs update" age 0 in each language; disappears after approval | Playwright |
| AC-I18N-03-03/04 / TC-I18N-019 (P0), TC-I18N-009 (P0), TC-I18N-015 | MM/CE/TR → 403; hi 97 % → refused with count; no sign-off → refused; enable → switcher/hreflang/sitemap after purge; disable → `/hi/` 404 within 5 min; audit rows | PHPUnit + Playwright |
| AC-I18N-03-06 | (no MT integration) static check: no outbound MT host configured | PHPUnit |
| AC-I18N-04-01/02 / TC-I18N-010 | en admin user: inbox fully English, JST; ja .po 0 untranslated | Playwright + CI |
| AC-SEO-01-01 / TC-SEO-002 | ja,en,ar enabled: hreflang ja,en,ar + x-default=en, reciprocal on all three | Playwright crawler |
| AC-SEO-01-02 / TC-SEO-003 | `/menu/curry/?branch=SHN` → canonical `/menu/curry/`, noindex; C10/C11/TEST noindex | Playwright |
| AC-SEO-01-03 / TC-SEO-004 | sitemap index: no noindex/TEST/hidden URL; all 200; listed in robots.txt | Playwright |
| AC-SEO-01-04 / TC-SEO-001 | `/Branches` → one 301 to `/branches/`; no `/ja/` prefix | Playwright |
| AC-SEO-01-05 | every branch/dish ≤ 3 clicks from home | crawler |
| AC-SEO-01-06 / TC-SEO-005 | ar meta title → `<title>` + `og:title` ar only; titles unique per language | Playwright |
| AC-SEO-01-07 / TC-NAV-004 | ar dish breadcrumbs mirrored = BreadcrumbList order | Playwright |
| AC-SEO-02-01…04 / TC-SEO-006, TC-SEO-007 | validator 0 errors; JSON-LD phone/address/hours = visible; closure next week → closed spec; no rating/review; one Restaurant node; HalalDiet only when certified | Playwright snapshot + manual Rich Results |
| AC-SEO-03-01/02 / TC-SEO-008 (P0), TC-SEO-010 | each legacy URL → single 301 → 200; 8 weekly reviews recorded; Search Console verified | Playwright + manual |
| TC-SEO-009 (P0) / AC-FND-01-07 | staging 401 + noindex; production robots.txt allows CSS/JS | Playwright |
| TC-CORP-006 / AC-CORP-07-01 | `/en/menu/does-not-exist/` → 404 English, noindex, 4 links, logged without IP | Playwright |
| AC-ANL-01-01 / TC-ANL-001, TC-ANL-002 (P0), TC-ANL-003 | exactly one `generate_lead` per new request (none on error/replay); each event once with §12.2 params; `reservation_form_start` once per load | Playwright (dataLayer spy) + DebugView manual |
| AC-ANL-01-02 / TC-ANL-004 (P0), TC-PRV-016 (P0) | no email/name/phone/ref/token in any push or hit; C10/C11 make 0 third-party requests; no-referrer | Playwright HAR |
| AC-ANL-01-03 / TC-ANL-005 | wp-admin: 0 GTM/GA4 requests; no ad pixels anywhere | Playwright |
| AC-ANL-01-04 / TC-ANL-011 | GA4 settings screenshots; `gtm.blocklist` before snippet; ≤ 2 publishers | manual + Playwright |
| AC-ANL-02-01 / TC-ANL-007, TC-ANL-010 | opt-out or GPC → 0 Google requests over 3 pages, `_ga*` removed, cookie 1 y Secure Lax; opt-in resumes; consent defaults correct | Playwright |
| AC-ANL-02-02 / TC-ANL-006 | C17 lists every observed third party in 6 languages; footer link on every page | manual |
| TC-ANL-008 / TC-ANL-009 | no outcome events; TEST branch fires nothing | manual / Playwright |
| TC-RTL-001/003/006/007, TC-I18N-016/017 | RTL templates, RTL form, direction toggling, staff view `dir=auto`, legal JA-authoritative note, long strings | Playwright + manual |

GATE-04: TC-I18N-001/002/007/009/011/013/015/019, TC-RTL-001…004 + journeys per enabled language. GATE-09: TC-SEO-004/008/009/010, TC-ANL-001…004/011.

## 20. Out of scope (Phase 1)

Machine-translation integration (no MT service connected; future use must follow BR-PRV-04/BR-DIET-05) · per-language translated slugs · automatic language redirects of any kind · RTL admin · consent banner/CMP (counsel Q-037) · hreflang in sitemaps · site search & SearchAction (DEC-045) · advertising pixels, Google Ads, Measurement Protocol, server-side tagging · GA4 business-outcome events (FR-ANL-003) · review/rating markup, offer/promotion schema · area/station landing pages (doorway risk) · GBP/directory clean-up (DEC-040, client operation) · Search Console/GA4 property administration (ops, outside code) · Rank Math/WPML/Elementor themselves (IMPL-001/004) · offer/notice detail URLs.

## 21. Open TBDs and documentation conflicts

### 21.1 TBDs (default used)

| Item | Default | Owner |
|---|---|---|
| Q-424 x-default when no English version | omit | SEO lead |
| Q-415 `servesCuisine`, `priceRange` per branch | omitted from JSON-LD until supplied (new branch fields to be added in branch spec when values exist) | HO + SEO |
| Q-406 native reviewers per language | none → languages stay hidden (ASM-007) | Owner |
| Q-416 language for non-user staff inboxes | ja | HO |
| DEC-027 translation SLA new dish / changes / halal text / urgent notices | 5 business days / 5 / 7 days / same day | HO |
| BR-I18N-03 "Japanese authoritative" wording | EN text in §15 | Counsel |
| GA4 retention | 14 months (2 if counsel prefers) | Owner + counsel |
| Q-037/Q-038 consent banner / external-transmission applicability | notice + opt-out only | Counsel |
| JST localised labels ar/ur/hi/bn; party-size plurals; prohibited-term lists ar/ur/hi/bn | native reviewer at GATE-04 | HO |
| Font KB budgets; ja slice count vs 6-file cap | §4.3 values, verify in PROTO | Tech lead |
| 404 log size | 1,000 rows | Tech lead |
| `seo.brand_short` value and dish title template | "Momo's"; `{dish} – {category} \| {brand}` | Brand/SEO |
| GTM container ID per environment | none on dev/staging | SA |

### 21.2 Conflicts found in the package (resolution applied here)

| # | Conflict | Resolution |
|---|---|---|
| C-1 | Untranslated-page notice param: PRD `?notice=untranslated` vs TRD `?momo_notice=missing_translation` | Emit `?notice=untranslated`; accept the TRD alias |
| C-2 | `cta_location` values differ: PRD (`sticky_bar`, `menu`), TRD dictionary (`mobile_bar`, `menu_page`, `home_section`, `reservation_page`, `error_page`), Doc 05 (`home_hero`, `home_branch_card`, `halal_page`), IA/Doc 08 (`sticky_bar`) | Union enum §12.3 with `sticky_bar`, `menu_page`, `hero`, `branch_card`, `halal_page`; analytics owner to confirm and TRD §4.7 to be updated |
| C-3 | `view_halal_information` param: PRD/Doc 12 `cta_location` vs TRD `source_page`; `select_language`: Doc 12 `language, cta_location` vs PRD/TRD `from_language, to_language` | TRD contract (`source_page`; `from/to_language`); TC-ANL-001 table (Doc 12 §2.17) to be aligned |
| C-4 | `view_menu` on hub: PRD `menu_category = hub` vs TRD `null` | `hub` |
| C-5 | Suggestion bar buttons: PRD "Switch / Dismiss", Doc 07 "Switch · Stay in 日本語", Doc 08 "Switch + ×" | Switch + × (`lang.dismiss` accessible name) |
| C-6 | Bar announcement: PRD "announced politely" vs Doc 08 "not live-announced" | PRD (polite) |
| C-7 | `momo_translate` holders: TRD/build grant to TR (gaps view) vs IA admin menu (gaps view hidden from TR) | TR sees gaps filtered to own languages (queue) |
| C-8 | FR-SEO-001 lowercase paths vs upper-case Crockford REF in `/reservation/status/{REF}/` (TRD rewrite) | Status route exempt from lowercase redirect (noindex page) |
| C-9 | DEC-061 "≤ 6 font files per page" vs Doc 08 ja unicode-range slices (many small files) | Count per weight family; verify in PROTO and record exception for ja |
| C-10 | Urdu line-height: Doc 08 2.0 vs TK-03-04/TK-08-12 ≈ 2.2 | 2.0 token, raise to 2.2 if native review finds clipping |
| C-11 | Gaps age: PRD "days" vs WF-ADM-19 "business days" | Calendar days (KPI-13 uses hours separately) |
| C-12 | Branch title template wording: PRD "{branch} – Halal restaurant near {area} \| Momo's" vs Doc 06 "MOMO's {Branch} – Halal Restaurant near {Station/Area}" | PRD template; final wording per copy/claim review |
| C-13 | Existing `Strings::t()` falls back to English at runtime vs PRD "runtime never falls back" | Keep fallback as safety net only, log WARNING, count as LCCS gap so enabled languages never hit it |
| C-14 | Doc 11 §4.5 lists WPML cookie `wp-wpml_current_language` | Not present in this build; C17 inventory lists `momo_lang_choice` instead |
| C-15 | PRD AC-SEO-02-02 "special openingHoursSpecification with no opening times" vs Google closed-day convention | `opens = closes = "00:00"` with `validFrom/validThrough` |
| C-16 | Audit catalogue has no translation/redirect actions | Use `settings.updated` until catalogue extended by change control |
