# Spec — Content, microcopy and corporate page content (`content-strings`)

Implementation-ready specification for the **string-key register, content rules, corporate page content and demo/sample
content**. Developers do not need the original docs. Sources: Doc 07 §6 (single string-key register, R2-12), Doc 06 §3.8
(ar/ur/hi/bn drafts), Doc 11 §4 / §8 / §9 (legal and claim text masters), Doc 04 F-CORP-01…10, F-PRV-01, F-ANL-02,
F-ADM-04, Doc 08 components, Doc 10 §7.2–7.3, Doc 12 tests, Doc 13 TK-01-12/18/27, TK-06-15, TK-08-06, Doc 17 decisions.

**Status of every non-English value:** all Japanese strings in the docs are **drafts — native/legal review required**
(Doc 07 §6.1); ar/ur/hi/bn values are **draft translations requiring native review** (IMPL-003). Legal and claim text also
need counsel and/or the Dietary Claims Approver (CAP-DCA). `[Prop]` = key or wording proposed in this spec (not in the docs);
`[Prop JA]` = Japanese proposed here, needs native review. ⟦…⟧ = TBD value with the documented proposal default.

---

## 0. How the documented mechanisms map to this build (IMPL-001…005)

| Documented mechanism | This build |
|---|---|
| WPML String Translation; text domain `momos-core`; string name = key | Key catalogue `momos-core/languages/strings/{lang}/{module}.php` (PHP array key ⇒ string) read by `Momos\Kernel\I18n\Strings::t($key, $args, $lang)` / `momos_t()`; reviewed edits stored in option `momos_strings_overrides[lang][key]` (SCR-A11 string editor). Admin-only labels may still use `__()` with text domain `momos-core` + ja `.po/.mo` (DEC-037). |
| WPML translation of pages / posts | Page/post translatable fields `title`, `content`, `excerpt` in `_momos_i18n_{lang}` JSON + `_momos_i18n_status_{lang}` ∈ `missing, draft, needs_review, approved`; page shown in a language only when `approved` (IMPL-002, BR-I18N-02). |
| Elementor pages (Home, About, FAQ, Legal) / Theme Builder header & footer | Classic theme `momos` page templates (`front-page.php`, `page-about.php`, `page-halal.php`, `page-faq.php`, `page-legal.php`, `404.php`, `header.php`, `footer.php`) rendering editable page content + data-driven sections. Content Editor edits text in the WP editor; layout is locked in templates. |
| Elementor Pro Form (Contact, DEC-038) | momos-core contact handler (admin-post / REST-less POST, nonce, Turnstile + honeypot), email only, **no storage** (BR-CNT-01). |
| Elementor Accordion (FAQ) | FAQ items as a repeater meta on the FAQ page (`momo_faq_items`, translatable JSON) rendered as `<button aria-expanded>` disclosure (§8.4). |
| ACF fields (`momo_*` names) | Native meta registered in code via `Kernel\Fields\FieldRegistry`; meta keys keep the documented ACF *names* (`momo_legal_version`, `momo_is_sample`, `momo_image_illustrative`, …). |
| Rank Math breadcrumbs / 404 monitor | momos-core `Seo` module (see spec `i18n-seo-analytics`). |
| `maintenance.php` drop-in | Static HTML generated at each release from the string catalogues (all enabled languages), served by `wp-content/maintenance.php` with 503 + `Retry-After`. |

---

## 1. Requirements covered (traceability)

| ID | Statement (one line) | Pri |
|---|---|---|
| FR-CORP-001 | Homepage sections in fixed order: notice · hero · offers · branch teaser · featured dishes · halal summary · "How reservations work"; empty sections not rendered | Must |
| FR-CORP-002 | About page editable without code; no unverified claims, no superlatives without named source, no ratings | Must |
| FR-CORP-003 | Halal page: brand statement or neutral text + per-branch register table from approved templates only | Must |
| FR-CORP-004 | FAQ grouped (Reservations, Halal & dietary, Allergens, Branches & access, Payments ⟦TBD⟧), accessible accordion, optional FAQPage | Should |
| FR-CORP-005 | News list/detail (10/page), translated posts only | Could |
| FR-CORP-006 | Five legal pages in every enabled language with "Last updated", version, Japanese-authoritative note; HO-only publish | Must |
| FR-CORP-007 | Localised 404 (noindex, 4 links) and static 503 maintenance page in all enabled languages | Must |
| FR-CORP-008 / 009 | Header nav labels/order, conditional Offers item, sticky bar, footer columns, legal links, copyright | Must |
| FR-CORP-010 / 011 | Offer copy rules (validity + conditions, tax-incl., no coupon wording); empty-state strings | Must |
| FR-CORP-012 | Gallery: real photos only, alt text per language | Could |
| FR-I18N-011 | All customer strings/templates exist in 6 languages, human-translated/reviewed, reviewer + date recorded | Must |
| FR-I18N-006 | Localised date/time/number/price formats (Doc 07 §6.10) | Must |
| FR-DIET-002(f) / FR-DIET-009 | Prohibited-wording list (Doc 07 §6.6 = Doc 11 §8.6 + §9.5 + request-context + ordering terms) and claim-term scan | Must |
| FR-DIET-003 | Halal/alcohol statements only from approved templates (no free text) | Must |
| FR-PRV-001 / 002 | Privacy Policy content; notice at collection + unticked consent next to submit | Must |
| FR-ANL-002 | External Transmission & Cookies page content + opt-out control | Must |
| FR-ADM-005(d)(f) | Alt text per language before publish; `momo_image_illustrative` flag + "Image for illustration" caption | Must |
| FR-MENU-004(g) | `momo_is_sample` publish block in production | Must |
| FR-NOT-001(b) | Common email footer: legal operator name, privacy link | Must |
| FR-FND-005(b) | Front-end elements depending on an empty brand field are not rendered | Must |
| BR-RES-01 | Request ≠ booking wording; only negated "not yet confirmed" may use confirmed/確定 before acceptance | Must |
| BR-RES-14 | No payment/deposit/no-show fee wording | Must |
| BR-DIET-01 / 03 / 05 | Halal wording only via templates; no "allergen-free"; claim text never unreviewed MT | Must |
| BR-I18N-01 / 02 / 03 | Language gate; no mixed-language fallback; JA legal text authoritative | Must |
| BR-CORP-01 / 03 | Offer copy rules; demo/sample content rules (DEC-047) | Must |
| BR-CNT-01 / 02 | Contact not stored; CE cannot publish legal pages/claims | Must |
| BR-SEO-01 | No self-serving rating markup; FAQ schema matches visible text | Must |
| DEC-011, 020, 021, 022, 027, 036, 044, 047, 053, 054, 060, 062 | Status labels, halal, allergen, tax-incl. prices, translation, a11y statement, offers, demo content, special-request guidance, policy text, nav, JST label | — |
| CMP-003/005/010/015/016/023/024/040/044/045 | Reservation Policy, legal pages, halal taxonomy, prohibited wording, marks, allergen wording, offers, demo content, UI-001 clean-up | Must |
| US-CORP-001…012, US-MENU-009, US-ADM-006, US-I18N-004/005/006, US-PRV-001 | Stories covered by this spec | — |
| SCR-C01/C02/C03/C12/C13/C14/C15–C18/C19/C20/C21/C22; SCR-A11/A14/A15/A18/A20; SCR-E01–E11 (footer/common text) | Screens | — |

---

## 2. Data model

### 2.1 String catalogue (Kernel i18n)

| Item | Specification |
|---|---|
| Files | `languages/strings/{lang}/{module}.php` returning `array<string key, string value>`; one file per module to avoid edit conflicts: `global.php` (nav, cta, ftr, lang, skip, bc, 404, media, price), `reservation.php` (`res.*`, `status.*`, `decline.*`, `cancel.reason.*`), `claims.php` (§4.3 keys), `menu.php`, `branch.php`, `offers.php`, `email.php` (`email.*`, `staff.email.*`), `privacy.php` (`notice.*`, `consent.*`, `anl.*`, `legal.*`), `corporate.php` (`home.*`, `halal.*` page chrome, `faq.*`, `contact.*`, `news.*`, `maint.*`), `demo.php`. Theme strings use the same keys (`theme.php` allowed). |
| ar/ur/hi/bn file header | `/* DRAFT – needs native review (IMPL-003, DEC-027). */` |
| Key format | `^[a-z0-9_]+(\.[a-z0-9_]+)*$`; claim template keys are the Doc 11 bare keys (`no_claim`, `branch_default`, `last_verified`, `certified.whole_kitchen`, …) — never renamed. |
| Placeholders | `{name}` (`{branch}`, `{phone}`, `{ref}`, `{date}`, `{time}`, `{max}`, `{min}`, `{n}`, `{days}`, `{hours}`, `{dish}`, `{language}`, `{list}`, `{certifier}`, `{cert_no}`, `{valid_until}`, `{covered_ingredients}`, `{branch_links}`, `{title}`, `{start}`, `{end}`, `{masked_email}`, `{duration}`, `{request_id}`, `{lo}`, `{day}`, `{domain}`). Values with numbers/Latin text are inserted wrapped in `<bdi>` (FR-I18N-005). A translation must contain exactly the placeholder set of the EN source (validator on save). |
| Plurals | Keys needing plurals store an array `['one'=>…, 'other'=>…]` (ar: `zero, one, two, few, many, other`) resolved with CLDR rules; JA uses `other` only. |
| Overrides | Option `momos_strings_overrides` `[lang][key] => string` (sanitised with `sanitize_textarea_field`; HTML not allowed except the link placeholders). |
| Review status [Prop] | Option `momos_strings_status` `[lang][key] => {status: draft\|needs_review\|approved, source: seed\|human\|mt, by: user_id, at: ISO-8601 UTC}`. Catalogue seeds for ar/ur/hi/bn and JA drafts start `draft`. `mt` source can never be `approved` without a human edit-or-confirm by a reviewer (BR-DIET-05, TC-I18N-011). |
| Group registry [Prop] | `config/string-groups.php`: prefix ⇒ `{lccs: bool, review: native\|native+counsel\|native+dca+counsel}` — see §2.2. |

### 2.2 Review level per key group (Doc 07 §6.7, Doc 06 §4.6 LCCS)

| Group (prefixes) | LCCS | Review level |
|---|---|---|
| `nav.*`, `bc.*`, `cta.*`, `ftr.*`, `skip.*`, `lang.*`, `price.*`, `404.*`, `media.illustration` | Yes | native |
| `branch.*`, closure-reason, facility, availability-reason, `menu.*`, spice and dietary-tag labels | Yes | native |
| `res.*` (form, hints, errors, info), `status.*`, `decline.*`, `cancel.reason.*` | Yes | native (LCCS, human only) |
| `res.notice.collection`, `res.label.consent`, `notice.*`, `consent.*`, `legal.*` | Yes | native + counsel |
| `email.*` (E01–E06 subjects/bodies, HTML + text) | Yes | native (human only) |
| Claim keys §4.3 (`certified.*`, `halal_ingredients`, `no_claim`, `branch_default`, `brand.neutral`, `last_verified`, `halal.*`, `alcohol.*`, `allergen.*`, `ingredients.heading`), allergen names (29) | Yes | native + CAP-DCA + counsel; never MT (BR-DIET-05) |
| `offers.*`, `faq.*`, `contact.*`, `news.*`, `home.*`, `maint.*`, `anl.*` | `home.*`, `maint.*`, `anl.*` yes; others no | native (offer conditions with prices: + HO, BR-CORP-01) |
| `staff.*`, `staff.email.*`, admin labels | n/a (ja/en admin) | ja native review |

### 2.3 Page meta (G16 `group_momo_page_settings`, post type `page`)

| Meta key | Type | Req | Translatable | Validation | Default | Edit cap |
|---|---|---|---|---|---|---|
| `momo_page_type` | enum `standard`, `legal` | Y | No (copy) | — | `standard` | `momo_publish_legal_pages` |
| `momo_legal_version` | string ≤ 20 | Y if legal | No | Doc 11 label format `PP-1.0`, `RP-1.0`, `TOU-1.0`, `ETC-1.0`, `A11Y-1.0` [Prop prefixes for non-PP/RP]; Privacy/Reservation versions must have a consent-registry row (Doc 11 §4.3) else save blocked | — | `momo_publish_legal_pages` |
| `momo_legal_last_updated` | date (JST) | Y if legal | No | ≤ today | — | `momo_publish_legal_pages` |
| `momo_noindex` | bool | — | No | — | false | `momo_manage_brand` |
| `momo_faq_items` [Prop] | JSON list `{id slug, group enum reservations\|halal_dietary\|allergens\|branches_access\|payments, question ≤ 200, answer html ≤ 2000, order int}` | — | Yes (question/answer per lang in `_momos_i18n_{lang}`) | `id` `^[a-z0-9-]{3,60}$` unique (URL hash) | [] | `edit_pages` |
| `_momos_i18n_{lang}` | JSON `{title, content, excerpt, faq_items?}` | — | — | — | — | `momo_translate` (+ assigned lang) |
| `_momos_i18n_status_{lang}` | enum `missing, draft, needs_review, approved` | — | — | `approved` needs `momo_approve_translations` | `missing` | — |

Brand options (`momo-brand`, G1) used by content: `momo_trading_name` (≤ 100, T), `momo_tagline` (≤ 120, T, no superlatives), `momo_legal_operator_name` (T; hidden on site while empty), `momo_contact_email`, `momo_contact_phone_e164` / `_display`, `momo_social_links[] {network: instagram|facebook|x|youtube|tiktok|line|other, url https}`, `momo_brand_halal_statement_key` (CAP-DCA; approved keys only), `momo_privacy_page`, `momo_reservation_policy_page`, `momo_terms_page`, `momo_external_tx_page`, `momo_accessibility_page` (page IDs, required before launch).

### 2.4 Demo / sample flags (DEC-047, BR-CORP-03, TK-06-15)

| Meta | On | Type | Default | Set/clear by | Rule |
|---|---|---|---|---|---|
| `momo_is_sample` | `momo_dish`, `momo_menu_category` (term meta), `momo_offer` | bool | false | ROLE-01/02 only (`momo_manage_brand`) | In production (`WP_ENVIRONMENT_TYPE=production`) publish refused (§10) |
| `momo_image_illustrative` | attachment | bool | false | `upload_files` roles | Allowed only as dish image / dish gallery and offer image; refused on branch photos/galleries, gallery page, halal page, About page |
| `momo_licence_source` | attachment | string ≤ 200 | — | `upload_files` | LC-001 REQ-IP-01 record |
| `momo_protected_mark` | attachment | bool | false | CAP-DCA | Hidden from media picker for non-CAP-DCA (CMP-016) |

---

## 3. Rules and algorithms

### 3.1 String rules (Doc 07 §6.1)
1. Doc 07 §6 is the single key register; claim templates are mastered in Doc 11 §8–9 under the same keys; legal notice/consent wording follows Doc 11 §4.2 (see conflict C1). If Doc 11 and Doc 07 claim wording ever differ, Doc 11 governs.
2. Tone: plain, polite, short sentences; no idioms; no blame. Japanese uses です・ます; お客様 avoided in short UI labels.
3. Never before acceptance: "booked", "reserved", "confirmed" (positive), "your reservation", 予約完了, 予約確定, 予約が確定, ご予約を承りました — the submission is "your request" / リクエスト (BR-RES-01). Never "allergen-free", "safe for allergies" (BR-DIET-03); never "100 % halal", seals or "certified" outside an active certified record (BR-DIET-01).
4. Restaurant facts (hours, phone, address, prices) are never typed into strings or pages; they come from fields (TR-001 risk 4; §0.4 rule 7 of Doc 07).
5. Times are 24-hour (`h23`); digits always Western; zone label "JST" only in non-Japanese languages on reservation screens/emails (DEC-062).

### 3.2 Resolution (`Strings::t`)
```
value = overrides[lang][key] ?? catalogue[lang][key]
if value missing and lang != 'en': value = overrides/catalogue['en'][key]   // dev safety net only
if still missing: value = key
substitute {placeholders} (escaped; bdi-wrapped where numeric/Latin)
```
Production guarantee: a language can be enabled (language gate, SCR-A15) only when **every customer-facing key** in that
language is present and `approved` and the LCCS content items are approved and signed off (`lccs_signoff_by/at/ref`);
therefore the English fallback never renders on an enabled public language (BR-I18N-02). Refusal message:
"Cannot enable {language}: {n} launch-critical items are missing or unreviewed." / 「{language}を公開できません：公開必須コンテンツのうち{n}件が未翻訳または未レビューです。」 [Prop JA]. Japanese source keys must also be `approved` (native/legal review) before production go-live.

### 3.3 Prohibited-term scan (FR-DIET-002(f), FR-DIET-009, TC-DIET-013/021, TC-RES-021)
- Term list = `config/claim-keywords.php` (classes `certification`, `absolute`, `group`, `practice`, `recognition`, `allergen`, `ordering`) **plus** class `request_context` [add] (§5). Matching: case-insensitive substring, NFKC-normalised (full-width → half-width), per language + `*`.
- Contexts: claim and ordering classes everywhere (pages, posts, offers, notices, dish/category text, image filenames/alt, rendered emails); `request_context` only in pre-acceptance contexts: SCR-C09, SCR-C10, SCR-C11 while `pending`, E01, E04/E06 while `pending`, home "How reservations work".
- Allow-list: text rendered from a `certified.*` template of a displayable record, `halal.label.certified` for an active certified record, `status.pending`, `res.submitted.heading` (the negated "not yet confirmed" / 未確定 / まだ確定していません).
- Save-time: Content Editors see a warning notice when a term is detected (save allowed); `momo_statements` text keyword guard blocks "100%", "完全", seal wording unless type = certified. Report: admin page "Claim-term scan" (ROLE-01/02, CAP-DCA) + `wp momos content claim-scan` listing URL, field, language, term; register-driven blocks excluded. Run before GATE-02 and monthly.

### 3.4 Legal page rules (FR-CORP-006, Doc 11 §4.3)
- Page shows `legal.last_updated` + `legal.version`; translations show `legal.ja_authoritative` at the top (not on ja).
- Table of contents when the page has > 5 headings (anchor links).
- Legal pages unpublished until counsel approval (GATE-06); Reservation Policy is always published (system rules only until DEC-054 text exists).
- A translation must carry the same version label and date as the JA master (TC-CORP-016); publishing a new Privacy/Reservation Policy version requires a new consent-registry row in the same change.
- ROLE-04 can save drafts and "Submit for review"; Publish/Update of a `legal` page requires `momo_publish_legal_pages`; message `admin.legal.publish_denied`.

### 3.5 Empty-field rule (FR-FND-005(b))
Any element whose source field is empty is not rendered (no "please inquire", no placeholder): footer copyright without operator name, social links, hero lead, concept label, response promise (`res.submitted.promise` only when DEC-013 value approved), policy text (DEC-054), Head Office phone on the maintenance page.

---

## 4. Microcopy register (Doc 07 §6 — complete)

### 4.1 Reservation form (Doc 07 §6.2)

| Key | Context | English | Japanese (draft) |
|---|---|---|---|
| `res.title` | C09 H1 | Request a table | 予約リクエスト |
| `res.intro` | C09 explainer | Your request is sent to the branch. The branch will reply by email. | リクエストは店舗に送信され、店舗からメールでご連絡します。 |
| `res.label.branch` | label | Branch | 店舗 |
| `res.label.date` | label | Date | 日付 |
| `res.label.time` | label | Time | 時間 |
| `res.label.party` | label | Number of people | 人数 |
| `res.label.name` | label | Your name | お名前 |
| `res.hint.name` | hint | As you'd like the branch to call you. Any language is fine. | 店舗でお呼びするお名前をご入力ください。どの言語・文字でも構いません。 |
| `res.label.email` | label | Email | メールアドレス |
| `res.hint.email` | hint | We send the branch's reply to this address. | 店舗からの返信をこのアドレスにお送りします。 |
| `res.label.phone` | label | Phone (recommended) | 電話番号（推奨） |
| `res.hint.phone` | hint | Include your country code, e.g. +60 12 345 6789. Japanese numbers may start with 0. The branch may call you if email doesn't reach you. | 海外の番号は国番号から入力してください（例：+60 12 345 6789）。国内の番号は0から始めて構いません。メールが届かない場合、店舗からお電話することがあります。 |
| `res.label.note` | label | Special request (optional) | ご要望（任意） |
| `res.hint.note` | hint (DEC-053) | e.g., high chair, celebration, seating preference. Please tell staff directly about serious allergies. | 例：子ども用椅子、お祝い、お席のご希望など。重いアレルギーについては、ご来店時にスタッフへ直接お伝えください。 |
| `res.hint.advance_order` | from advance-order dish | {dish} needs to be ordered at least {hours} hours ahead. Mention it here. | {dish}は{hours}時間前までのご注文が必要です。こちらにご記入ください。 |
| `res.label.lang` | label | Language for our emails | メールの言語 |
| `res.summary.title` | summary panel | Check your request | リクエスト内容の確認 |
| `res.notice.collection` | notice above submit — **counsel** | see §4.5 (Doc 11 `notice.reservation` wording) | see §4.5 |
| `res.label.consent` | consent checkbox — **counsel** | see §4.5 (Doc 11 `consent.reservation` wording) | see §4.5 |
| `res.submit` / `res.submit.busy` | button | Send request / Sending… | リクエストを送信／送信中… |
| `res.call_alt` | under button | Can't find a suitable time? Call {branch}: {phone} | ご希望の時間がない場合は{branch}（{phone}）へお電話ください。 |
| `res.err.summary` | error summary | Please correct {n} item(s) below. | 以下の{n}件の項目をご確認ください。 |
| `res.err.branch.required` | REQUIRED | Choose a branch. | 店舗を選択してください。 |
| `res.err.branch.unavailable` | 422 `branch_not_accepting` | {branch} is not taking online requests right now. Please call {phone}. | {branch}は現在オンラインでのリクエストを受け付けていません。{phone}へお電話ください。 |
| `res.err.date.required` | REQUIRED | Choose a date. | 日付を選択してください。 |
| `res.err.date.closed` | 409 `slot_unavailable` (closed) | {branch} is closed on this date. Choose another date. | この日は{branch}の休業日です。別の日をお選びください。 |
| `res.err.date.window` | 422 `outside_booking_window` | You can request up to {days} days ahead. | {days}日先までリクエストできます。 |
| `res.err.date.too_soon` / `res.err.time.too_soon` | 422 `lead_time_not_met` | This is too soon to request online. Please call {branch}: {phone}. | オンラインでのリクエスト受付時間を過ぎています。{branch}（{phone}）へお電話ください。 |
| `res.err.time.required` | REQUIRED | Choose a time. | 時間を選択してください。 |
| `res.err.time.unavailable` | 409 `slot_unavailable` | This time is no longer available. Choose another time. | この時間は選択できなくなりました。別の時間をお選びください。 |
| `res.err.party.required` | REQUIRED | Enter the number of people. | 人数を入力してください。 |
| `res.err.party.min` | 422 `party_size_out_of_range` | The minimum for online requests is {min}. | オンラインでのリクエストは{min}名様からです。 |
| `res.err.party.max_call` | 422 `party_size_out_of_range` | For more than {max} people, please call {branch}: {phone}. | {max}名様を超える場合は、{branch}（{phone}）へお電話でご相談ください。 |
| `res.err.name.required` / `.length` | REQUIRED / RANGE | Enter your name. / Your name can be up to 100 characters. | お名前を入力してください。／お名前は100文字以内で入力してください。 |
| `res.err.email.required` / `.format` | REQUIRED / FORMAT | Enter your email address. / Enter an email address like name@example.com. | メールアドレスを入力してください。／メールアドレスを name@example.com の形式で入力してください。 |
| `res.err.phone.format` | FORMAT | Enter a phone number with country code, e.g. +60 12 345 6789, or a Japanese number starting with 0. | 国番号付き（例：+60 12 345 6789）または0から始まる国内の電話番号を入力してください。 |
| `res.err.note.length` | RANGE | Your request can be up to 1,000 characters. | ご要望は1,000文字以内で入力してください。 |
| `res.err.consent.required` | REQUIRED | Tick the box to agree to the Privacy Policy and the Reservation Policy. | プライバシーポリシーおよび予約ポリシーへの同意にチェックを入れてください。 |
| `res.err.limit.active` | 409 `pending_limit_reached` | You already have {n} requests waiting at this branch. Please wait for a reply or call {phone}. | この店舗への返信待ちのリクエストが既に{n}件あります。返信をお待ちいただくか、{phone}へお電話ください。 |
| `res.err.rate` | 429 `rate_limited` | Too many requests from this connection. Please try again in a few minutes or call {phone}. | 短時間に多くのリクエストが送信されました。数分後に再度お試しいただくか、{phone}へお電話ください。 |
| `res.err.captcha` | 403 `captcha_failed`, `origin_rejected` | We couldn't verify your request. Please try again. | リクエストを確認できませんでした。もう一度お試しください。 |
| `res.err.system` | 5xx, 400 `invalid_json`, 413, 415 | Your request was not sent. Please try again. If it keeps failing, call {phone}. | リクエストを送信できませんでした。もう一度お試しください。解決しない場合は{phone}へお電話ください。 |
| `res.err.reference` | under `res.err.system` | Error reference: {request_id} | エラー番号：{request_id} |
| `res.err.branch.not_found` | 404 `branch_not_found` | This branch could not be found. Please choose a branch from the list. | この店舗は見つかりませんでした。一覧から店舗を選んでください。 |
| `res.info.duplicate` | 200 `duplicate: true` | We already received this request. Your reference is {ref}. | このリクエストは既に受け付けています。受付番号は{ref}です。 |
| `res.info.branch_changed` | status message | Date and time were cleared because you changed the branch. | 店舗を変更したため、日付と時間をリセットしました。 |
| `res.no_payment` [Prop key; text FR-CORP-006(d), Doc 06 C09 panel] | panel / policy | No payment or deposit is required. | お支払いやデポジットは不要です。 [Prop JA] |

### 4.2 Reservation status wording (Doc 07 §6.3, Doc 04 §16.0.3 — single source)

| Key | English | Japanese (draft) |
|---|---|---|
| `status.pending` | Request received — not yet confirmed | リクエスト受付・未確定 |
| `status.confirmed` | Confirmed by the branch | 店舗にて確定済み |
| `status.rejected` | Declined | お受けできませんでした |
| `status.cancelled` | Cancelled | キャンセル済み |
| `status.expired` | Expired — not confirmed in time | 期限切れ（時間内に確定できませんでした） |
| `status.past` (note with confirmed label for `completed`/`no_show`) | The date of this reservation has passed. | このご予約の日時は過ぎています。 |
| `res.submitted.heading` (C10 H1) | Request received — not yet confirmed | リクエストを受け付けました（まだ確定していません） |
| `res.submitted.email_to` | We will email {masked_email}. | {masked_email}宛てにメールでご連絡します。 |
| `res.submitted.expiry` | If the branch has not replied by {time}, your request expires and we will email you. | {time}までに店舗から返信がない場合、リクエストは期限切れとなり、メールでお知らせします。 |
| `res.submitted.generic` | If you sent a request, check your email for the reference number. If you did not receive an email, contact the branch. | リクエストを送信された方は、メールに記載の受付番号をご確認ください。メールが届いていない場合は店舗へお問い合わせください。 |
| `res.submitted.next` | The branch will review your request and reply by email. A table is not set aside for you until the branch accepts your request. | 店舗がリクエストを確認し、メールでご連絡します。店舗が承認するまで、お席は確保されていません。 |
| `res.submitted.dont_travel` (validate V8) | Please don't travel to the branch based on this request alone. | 承認のご連絡をお受け取りになるまでは、このリクエストのみでのご来店はお控えください。 |
| `res.submitted.promise` (only if DEC-013 approved) | The branch usually replies within {duration} during opening hours. | 営業時間内であれば、通常{duration}以内に店舗からご連絡します。 |
| `res.submitted.reference` | Your reference: {ref} | 受付番号：{ref} |
| `res.submitted.status_link` | View request status | リクエストの状況を確認する |
| `res.carrier_tip` | Not receiving our emails? If you use a Japanese mobile carrier address, allow mail from @{domain}. | メールが届かない場合：携帯キャリアのメールアドレスをご利用の方は「@{domain}」からのメールを受信できるよう設定してください。 |
| `status.cancel.cta.pending` / `.confirmed` | Cancel this request / Cancel this reservation | このリクエストを取り消す／このご予約をキャンセルする |
| `status.cancel.confirm` | Cancel your request for {date}, {time}? The branch will be told. | {date} {time}のリクエストを取り消しますか？店舗にお知らせします。 |
| `status.cancel.keep` / `.yes` | Keep it / Yes, cancel | 取り消さない／取り消す |
| `status.link_invalid` (404 `not_found`, 410 `link_expired`) | This link is no longer valid. If you need help, contact the branch. | このリンクは無効です。お問い合わせは店舗までご連絡ください。 |
| `status.cancel.not_allowed` (409 `cancel_not_allowed`/`invalid_transition`) | This request can no longer be cancelled online. Please call {phone}. | このリクエストはオンラインでは取り消しできなくなりました。{phone}へお電話ください。 |
| `status.no_js` | Please contact the branch: {phone} | 店舗へお問い合わせください：{phone} |
| `email.confirmed.allergy` (E02) | If anyone in your group has a food allergy, please tell our staff when you arrive. | アレルギーをお持ちの方がいらっしゃる場合は、ご来店時にスタッフへお伝えください。 |
| `decline.reason.fully_booked` | The branch is fully booked at that time. | ご希望の時間は満席です。 |
| `decline.reason.closed_or_private_event` | The branch is closed or reserved for a private event at that time. | ご希望の時間は休業または貸切のため、お受けできません。 |
| `decline.reason.party_size_not_supported` | The branch can't seat a group of this size at that time. | ご希望の人数ではお席をご用意できません。 |
| `decline.reason.incomplete_information` | We needed more information to accept this request. | リクエストをお受けするための情報が不足していました。 |
| `decline.reason.other` | The branch couldn't accept this request. | 店舗の都合によりお受けできませんでした。 |
| `decline.suggested` | The branch suggested these times. They are not held for you — send a new request if one suits you. | 店舗から以下の時間のご提案があります。お席は確保されていませんので、ご希望の場合は改めてリクエストをお送りください。 |

Staff labels (admin ja/en, DEC-037): pending "Pending / 未対応" · confirmed "Accepted / 承認済み" · rejected "Declined / お断り済み" ·
cancelled "Cancelled / キャンセル済み" · expired "Expired / 期限切れ" · completed "Attended / 来店済み" · no_show "No-show / 無断キャンセル";
actions "Accept / 承認する", "Decline / お断りする", "Cancel request / キャンセルする", "Claim / 担当する". Customers never see completed/no_show labels.

### 4.3 Halal, alcohol and allergen statements (claim text — Doc 11 §8.1/§8.7/§9.2/§9.4 master)
Approval: Owner/Halal officer + Dietary Claims Approver (DEC-020/021) + counsel (ER-012). Every factual element is a variable
from the approved register record; no template states a kitchen/supplier/preparation practice as fixed text.

| Key | Use / render condition | English draft | Japanese draft |
|---|---|---|---|
| `halal.label.certified` | claim-type label, active `certified` record only | Halal-certified | ハラール認証取得 |
| `halal.label.ingredients` | claim-type label, active `halal_ingredients` only | Halal ingredients | ハラール食材使用 |
| `certified.whole_kitchen` | branch, `certified` scope `whole_kitchen` | This branch is halal-certified by {certifier} (certificate no. {cert_no}, scope: whole kitchen, valid until {valid_until}). | 当店舗は{certifier}によるハラール認証を取得しています（認証番号：{cert_no}、範囲：厨房全体、有効期限：{valid_until}）。 |
| `certified.specific_menu` | branch/dish, scope `specific_menu` | Dishes marked on our menu are halal-certified by {certifier} (certificate no. {cert_no}, valid until {valid_until}). Other dishes are not covered by this certification. | メニューに表示した料理は{certifier}によるハラール認証の対象です（認証番号：{cert_no}、有効期限：{valid_until}）。その他の料理は本認証の対象外です。 |
| `certified.specific_ingredients` | branch, scope `specific_ingredients` | At this branch, {covered_ingredients} are covered by halal certification from {certifier} (certificate no. {cert_no}, valid until {valid_until}). The kitchen and other ingredients are not covered by this certification. | 当店舗では、{covered_ingredients}が{certifier}によるハラール認証の対象です（認証番号：{cert_no}、有効期限：{valid_until}）。厨房およびその他の食材は本認証の対象外です。 |
| `halal_ingredients` | branch/dish, `halal_ingredients` | At this branch, {covered_ingredients} come from suppliers holding halal certification, as recorded in our halal register. This branch's kitchen is not halal-certified. Please ask our staff about preparation if you have specific requirements. | 当店舗では、{covered_ingredients}についてハラール認証を受けた仕入先のものを使用しています（ハラール登録記録による）。当店舗の厨房はハラール認証を取得していません。調理方法についてはスタッフにお尋ねください。 |
| `no_claim` | `no_claim`, and fallback when a record is not active | Please ask our staff at this branch about ingredients and preparation. | 食材や調理方法については、この店舗のスタッフにお尋ねください。 |
| `branch_default` | dish, `halal_basis = branch_default` (default) | Halal information is shown for each branch. Branches offering this dish: {branch_links} | ハラールに関する情報は店舗ごとに表示しています。この料理を提供している店舗：{branch_links} |
| `brand.neutral` | home/halal page when no approved brand statement | Halal information is shown for each branch. | ハラールに関する情報は店舗ごとに表示しています。 |
| `last_verified` | all halal claim blocks | Last verified: {date} | 最終確認日：{date} |
| `alcohol.served.yes` / `.no` | `alcohol_served` yes / no (unknown → hidden) | Alcoholic drinks are served at this branch. / Alcoholic drinks are not served at this branch. | この店舗ではアルコール飲料を提供しています。／この店舗ではアルコール飲料を提供していません。 |
| `alcohol.cooking.yes` | `used_in_cooking = yes` | Alcohol is used in cooking some dishes at this branch. Please ask our staff which dishes. | この店舗では一部の料理の調理にアルコールを使用しています。該当する料理はスタッフにお尋ねください。 |
| `alcohol.cooking.no` | `used_in_cooking = no` (spec-sheet evidence for every component) | Alcohol is not used in cooking at this branch. | この店舗では調理にアルコールを使用していません。 |
| `allergen.state.contains` / `.may_contain` / `.not_contained` / `.not_verified` | state labels | Contains / May contain (cross-contact) / Not contained (checked) / Not verified | 含む／含む可能性あり（混入の可能性）／含まない（確認済み）／未確認 |
| `allergen.summary.contains` / `.may_contain` | summary above table; omitted when empty | Contains: {list} / May contain (cross-contact): {list} | 含む：{list}／混入の可能性あり：{list} |
| `allergen.banner.not_verified` | any `not_verified` item or `momo_allergen_review_required` | Allergen information not verified — please ask staff. | アレルゲン情報は未確認です。スタッフへお尋ねください。 |
| `allergen.disclaimer.voluntary` | every dish page | Allergen information is provided voluntarily for reference. It may change when suppliers or recipes change, and ingredients may differ between branches. | アレルゲン情報は参考として任意で提供しています。仕入れ先やレシピの変更により変わる場合があり、店舗によって食材が異なることがあります。 |
| `allergen.cross_contact` | only when food-safety owner confirmation is recorded (`momo_allergen_cross_contact_confirmed`) ⟦TBD per branch⟧ | Our kitchen also handles other ingredients and may share equipment and frying oil, so we cannot guarantee the absence of trace amounts. | 同じ厨房で他の食材も扱っており、調理器具や揚げ油を共用する場合があるため、微量の混入がないことは保証できません。 |
| `allergen.tell_staff` | every dish page | If you have a severe allergy, please tell our staff when you arrive and when ordering. We will tell you honestly if we cannot prepare a dish for you. | 重いアレルギーをお持ちの方は、ご来店時およびご注文時に必ずスタッフへお申し出ください。対応できない料理の場合は、その旨を正直にお伝えします。 |
| `allergen.last_verified` | dish page with ≥ 1 verified state | Information last verified on {date}. | 最終確認日：{date} |
| `allergen.group.mandatory` / `.recommended` / `.other` | table group headings | Specified ingredients (9 — mandatory for packaged food) / Recommended items (20) / Other ingredients | 特定原材料（9品目・容器包装食品の表示義務対象）／特定原材料に準ずるもの（20品目）／その他 |
| `ingredients.heading` | dish page | Key ingredients (not a complete list) | 主な食材（すべての食材ではありません） |

Allergen item names (29) are seeded from Doc 11 §9.1 (`config/allergens.php`; see spec `menu-dietary`).

### 4.4 Global, navigation and CTA strings (Doc 07 §6.5 + Doc 06 §3.8 six-language drafts)

| Key | EN | JA | AR | UR | HI | BN |
|---|---|---|---|---|---|---|
| `nav.menu` | Menu | メニュー | القائمة | مینو | मेनू | মেনু |
| `nav.branches` | Branches | 店舗一覧 | الفروع | برانچیں | शाखाएँ | শাখাসমূহ |
| `nav.halal` | Halal | ハラール | حلال | حلال | हलाल | হালাল |
| `nav.about` | About | 私たちについて | من نحن | ہمارے بارے میں | हमारे बारे में | আমাদের সম্পর্কে |
| `nav.reservation` | Reservation | 予約リクエスト | طلب حجز | ریزرویشن کی درخواست | आरक्षण अनुरोध | রিজার্ভেশন অনুরোধ |
| `nav.offers` | Offers | お得な情報 | العروض | آفرز | ऑफ़र | অফার |
| `nav.faq` | FAQ | よくある質問 | الأسئلة الشائعة | عام سوالات | सामान्य प्रश्न | সাধারণ প্রশ্নাবলি |
| `nav.contact` | Contact | お問い合わせ | اتصل بنا | رابطہ کریں | संपर्क करें | যোগাযোগ |
| `nav.gallery` | Gallery | ギャラリー | معرض الصور | گیلری | गैलरी | গ্যালারি |
| `nav.news` | News | ニュース | الأخبار | خبریں | समाचार | খবর |
| `bc.home` | Home | ホーム | الرئيسية | ہوم | होम | হোম |
| `cta.request_table` | Request a table | 予約リクエスト | اطلب حجز طاولة | میز کی درخواست کریں | टेबल का अनुरोध करें | টেবিলের অনুরোধ করুন |
| `cta.request_table_branch` | Request a table at this branch | この店舗に予約リクエスト | اطلب طاولة في هذا الفرع | اس برانچ میں میز کی درخواست کریں | इस शाखा में टेबल का अनुरोध करें | এই শাখায় টেবিলের অনুরোধ করুন |
| `cta.find_branch` | Find a branch | 店舗を探す | ابحث عن فرع | برانچ تلاش کریں | शाखा खोजें | শাখা খুঁজুন |
| `cta.see_branches` | See branches | 店舗一覧を見る | عرض الفروع | برانچیں دیکھیں | शाखाएँ देखें | শাখাগুলো দেখুন |
| `cta.call` | Call | 電話する | اتصل | کال کریں | कॉल करें | কল করুন |
| `cta.directions` | Directions | 道順を見る | الاتجاهات | راستہ دیکھیں | रास्ता देखें | পথনির্দেশ |
| `cta.open_maps` | Open in Google Maps | Googleマップで開く | افتح في خرائط جوجل | گوگل میپس میں کھولیں | Google Maps में खोलें | গুগল ম্যাপে খুলুন |
| `cta.view_menu` | View menu | メニューを見る | عرض القائمة | مینو دیکھیں | मेनू देखें | মেনু দেখুন |
| `cta.details` | Details | 詳細 | التفاصيل | تفصیلات | विवरण देखें | বিস্তারিত দেখুন |
| `cta.halal_info` | Halal information | ハラール情報 | معلومات الحلال | حلال معلومات | हलाल जानकारी | হালাল তথ্য |
| `cta.request_other_time` | Request another time | 別の日時でリクエスト | اطلب وقتًا آخر | کسی اور وقت کی درخواست کریں | दूसरे समय का अनुरोध करें | অন্য সময়ের জন্য অনুরোধ করুন |
| `offers.see_all` | See all offers | すべての特典を見る | جميع العروض ⟦re-translate⟧ | تمام آفرز ⟦re-translate⟧ | सभी ऑफ़र ⟦re-translate⟧ | সব অফার ⟦re-translate⟧ |
| `branch.copy_address` | Copy Japanese address | 住所をコピー | نسخ العنوان الياباني | جاپانی پتہ کاپی کریں | जापानी पता कॉपी करें | জাপানি ঠিকানা কপি করুন |
| `res.submit` | Send request | リクエストを送信 | إرسال الطلب | درخواست بھیجیں | अनुरोध भेजें | অনুরোধ পাঠান |
| `res.submitted.status_link` | View request status | リクエストの状況を確認する | عرض حالة الطلب | درخواست کی صورتحال دیکھیں | अनुरोध की स्थिति देखें | অনুরোধের অবস্থা দেখুন |
| `status.cancel.cta.pending` | Cancel this request | このリクエストを取り消す | إلغاء الطلب ⟦re-translate⟧ | درخواست منسوخ کریں ⟦re-translate⟧ | अनुरोध रद्द करें ⟦re-translate⟧ | অনুরোধ বাতিল করুন ⟦re-translate⟧ |
| `menu.filters.clear` | Clear filters | 条件をクリア | إعادة ضبط التصفية ⟦re-translate⟧ | فلٹر ری سیٹ کریں ⟦re-translate⟧ | फ़िल्टर रीसेट करें ⟦re-translate⟧ | ফিল্টার রিসেট করুন ⟦re-translate⟧ |
| `lang.switch` | Switch | 切り替える | تبديل | تبدیل کریں | बदलें | পরিবর্তন করুন |
| `ftr.privacy` | Privacy Policy | プライバシーポリシー | سياسة الخصوصية | رازداری کی پالیسی | गोपनीयता नीति | গোপনীয়তা নীতি |
| `ftr.terms` | Terms of Use | 利用規約 | شروط الاستخدام | شرائطِ استعمال | उपयोग की शर्तें | ব্যবহারের শর্তাবলি |
| `ftr.res_policy` | Reservation Policy | 予約ポリシー | سياسة الحجز | ریزرویشن پالیسی | आरक्षण नीति | রিজার্ভেশন নীতি |
| `ftr.ext_trans` | External Transmission & Cookies | 外部送信・Cookieについて | الإرسال الخارجي وملفات تعريف الارتباط | بیرونی ترسیل اور کوکیز | बाहरी प्रेषण और कुकीज़ | বাহ্যিক প্রেরণ ও কুকিজ |
| `ftr.accessibility` | Accessibility | アクセシビリティ | إمكانية الوصول | رسائی | सुलभता | প্রবেশযোগ্যতা |
| `ftr.analytics` | Analytics settings | アクセス解析の設定 | إعدادات التحليلات | اینالیٹکس کی ترتیبات | एनालिटिक्स सेटिंग्स | অ্যানালিটিক্স সেটিংস |
| `media.illustration` | Image for illustration | 写真はイメージです ⟦counsel⟧ | الصورة للتوضيح فقط | تصویر صرف وضاحت کے لیے ہے | चित्र केवल उदाहरण के लिए | ছবিটি শুধু উদাহরণের জন্য |

`⟦re-translate⟧` = Doc 06 flags these ar/ur/hi/bn drafts as stale (EN source changed) — seed them with status `needs_review`.

EN/JA-only keys of Doc 07 §6.5:

| Key | English | Japanese (draft) |
|---|---|---|
| `branch.open_now` | Open now · until {time} (last order {lo}) | 営業中・{time}まで（ラストオーダー{lo}） |
| `branch.closed_now` | Closed now · opens {day} {time} | 営業時間外・{day} {time}から営業 |
| `branch.temp_closed` | Temporarily closed — reopens {date} | 臨時休業中（{date}より営業再開） |
| `branch.copy_address.copied` | Address copied | 住所をコピーしました |
| `price.tax_incl` | (tax incl.) | （税込） |
| `menu.branch_select.all` | All branches — reference prices | 全店舗（参考価格） |
| `menu.sold_out` | Sold out today | 本日売り切れ |
| `menu.unavailable` | Not available at this branch now | この店舗では現在ご提供していません |
| `menu.advance_order` | Order at least {hours} h ahead | {hours}時間前までに要予約注文 |
| `menu.not_available` | Not currently available | 現在ご提供していません |
| `menu.filters.empty` | No dishes match these filters. | 条件に合う料理がありません。 |
| `lang.suggest` | This page is available in {language}. (each language's string written in that language) | このページは日本語でもご覧いただけます。 |
| `lang.not_available` | This page is not yet available in {language}. | このページは{language}ではまだご覧いただけません。 |
| `skip.main` | Skip to main content | メインコンテンツへ移動 |
| `404.title` | Page not found | ページが見つかりません |
| `offers.title` | Offers | お得な情報 |
| `offers.intro` | Offers are used at the branch. Mention the offer when you request a table or when you arrive. | 特典は店舗でご利用いただけます。予約リクエストの際、またはご来店時にお伝えください。 |
| `offers.valid` | Valid {start} – {end} | 期間：{start}～{end} |
| `offers.scope.all` | All branches | 全店舗 |
| `offers.conditions` / `offers.conditions.show_all` | Conditions / Show all conditions | 条件／条件をすべて表示 |
| `offers.see_current` (hero, only while ≥ 1 featured offer active) | See current offers | 現在の特典を見る |
| `offers.empty` | There are no current offers. Please check again later. | 現在ご案内している特典はありません。後日あらためてご確認ください。 |
| `offers.mention_hint` | Mention the offer: {title} | 特典名をご記入ください：{title} |

### 4.5 Privacy notice and consent texts (Doc 11 §4.2 — counsel drafts; JA master governs)

| Key | EN draft | JA draft (master) |
|---|---|---|
| `res.notice.collection` (= Doc 11 `notice.reservation`) | We use the details you provide to handle your reservation request at the branch you select and to contact you about it. See our Privacy Policy for details, including how to request access, correction or deletion. | ご入力いただいた情報は、選択された店舗でのご予約リクエストの受付・対応およびそれに関するご連絡のために利用します。開示・訂正・削除等のご請求方法を含む詳細は、プライバシーポリシーをご確認ください。 |
| `res.label.consent` (= `consent.reservation`; required, unticked) | I agree to the Privacy Policy and the Reservation Policy. I understand this is a request and is not confirmed until the branch accepts it. If I mention allergies or health needs in my request, they will be used only to prepare for my visit. | プライバシーポリシーおよび予約ポリシーに同意します。本リクエストは店舗が承諾するまで確定しないことを理解しました。特記事項にアレルギーや健康に関する情報を記載した場合、その情報はご来店の準備のためにのみ利用されることに同意します。 |
| `consent.special_request` (only if `special_request_separate_consent` = on, default off) | I agree that the health or allergy information in my note will be used only to prepare for my visit. | 特記事項に記載した健康・アレルギーに関する情報が、ご来店の準備のためにのみ利用されることに同意します。 |
| `notice.contact` | We use the details you provide only to respond to your inquiry. See our Privacy Policy for details. | ご入力いただいた情報は、お問い合わせへの回答のためにのみ利用します。詳しくはプライバシーポリシーをご確認ください。 |
| `consent.contact` (required, unticked) | I agree to the Privacy Policy. | プライバシーポリシーに同意します。 |

Note: `res.label.consent` contains 確定 only in the negated form (しない) — add it to the §3.3 allow-list. "Privacy Policy" /
"Reservation Policy" inside labels are links to the same-language pages (`momo_privacy_page`, `momo_reservation_policy_page`).

### 4.6 Corporate page strings (Doc 04/07/08 wording; keys `[Prop]` unless noted)
Home section headings reuse existing keys: offers `offers.title`, branches `nav.branches`, halal `cta.halal_info`.

| Key | English | Japanese |
|---|---|---|
| `home.branches.see_all` (Doc 07 §5.1) | See all branches | すべての店舗を見る [Prop JA] |
| `home.featured.title` | Featured dishes | おすすめ料理 [Prop JA] |
| `home.halal.title` → `cta.halal_info` | Halal information | ハラール情報 |
| `home.how.title` (FR-CORP-001(f)) | How reservations work | 予約リクエストの流れ [Prop JA] |
| `home.how.step1` (Doc 08 CMP-StepList) | Send a request | リクエストを送信 [Prop JA] |
| `home.how.step1.text` | Choose a branch, date and time, and send your request. | 店舗・日付・時間を選んでリクエストを送信します。 [Prop] |
| `home.how.step2` | The branch reviews it | 店舗が内容を確認 [Prop JA] |
| `home.how.step2.text` | Your request is not a booking until the branch accepts it. | 店舗が承認するまで、お席は確保されていません。 [Prop] |
| `home.how.step3` | We email you the decision | 結果をメールでお知らせ [Prop JA] |
| `home.how.step3.text` | You receive an email when the branch accepts or declines. | 店舗の承認またはお断りをメールでお知らせします。 [Prop] |
| `halal.title` (C03 H1) | Halal information | ハラール情報 |
| `halal.explainer.title` (Doc 08 "What the statements mean") | What the statements mean | 表示の意味 [Prop JA] |
| `halal.explainer.certified` (**CAP-DCA + counsel**) | Halal-certified: a named certification body has certified this branch, listed dishes or listed ingredients. We show the certifier, certificate number, scope and validity. | ハラール認証取得：認証機関が、この店舗、表示した料理、または表示した食材を認証しています。認証機関・認証番号・範囲・有効期限を表示しています。 [Prop] |
| `halal.explainer.ingredients` (**CAP-DCA + counsel**) | Halal ingredients: the listed ingredients come from suppliers holding halal certification. The branch itself is not certified. | ハラール食材使用：表示した食材はハラール認証を受けた仕入先のものです。店舗自体は認証を受けていません。 [Prop] |
| `halal.explainer.none` (**CAP-DCA + counsel**) | No statement: we make no halal statement for this branch. Please ask our staff about ingredients and preparation. | 表示なし：この店舗についてはハラールに関する表示を行っていません。食材や調理方法はスタッフにお尋ねください。 [Prop] |
| `halal.col.branch` / `.statement` / `.certifier` / `.scope` / `.cert_no` / `.valid_until` / `.alcohol` / `.last_verified` | Branch / Statement / Certifier / Scope / Certificate no. / Valid until / Alcohol / Last verified | 店舗／表示内容／認証機関／範囲／認証番号／有効期限／アルコール／最終確認日 [Prop JA] |
| `halal.scope.whole_kitchen` / `.specific_menu` / `.specific_ingredients` | Whole kitchen / Specific dishes / Specific ingredients | 厨房全体／特定の料理／特定の食材 [Prop JA] |
| `halal.na` (FR-CORP-003(c), halal_ingredients rows) | Not applicable | 該当なし [Prop JA] |
| `halal.verify_link` (FR-CORP-003(e)) | Verify with the certifier | 認証機関で確認する [Prop JA] |
| `halal.mark_alt` (Doc 07 §5.3) | Halal certification mark of {certifier} | {certifier}のハラール認証マーク [Prop JA] |
| `halal.ask_staff.title` (Doc 08) | Ask our staff | スタッフにお尋ねください [Prop JA] |
| `faq.group.reservations` / `.halal_dietary` / `.allergens` / `.branches_access` / `.payments` | Reservations / Halal & dietary / Allergens / Branches & access / Payments | 予約について／ハラール・食事制限／アレルギー／店舗・アクセス／お支払い [Prop JA] |
| `contact.title` → `nav.contact`; `contact.notice.reservations` (Doc 07 §5.11) | For table requests, please use the reservation form. | お席のご予約は予約リクエストフォームをご利用ください。 [Prop JA] |
| `contact.label.topic` / `.branch` / `.message` | Topic / Branch (optional) / Message | お問い合わせ内容／店舗（任意）／メッセージ [Prop JA] |
| `contact.topic.general` / `.group_event` / `.feedback` / `.media` / `.other` | General question / Group or event / Feedback / Media / Other | 一般的なご質問／団体・イベント／ご意見／取材・メディア／その他 [Prop JA] |
| `contact.submit` (Doc 07) | Send message | メッセージを送信 [Prop JA] |
| `contact.success` (Doc 04 FR-CNT-001(b); see C2) | Thank you. We will reply by email. | お問い合わせありがとうございます。メールにてご返信いたします。 [Prop JA] |
| `contact.err.topic.required` / `contact.err.message.required` / `.length` | Choose a topic. / Enter your message. / Your message can be up to 2,000 characters. | お問い合わせ内容を選択してください。／メッセージを入力してください。／メッセージは2,000文字以内で入力してください。 [Prop JA] |
| `news.empty` (Doc 07 §5.13) | No news yet | お知らせはまだありません [Prop JA] |
| `legal.last_updated` | Last updated: {date} | 最終更新日：{date} [Prop JA] |
| `legal.version` | Version {version} | 版：{version} [Prop JA] |
| `legal.ja_authoritative` (FR-CORP-006(c); wording ⟦counsel⟧; not shown on ja) | The Japanese version of this document is authoritative. | (not rendered on ja) |
| `legal.toc` | Contents | 目次 [Prop JA] |
| `404.body` (as spec `i18n-seo-analytics`) | The page you were looking for does not exist or has moved. | お探しのページは存在しないか、移動しました。 [Prop] |
| `maint.title` | We are carrying out maintenance | ただいまメンテナンス中です [Prop] |
| `maint.body` | This website is temporarily unavailable. Please try again later. To request a table, please call the branch. | このウェブサイトは一時的にご利用いただけません。しばらくしてから再度アクセスしてください。お席のご予約は店舗へお電話ください。 [Prop] |
| `maint.return` | Expected back: {time} | 再開予定：{time} [Prop] |
| `maint.phone` (only if HO phone set ⟦TBD FR-FND-005⟧) | Head Office: {phone} | 本部：{phone} [Prop] |
| `anl.optout.label` / `.on` / `.off` / `.off_done` / `.on_done` / `.gpc` | Allow analytics / On / Off / Analytics is now off for this browser. / Analytics is now on for this browser. / Your browser sends a Global Privacy Control signal, so analytics stays off. | アクセス解析を許可する／オン／オフ／このブラウザでのアクセス解析をオフにしました。／このブラウザでのアクセス解析をオンにしました。／お使いのブラウザがGlobal Privacy Control信号を送信しているため、アクセス解析はオフのままです。 [Prop, aligned with spec `i18n-seo-analytics`] |
| `a11y.breadcrumb` | Breadcrumb | パンくずリスト [Prop] |

### 4.7 Admin and staff messages (ja/en; DEC-037)

| Key | English (source) | Japanese |
|---|---|---|
| `admin.media.alt_required` (FR-ADM-005(d)) | Add alternative text before publishing | 公開前に代替テキストを入力してください [Prop JA] |
| `admin.media.demo_not_allowed` (FR-ADM-005(f)) | Demo images cannot be used here. Upload a real photo. | ここではデモ画像を使用できません。実際の写真をアップロードしてください。 [Prop JA] |
| `admin.sample.publish_blocked` (FR-MENU-004(g)) | Sample dishes cannot be published on the live site | サンプル料理は本番サイトで公開できません [Prop JA] |
| `admin.legal.publish_denied` (F-CORP-06) | Only Head Office can publish legal pages | 法務ページを公開できるのは本部のみです [Prop JA] |
| `admin.legal.registry_missing` (Doc 11 §4.3) | Add a consent-registry entry for this policy version before saving. | 保存する前に、このポリシー版の同意バージョン登録を追加してください。 [Prop] |
| `admin.offer.err.required` / `.end_before_start` / `.ended` / `.no_branch` / `.dish_unpublished` / `.featured_limit` (F-CORP-09) | Complete title, conditions and validity dates. / The end must be after the start. / This offer has already ended. / Select at least one branch. / The linked dish is not published. / Only the first {n} featured offers are shown on the homepage. | タイトル・条件・有効期間を入力してください。／終了日時は開始日時より後にしてください。／この特典は既に終了しています。／店舗を1つ以上選択してください。／リンク先の料理は公開されていません。／トップページに表示されるのは最初の{n}件のおすすめ特典のみです。 [Prop JA] |
| `admin.offers.empty` / `admin.offers.create` (Doc 07 §5.39) | No offers yet / Create offer | 特典はまだありません／特典を作成 [Prop JA] |
| `admin.claims.term_warning` (Doc 11 §8.6) | This text contains restricted claim wording ("{term}"). Halal and allergen statements must come from approved templates. | 制限された表示用語（「{term}」）が含まれています。ハラール・アレルゲンの表示は承認済みテンプレートのみ使用できます。 [Prop] |
| `admin.strings.placeholder_mismatch` | The translation must contain exactly these placeholders: {list} | 翻訳には次のプレースホルダーを正確に含めてください：{list} [Prop] |
| `admin.translation.gaps_none` | No gaps for {language} | 「{language}の未翻訳はありません」 |
| `staff.err.*`, `staff.warn.*` | Doc 10 §7.3 table (version_conflict, invalid_transition, forbidden_branch, forbidden_field, reason_required, attendance_window, expired, customer_not_notified, needs_action_closure, captcha_unverified) — EN/JA as given there; owned by spec `reservation` | — |

### 4.8 Localisation table (Doc 07 §6.10; FR-I18N-006)

| Lang | Locale tag | Long date | Short date | Time | JST label | Price | Branch phone |
|---|---|---|---|---|---|---|---|
| ja | `ja-JP-u-ca-gregory-nu-latn` | 2026年12月3日（木） | 12/3（木） | 19:00 (`h23`) | not shown | ¥1,500（税込） | national 06-xxxx-xxxx |
| en | `en-GB-u-ca-gregory-nu-latn` | Thu 3 Dec 2026 | Thu 3 Dec | 19:00 | "JST" after time (reservation screens/emails) | ¥1,500 (tax incl.) | +81 6-xxxx-xxxx |
| ar / ur | `ar-u-ca-gregory-nu-latn` / `ur-u-ca-gregory-nu-latn` | CLDR `EEEEdMMMMy` | `EEEdMMM` | 19:00 | translated label (native reviewer) | ¥1,500 isolated LTR + translated tax label | international, isolated LTR |
| hi / bn | `hi-u-…-nu-latn` / `bn-u-…-nu-latn` (override bn Bengali digits) | CLDR `EEEEdMMMMy` | `EEEdMMM` | 19:00 | translated label | ¥1,500 + translated tax label | international |
| Admin | ja/en | ISO 2026-12-03 in tables | — | 19:00 | — | ¥1,500 | guest phone as entered + E.164 |

Input digits normalised to ASCII before validation: U+0660–0669, U+06F0–06F9, U+0966–096F, U+09E6–09EF, U+FF10–FF19.
References render `XXXX-XXXX` inside an LTR isolate (DEC-051). Time zone always Asia/Tokyo.

### 4.9 UI-001 strings that must be replaced (Doc 07 §6.9 — never import prototype tables as-is)

| UI-001 string | Problem | Replace with |
|---|---|---|
| "Order Now" / 今すぐ注文 | ordering out of scope (DEC-042) | `cta.request_table` |
| "Add to Cart", "+ Add", "Add to Order" | ordering | card links to dish page; `cta.details` |
| "Book A Table" / 席を予約 | implies booking | `cta.request_table` |
| "Confirm reservation" / 予約を確定 | confirmation on submit | `res.submit` |
| "Reservation confirmed!" / ご予約を承りました; "See you soon at MOMO's Cafe." / ご来店をお待ちしております。 | false confirmation | `status.pending` + `res.submitted.next` |
| "100% Halal Certified Kitchen", "100% Halal Verified", "Strictly halal certified", "Halal Status: 100% Halal Verified Kitchen", "حلال Certified" badge | unverified claim | register-driven templates §4.3 only |
| "Portion price", "Base Price" | not in data model | `price.tax_incl` with single price |
| "Instant Seating Request", "Guarantee your seating", "Direct reservation" | guaranteed booking | `res.intro` |
| "Dining in? Tap Table 05 to order", "Order Online or at Table", "Pay at Table or Counter" | table-QR ordering dropped | remove |
| "Open daily 12:00 PM – 10:45 PM", "+81 6 6214 2716", "Osaka, Japan" (hard-coded) | conflicting public data (FACT-04) | branch record values after GATE-01 |
| "12:00 PM", "6:30 PM" | 24-hour canonical | "12:00", "18:30" |
| "Continuous kitchen service from noon till late", "Welcoming Diners & Tour Groups", "Tap to call our Osaka team directly" (UIA-077) | unsourced statements | remove |
| "51 dishes / 8 categories / 100 % halal" counters; claims marquee | stale / prohibited | remove |

---

## 5. Prohibited-term scan list (Doc 07 §6.6 = Doc 11 §8.6 + §9.5 + request-context + ordering)

| Class | en | ja | ar / ur / hi / bn |
|---|---|---|---|
| certification (only via `certified.*` template of an active record) | halal certified, certified halal, halal certified kitchen | ハラール認証, 認証取得, bare 認証 next to ハラール/ハラル | ar حلال معتمد; ur حلال سرٹیفائیڈ; rest ⟦native reviewer — GATE-04⟧ |
| absolute (never as free text) | 100% halal, 100% Halal Prepared, fully halal, strictly halal certified, halal guaranteed | 100%ハラール, 完全ハラール | ⟦TBD⟧ |
| group | All branches halal, fully halal restaurant group | — | ⟦TBD⟧ |
| practice | Pork-free kitchen, alcohol-free restaurant | 豚肉不使用の厨房 | ⟦TBD⟧ |
| recognition / religious | Approved by JAKIM/BPJPH/MUIS (without documented recognition); permissible for all Muslims | — | ⟦TBD⟧ |
| allergen (BR-DIET-03) | allergen-free, allergy-free, allergy-safe, safe for allergies, safe for allergy sufferers, 100% safe, no risk, free from {allergen}, gluten-free, nut-free, dairy-free | アレルゲンフリー, アレルギー対応 / アレルギー対応済み (safety sense), 完全除去, 安全です (allergy context), アレルギーの方も安心, 100%安全, リスクなし, 〇〇フリー, グルテンフリー | ⟦TBD⟧ |
| ordering (UI-001, DEC-042) | order now, order online, add to cart, add to order, checkout, place order, takeout, delivery (ordering context), coupon code, redeem | 今すぐ注文, カートに追加, 注文を確定 | ar اطلب الآن; rest ⟦TBD⟧ |
| request_context (pre-acceptance contexts only) | booked, you're booked, reservation confirmed, booking confirmed, confirm table reservation, guarantee your seating, instant seating, your table is reserved, reservation complete, your reservation | 予約完了, 予約確定, 予約が確定, 予約を確定, ご予約を承りました, このご予約, 席を予約 (as a status) | ar تأكيد الحجز, تم تأكيد الحجز; rest ⟦TBD⟧ |
| images | any halal seal/badge/certifier logo; file names/alt containing "halal"/"seal" outside the register mark | — | — |

Scan base terms (claim-term report): `halal`, `ハラール`, `ハラル`, `حلال`, `हलाल`, `হালাল`, `certified`, `認証`, `100%`.
Exceptions: §3.3 allow-list. Offer-specific: "limited time" only when `end_at` set; comparison patterns ("usual price", "% off",
通常価格) require `momo_offer_reference_basis` (warning) (CMP-040/041).

---

## 6. Terminology glossary (Doc 07 §6.8 + Doc 06 §5.6)

| Concept | English | Japanese | Rule |
|---|---|---|---|
| Reservation request | reservation request / request | 予約リクエスト / リクエスト | Never 予約 alone for an unaccepted request |
| Branch | branch | 店舗 | — |
| Reference | reference | 受付番号 | 8 chars shown `XXXX-XXXX` |
| Halal | halal | ハラール | Never ハラル (SEO-001-R037) |
| Last order | last order | ラストオーダー | "LO" only in compact UI |
| Tax included | tax incl. | 税込 | Every price (DEC-022) |
| Japan Standard Time | JST | 日本時間 | Label only in non-ja reservation screens/emails (DEC-062) |
| Not verified (allergen) | not verified | 未確認 | Never "unknown" |
| Offer | offer | 特典 / お得な情報 | Informational; never "coupon" (クーポン) |
| Accept / Decline (staff) | Accept / Decline | 承認する / お断りする | — |
| Superlatives | — | — | No "best", "No.1", "most popular" without a named third-party source (SEO-001-R042) |
| Photos | — | — | Real photos; demo only on dish/offer images with `media.illustration` caption |

---

## 7. Navigation labels and placement (Doc 06 §3, FR-CORP-008/009)

| Element | Content (start → end; mirrored in RTL by logical CSS, never by DOM reversal) |
|---|---|
| Desktop header ≥ 1024 | Logo (→ current-language home; never mirrored) · `nav.menu` · `nav.branches` ▾ (published branches: name + area, generated from branch records, plus an "All branches" link → `/branches/`, label `offers.scope.all`) · `nav.offers` (only while ≥ 1 active offer translated in the language — DEC-060) · `nav.halal` · `nav.about` · `nav.reservation` · language switcher · button `cta.request_table` |
| Tablet 768–1023 | Logo · switcher · `cta.request_table` · hamburger |
| Mobile < 768 | Logo · switcher (current native name) · hamburger; sticky action bar |
| Hamburger order | Menu · Branches (expandable) · Halal information (`cta.halal_info`) · Offers (conditional) · About · FAQ · Contact · Gallery (C) · News (C) · `cta.request_table` button · language list (native names) · branch phones (tel) |
| Sticky bar | Branch page: `cta.request_table` (prefilled) · `cta.call` · `cta.directions`; branch not accepting: Call · Directions; other pages: `cta.request_table` · `nav.branches`; hidden on C09/C10/C11; Call omitted when no phone |
| Footer columns | Branches (name link + tel) · Explore: Menu · Offers (always) · Halal information · About · FAQ · Contact · Gallery (C) · News (C) · Legal & privacy: `ftr.privacy` · `ftr.terms` · `ftr.res_policy` · `ftr.ext_trans` · `ftr.accessibility` · `ftr.analytics` · Social & languages · bottom line "© {year} {momo_legal_operator_name}" (omitted while empty) |
| Language names | 日本語 · English · العربية · اردو · हिन्दी · বাংলা (enabled only; no flags) |
| Breadcrumbs | `bc.home` › {page}; e.g. ホーム › 店舗一覧 › {店舗名}; Halal: Home › Halal information / ホーム › ハラール情報; Offers: Home › Offers / ホーム › お得な情報; not on Home or reservation task pages |
| Forbidden | No keyword-anchor/city lists in footer; no public "Admin" link |

---

## 8. Corporate page content outlines

All pages: one H1; sections without content are not rendered; no restaurant facts typed as text; no claims outside templates;
JA source written first (TK-01-12 content freeze, prohibited-term scan clean); other languages shown only when `approved`.

### 8.1 Home (SCR-C01, FR-CORP-001)
| # | Section | Source / string | Empty rule |
|---|---|---|---|
| 1 | Notice banner(s) | active site-wide notices, `warning` before `info`, newest first | hidden |
| 2 | Hero: H1 = `momo_tagline` (brand line ⟦TBD hero copy — Owner⟧), lead (page content), `cta.request_table` → `/{lang}/reservation/`, `cta.find_branch` → `/{lang}/branches/`, link `offers.see_current` (featured active offer only), category chips → C07 | brand + strings | link hidden when none; image missing → brand colour panel |
| 3 | Offers: `offers.title` + up to ⟦3 — Q-414⟧ featured active translated offers + `offers.see_all` | ENT-25 | section omitted (no message) |
| 4 | Branch teaser: `nav.branches` H2, card per published non-test branch (name, concept, area, open-now, Request · Directions · Details) + `home.branches.see_all` | ENT-02/03/04 | omitted if none |
| 5 | Featured dishes `home.featured.title`: max ⟦6 — Q-414⟧ featured, published, translated; photo, name, price + `price.tax_incl`, spice | ENT-06 | omitted |
| 6 | Halal summary `cta.halal_info`: brand statement template, else `brand.neutral` + link to `/halal/` | ENT-01 | never "100%", "certified", mark unless active certified template |
| 7 | `home.how.title` + `<ol>` steps 1–3 (+ `res.submitted.promise` only if DEC-013 approved) | strings | always |
Forbidden: counters, claims marquee, "Order"/cart elements, carousel/autoplay.

### 8.2 About (SCR-C02, FR-CORP-002)
Outline: H1 (`nav.about` or page title) · intro · story sections (founder/chef, cuisine roots ⟦to confirm — Owner; e.g., "Bangladeshi/Pakistani home cooking" per SEO-001-R039 is unconfirmed⟧) · cuisine · team · real photos only (demo images refused) · CTA block (`cta.request_table`, links `cta.view_menu`, `cta.see_branches`). Rules: no halal certification wording (if halal is mentioned, link to `/halal/` instead), no superlatives without named source recorded in the revision note, no ratings. Not published until content exists; not LCCS but never mixed-language.

### 8.3 Halal information (SCR-C03, FR-CORP-003)
Outline: breadcrumb · H1 `halal.title` · brand statement (approved template via `momo_brand_halal_statement_key`, else `brand.neutral`) · per-branch register (table ≥ 768 px if ≤ 5 columns fit, else one card per branch; `<caption>`, `scope` headers) with columns `halal.col.*`: branch link · claim label (`halal.label.*`) + statement template · certifier · scope (`halal.scope.*`) · certificate no. · valid until · alcohol lines (`alcohol.*`, unknown omitted) · `last_verified` (= later of certificate and branch `verified_at`) · mark image only with `mark_permission` (alt `halal.mark_alt`) · `halal.verify_link` when a public register URL exists. Certifier/scope/number/validity only for active, in-validity, approved `certified`; `halal_ingredients` rows show `halal.na` in those cells; inactive → `no_claim` row. Then `halal.explainer.*` (3 cards) · `halal.ask_staff.title` box with branch links · FAQ excerpt (3 Halal & dietary items) · links FAQ, Branches, `cta.request_table`. Register failure → `brand.neutral` + ask-staff box (no stale claims). Page text editable by ROLE-04 (no claims); data CAP-DCA only. Fires `view_halal_information`.

### 8.4 FAQ (SCR-C13, FR-CORP-004 — Should)
Groups in order (`faq.group.*`): Reservations · Halal & dietary · Allergens · Branches & access · Payments ⟦TBD — Owner; hidden until approved answers exist⟧. Anchor chips per group; each question a `<button aria-expanded aria-controls>`; multiple open; `#{id}` opens it; answers in server HTML. Halal/allergen answers **link** to governed pages (C03, dish pages) instead of restating claims; FAQPage JSON-LD only if every marked-up Q&A is visible, text identical.
Question list [Prop — Content lead + Owner to confirm; answers must use confirmed facts only]:

| Group | Questions | Answer source / rule |
|---|---|---|
| Reservations | How do I request a table? · Is my table booked when I send a request? · How long does the branch take to reply? · How can I cancel or change my request? · Can I request for a large group or for today? · Do I need to pay a deposit? · I did not receive an email — what should I do? | BR-RES-01 wording; response time only if DEC-013 approved; cancellation per DEC-016 cut-off ⟦TBD⟧; party max/lead time → "please call"; `res.no_payment`; `res.carrier_tip` |
| Halal & dietary (SEO-001-R009, 8–12 Qs) | Is everything halal? · Is alcohol served? · Is alcohol used in cooking? · Where does the meat come from? · Are vegetarian dishes available? | link to `/halal/` per-branch statements; no free-text claims; answers only from register/approved templates |
| Allergens | Where can I find allergen information? · Can you guarantee a dish is free from an allergen? | link to dish pages; `allergen.disclaimer.voluntary` + `allergen.tell_staff` wording; never "free from" |
| Branches & access | What are the opening hours? · How do I get to the branch? · Is there a prayer space? | link to branch pages; facility values only if confirmed |
| Payments ⟦TBD⟧ | Which payment methods are accepted? | only after Owner confirms per branch |

### 8.5 Contact (SCR-C12, FR-CNT-001 — Should)
H1 `nav.contact` · `contact.notice.reservations` (+ link to C09, branch phones) · form: name* (1–100, `res.label.name`), email* (`res.label.email`), topic* (`contact.topic.*`), branch (optional), message* (1–2,000) · `notice.contact` · `consent.contact` (unticked) · `contact.submit`. Success `contact.success` (announced, form cleared). Email to HO inbox, Reply-To submitter, subject "[Contact] {topic label} — {branch code or 'All'}", line "Consent: yes — PP{x.y}"; optional auto-reply without message copy; nothing stored.

### 8.6 Legal pages (SCR-C15–C18, FR-CORP-006) — layout: breadcrumb · H1 · `legal.last_updated` + `legal.version` · `legal.ja_authoritative` (translations) · `legal.toc` (> 5 headings) · body at reading width (≤ 720 px); tables stack at 360 px; no PDF-only content.

**Privacy Policy `/privacy-policy/` (C15; LCCS; Doc 11 §4.1 checklist, JA master counsel-approved)**
| Row | Section | Required content |
|---|---|---|
| P-1 | Operator | legal entity ⟦TBD⟧, registered address ⟦TBD⟧, representative ⟦TBD⟧, corporate no. (optional); one block per operating entity |
| P-2 | Scope | website, reservation requests, contact inquiries; which branch is operated by which entity; joint-use statement if counsel requires |
| P-3 | Information collected | reservation fields, optional special request, contact fields, hashed abuse signals, cookies, analytics IDs, server logs; "we do not ask for religion, nationality, ID numbers, date of birth or payment data" |
| P-4 | Purposes | handle/accept/decline/manage requests and contact you; respond to inquiries; operate and secure the site incl. fraud/abuse prevention; aggregated usage analysis; legal compliance; no marketing use |
| P-5 | Special-care information | "We do not ask for religious or health information. If you choose to mention allergies or health needs in your request, we use it only to prepare your visit." + reference to consent text |
| P-6 | Third parties | none except as permitted by law; shared only with the chosen branch's staff and Head Office |
| P-7 | Entrustment | hosting, CDN/WAF, email delivery, mailboxes, spam protection, backup storage, maintenance developer; supervision by contract |
| P-8 | Storage location / foreign countries | country of each processor (e.g., Google/USA, Cloudflare/USA, vendor/Bangladesh where access exists), regime summary, measures, Art. 28 route |
| P-9 | Cookies / external transmission | link to `/external-transmission/`, how to opt out |
| P-10 | Retention | reservation PII ⟦12 months⟧ after reservation date; notifications 180 days; audit 24 months; backups up to 30 days |
| P-11 | Security control measures | organisational, human, physical, technical (2FA, TLS, branch-scoped access, logging, WAF, encrypted backups), external environment |
| P-12 | Requests | disclosure, correction, deletion, cessation; where to apply ⟦TBD⟧; identity check; response timeline ⟦14 days — counsel⟧; fee ⟦free — Owner⟧ |
| P-13 | Complaints / contact | privacy email ⟦TBD⟧, phone ⟦TBD⟧, hours ⟦TBD⟧, postal address ⟦TBD⟧ |
| P-14 | Marketing email | "We do not send marketing email." |
| P-15 | Children | ⟦counsel wording⟧ |
| P-16 | Changes | announcement method, effective date, version history table |
| P-17 | Language precedence | Japanese version prevails ⟦counsel⟧ |

**Terms of Use `/terms/` (C16; not LCCS)**: site use, governing law Japan, competent court ⟦PLACEHOLDER district court — counsel⟧, language precedence, version/last updated. No clause excluding all liability (Consumer Contract Act Arts. 8–10).

**Reservation Policy `/reservation-policy/` (C16 second URL; LCCS; always published; Doc 11 §4.4)** — items without an approved value are not shown:
| Item | Content rule |
|---|---|
| Nature of a request | A submitted request is not a reservation until the branch accepts it; acceptance by email (E02) and on the status page |
| Response time | only after DEC-013 value approved |
| Expiry | unanswered requests expire automatically before the decision cut-off; customer emailed (E05) |
| Short notice / large parties | inside lead time or above online maximum → by phone (branch phone shown) |
| Booking window / party limits | as configured per branch (values read from settings, never typed) |
| Customer cancellation | via email link until ⟦TBD cut-off — BR-RES-07⟧, or by phone |
| Branch cancellation | possible reasons in neutral terms; customer emailed (E04) |
| Late arrival / no-show | ⟦TBD — DEC-054⟧; no fees/deposits/penalties; no cross-branch blacklist |
| Allergies | tell staff directly; website info voluntary, for reference |
| Advance-order dishes | mention in special request; not ordered online |
| Email delivery | carrier whitelisting guidance (`res.carrier_tip` domain) |
| Time zone | all times JST; non-ja pages show "19:00 JST", ja omits |
| Alternative channels | phone/email for anyone unable to use the form |
| Payment | `res.no_payment` "No payment or deposit is required." |
Prohibited: liability exclusion, penalty/fee clauses, "guarantees a table", invented grace periods.

**External Transmission & Cookies `/external-transmission/` (C17; LCCS)** — table columns: service · provider (country) · information sent · purpose · opt-out. Rows must equal the build-time tag audit: T1 Google Tag Manager (Google LLC, US; not on submitted/status pages, never wp-admin; not loaded after opt-out/GPC) · T2 Google Analytics 4 (`_ga`, `_ga_<ID>`; pseudonymous client ID, redacted URL/title, referrer, events with branch_code/language/cta_location/party_size_band, device, approximate location; aggregated analytics; on-page opt-out + Google add-on) · T3 Cloudflare Turnstile (C09, C12 only; IP, browser/device/interaction signals; spam protection; alternative phone/email) · T4 CDN/WAF ⟦provider TBD⟧ · T5 first-party cookies (language choice `momo_lang_choice`, suggestion dismissal, `momo_analytics_optout`; staff login cookies) · Google Maps: nothing on load (static image; link opens google.com) · Not used: Meta Pixel, LINE Tag, Google Ads, YouTube/social embeds, Google Fonts. Opt-out block: `<button aria-pressed>` `anl.optout.label` with text state, status message `anl.optout.off_done`/`on_done`/`gpc`; applies without reload; cookie 1 year.

**Accessibility statement `/accessibility/` (C18; not LCCS)**: target WCAG 2.2 AA for customer templates and the reservation journey (internal standard — **not** described as legally mandatory); JIS X 8341-3 conformance **not claimed**; known limitations ⟦list at launch⟧; reasonable-accommodation contact (phone/email ⟦TBD⟧) for anyone unable to use the form (DEC-036, CMP-032).

### 8.7 404 / Maintenance / News / Gallery / Offers
- **404 (C19)**: HTTP 404, `noindex`, language from URL prefix (hidden language prefix → ja page); H1 `404.title`, `404.body`, four link cards `bc.home`, `nav.branches`, `nav.menu`, `cta.request_table`; language switcher.
- **Maintenance (C20)**: static HTML, 503 + `Retry-After` ⟦seconds — Q-419⟧; one block per enabled language (`lang`/`dir`), each `maint.title`, `maint.body`, `maint.return` (if set), `maint.phone` (if set) + branch phone list (exported at release); system fonts; no external resources.
- **News (C14, Could)**: title, date (locale format, JST), excerpt, image; 10 per page; `news.empty` if reached directly; not linked in nav until ≥ 1 item.
- **Gallery (C22, Could)**: real photos only, captions visible (not hover), alt per language; demo-flagged images refused.
- **Offers (C21)**: H1 `offers.title` · `offers.intro` · branch chips (only when scopes differ) · cards (image + `media.illustration` if flagged, eyebrow "Offer" (`offers.eyebrow` [Prop] Offer / 特典), title, summary, `offers.valid` (+ JST non-ja), scope, `offers.conditions`, CTA) · `offers.empty` + links `cta.view_menu`, `cta.see_branches`; `noindex` while empty. Offer text: prices tax-incl. with `price.tax_incl`; no coupon/redemption/payment wording; no halal wording.

---

## 9. Email footer and common email text (FR-NOT-001(b), Doc 08 CMP-EmailLayout)

Footer order (customer E01–E06): help/whitelisting line `res.carrier_tip` (only when `carrier_email`) → `email.footer.auto` → privacy link `ftr.privacy` (same-language URL) → "© {year} {momo_legal_operator_name}" (omitted while empty) → trading name `momo_trading_name`. No tracking pixels, no shorteners, no unsubscribe link (transactional only; "We do not send marketing email").

| Key | English | Japanese |
|---|---|---|
| `email.footer.auto` (spec `notifications`) | This is an automatic email about your table request. Replies go to the branch. | このメールはお席のリクエストに関する自動送信メールです。ご返信は店舗に届きます。 [Prop] |
| `email.footer.copyright` [Prop] | © {year} {operator} | © {year} {operator} |
| `email.footer.privacy` → reuse `ftr.privacy` | Privacy Policy | プライバシーポリシー |
| Staff E07–E11 footer [Prop] | Internal notification for {trading_name} staff. Do not forward. | {trading_name}スタッフ向けの社内通知です。転送しないでください。 [Prop] |
Subjects and bodies: spec `notifications` §5 (subjects contain no PII except the reference).

---

## 10. Demo / sample content (DEC-047, BR-CORP-03, CMP-044/045, TK-06-15, TK-01-27)

### 10.1 Rules
| # | Rule |
|---|---|
| D1 | Sample dish names, descriptions and sample prices are **never public**. Prototype (UI-001) prices are SAMPLE and are never imported to production. |
| D2 | `momo_is_sample = 1` on dishes, categories and offers created for design approval/staging; set/cleared only by ROLE-01/02. In production a publish of such an item is refused with `admin.sample.publish_blocked`; item stays draft. |
| D3 | Demo photos (`momo_image_illustrative = 1`) allowed only as dish and offer images until real photos arrive; caption `media.illustration` rendered as text directly **below** the image in the page language (CMP-IllustrationLabel, 12–14 px muted, ≥ 4.5:1); never baked into the image. |
| D4 | Flagged images refused on branch photos/galleries, `/gallery/`, the halal page and About (`admin.media.demo_not_allowed`; `Kernel\Media\IllustrativeImageGuard`). |
| D5 | Non-production visual marking: every sample item shows a visible "SAMPLE" badge next to its price and on its card (`demo.sample_badge` [Prop] "SAMPLE" / 「サンプル」), so design comps cannot be mistaken for real data (Doc 08 price rule; TK-03-16). |
| D6 | AI-generated images only if separately ordered and approved in writing by the Owner. |
| D7 | Go-live check `wp momos content sample-check` (Doc 11 CMP-044): lists sample dishes/categories/offers (any status), published items using flagged images (with location), flagged images on forbidden contexts, and crawl matches of seeded sample names in pages, JSON-LD, sitemaps, OG tags in every enabled language; exit code 1 if any published sample or unlabelled demo image (target 0). Dashboard widget "Demo content remaining" (HO, MM, CE). |
| D8 | `wp momos purge-samples` deletes all `momo_is_sample` items (non-production default; production requires `--yes` + ROLE-01 run, audited). |
| D9 | Seeding refuses to run when `WP_ENVIRONMENT_TYPE=production` (except the hidden TST branch fixture, DEC-055). Staging data synthetic only; emails on `example.test`/`example.com`. |

### 10.2 Seed content (`wp momos seed --profile=standard|perf` + `demo` [Prop])
| Profile | Content |
|---|---|
| `standard` (QA, Doc 12 §1.5) | Fictional branches QAA (certified active), QAB (halal_ingredients, split hours), QAC (cross-midnight, no_claim), QAD (minimal fields, expired cert), QAE (revoked), QAY (temporarily closed), QAZ (closed permanently), TST (hidden test); 8 categories (1 empty, 1 hidden); ~60 dishes incl. D9001–D9006, D9010/D9011, D9012; offers O1–O7; all 29 allergens across states; certificates in every state; 200 reservations; fixtures F-NAMES, F-BIDI, F-DIG, F-LONG, F-PHONE, F-EMAIL, F-HOSTILE |
| `demo` [Prop] (design approval / staging, PR-001 item 09) | 5 placeholder branches "Demo Branch A–E" / 「デモ店舗A–E」 with codes `DMA`–`DME` [Prop], address "〒000-0000 SAMPLE address — to be replaced (GATE-01)", phone `+81 90-0000-010x`, hours sample, `no_claim`; 8 sample categories (below, `momo_is_sample = 1`); sample dishes named `[SAMPLE] {category} {n}` / 「【サンプル】{カテゴリー}{n}」 (≈ 51 across the 8 categories as in UI-001), prices from a SAMPLE range, flagged demo images; 2 sample offers; About/Home placeholder copy marked "SAMPLE TEXT" |

Sample categories (UI-001 `data.js` = 8 sections of momoscafehalal.com, FACT-06; confirm with master menu — SEO-001 M6):

| # | EN | JA [Prop JA] |
|---|---|---|
| 1 | Curry | カレー |
| 2 | Biryani & Rice | ビリヤニ・ライス |
| 3 | Fried Rice & Noodles | チャーハン・麺 |
| 4 | Grill & Kebab | グリル・ケバブ |
| 5 | Rice Sets | ライスセット |
| 6 | Naan & Bread | ナン・パン |
| 7 | Sides, Soups & Salads | サイド・スープ・サラダ |
| 8 | Dessert | デザート |

Real branch names, addresses, phones, hours, certifiers and prices are **never** seeded; they arrive via GATE-01/GATE-02 data entry.

---

## 11. Permissions (ROLE-01 SA · 02 HO · 03 MM · 04 CE · 05 TR · 06 BM · 07 RS · CAP-DCA)

| Action | 01 | 02 | 03 | 04 | 05 | 06 | 07 | Cap |
|---|---|---|---|---|---|---|---|---|
| Edit Home/About/FAQ/Contact page text (drafts) | Y | Y | — | Y | — | — | — | `edit_pages` |
| Publish standard pages | Y | Y | — | Y | — | — | — | `publish_pages` |
| Publish/update legal pages; set version/date | Y | Y | — | draft + submit only | — | — | — | `momo_publish_legal_pages` |
| Translate strings / page translations (assigned language) | Y | Y | Y | Y | Y (own lang) | — | — | `momo_translate` |
| Approve translations / strings (`approved`) | Y | Y | — | — | Y (reviewer, own lang) | — | — | `momo_approve_translations` |
| Enable a language (gate) | Y | Y | — | — | — | — | — | `momo_manage_languages` |
| Brand settings (tagline, operator, policy pages, social) | Y | Y | — | — | — | — | — | `momo_manage_brand` |
| Select brand halal statement; edit claim templates/statements | CAP-DCA | CAP-DCA | — | — | — (translate text only, human) | — | — | `momo_edit_dietary_claims` |
| Prohibited-term list maintenance (SCR-A15) | Y | Y | — | — | — | — | — | `momo_manage_brand` |
| Run claim-term scan report | Y | Y | — | — | — | — | — | `momo_manage_brand` or CAP-DCA |
| Set/clear `momo_is_sample` | Y | Y | — | — | — | — | — | `momo_manage_brand` |
| Upload media; set `momo_image_illustrative` | Y | Y | Y | Y | — | own branch photos | — | `upload_files` |
| Insert protected mark images | CAP-DCA | CAP-DCA | — | — | — | — | — | CAP-DCA |
| Draft offers / publish offers | Y/Y | Y/Y | R | Y/— | translate | own branches/— | R | `publish_momo_offers` |

ROLE-00 (public) reads published, approved content only. Denials write `access.denied` to the audit log.

---

## 12. Screens

| Screen | Content-related behaviour | States |
|---|---|---|
| SCR-A11 Translation management — **Strings tab** [Prop] | Table per language: key, group, EN source, JA, target value (editable textarea), placeholders, status (`draft/needs_review/approved`), source, reviewer/date; filters: group, status, LCCS only, missing; actions "Save draft", "Mark reviewed" (approve cap); export/import CSV for translators (no PII) | Empty: `admin.translation.gaps_none`; error: placeholder mismatch notice; success notice `role="status"` |
| SCR-A11 Gaps view | Pages/FAQ/offers/notices/strings missing or unapproved per language, age, LCCS flag | — |
| SCR-A14 Pages & content | WP editor; legal pages: CE sees "Submit for review" only; meta box "Legal page" (type, version, last updated); claim-term save warning | Pending review badge; publish denied notice |
| SCR-A15 Settings | Brand tab (§2.3), Languages tab (gate + sign-off fields), Prohibited terms tab (per class × language lists), consent registry (spec `access-security-privacy`) | Gate refusal message §3.2 |
| SCR-A18 Media | Alt text per language fields; "Illustrative image (demo)" checkbox; licence source | Blocked-use notice |
| SCR-A06 / A20 | "Sample dish"/"Sample offer" checkbox (ROLE-01/02, locked for others) + "Sample" chip in list | Publish refused in production |
| Claim-term scan report [Prop menu: Settings › Claim-term scan] | URL, language, field, term, class; "Mark reviewed" by CAP-DCA | Empty "No restricted wording found." / 「制限された表示用語は見つかりませんでした。」 [Prop] |
| Customer C01/C02/C03/C12/C13/C14/C15–C18/C19/C20/C21/C22 | §8 | Loading: server-rendered; error: failing widget hides itself; empty per §8 |

---

## 13. Endpoints and handlers

| Handler | Method / route | Auth | Request | Response / errors |
|---|---|---|---|---|
| Save strings | `POST /wp-admin/admin-post.php?action=momos_strings_save` | nonce + `momo_translate` (+ assigned lang) | `lang`, `values[key]`, `mark_reviewed[]` | redirect with notice; placeholder mismatch → notice, value not saved; approve without `momo_approve_translations` → 403 `rest_forbidden` semantics |
| Contact form | `POST /wp-admin/admin-post.php?action=momos_contact` (nopriv) | nonce, Turnstile, honeypot, rate limit | name, email, topic, branch?, message, consent | success → `contact.success`; `captcha_failed` → `res.err.captcha`; field errors inline; nothing stored |
| Analytics opt-out | client-side only (cookie `momo_analytics_optout`) | — | — | — |
| CLI | `wp momos content sample-check`, `wp momos content claim-scan [--lang=]`, `wp momos purge-samples`, `wp momos seed --profile=standard\|perf\|demo`, `wp momos strings export\|import --lang=` [Prop], `wp momos maintenance build` [Prop] | shell | — | non-zero exit on findings (CI gate) |

Public REST error messages use `data.message_key` mapped per Doc 10 §7.2 (table in §4.1/§4.2 "Context" column); only Doc 04 §16.0.4 codes are returned.

---

## 14. Acceptance criteria and tests to automate

| # | Criterion | Tests |
|---|---|---|
| A1 | Every customer string key exists in all enabled languages with status `approved`; no MT-only values; reviewer + date recorded | TC-I18N-011, TC-UAT-009 |
| A2 | Language enable refused while any key/LCCS item unapproved; message per §3.2 | TC-I18N-009 |
| A3 | Prohibited-term crawl of all pages + rendered E01–E06 in every language: 0 hits (allow-list excepted) | TC-DIET-013, TC-RES-021, TC-HOME-004, AC-NOT-01-02 |
| A4 | Claim-term scan lists "100% halal" and "allergen-free" in a draft page with URL + field; register blocks not flagged | TC-DIET-021 |
| A5 | Home with no approved brand statement shows `brand.neutral` + link, no "certified"/mark | TC-HOME-003, AC-CORP-01-04 |
| A6 | "How reservations work" shows 3 steps, no response promise unless approved | TC-HOME-004, AC-CORP-01-05 |
| A7 | Halal page per-branch rows exactly per template rules; `halal.na` for halal_ingredients; expired → no certified wording | TC-CORP-002, AC-CORP-03-01…04 |
| A8 | FAQ accordion keyboard operable; empty groups hidden; schema text = visible text | TC-CORP-003, AC-CORP-04-01/02 |
| A9 | 5 legal pages per language with last-updated, version, ja-authoritative note; CE cannot publish | TC-CORP-005, TC-I18N-016, AC-CORP-06-01/02 |
| A10 | Translations carry the same version/date as JA; consent registry match; footer links to 5 legal pages in same language | TC-CORP-016, AC-CORP-06-04 |
| A11 | Reservation Policy without DEC-054 text: no late/no-show/fee terms; states no payment required | TC-RES-031, AC-CORP-06-03 |
| A12 | Accessibility statement: WCAG 2.2 AA target, no JIS/legal claim, accommodation channel | TC-A11Y-010 |
| A13 | External Transmission page lists every observed third party; opt-out works without reload | TC-ANL-006, TC-ANL-007 |
| A14 | 404 localised, `noindex`, 4 links; maintenance 503 + Retry-After with RTL blocks | TC-CORP-006, TC-CORP-007 |
| A15 | Sample dish/offer publish refused in production with sample message; sample-check report = 0 before launch | TC-MENU-013, TC-MENU-012, AC-MENU-03-07 |
| A16 | Demo-flagged image on dish and offer shows translated caption below; refused on branch gallery/gallery/halal/About | TC-DISH-006, AC-ADM-04-04 |
| A17 | No ordering UI or strings from UI-001 in any language | TC-REG-007 |
| A18 | Header Offers item only while an offer is active; footer always links Offers | TC-NAV-001, AC-CORP-08-06 |
| A19 | Footer copyright absent while operator name empty | AC-FND-01-10 |
| A20 | Every dish page shows `allergen.disclaimer.voluntary` + `allergen.tell_staff` verbatim in page language | TC-DIET-027 |
| A21 | Long strings (F-LONG) fit at 360 px in 6 languages | TC-I18N-017 |
| A22 | Unit: `Strings::t` placeholder substitution, plural selection (ar 6 forms), placeholder-set validator, NFKC term matching | PHPUnit (new) |

---

## 15. Out of scope (Phase 1)
Machine translation integration (MT drafts are pasted by translators, never auto-published; no PII to MT/AI — BR-PRV-04);
site search; offer detail pages; ratings/reviews; marketing email/newsletter; customer accounts; online ordering/payment wording;
AI-generated images; area/station landing pages; separate RTL page sets; per-dish halal free text.

## 16. Open TBDs (build with the default as configuration)
| Item | Default used | Owner / ref |
|---|---|---|
| All JA strings (native/legal review) and ar/ur/hi/bn values | seed as `draft` | Head Office, GATE-04 |
| Hero copy / tagline | omit lead; H1 = trading name until supplied | Owner, FR-CORP-002 |
| Legal operator name, address, representative, privacy contact, court | not rendered while empty | Owner/Counsel, Q-409/Q-024, LC-001-Q025 |
| `legal.ja_authoritative` wording; `media.illustration` wording/placement | as §4.6/§4.4 | Counsel, BR-I18N-03, DEC-047 |
| Consent/notice final wording | Doc 11 §4.2 drafts | Counsel, GATE-06 |
| Response promise (DEC-013); reservation policy text (DEC-054); cancellation cut-off (BR-RES-07) | hidden | Owner |
| Featured dishes / offers on home | 6 / 3 | Q-414 |
| Retry-After seconds | 3600 [Prop] | Q-419 |
| Payments FAQ group | hidden | Owner |
| Notice body length | 300 chars | Content lead |
| ar/ur/hi/bn prohibited-term equivalents | seeds in `claim-keywords.php` | native reviewers, GATE-04 |
| Cross-contact confirmation per branch | not shown | Food-safety owner, DEC-021 |
| Real photos inventory | demo images flagged | Owner, Q-045 |

## 17. Documentation conflicts found and resolution used
| # | Conflict | Resolution |
|---|---|---|
| C1 | Consent/notice wording: Doc 07 `res.label.consent` / `res.notice.collection` (short) vs Doc 11 §4.2 `consent.reservation` / `notice.reservation` (incl. BR-RES-01 acknowledgement + DEC-053 special-care clause). Sibling specs disagree (`reservation.md` uses Doc 07; `access-security-privacy.md` uses Doc 11). | Doc 11 wording under the Doc 07 keys (privacy master; DEC-053 requires the allergy clause); counsel confirms. `reservation.md` §strings to be aligned. |
| C2 | Contact success: Doc 07 §5.11 "Thank you — your message has been sent to our head office." vs Doc 04 FR-CNT-001(b) "Thank you. We will reply by email." | Doc 04 (requirement text; AC-CNT-01-01). |
| C3 | E01 travel sentence: `res.submitted.dont_travel` "…based on this request alone" vs Doc 07 §5.35 "Please don't go to the branch based on this email alone" | Use the key `res.submitted.dont_travel` everywhere (behind V8 validation). |
| C4 | Doc 07 §6.9 refers to `halal.tpl.*` keys that do not exist | Use Doc 11 §8.1 keys (`certified.*`, `halal_ingredients`, `no_claim`, …). |
| C5 | Legal version formats: Doc 04 `PP-2026-11-v1`; Doc 11 `PP-1.0` / consent key `PP1.0-RP1.0`; Doc 06 `momo_consent_version` `2027-01-v1`; TK-01-18 `2026-12-v1` | Doc 11 (consent registry; matches spec `access-security-privacy`). |
| C6 | String catalogue location: spec `notifications` says `languages/strings/{lang}.json`; code and other specs use `languages/strings/{lang}/{module}.php` | Code (`Kernel\I18n\Strings`); `notifications.md` to be corrected. |
| C7 | R2-12 three competing inventories (Doc 06 `nav_about` = Momo'sについて, Offers キャンペーン; Doc 10 `res.status.pending`) | Doc 07 §6 keys/values (docs' own ruling); Doc 06 §3.8 used only for ar/ur/hi/bn drafts. |
| C8 | Ordering terms in `config/claim-keywords.php` lack "takeout", "delivery", "coupon code", "redeem" and there is no `request_context` class | Add both to the config (§5). |
| C9 | Home step 3 wording: Doc 04 "you receive an email" vs Doc 08 "We email you the decision" | Doc 08 component text (`home.how.step3`). |
| C10 | LCCS legal scope: FR-CORP-006(f) names Privacy + Reservation Policy only; Doc 06 §4.6 and FR-ANL-002(c) add External Transmission | External Transmission is LCCS. |
| C11 | `Strings::t` falls back to English for missing keys vs BR-I18N-02 no mixed language | Fallback kept as dev safety net; language gate requires 100 % approved keys (§3.2). |
| C12 | `momo_is_sample` scope: Doc 04/11 dish + offer; TK-06-15 adds categories | Dishes, categories and offers. |
| C13 | Doc 11 `guidance.special_request` (adds "advance-order dishes… do not include information we do not need") vs Doc 07 `res.hint.note` (DEC-053 quote) | Doc 07 `res.hint.note`; counsel may switch to the Doc 11 text. |
| C14 | WPML/Elementor/Rank Math mechanisms (TRD/SDD) vs IMPL-001/002 | Mapped per §0. |
