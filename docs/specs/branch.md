# Branch module — implementation spec (branches, hours & closures, notices, offers)

Status: build spec v1.0 for `momos-core` (PHP 8.3, namespace `Momos\Branch\…`) and theme `momos`.
Audience: developers who will not read the source package. Every rule below is traceable to an ID from the
documentation package (Doc 04 PRD, 04A, 05, 06, 07, 08, 09 TRD, 10 SDD, 11, 12, 13, 17).
Values in ⟦…⟧ are TBD with the documented proposal used as default. Business facts (branch names, addresses,
codes, certifications) are NEVER hard-coded: use placeholders such as `{BRANCH_NAME}`, `SHN` (DEC-052 example only).

## 0. How the documented mechanisms map to this build (IMPL-001/002)

| Documented mechanism | Build equivalent (this spec) |
|---|---|
| ACF field groups G4/G5/G6 (`momo_branch`), G15 (`momo_notice`), G17 (`momo_offer`) | `register_post_meta()` with the **same meta keys** (`momo_*`), native meta boxes rendered as tabs by `Branch\Admin\BranchEditScreen`; validation in `Branch\Service\*Validator`; write guard in `BranchFieldGuard` (replaces `acf/update_value` / `acf/prepare_field`) |
| ACF field keys `field_momo_*` | Kept as HTML `id`/`name` of the form controls for test selectors; not stored |
| ACF repeater (recipients, access directions) | One meta key holding a JSON array (schema below) |
| WPML "copy" fields | Stored once on the post (language-independent) |
| WPML "translate" fields | Japanese source in native field/meta; other languages in `_momos_i18n_{lang}` JSON + `_momos_i18n_status_{lang}` ∈ `missing, draft, needs_review, approved` |
| WPML "only show translated items" | An entity renders in language L≠ja only if `_momos_i18n_status_L = approved` (BR-I18N-02, DEC-027) |
| "Canonical JA source post ID" | Simply the post ID (one post per entity, IMPL-002). All custom-table `branch_id` = `wp_posts.ID` of the branch |
| Elementor Theme Builder / Loop Grids / widgets / dynamic tags | Theme `momos` templates (`single-momo_branch.php`, page templates for `/branches/` and `/offers/`) calling `Momos\Presentation` helpers; widget slugs below become template-part names (`template-parts/branch/open-now.php` etc.) |
| Rank Math redirects / robots / sitemap | `momos-core` SEO layer (SEO spec). This module exposes predicates `BranchVisibility::isPublic()`, `OfferQuery::hasActive()` |
| Action Scheduler jobs | `Kernel\Jobs\ScheduleRegistry` (Action Scheduler if bundled, else WP-Cron single events driven by real server cron). Hook names unchanged |

URL model: Japanese at root, `/en/ /ar/ /ur/ /hi/ /bn/` prefixes; slugs shared (romaji) across languages (DEC-003, IMPL-002).

## 1. Requirements covered

Priority: M = Must, S = Should, C = Could.

### 1.1 Features, functional requirements, business rules

| ID | Statement (one line) | Pri |
|---|---|---|
| F-BR-01 / FR-BR-001 | Public branch directory `/branches/` with status, today's hours, open-now, contact, actions | M |
| F-BR-02 / FR-BR-002 | Branch detail `/branches/{slug}/` with all visit/request information, map facade, facilities | M |
| FR-BR-003 | No placeholders: empty / `unknown` fields and their labels are not rendered | M |
| F-BR-03 / FR-BR-004 | Branch identity (HO only), facts confirmation (GATE-01), immutable `branch_code`, permanent-closure rules | M |
| FR-BR-005 | Reservation-settings tab editable only with `momo_edit_branch_settings` | M |
| F-BR-04 / FR-BR-006 | Weekly hours: periods, last order, cross-midnight, seasonal sets | M |
| FR-BR-007 | Date closures / special hours; one record per date; ranges ≤ 31 dates | M |
| FR-BR-008 | Branch Manager self-service (hours, closures, notices) immediate, audited, HO digest | M |
| FR-BR-010 | Closures vs reservations: block new, flag existing (`needs_action_flag`), never auto-cancel | M |
| FR-BR-011 | n-th branch without code; no hard-coded branch lists anywhere | M |
| F-BR-05 / FR-BR-009 | Notices `momo_notice` site-wide or branch-scoped with start/end auto-expiry | M |
| F-CORP-09 / FR-CORP-010 | Offer management `momo_offer`: draft → pending → publish (HO approval), no detail URLs | M |
| FR-CORP-011 | Offer display (home, branch page, `/offers/`) and automatic expiry; empty states | M |
| FR-CORP-008(f) / FR-CORP-009(b) / DEC-060 | Header "Offers" item only while ≥ 1 active offer in the language; footer link always | M |
| FR-RES-023 (fields only) | Per-branch reservation settings fields and validation | M |
| FR-RES-024 (data side) | Branch status/settings read fresh for every submission (no stale cache) | M |
| FR-RES-026 / FR-RES-030 | Hidden TEST branch: excluded from every public output; test data only | M |
| FR-SEO-006(b) / FR-SEO-007 | (dependency) Restaurant JSON-LD and breadcrumbs consume this module's data | M |
| BR-BR-01 | Only HO changes identity fields (name, code, concept, address, coordinates, phone, email, status) and settings | M |
| BR-BR-02 | BM changes hours, closures, notices, description/photos for assigned branches only; immediate; audited; digest | M |
| BR-BR-03 | Publish only after written facts confirmation (GATE-01) | M |
| BR-BR-04 | Permanently closed: removed from all public lists, blocks reservations, 301 to `/branches/`; future reservations handled first | M |
| BR-BR-05 | Open-now and slots computed in Asia/Tokyo from hours + closures | M |
| BR-RES-13 | New closures never auto-cancel; affected pending/confirmed are flagged | M |
| BR-RES-15 | `decision_cutoff ≤ lead_time − momo_min_decision_window_minutes` ⟦30⟧ | M |
| BR-CORP-01 | Offers informational: no coupon/redemption/payment/price change; validity + conditions always shown; prices tax-incl. | M |
| BR-CORP-02 | Offers publish only after HO approval; expired offers hidden automatically | M |
| BR-CORP-03 / DEC-047 | `momo_is_sample` offers never public in production; illustrative images allowed on offers, never on branches | M |
| BR-I18N-02 | No mixed-language fallback: untranslated branch/notice/offer hidden in that language | M |
| NFR-SCL-01 | Works for 1..n branches (≤ 20) without code | M |

### 1.2 Decisions applied

DEC-001 (optional concept label; model works for one brand or sister concepts) · DEC-009 (8 custom tables; settings on branch post) · DEC-015 (per-branch reservation rules) · DEC-025 (BM edits hours/closures/notices/availability directly; identity & settings HO only) · DEC-029 (map click-to-load facade) · DEC-044 (notices Must; offers Must, informational, no detail pages) · DEC-047 (demo content) · DEC-049 (business-controlled recipients) · DEC-050 (Asia/Tokyo) · DEC-052 (`branch_code` = 3 uppercase letters, romaji slug, canonical ID = post ID) · DEC-055 (hidden TEST branch for production smoke) · DEC-060 (Offers nav) · DEC-062 (JST label only in non-ja reservation contexts).

### 1.3 User stories

| ID | Story (short) | Pri |
|---|---|---|
| US-BR-001 | Visitor sees which branch is open now (JST) | M |
| US-BR-002 | Visitor copies Japanese address and opens directions | M |
| US-BR-003 | Visitor sees only confirmed facilities | M |
| US-BR-004 | HO adds a branch without a developer (GATE-01 to publish) | M |
| US-BR-005 | HO closes a branch temporarily or permanently | M |
| US-BR-006 | BM edits own weekly hours / last order | M |
| US-BR-007 | BM adds closures/special hours and sees affected requests | M |
| US-BR-008 | BM posts a time-limited branch notice | M |
| US-BR-009 | BM updates own branch description and photos | M |
| US-CORP-002 | Visitor sees current offers with validity and conditions | M |
| US-CORP-003 | Content Editor drafts an offer and submits for review | M |
| US-CORP-004 | HO approves/publishes offers (stamps, audit) | M |
| US-CORP-005 | BM proposes an own-branch offer | M |
| US-RES-024 | HO configures each branch's reservation rules | M |
| US-RES-026 / US-RES-030 | Hidden TEST branch for production smoke; isolated test data | M |

### 1.4 Security / privacy / compliance

| ID | Statement | Pri |
|---|---|---|
| SEC-010 | Central `map_meta_cap` resolution of `momo_edit_branch(branch_id, aspect)` | M |
| SEC-011 | Hours/closures repositories require an `AccessContext`; unscoped call throws `UnscopedQueryException` | M |
| SEC-012 / SEC-013 | Every channel enforced (admin screen, admin-post, core editor, meta writes, REST); 403 + `access.denied` audit | M |
| SEC-015 | GATE-03 isolation suite incl. hours/closures/notices/offers per branch | M |
| SEC-020 | Server-side validation of every field | M |
| PRV-010 | Notification recipients only on business-controlled domains | M |
| PRV-024 | Branch page makes 0 Google requests before user click (maps facade) | M |
| CMP-034 | Smoking status shown only when confirmed | M |
| CMP-040 / CMP-041 / CMP-043 / CMP-044 | Offer validity/conditions/tax-incl.; substantiated comparison price; HO-only publish; demo content rules | M |

## 2. Component design (`src/Branch/`)

| Class | Responsibility |
|---|---|
| `BranchModule` | Registers services; boots hooks (context-aware: admin classes only on `is_admin()`) |
| `PostType\BranchPostType`, `NoticePostType`, `OfferPostType` | CPT registration (§3) |
| `Taxonomy\BranchScopeTaxonomy` | `momo_branch_scope` (system-managed) |
| `Fields\BranchFieldRegistry`, `NoticeFieldRegistry`, `OfferFieldRegistry` | `register_post_meta` + field metadata (type, validation, translatable flag, edit capability). Exposes `translatableFields(): list<string>` to the i18n layer |
| `Domain\BranchDto`, `BranchSettings`, `OpeningPeriod`, `ClosureDay`, `ClosurePeriod`, enums | Immutable value objects; PHP backed enums (§3.7) |
| `Repository\BranchRepository` (`BranchReader`) | `findByCode(code, includeTest=false)`, `findById(id)`, `published(includeTest=false)`; request-cached |
| `Repository\BranchSettingsRepository` (`BranchSettingsReader`) | `get(branchId): BranchSettings`; invalid/missing meta → `BranchSettings::disabled()` (refuse, never accept with wrong rules) |
| `Repository\HoursRepository`, `ClosureRepository` | CRUD on custom tables; `replaceWeek(branchId, validity, periods)` and `replaceDate(branchId, date, ClosureDay)` each in one `Kernel\Db\Transaction` |
| `Service\ScheduleService` (`ScheduleProvider`) | `periodsForBusinessDate()`, `closureFor()`, `isOpenAt()`, `scheduleJson(branchId)` (open-now embed), `endOfBusinessDay()` (used by Menu) |
| `Service\BranchPublishValidator` | Publish / status rules (§4.1, §4.3) |
| `Service\SettingsValidator` | G6 rules (§4.8) |
| `Service\HoursValidator` | Weekly + closure period rules (§4.4, §4.6) |
| `Service\BranchFieldGuard` | Field-level write guard (§4.2) |
| `Service\BranchScopeSync` | One scope term per branch; hide on permanent closure |
| `Service\ClosureImpactService` | Preview + flag affected reservations via Reservation's `ReservationFlagger` interface (§4.7) |
| `Service\OfferScopeGuard` | Offer/notice scope vs `AccessContext`; blocks non-HO edits of published offers |
| `Query\NoticeQuery`, `Query\OfferQuery` | Active-by-time + scope + language queries; `OfferQuery::hasActive(lang)` |
| `Job\OfferExpiryJob`, `Job\ScheduledPurgeJob` | Daily expiry stamp; single purge actions at start/end |
| `Admin\BranchEditScreen`, `HoursEditorPage`, `HoursFormHandler`, `BranchStaffPanel`, `OfferListColumns`, `NoticeListColumns` | SCR-A03/A04/A13/A20 |

Public interfaces (consumed by Reservation, Menu, Presentation, SEO):
```php
interface BranchReader { public function findByCode(string $code, bool $includeTest = false): ?BranchDto;
    public function findById(int $id): ?BranchDto; /** @return list<BranchDto> */ public function published(bool $includeTest = false): array; }
interface ScheduleProvider { /** @return list<OpeningPeriod> resolved against closures */
    public function periodsForBusinessDate(int $branchId, \DateTimeImmutable $date): array;
    public function closureFor(int $branchId, \DateTimeImmutable $date): ?ClosureDay;
    public function isOpenAt(int $branchId, \DateTimeImmutable $instantUtc): bool; }
interface BranchSettingsReader { public function get(int $branchId): BranchSettings; }
```

Domain hooks emitted after commit (`Kernel\Events\AfterCommitDispatcher`):

| Hook | Args | Consumers |
|---|---|---|
| `momos/branch/schedule_changed` | `int $branchId, list<string> $dates` (`[]` = all future) | ClosureImpactService, cache purge (branch page, directory, home, API-01), Audit |
| `momos/branch/settings_changed` | `int $branchId, list<string> $keys` | cache purge (API-01), Audit |
| `momos/branch/identity_changed` | `int $branchId` | purge `momo-global` (footer), Audit |
| `momos/offer/visibility_changed` | `int $offerId, bool $global` | purge; `global = true` when active-offer count crosses 0 ↔ ≥ 1 (header nav) |

## 3. Data model

### 3.1 CPT registrations

| Arg | `momo_branch` | `momo_notice` | `momo_offer` |
|---|---|---|---|
| public / publicly_queryable | true / true | false / false | true / **false** (no single URLs) |
| show_ui / menu | true / "Branches" (店舗) | true / "Notices" (お知らせ) | true / "Offers" (キャンペーン) |
| show_in_rest | true, **GET only**, public fields only, TEST + closed_permanently excluded | false | false |
| rewrite | `['slug'=>'branches','with_front'=>false]` | false | false |
| has_archive | false (directory = Page `branches`) | false | false (list = Page `offers`) |
| supports | title, editor, thumbnail, revisions, page-attributes (`menu_order` = directory order) | title, revisions | title, thumbnail, revisions |
| capability_type | `['momo_branch','momo_branches']` | `['momo_notice','momo_notices']` | `['momo_offer','momo_offers']` |
| map_meta_cap / create_posts | true / `create_momo_branches` | true / `create_momo_notices` | true / `create_momo_offers` |
| exclude_from_search / delete_with_user | true / false | true / false | true / false |
| Block editor | disabled | disabled | disabled |
| Taxonomy | — | `momo_branch_scope` | `momo_branch_scope` |
| Statuses used | draft, publish | draft, publish | draft, pending, publish |

Taxonomy `momo_branch_scope`: object types notice+offer; not public; `show_ui` false; not translated; manage = `manage_options`, assign = `edit_momo_notices`; one term per branch, slug = lower-case `branch_code`, term meta `momo_branch_id`. Created on first save of a branch, renamed on title change, hidden (term meta `momo_scope_hidden = 1`) on `closed_permanently`.

Delete protection (`pre_delete_post`, `wp_trash_post`): a branch with any reservation row or any branch-menu row cannot be trashed/deleted → admin notice + audit `access.denied`.

### 3.2 `momo_branch` fields

Legend — T: translatable (ja in native field/meta; other languages in `_momos_i18n_{lang}`); C: language-independent. Edit cap: ID = `momo_edit_branch_identity` (ROLE-01/02); ST = `momo_edit_branch_settings` (ROLE-01/02); EP = `edit_post` on the branch (ROLE-01/02/04, ROLE-06 assigned); SA = `manage_options`.

**Identity tab (G4)**

| Meta key | Type | Req | T/C | Validation | Default | Edit |
|---|---|---|---|---|---|---|
| `post_title` (name) | string | Y | T | 1–80 chars ⟦TRD: ≤ 100; PRD/IA: ≤ 80 → 80 used⟧ | — | ID |
| `post_name` (slug) | string | Y | C | `^[a-z0-9-]+$`, unique; error instead of WP auto-suffix; identical in all languages | — | ID |
| `momo_branch_code` | char(3) | Y | C | `^[A-Z]{3}$`, unique among branches; immutable after first publish **or** once any reservation references the branch (§4.1) | — | ID |
| `momo_concept_label` | string ≤ 60 | — | T | optional (DEC-001); rendered only if set | '' | ID |
| `momo_operating_status` | enum | Y | C | `open`, `temporarily_closed`, `closed_permanently` | `open` | ID |
| `momo_reopen_date` | date `Y-m-d` | — | C | only with `temporarily_closed`; ≥ today (JST) when saved | null | ID |
| `momo_area_label` | string ≤ 40 | — | T | e.g. area / station name | '' | ID |
| `momo_postal_code` | string | Y | C | `^\d{3}-\d{4}$` | — | ID |
| `momo_prefecture`, `momo_city` | string | Y | T | — | — | ID |
| `momo_address_line1` | string | Y | T | street | — | ID |
| `momo_address_line2` | string | — | T | building, floor (floor must be owner-confirmed) | '' | ID |
| `momo_address_ja` | text | Y | C | full Japanese postal address incl. postal code, building, floor; copied verbatim by the copy button | — | ID |
| `momo_lat`, `momo_lng` | decimal(9,6) | Y | C | ≥ 5 decimals; lat 20–46, lng 122–154 | — | ID |
| `momo_place_id` | string | — | C | Google Place ID | '' | ID |
| `momo_phone_e164` | string | Y | C | `^\+81\d{9,10}$` (inputs normalised: full-width/Eastern digits → ASCII, national `0…` → `+81…`) | — | ID |
| `momo_phone_display` | string | Y | C | national format, e.g. `06-0000-0000` (placeholder) | derived | ID |
| `momo_branch_email` | email | Y | C | business domain (same domain rule as recipients §4.8); reply-to for customer emails; not displayed publicly ⟦Q-002⟧ | — | ID |
| `momo_is_test_branch` | bool | Y | C | FR-RES-026; ROLE-01 only | 0 | SA |
| `momo_facts_confirmed_by` | string ≤ 100 | publish | C | GATE-01 | '' | ID |
| `momo_facts_confirmed_at` | date | publish | C | GATE-01; ≤ today | null | ID |
| `momo_facts_evidence_note` | text ≤ 500 | — | C | evidence / signed fact-sheet reference (PRD `facts_evidence_note`, IA `facts_confirmation_ref`) | '' | ID |
| `post_content` (description) | text | — | T | plain/limited HTML (`wp_kses_post`) | '' | EP |
| `_thumbnail_id`, `momo_gallery` | attachment ID / JSON int[] (0–12) | — | C | alt text required per language; JPEG/PNG/WebP; **no `momo_image_illustrative = 1` images** (DEC-047 `IllustrativeImageGuard`) | — | EP |
| `menu_order` | int | — | C | directory order (HO) | 0 | ID |
| `momo_external_ref` | string ≤ 64 | — | C | reserved for Phase 2 (DEC-056); never displayed | '' | SA |
| `momo_first_published_at` | datetime UTC | system | C | stamped on first publish; drives code immutability | null | system |

**Access & facilities tab (G5)** — edit = ID. Value `unknown` (default) is never rendered (FR-BR-003).

| Meta key | Type | Values / validation | T/C |
|---|---|---|---|
| `momo_access_directions` | JSON `[{station, exit, walking_minutes}]` ≤ 5 rows | station required ≤ 60; exit ≤ 20; walking_minutes int 1–60 | station/exit T (stored in i18n JSON as array of `{station, exit}` aligned by index), minutes C |
| `momo_access_notes` | text ≤ 400 | landmarks, no keyword lists (IA [Rec], S) | T |
| `momo_prayer_space` | enum | `yes`, `no`, `nearby`, `unknown` | C |
| `momo_prayer_note` | string ≤ 120 | optional approved note | T |
| `momo_smoking_policy` | enum | `non_smoking`, `smoking_area`, `unknown` | C |
| `momo_step_free_access` | enum | `yes`, `partial`, `no`, `unknown` | C |
| `momo_seating_types` | JSON string[] | ⊆ `table`, `counter`, `private_room`, `tatami`, `terrace` | C |
| `momo_payment_methods` | JSON string[] | ⊆ `cash`, `credit_card`, `ic_card`, `qr_payment`, `other` ⟦per branch — Q-002⟧ | C |
| `momo_wifi` | enum | `yes`, `no`, `unknown` | C |
| `momo_map_preview_image` | attachment ID | own/licensed image, no Google imagery; optional (neutral placeholder graphic from theme otherwise) | C |

**Reservation settings tab (G6, FR-RES-023, SDD §3.4)** — all C, edit = ST; ROLE-06/07 read-only for assigned branches. Read always from the post (never cached across requests) for submissions (FR-RES-024(b)).

| Meta key | Type | Range | Default ⟦proposal⟧ |
|---|---|---|---|
| `momo_res_enabled` | bool | see §4.8 preconditions | 0 |
| `momo_res_party_min` | int | 1–20 | ⟦1 — Q-013⟧ |
| `momo_res_party_max` | int | party_min–50 | ⟦8 — Q-013⟧ |
| `momo_res_lead_time_minutes` | int | 0–10,080 | ⟦120 — Q-014⟧ |
| `momo_res_booking_window_days` | int | 1–365 | ⟦60 — Q-015⟧ |
| `momo_res_slot_interval_minutes` | enum int | 15, 30, 60 | ⟦30 — Q-016⟧ |
| `momo_res_decision_cutoff_minutes` | int | 0–1,440 and BR-RES-15 | ⟦60 — Q-017⟧ |
| `momo_res_response_sla_minutes` | int | 0 (= off) or 15–1,440 | ⟦120 — Q-402⟧ |
| `momo_res_sla_clock` | enum | `business_hours`, `wall_clock` | ⟦`wall_clock` — DEC-013/Q-402⟧ |
| `momo_res_show_response_promise` | bool | management approval required (DEC-013) | 0 |
| `momo_res_max_pending_per_email` | int | 1–10 | ⟦3 — DEC-015⟧ |
| `momo_res_customer_cancel_cutoff_minutes` | int | 0–1,440 (0 = until start) | ⟦0 — Q-022⟧ |
| `momo_res_notification_recipients` | JSON `[{email, language: "ja"\|"en", active: bool}]` | 0–10 rows; unique emails (case-insensitive); domain rule §4.8; ≥ 1 active row when enabled | `[]` ⟦branch inbox — DEC-049/Q-020⟧ |
| `momo_res_policy_page` | page ID / null | page must be published to be shown (DEC-054) | null |

Global (not per branch, SCR-A15, owned by Settings spec): `momo_min_decision_window_minutes` ⟦30⟧, `momo_res_global_enabled`, recipient domain block-list/allow-list.

**Dietary tab (G7)** — owned by the Dietary spec (CAP-DCA); rendered as a fourth tab on SCR-A03. Not specified here.

### 3.3 i18n field registry (IMPL-002)

| Entity | Translatable keys (`_momos_i18n_{lang}` JSON) | Shown in L≠ja only when |
|---|---|---|
| `momo_branch` | `post_title`, `post_content`, `momo_concept_label`, `momo_area_label`, `momo_prefecture`, `momo_city`, `momo_address_line1`, `momo_address_line2`, `momo_access_directions` (station/exit), `momo_access_notes`, `momo_prayer_note` (+ SEO title/description — SEO spec) | `_momos_i18n_status_L = approved` |
| `momo_notice` | `post_title`, `momo_notice_body`, `momo_notice_link_url`, `momo_notice_link_label` | approved |
| `momo_offer` | `post_title`, `momo_offer_summary`, `momo_offer_conditions`, `momo_offer_cta_label` | approved |

Copied/structured data (hours, closures, reason labels, facilities enum labels, operating status) renders in every enabled language from `.po` strings, even if the branch text is untranslated in that language — but the branch page itself is only served in L when approved (otherwise 404 in L; switcher behaviour per i18n spec). A change to a translatable JA value sets every approved language to `needs_review`; the previous approved JSON keeps rendering until re-approved (no mixed language).

### 3.4 Custom tables (SDD §3.3 M0001 — copy exactly)

```sql
CREATE TABLE {$prefix}momo_branch_hours (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  branch_id bigint(20) unsigned NOT NULL,
  weekday tinyint(3) unsigned NOT NULL,
  period_no tinyint(3) unsigned NOT NULL DEFAULT 1,
  period_label varchar(20) NOT NULL DEFAULT 'all_day',
  opens_at time NOT NULL,
  closes_at time NOT NULL,
  closes_next_day tinyint(1) unsigned NOT NULL DEFAULT 0,
  last_order_at time DEFAULT NULL,
  valid_from date DEFAULT NULL,
  valid_until date DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_branch_weekday (branch_id,weekday,period_no),
  KEY idx_branch_validity (branch_id,valid_from,valid_until)
) ENGINE=InnoDB {$charset_collate};

CREATE TABLE {$prefix}momo_branch_closures (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  branch_id bigint(20) unsigned NOT NULL,
  closure_date date NOT NULL,
  closure_type varchar(20) NOT NULL,
  period_no tinyint(3) unsigned NOT NULL DEFAULT 0,
  period_label varchar(20) DEFAULT NULL,
  opens_at time DEFAULT NULL,
  closes_at time DEFAULT NULL,
  closes_next_day tinyint(1) unsigned NOT NULL DEFAULT 0,
  last_order_at time DEFAULT NULL,
  public_reason varchar(30) NOT NULL DEFAULT 'other',
  internal_note varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned DEFAULT NULL,
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_branch_date_period (branch_id,closure_date,period_no),
  KEY idx_date (closure_date)
) ENGINE=InnoDB {$charset_collate};
```

M0002 CHECK constraints (idempotent; add only if constraint name absent):
```sql
ALTER TABLE {$prefix}momo_branch_hours
  ADD CONSTRAINT chk_momo_hours_weekday CHECK (weekday BETWEEN 1 AND 7),
  ADD CONSTRAINT chk_momo_hours_period CHECK (period_no BETWEEN 1 AND 6),
  ADD CONSTRAINT chk_momo_hours_label CHECK (period_label IN ('lunch','dinner','all_day','other')),
  ADD CONSTRAINT chk_momo_hours_validity CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from);
ALTER TABLE {$prefix}momo_branch_closures
  ADD CONSTRAINT chk_momo_clos_type CHECK (closure_type IN ('closed_all_day','special_hours')),
  ADD CONSTRAINT chk_momo_clos_reason CHECK (public_reason IN ('public_holiday','private_event','maintenance','staff_shortage','other')),
  ADD CONSTRAINT chk_momo_clos_shape CHECK (
    (closure_type = 'closed_all_day' AND period_no = 0 AND opens_at IS NULL AND closes_at IS NULL)
    OR (closure_type = 'special_hours' AND period_no BETWEEN 1 AND 6 AND opens_at IS NOT NULL AND closes_at IS NOT NULL));
```
Rules: `branch_id` = branch post ID (no DB FK); all `datetime` UTC; `time`/`date` columns are JST business-local (DEC-050). CI asserts PHP enum cases equal the CHECK lists. Queries: Q1 weekly periods (`idx_branch_weekday`), Q2 closures by date range (`uq_branch_date_period` prefix). All via `$wpdb->prepare`, scoped by `AccessContext` for writes and admin reads.

Retention: closure rows for dates older than ⟦24 months⟧ purged by the integrity job [Rec]. Hours history kept (validity dates).

### 3.5 `momo_notice` fields (G15, ENT-13)

| Key | Type | Req | T/C | Validation |
|---|---|---|---|---|
| `post_title` | string ≤ 80 | Y | T | — |
| `momo_notice_body` | plain text ≤ 300 | Y | T | no HTML; optional single link via the two link fields |
| `momo_notice_severity` | enum `info`, `warning` | Y | C | — |
| `momo_notice_scope` | enum `site_wide`, `branches` | Y | C | `site_wide` requires `momo_access_all_branches` |
| scope terms (`momo_branch_scope`) | term IDs | if scope = branches (≥ 1) | C | BM: every term's branch must be assigned (403 `forbidden_branch` + audit otherwise) |
| `momo_notice_start_at` | datetime (JST input, stored UTC `Y-m-d H:i:s`) | Y | C | — |
| `momo_notice_end_at` | datetime (JST input, stored UTC) | HO/CE optional; **BM required** | C | end > start; BM: end ≤ start + 60 days |
| `momo_notice_link_url` / `momo_notice_link_label` | url / string ≤ 40 | — | T | internal or https URL |

### 3.6 `momo_offer` fields (G17, ENT-25)

| Key | Type | Req | T/C | Validation | Edit cap |
|---|---|---|---|---|---|
| `post_title` | string 1–80 | Y | T | — | edit_post |
| `_thumbnail_id` | attachment | — | C | JPG/PNG/WebP; alt per language; `momo_image_illustrative` allowed (caption rendered) | edit_post |
| `momo_offer_summary` | text ≤ 200 | Y | T | — | edit_post |
| `momo_offer_conditions` | text 1–500 | Y | T | days, times, branches, party limits, "cannot be combined" | edit_post |
| `momo_offer_start_at`, `momo_offer_end_at` | datetime JST → UTC | Y | C | end > start; end ≤ start + 366 days; at publish end > now | edit_post |
| `momo_offer_scope` | enum `all_branches`, `branches` | Y | C | `all_branches` requires `momo_access_all_branches` | edit_post |
| scope terms | term IDs | if `branches` (≥ 1) | C | BM: ⊆ assigned (M3) | edit_post |
| `momo_offer_price_jpy` | int 0–100,000 | — | C | rendered "¥{n} (tax incl.)" | edit_post |
| `momo_offer_reference_price_jpy` | int | — | C | requires `momo_offer_reference_basis` | publish_momo_offers |
| `momo_offer_reference_basis` | text (internal, never rendered) | if reference price | C | substantiation ⟦counsel⟧ | publish_momo_offers |
| `momo_offer_cta_type` | enum `none`, `request_table`, `menu`, `dish` | Y | C | default `request_table` | edit_post |
| `momo_offer_cta_dish` | dish post ID | if `dish` | C | must be published, not retired ("The linked dish is not published.") | edit_post |
| `momo_offer_cta_category` | menu category term ID | — | C | optional target for `menu` (PRD FR-CORP-010(a); absent from TRD G17) | edit_post |
| `momo_offer_cta_label` | string ≤ 30 | — | T | default label per CTA type | edit_post |
| `momo_offer_homepage_featured` | bool | — | C | — | publish_momo_offers |
| `momo_offer_order` | int | — | C | default 0, ascending | edit_post |
| `momo_offer_approved_by` / `_approved_at` | user ID / datetime UTC | system | C | stamped on publish by a `publish_momo_offers` holder; read-only | system |
| `momo_offer_expired_at` | datetime UTC | system | C | stamped by job | system |
| `momo_offer_review_note` | text (internal) | when returned | C | HO return-to-draft comment (required on return) [Rec] | publish_momo_offers |
| `momo_is_sample` | bool | — | C | publish refused when `wp_get_environment_type() === 'production'` | momo_manage_brand |

No coupon code, redemption, cart or payment field exists (BR-CORP-01). `Dietary\Service\WordingGuard` runs on title/summary/conditions (blocks "100% halal", seal wording, unconditional "free" ⟦counsel⟧).

### 3.7 Enumerations (`Branch\Domain`)

| Enum | Values |
|---|---|
| `OperatingStatus` | `open`, `temporarily_closed`, `closed_permanently` |
| `PeriodLabel` | `lunch`, `dinner`, `all_day`, `other` |
| `ClosureType` | `closed_all_day`, `special_hours` |
| `ClosureReason` | `public_holiday`, `private_event`, `maintenance`, `staff_shortage`, `other` |
| `NoticeSeverity` / `NoticeScope` | `info`, `warning` / `site_wide`, `branches` |
| `OfferScope` / `OfferCta` | `all_branches`, `branches` / `none`, `request_table`, `menu`, `dish` |
| `OfferState` (computed) | `draft`, `pending`, `scheduled` (publish, now < start), `active` (start ≤ now < end), `expired` (now ≥ end) |
| Facilities | see §3.2 |

## 4. Business rules and algorithms

### 4.1 `BranchPublishValidator` (on `wp_insert_post_data` + save handler)

Publishing (`draft → publish`) requires all of:
1. Capability `publish_momo_branches` (ROLE-01/02).
2. Unique `momo_branch_code` matching `^[A-Z]{3}$`; slug `^[a-z0-9-]+$` unique.
3. Required identity: name, postal code, prefecture, city, address line1, `momo_address_ja`, lat/lng (≥ 5 decimals, in Japan bounds), `momo_phone_e164`, `momo_branch_email`.
4. `momo_facts_confirmed_by` and `momo_facts_confirmed_at` set (GATE-01, BR-BR-03) → else "Record the owner's written confirmation before publishing (GATE-01)."
5. If `momo_res_enabled = 1` (evaluated on the post-save state): ≥ 1 weekly period, every period has `last_order_at`, ≥ 1 active recipient.
6. No attached image (featured, gallery) with `momo_image_illustrative = 1`.

Code immutability: once `momo_first_published_at` is set **or** any `momo_reservations` row references the branch, a changed `momo_branch_code` is refused ("The branch code cannot be changed after reservations exist." / after publication) ⟦Q-422⟧.

Identity re-confirmation [interpretation of FR-BR-004(b)]: when a published branch's name, address fields or phone change, the same save must carry a `momo_facts_confirmed_at` ≥ the previous value and not older than today − ⟦7⟧ days; otherwise refused with the GATE-01 message. Weekly-hours changes (SCR-A04, BM self-service) are not blocked.

On publish/update: `BranchScopeSync` creates/renames scope term; stamp `momo_first_published_at`; emit `identity_changed` when name/address/phone/coords/status change; audit `branch.identity_updated` / `branch.status_changed` / `branch.content_updated` with before/after; cache purge.

### 4.2 `BranchFieldGuard` (identity / settings / content)

- Every `momo_branch` meta write goes through `register_post_meta(..., 'auth_callback')` **and** the save handler. For each submitted key the guard checks the field's edit cap (§3.2). Changed values without the cap are discarded (old value kept), `access.denied` audited (actor, role, capability, branch, field name — never the value), and the admin notice `staff.err.forbidden_field` is shown. Allowed fields in the same request are still saved.
- `wp_insert_post_data`: users without `momo_edit_branch_identity` cannot change `post_title`, `post_name`, `post_status`, `menu_order`.
- Fields render read-only with a lock icon and text "Head Office only" / 「本部のみ編集可」 [draft] for users lacking the cap (cosmetic only).
- ROLE-06/ROLE-04 saves of description/photos: immediate, audited `branch.content_updated`, included in HO digest (ROLE-06 changes).

### 4.3 Operating status transitions (HO only)

| To | Rule | Effects |
|---|---|---|
| `open` | — | Accepting again (if `momo_res_enabled`); label removed |
| `temporarily_closed` | `momo_reopen_date` optional (if set ≥ today) | Directory/page label `branch.temp_closed` (or `branch.temp_closed_nodate`); Request CTAs hidden; API-01 `accepting:false, not_accepting_reason:"temporarily_closed"`; API-02 422 `branch_not_accepting`; `ClosureImpactService` flags every pending/confirmed reservation with start ≥ now; offers hidden on this branch; reopen date in the past is not displayed. No automatic reopening. |
| `closed_permanently` | Refused while any `pending`/`confirmed` reservation with `requested_start_utc ≥ now` exists: "Handle the {n} pending or confirmed future requests before closing this branch." + list (ref, date, time, status) | Branch URL in every language 301 → `/{lang}/branches/` (`template_redirect`); removed from directory, footer, selectors, sitemap, hreflang, JSON-LD; scope term hidden; staff assignments to the branch removed (audited `user.branch_unassigned`); branch-menu rows set `is_offered = 0` [Rec]; history kept |

Every change audited `branch.status_changed` and emits `identity_changed`.

### 4.4 Weekly hours (FR-BR-006, `HoursValidator`)

Model: a **schedule set** = rows sharing (`valid_from`, `valid_until`). Default set: both NULL. Dated sets: `valid_from` required, `valid_until` optional (open-ended "effective from").
Per period: `period_no` 1..6 per weekday, `period_label`, `opens_at`, `closes_at`, `last_order_at`, `closes_next_day`.

| Rule | Error (admin, key → EN) |
|---|---|
| `opens_at = closes_at` | `admin.hours.err.same` → "Opening and closing time cannot be the same." |
| `closes_next_day` is set automatically = (`closes_at < opens_at`); period length < 24 h | — |
| `last_order_at` (optional) must satisfy open ≤ LO < close (LO after midnight allowed when `closes_next_day`) | `admin.hours.err.lo` → "Last order must be between opening and closing time." |
| Periods of the same weekday in the same set must not overlap, **including the after-midnight tail of the previous weekday** (weekday 1's previous = 7) | `admin.hours.err.overlap` → "Periods on the same day must not overlap." |
| Dated sets must not overlap each other (at most one dated set valid on any date); `valid_until ≥ valid_from` | `admin.hours.err.validity` → "Schedules with dates must not overlap." [draft] |
| Branch with `momo_res_enabled = 1`: every period needs `last_order_at` | `admin.hours.err.lo_required` → "Add a last-order time to every period while online requests are on." [draft] |
| > 6 periods per weekday | `admin.hours.err.max` [draft] |

Weekday with 0 periods = regular closed day. Saving uses `HoursRepository::replaceWeek()` in one transaction (no partial week); emits `schedule_changed(branchId, [])`; audit `branch.hours_updated` with before/after JSON; HO digest when actor is ROLE-06. Concurrency: last write wins (audited); optional check (C): hidden `loaded_max_updated_at`, refuse with "This schedule was changed by someone else…" if newer rows exist.

Digit normalisation (`Kernel\Support\Digits`) on all time inputs; inputs are text `HH:MM` with `inputmode="numeric"`.

### 4.5 Schedule resolution and open-now (BR-BR-05, SDD §5.5)

Definitions: business date *b* = JST calendar date on which a period opens. Resolution for *b* (`periodsForBusinessDate`):
1. Closure row(s) for *b* → `closed_all_day` ⇒ no periods; `special_hours` ⇒ only special periods.
2. Else weekly rows for `weekday(b)` from the dated set covering *b* (if that set has rows for that weekday) — this **replaces** default rows for that weekday.
3. Else default-set rows for `weekday(b)`.
4. Else closed.

A closure on X removes business day X including its after-midnight tail on X+1, but not the tail of X−1 running into X. `isOpenAt(instant)` = instant ∈ [open, close) of any resolved period of business date `date(instant)` or `date(instant) − 1`. All computations use `Asia/Tokyo` (fixed +09:00, via `wp_timezone()`), never the device time zone.

**Open-now badge (cache-safe, §16.0.5).** Server renders a no-JS fallback (weekly hours table + today's periods) and embeds JSON in `<script type="application/json" class="momos-schedule">`:
```json
{ "tz": "Asia/Tokyo", "status": "open|temporarily_closed", "reopen_date": "YYYY-MM-DD|null",
  "days": [ { "date": "2026-12-24", "closed_reason": "public_holiday|null",
              "periods": [ { "open": "2026-12-24T17:00:00+09:00", "close": "2026-12-25T02:00:00+09:00",
                             "lo": "2026-12-25T01:30:00+09:00|null", "label": "dinner" } ] } ],
  "generated_at": "…" }
```
`days` covers business dates yesterday … today + 7 (needed for the next opening). `assets/js/open-now.js` (no deps) computes with `Date.now()` (UTC) — tolerance ±1 min at boundaries — and recomputes every 60 s:

| Condition | Output key |
|---|---|
| status `temporarily_closed` | `branch.temp_closed` / `branch.temp_closed_nodate` |
| now inside a period | `branch.open_now` (`{time}` = close, `{lo}` = LO) or `branch.open_now_nolo` if LO null |
| today's business date has `closed_all_day` closure | `branch.closed_today_reason` ({reason} = translated reason label) |
| today has no periods (regular closed day) | `branch.closed_today` |
| otherwise next period found in window | `branch.closed_now` (`{day}` = `branch.day.today` / `branch.day.tomorrow` / localised short weekday; `{time}` = opening) |
| nothing found / script error | badge hidden; hours table stays (Doc 07 §5.0) |

Today's-hours line: all periods of today's business date "11:00–15:00 · 17:00–23:00" + "L.O. {time}" per period; special hours replace weekly.

### 4.6 Closures and special hours (FR-BR-007)

- One logical record per (branch, date): `closed_all_day` = one row `period_no = 0`; `special_hours` = 1..6 rows sharing type and reason, each validated like §4.4.
- Input: single date or range (from–to); range creates one record per date; max 31 dates → "A closure range can cover at most 31 days. Use temporary closure for longer periods."
- Dates < today (JST) are read-only: create/edit/delete refused "You cannot change past dates." Delete allowed for today and future.
- Special hours without any period → "Add at least one period or choose 'Closed all day'."
- A date that already has a record: editor opens the existing record; a range that hits existing dates lists them and requires confirming "Replace existing entries" [Rec].
- `public_reason` required (default `other`); `internal_note` ≤ 255, staff-only — never in HTML, API-01, JSON-LD or emails.
- Save = `ClosureRepository::replaceDate()` per date inside one transaction for the whole range; audit `branch.closure_created|updated|deleted`; emit `schedule_changed(branchId, dates)`.
- Public display: branch page lists closures/special hours for the **next 30 days** (date, "Closed" or special periods, reason label); directory/today line shows "Closed today — {reason}"; API-01 `closed_reason`/`closed_label`; JSON-LD next 60 days (SEO spec).

### 4.7 `ClosureImpactService` (FR-BR-010, BR-RES-13, SDD §5.16)

Triggers: closure create/update/delete, weekly-hours save, status → `temporarily_closed`, `momo_res_enabled` 1 → 0 (SDD/TRD).
1. **Preview (before save, SCR-A04 / A03):** compute affected = `pending`/`confirmed` reservations of the branch with `requested_start_utc ≥ now` in the affected range (closure dates **and the following calendar date** for after-midnight slots; weekly change = all future; status/kill switch = all future) that would no longer satisfy `SlotGenerator::isValidSlot()` **ignoring lead time and booking window** (status/kill-switch triggers: all). Show "This affects {n} pending or confirmed requests" + list (ref `XXXX-XXXX`, date, time JST, party size, status) — no guest name/email in the list.
2. **Apply (after commit):** for each affected reservation not yet flagged: `needs_action_flag = 1`, insert reservation event `event_type = closure_conflict`, actor `system`, note text "Affected by closure on {date}" (EN) / 「{date}の休業・営業時間変更の影響を受けています」 [draft]. Through `Reservation\…\ReservationFlagger` only; no status change, no customer email (BR-RES-13).
3. Return count → admin notice `admin.closure.affected` with link to inbox filtered "Needs action" (`admin.php?page=momos-inbox&flag=needs_action&branch={code}`).
4. Flags are **not** auto-cleared when a closure is deleted (PRD A3); they clear when the reservation leaves pending/confirmed or via staff "Mark as handled" (API-13 `flag_resolved`, reservation spec).
5. New submissions into closed time are refused by API-02 (409 `slot_unavailable`, `fields.time = branch_closed_on_date | outside_opening_hours | after_last_order | not_a_slot`; 422 `branch_not_accepting` for status/kill switch) — reservation spec; this module guarantees fresh reads and API-01 cache purge.

### 4.8 `SettingsValidator` (G6)

| Rule | Message (key → EN) |
|---|---|
| Each field within range (§3.2) | `admin.settings.err.range` → "Enter a value between {min} and {max}." [draft] |
| `party_min ≤ party_max` | `admin.settings.err.party` → "Minimum party size cannot be larger than the maximum." [draft] |
| `decision_cutoff ≤ lead_time − momo_min_decision_window_minutes` ⟦30⟧ (BR-RES-15) | `admin.settings.err.cutoff` → "The decision cutoff must be at least {window} minutes shorter than the minimum lead time." |
| Recipients: valid email, unique, ≤ 10, domain lower-cased; domain **not** on the free-webmail block list (default: gmail.com, yahoo.co.jp, yahoo.com, outlook.com, hotmail.com, icloud.com, docomo.ne.jp, ezweb.ne.jp, au.com, softbank.ne.jp, i.softbank.jp — maintained by ROLE-01) **and**, if the setting `momo_allowed_recipient_domains` is non-empty, on that allow-list (PRV-010) | `admin.settings.err.recipient` → "Use a business-controlled address (personal webmail is not allowed)." (+ allowed domains when allow-list set) |
| `momo_res_enabled = 1` only if branch is (or becomes in this save) published, `open`, ≥ 1 active recipient, ≥ 1 weekly period, every period has LO | `admin.settings.err.enable` → "Online requests can be turned on only when the branch is published and open, has an active recipient and every opening period has a last-order time." [draft, from SDD field instructions] |
| TEST branch: recipients must not include any active recipient of a non-test branch [proposal; FR-RES-026(c) "test inboxes"] | `admin.settings.err.test_recipient` [draft] |

Changes apply to new submissions/modifications only; audit `branch.settings_updated` (before/after); emit `settings_changed`.

### 4.9 TEST branch (FR-RES-026, FR-RES-030, DEC-055)

`momo_is_test_branch = 1` (ROLE-01 only), code ⟦TBD, e.g. `TST` — DEC-052⟧:
- Excluded from: directory, footer, menu and reservation selectors, sitemaps, hreflang, JSON-LD, analytics pushes (`branch_code` never the test code), offer/notice public scope lists, core REST `wp/v2/momo_branch`, reports/KPIs/exports (by default).
- `/branches/{test-slug}/`: 404 for anyone without assignment (ROLE-01/02 or assigned staff see it with `noindex`).
- `BranchReader::findByCode($code, includeTest:false)` returns null for anonymous callers ⇒ API-01/API-02 404 `branch_not_found` (no disclosure).
- Reservation form for TEST shows banner `res.test_branch_banner` "TEST BRANCH — not a real restaurant".
- An offer/notice scoped only to TEST is never public.

### 4.10 Notices (FR-BR-009) — `NoticeQuery`

- Lifecycle: draft → publish (no approval; BM may publish own-branch notices). Audit `notice.published|updated|trashed`; BM-created notices in HO digest.
- Active ⇔ published ∧ `start ≤ now` ∧ (`end` null ∨ now < `end`) (UTC compare) ∧ (lang = ja ∨ status_lang = approved).
- Placement: `site_wide` → global banner area under the header on every public page (incl. C09); `branches` → top of that branch's page (C05), one-line chip on its directory card, and on the reservation form when that branch is selected.
- Order: `warning` before `info`, then start_at desc.
- Cache safety: server also renders notices starting within the next 24 h with the `hidden` attribute and `data-start`/`data-end` (UTC ms); `notice.js` reveals/hides at the boundaries (±1 min). Scheduled single actions `momos_purge_scheduled` at start and end purge affected caches (site-wide → `momo-global`; branch → `momo-branch-{id}`).
- Dismiss: only site-wide `info` notices have a dismiss button (sessionStorage key `momo_notice_dismissed_{id}`); never blocks content; `role="region"` + `aria-label` "Notice".
- Structured closures still render in every language even when the notice text is untranslated (A1).

### 4.11 Offers (FR-CORP-010/011) — lifecycle and `OfferQuery`

**Lifecycle.** `draft` → `pending` ("Submit for review", audit `offer.submitted`, counted in HO dashboard/digest "Offers awaiting approval") → `publish` (HO "Approve and publish"; `transition_post_status` stamps `momo_offer_approved_by/_at`, audit `offer.published`, schedules purge actions at start/end). HO "Return to draft" requires `momo_offer_review_note`. Edits of published offers only by `edit_published_momo_offers` (ROLE-01/02; audit `offer.updated` before/after). ROLE-04/06 opening a published or scheduled offer: editing refused, action "Duplicate as draft" offered (copies all fields except approval/expiry stamps, status draft). Withdrawal: HO sets draft or `end_at = now` → audit `offer.withdrawn`, purge ≤ 60 s. A user without `publish_momo_offers` submitting `post_status=publish` → status forced to `pending` (UI) / 403 (crafted).

**Save validation (admin language):**

| Condition | Message (key → EN) |
|---|---|
| Missing title, conditions, start or end | `admin.offer.err.required` → "Complete title, conditions and validity dates." |
| end ≤ start | `admin.offer.err.order` → "The end must be after the start." |
| end ≤ now at publish | `admin.offer.err.ended` → "This offer has already ended." |
| scope `branches` with none | `admin.offer.err.scope` → "Select at least one branch." |
| BM: `all_branches` or unassigned branch (crafted) | 403 `forbidden_branch`, audit `access.denied` |
| CTA dish not published/retired | `admin.offer.err.dish` → "The linked dish is not published." (save blocked) |
| > ⟦3⟧ featured active simultaneously | warning `admin.offer.warn.featured` → "Only the first {n} featured offers are shown on the homepage." (save allowed) |
| `momo_is_sample = 1` publish in production | "Sample dishes cannot be published on the live site" (SampleGuard) |
| WordingGuard hit | field error naming the phrase (Dietary spec) |

**Active** ⇔ `publish` ∧ `start_at ≤ now < end_at` ∧ `momo_is_sample = 0` (production) ∧ (lang = ja ∨ status approved) ∧ (scope `all_branches` ∨ ≥ 1 scoped branch that is published, non-test and `open`). Card branch list drops non-open/test branches.

| Query | Filter / order |
|---|---|
| `homeFeatured(lang)` | active ∧ featured; order `momo_offer_order` asc, `start_at` asc; limit ⟦3 — Q-414⟧ |
| `forBranch(branchId, lang)` | active ∧ (all_branches ∨ scope ∋ branch); nothing when branch is not `open` |
| `allActive(lang)` | active; same order |
| `hasActive(lang)` | drives header nav item "Offers" (DEC-060) and `/offers/` noindex/sitemap |

Cache safety as notices (render items starting within 24 h hidden + `data-start/end`; JS check; purge at start/end). `momos_job_offer_expiry` daily 00:05 JST stamps `momo_offer_expired_at` where null and end ≤ now, purges. Safety sweep every 15 min purges pages of offers whose start/end passed since last run (PRD FR-CORP-011(e)). When active count in a language crosses 0 ↔ ≥ 1 → `momos/offer/visibility_changed(global=true)` → purge `momo-global`.

**CTA resolution:** `request_table` → `/{lang}/reservation/` + `?branch={code}` when exactly one open branch in scope + `&offer={id}` so the form can show `offers.mention_hint` [proposal; form spec]; `menu` → `/{lang}/menu/` or the chosen category, + `?branch=` for single-branch scope; `dish` → dish permalink, falls back to menu if the dish is no longer published (WF-CUS-15 A4); `none` → no button. Request CTA fires `click_reservation` with `cta_location = offer_card`.

**Never:** offer single URLs (`/?post_type=momo_offer&p=` → 404), REST exposure, search, sitemap entries, hreflang, schema.org Offer/promotion markup (Phase 1), coupon/payment/countdown UI.

### 4.12 Maps facade (DEC-029, TRD §4.6)

- Initial render: local preview image (`momo_map_preview_image` or theme placeholder), alt "Map showing {branch} location"; links "Open in Google Maps" `https://www.google.com/maps/search/?api=1&query={lat},{lng}[&query_place_id={place_id}]` and "Directions" `https://www.google.com/maps/dir/?api=1&destination={lat},{lng}[&destination_place_id={place_id}]`; `target="_blank" rel="noopener"`. If lat/lng and Place ID are both missing, query = URL-encoded `momo_address_ja`.
- "Show map" button rendered **only if** constant `MOMOS_GMAPS_EMBED_KEY` is defined; on click replace with `<iframe src="https://www.google.com/maps/embed/v1/place?key={key}&q=place_id:{id}|{lat},{lng}" title="…" loading="lazy" referrerpolicy="no-referrer-when-downgrade">`.
- Zero requests to `maps.googleapis.com` / `google.com/maps/embed` before the click (AC-BR-02-03, PRV-024). No Google script ever enqueued.
- Analytics: Directions / Open in Google Maps fire `click_directions` (`branch_code`, `cta_location`, `language`); loading the map is **not** an event (PRD A3).

### 4.13 Caching, purge and jobs

| Event | Purge (via `Kernel\Cache\CacheInvalidator`) |
|---|---|
| Identity change | `momo-global` (footer), `momo-branch-{id}`, directory, `momo-home` |
| Hours / closures / settings change | `momo-branch-{id}`, directory, `momo-home`, API-01 for branch |
| Notice publish/edit/start/end | site-wide → `momo-global`; branch → `momo-branch-{id}` |
| Offer publish/update/trash/start/end | `momo-offers`, `momo-home` (featured), scoped `momo-branch-{id}` (all branch pages for `all_branches`); `momo-global` when 0 ↔ ≥ 1 |

Purge within 60 s of save (NFR shared rule). Jobs: `momos_job_offer_expiry` (daily 00:05 JST), `momos_purge_scheduled` (single actions at notice/offer start/end), 15-min offer/notice sweep, `momos_job_integrity_check` items: rows whose `branch_id` is not a `momo_branch`; branches with `momo_res_enabled = 1` but no active recipient or a period without LO; past closures > ⟦24 months⟧ purge. HO digest (`momos_job_daily_digest`, Notification spec) reads audit entries of ROLE-06 branch changes in the last 24 h; until it exists the audit viewer filter "Branch changes (last 24 h)" serves (FR-BR-008(c)).

### 4.14 Audit actions used (SDD §2.4.7 catalogue — no other names)

`branch.identity_updated`, `branch.settings_updated`, `branch.hours_updated`, `branch.closure_created`, `branch.closure_updated`, `branch.closure_deleted`, `branch.status_changed`, `branch.content_updated`, `notice.published`, `notice.updated`, `notice.trashed`, `offer.submitted`, `offer.published`, `offer.updated`, `offer.withdrawn`, `offer.trashed`, `access.denied`, `user.branch_unassigned`. Payload: actor, role, branch_id, object, before/after (no PII). Audit write failure inside a transaction fails the save.

## 5. Permissions

`assigned(user, branch)` ≡ user has `momo_access_all_branches` OR a `momo_user_branches` row exists, and the account is not disabled/expired. Meta caps: M1 (`edit_post` on branch → + assigned), M2 (`momo_edit_branch(branch_id, aspect)` → aspect primitive + assigned), M3 (notice/offer: without `momo_access_all_branches` scope must be `branches` and every term assigned; non-publisher editing a published/scheduled offer → `do_not_allow`).

| Resource / action | R01 SA | R02 HO | R03 MM | R04 CE | R05 TR | R06 BM | R07 RS |
|---|---|---|---|---|---|---|---|
| Create / publish / trash branch | ✓ | ✓ | — | — | — | — | — |
| Identity + facilities fields, status, order | ✓ | ✓ | R | R | — | R(b) | R(b) |
| `momo_is_test_branch`, `momo_external_ref` | ✓ | R | — | — | — | — | — |
| Description & photos | ✓ | ✓ | R | U (all) | — | U(b) | R(b) |
| Translations of branch/notice/offer text | ✓ | ✓ | — | — | U (`momo_translate`, text fields only) | — | — |
| Reservation settings (G6) | ✓ | ✓ | — | — | — | R(b) | R(b) summary in SCR-A09 |
| Dietary tab (G7) | CAP-DCA | CAP-DCA | — | — | — | R(b) | — |
| Weekly hours / closures (`momo_edit_branch_hours`) | ✓ | ✓ | R | R | — | CRUD(b) | R(b) |
| Notices | CRUDP | CRUDP | — | CRUDP (any scope) | translate | CRUDP(b) branch scope only | R(b) |
| Offers: create/edit draft, submit | ✓ | ✓ | R | ✓ (any scope) | translate | ✓(b) branch scope only | R(b) |
| Offers: publish, edit published, delete, featured flag, reference price | ✓ | ✓ | — | — | — | — | — |
| `momo_is_sample` on offers | ✓ | ✓ | — | — | — | — | — |
| Assigned-staff panel (read) | ✓ | ✓ | — | — | — | R(b) | — |

CAP-DCA = per-user `momo_edit_dietary_claims`. Primitive grants (from TRD §2.7.2): `create/publish/delete_*_momo_branches` R01/R02; `edit_(others_|published_)momo_branches` R01/R02/R04/R06(b); `momo_edit_branch_identity`, `momo_edit_branch_settings` R01/R02; `momo_edit_branch_hours` R01/R02/R06(b); all notice caps R01/R02/R04/R06(b); `create/edit/edit_others_momo_offers` R01/R02/R04/R06(b); `edit_published/publish/delete*_momo_offers`, `read_private_momo_offers` R01/R02; `momo_access_all_branches` R01/R02/R03/R04.

Enforcement points: admin screens (`AdminScreenGuard` → `wp_die` 403), admin-post handlers (nonce + `current_user_can('momo_edit_branch', $id, 'hours')`), core post editor (`map_meta_cap`; `pre_get_posts` narrows list tables and view counts for scoped users), meta writes (`auth_callback` + guard), core REST (GET-only; writes → 405 `method_not_allowed`). Every denial → audit `access.denied` (never request body).

## 6. Customer screens

General: no placeholders ("please inquire", "TBD", "N/A", "-", "0"); phone/times/prices/codes in `<bdi dir="ltr">`; Japanese address with `lang="ja"`; RTL via logical properties; formats per Doc 07 §6.10 (`ja-JP-u-ca-gregory-nu-latn`, `en-GB…`, Western digits).

### 6.1 SCR-C04 Branch directory `/{lang}/branches/`

| Aspect | Spec |
|---|---|
| Data | `BranchReader::published()` ∩ status ∈ {open, temporarily_closed} ∩ not test ∩ translated(lang); order `menu_order`, then title — identical in every language |
| Layout | Breadcrumb Home › Branches · H1 · intro · site-wide notice banner · cards (360: 1 col; 768: 2; ≥ 1024: 3) · closing CTA |
| Card (`<article>`, name as heading) | name · concept label (if set) · area (if set) · branch notice chip (if active) · open-now badge (§4.5) + today's periods with "L.O. HH:MM" · localised address + Japanese address + copy button · phone `tel:{E.164}` showing `momo_phone_display` · actions: Request a table (`/{lang}/reservation/?branch={code}`), Directions, Details. Action names include branch ("Directions to {branch}") |
| Temporarily closed | warning chip `branch.temp_closed`; Request hidden; Call + Directions + Details remain |
| Not accepting (open but `momo_res_enabled = 0` or global off) | Request replaced by "Call to reserve" (`cta.call_to_reserve` [draft]) |
| Loading | open-now skeleton sized to text |
| Empty | No eligible branch → "Branch information will be published soon." + contact link [draft key `branch.dir.empty`] |
| Error | Clipboard API unavailable → copy button hidden, text selectable; schedule JSON failure → badge hidden |
| Success | copy → `branch.copied` in `aria-live="polite"` region for 2 s |
| Analytics | `click_reservation` (`cta_location=branch_card`), `click_phone`, `click_directions` |

### 6.2 SCR-C05 Branch detail `/{lang}/branches/{slug}/`

Order (Doc 07 §5.5, authoritative): 1 branch notices · breadcrumb Home › Branches › {name} · 2 H1 name + concept tag · 3 open-now + today's hours + L.O. (or temporarily-closed banner `branch.temp_closed`) · 4 action row Request a table · Call · Directions · 5 address (localised + Japanese + copy) and access directions (`{station} · Exit {exit} · {n} min walk`) + access notes · 6 map facade · 7 weekly hours table (`<table>` with caption; Day / Opening hours / Last order; closed weekday "Closed"; today row marked "(today)" in text) · 8 closures & special hours next 30 days (hidden if none) · 9 halal statement + alcohol policy (Dietary spec) · 10 facilities (confirmed values only; hidden if all unknown) · 11 "Offers at this branch" (hidden if none) · 12 "See this branch's menu" → `/{lang}/menu/?branch={code}` · 13 gallery (real photos only; hidden if empty) · 14 closing CTA `cta.request_table_branch` (hidden when not accepting).

| Aspect | Spec |
|---|---|
| Desktop ≥ 1024 | two columns: main (5–13) + sticky side panel (open-now, today's hours, Request, Call, Directions); side panel on the left in RTL |
| Mobile action bar | "Request a table · Call · Directions"; "Call · Directions" when not accepting (AC-CORP-08-04) |
| `closed_permanently` | 301 → `/{lang}/branches/` (all languages) |
| TEST branch | 404 unless assigned staff (then `noindex`) |
| Untranslated in lang | 404 (switcher → that language's home with notice — i18n spec) |
| Facilities rendering | prayer space (yes "Prayer space available" / no "No prayer space" / nearby "Prayer space nearby" + approved note), smoking, step-free, seating (comma list), payment (comma list in enum order, e.g. "Cash, QR payments"), Wi-Fi; `unknown`/empty omitted with its label |
| Data extras | Restaurant + BreadcrumbList JSON-LD (SEO spec; none for TEST/closed); `view_branch` dataLayer push (not for TEST) |
| Error | map image fails → address + links remain |

### 6.3 SCR-C21 Offers `/{lang}/offers/` and offer sections

| Aspect | Spec |
|---|---|
| Layout | Breadcrumb Home › Offers · H1 `offers.title` · `offers.intro` · branch filter chips ("All" + each branch with an active offer) only when active offers have different scopes; client-side, updates `?branch=` (canonical without query; unknown value ignored) · cards in order |
| Card (`<article id="offer-{id}">`) | optional image (4:3, alt) + `media.illustration` caption if flagged · eyebrow "Offer" · title (H2 on C21, H3 in sections) · summary · `offers.valid` with localised dates (+ times when not whole days; + "JST" for non-ja per DEC-062) · scope `offers.scope.all` or branch names · `offers.conditions` (always visible; > 3 lines → `offers.show_all` disclosure button) · price "¥1,500 (tax incl.)" if set · CTA |
| Empty | `offers.empty` + links to Menu and Branches; HTTP 200, `noindex`, excluded from sitemap while empty |
| Home (C01) | section directly after hero: `homeFeatured`, heading + `offers.see_all`; hero link `offers.see_current` while ≥ 1 featured active; whole section absent when none |
| Branch page (C05) | section 11 via `forBranch` |
| Responsive / a11y | 1 / 2 / 3 columns; dates written out; no countdowns or "ends soon" animation; no text in images |

### 6.4 Other surfaces

Header: site-wide notice banner; "Offers" nav item after "Branches" only while `hasActive(lang)`; footer: branch list (name, phone, link; non-test, not closed_permanently, translated) and "Offers" link always. Reservation form (C09): branch notices for the selected branch; TEST banner. Home branch teaser: same card data.

## 7. Admin screens (admin language ja/en per user)

### 7.1 SCR-A03 Branch list / edit
- List columns: name, code, operating-status badge, published, `momo_res_enabled`, language completion (per-language i18n status), last updated. Scoped users see only assigned branches (`pre_get_posts`); CE sees all.
- Edit: tabs Identity · Access & facilities · Reservation settings · Dietary · Description & photos; publish box contains the GATE-01 block "Facts confirmed in writing by {name, date}" (+ evidence note). Read-only fields show lock + "Head Office only". `temporarily_closed` reveals reopen date; selecting `closed_permanently` shows warning with the future-reservation list before save. Side panel "Assigned staff" (read-only, `BranchStaffPanel`; BM sees own branch staff).
- States: field errors inline + summary notice (`role="alert"`); success "Branch updated" / 「店舗情報を更新しました」 [draft]; publish blocked notice for GATE-01; affected-requests notice after status/kill-switch change.
- Branch photos reject illustrative images: "Demo images cannot be used here. Upload a real photo." (DEC-047).

### 7.2 SCR-A04 Hours & closures (`admin.php?page=momos-hours&branch={id}`)
- Branch selector (only assigned branches; foreign `branch` param → 403 + audit). Tab **Weekly hours**: schedule-set selector (Default / dated sets + "Add schedule from date"); 7 rows (360 px: cards), each 0..6 periods (label select, opens, closes, last order, read-only "crosses midnight" indicator), "Closed" toggle per weekday, "Copy Monday to all weekdays". Tab **Closures & special hours**: upcoming list (next 90 days; past read-only) + add form (date or range, type, periods if special, public reason, internal note) + optional "Post a notice for these dates" link prefilled to SCR-A13 [Rec].
- 7-day preview (customer view in JST, e.g. "Mon 12:00–22:00, last order 21:30").
- Save flow: POST `admin-post.php?action=momos_save_hours|momos_save_closure|momos_delete_closure` (nonce `momos_hours_{branch}`) with `confirm=0` → if `ClosureImpactService::preview()` > 0, re-render with impact panel "This affects {n} pending or confirmed requests" + list + buttons "Save anyway" (`confirm=1`) / "Cancel"; if 0 → save directly. After save: notice + preview refresh + link to inbox "Needs action".
- Errors: inline per period, linked (`aria-describedby`), labelled "Monday, period 1"; unsaved-changes warning on leave; save failure keeps inputs. Targets ≥ 44 px; usable at 360 px.

### 7.3 SCR-A13 Notices
Native list/edit with meta box: title, body (counter 300), severity, scope (BM: only own branches; "Site-wide" not offered), start/end (JST, `HH:MM` 24 h), link; preview. List column state badge Scheduled / Active / Expired; translation status per language (untranslated active notices listed first in the gaps view).

### 7.4 SCR-A20 Offers
- List columns: title, scope, start, end, state (Draft · Pending review · Scheduled · Active · Expired), featured, order, translation completeness; filters by state, scope, branch. Expired offers remain listed (read-only badge). Empty: "No offers yet" + "Create offer".
- Edit meta box per §3.6 with helper text: "Enter prices including tax. Coupon codes, online redemption and 'usual price' comparisons without evidence are not allowed." [draft]; live card preview. Buttons: CE/BM "Save draft", "Submit for review"; HO "Approve and publish", "Return to draft" (comment required); CE/BM on published: "Duplicate as draft".
- HO dashboard widget "Offers waiting for approval" (count → filtered list) (S, F-ADM-01).

## 8. Strings (Doc 07 §6 keys; JA marked [draft] need native review — all JA in Doc 07 are drafts)

| Key | EN | JA |
|---|---|---|
| `nav.branches` | Branches | 店舗一覧 |
| `nav.offers` | Offers | お得な情報 |
| `cta.request_table` | Request a table | 予約リクエスト |
| `cta.request_table_branch` | Request a table at this branch | この店舗に予約リクエスト |
| `cta.call` / `cta.directions` / `cta.details` | Call / Directions / Details | 電話する／道順を見る／詳細 |
| `cta.open_maps` | Open in Google Maps | Googleマップで開く |
| `cta.view_menu` | View menu | メニューを見る |
| `branch.open_now` | Open now · until {time} (last order {lo}) | 営業中・{time}まで（ラストオーダー{lo}） |
| `branch.closed_now` | Closed now · opens {day} {time} | 営業時間外・{day} {time}から営業 |
| `branch.temp_closed` | Temporarily closed — reopens {date} | 臨時休業中（{date}より営業再開） |
| `branch.copy_address` / `branch.copied` | Copy Japanese address / Address copied | 住所をコピー／住所をコピーしました |
| `price.tax_incl` | (tax incl.) | （税込） |
| `offers.title` / `offers.intro` | Offers / Offers are used at the branch. Mention the offer when you request a table or when you arrive. | お得な情報／特典は店舗でご利用いただけます。予約リクエストの際、またはご来店時にお伝えください。 |
| `offers.valid` | Valid {start} – {end} | 期間：{start}～{end} |
| `offers.scope.all` | All branches | 全店舗 |
| `offers.conditions` / `offers.show_all` | Conditions / Show all conditions | 条件／条件をすべて表示 |
| `offers.see_all` / `offers.see_current` | See all offers / See current offers | すべての特典を見る／現在の特典を見る |
| `offers.empty` | There are no current offers. Please check again later. | 現在ご案内している特典はありません。後日あらためてご確認ください。 |
| `offers.mention_hint` | Mention the offer: {title} | 特典名をご記入ください：{title} |
| `media.illustration` | Image for illustration | 写真はイメージです |
| `res.err.branch.not_found` | This branch could not be found. | この店舗は見つかりませんでした。 |
| `staff.err.forbidden_branch` | You do not have access to this branch. | この店舗へのアクセス権限がありません。 |
| `staff.err.forbidden_field` | You are not allowed to change: {field}. | 次の項目を変更する権限がありません：{field} |
| `staff.warn.needs_action_closure` | This reservation falls on a closure or changed hours. Please contact the guest. | このご予約は休業日または変更後の営業時間外です。お客様へご連絡ください。 |

New keys required by this spec (add to the Doc 07 register; EN from PRD where quoted, JA [draft]):

| Key | EN | JA [draft] |
|---|---|---|
| `branch.open_now_nolo` | Open now · until {time} | 営業中・{time}まで |
| `branch.closed_today` | Closed today | 本日休業 |
| `branch.closed_today_reason` | Closed today — {reason} | 本日休業（{reason}） |
| `branch.temp_closed_nodate` | Temporarily closed | 臨時休業中 |
| `branch.day.today` / `branch.day.tomorrow` | today / tomorrow | 本日／明日 |
| `branch.lo_short` | L.O. {time} | L.O. {time} |
| `branch.closed_day` | Closed | 定休日 |
| `branch.today_marker` | (today) | （本日） |
| `branch.access_walk` | {minutes} min walk | 徒歩{minutes}分 |
| `branch.map_alt` | Map showing {branch} location | {branch}の地図 |
| `branch.show_map` | Show map | 地図を表示 |
| `branch.hours.title` / `branch.closures.title` / `branch.facilities.title` / `branch.offers.title` / `branch.menu_link` | Opening hours / Closures and special hours / Facilities / Offers at this branch / See this branch's menu | 営業時間／休業日・特別営業時間／設備／この店舗のお得な情報／この店舗のメニューを見る |
| `branch.dir.empty` | Branch information will be published soon. | 店舗情報は近日公開予定です。 |
| `cta.call_to_reserve` | Call to reserve | 電話で予約 |
| `res.test_branch_banner` | TEST BRANCH — not a real restaurant | テスト店舗（実在の店舗ではありません） |
| `period.lunch/dinner/all_day/other` | Lunch / Dinner / All day / Other | ランチ／ディナー／通し営業／その他 |
| `closure_reason.public_holiday/private_event/maintenance/staff_shortage/other` | Public holiday / Private event / Maintenance / Closed for operational reasons / Closed (PRD FR-BR-007 proposals) | 祝日休業／貸切営業／設備点検／都合により休業／休業 |
| `facility.prayer.yes/no/nearby` | Prayer space available / No prayer space / Prayer space nearby | 礼拝スペースあり／礼拝スペースなし／近隣に礼拝スペースあり |
| `facility.smoking.non_smoking/smoking_area` | Non-smoking / Smoking area | 全席禁煙／喫煙スペースあり |
| `facility.step_free.yes/partial/no` | Step-free access / Partly step-free / Not step-free | 段差なし／一部段差あり／段差あり |
| `facility.seating.*` | Table / Counter / Private room / Tatami / Terrace | テーブル席／カウンター席／個室／座敷／テラス席 |
| `facility.payment.*` | Cash / Credit cards / IC cards / QR payments / Other | 現金／クレジットカード／交通系ICカード／QRコード決済／その他 |
| `facility.wifi.yes/no` | Wi-Fi available / No Wi-Fi | Wi-Fiあり／Wi-Fiなし |
| `offer.state.*` (admin) | Draft / Pending review / Scheduled / Active / Expired | 下書き／承認待ち／公開予定／公開中／終了 |

Admin error strings (EN from PRD; JA [draft]): branch code "Branch code must be 3 capital letters and unique." 「店舗コードは半角大文字3文字で、他の店舗と重複しないようにしてください。」; code locked "The branch code cannot be changed after reservations exist." 「予約がある店舗の店舗コードは変更できません。」; slug "Use lowercase letters, numbers and hyphens; this slug is already used." 「半角小文字・数字・ハイフンを使用してください。このスラッグは既に使われています。」; coordinates "Enter latitude and longitude with at least 5 decimal places." 「緯度・経度は小数点以下5桁以上で入力してください。」; phone "Enter a valid phone number." 「正しい電話番号を入力してください。」; GATE-01 "Record the owner's written confirmation before publishing (GATE-01)." 「公開前にオーナーの書面確認を記録してください（GATE-01）。」; closure range/past/special-hours/notice "End must be after start." 「終了日時は開始日時より後にしてください。」 and the §4.4/§4.6/§4.11 messages.

## 9. Endpoints

| Endpoint | Method | Auth | Purpose / contract | Errors (Doc 04 §16.0.4) |
|---|---|---|---|---|
| `/{lang}/branches/`, `/{lang}/branches/{slug}/`, `/{lang}/offers/` | GET | public | HTML (page-cached per language) | 404 (TEST/untranslated), 301 (closed_permanently) |
| `/wp-json/momos/v1/branches/{code}/reservation-rules` (API-01, owned by Reservation/API spec; data from this module) | GET | public; TEST only for assigned logged-in staff | `code` `^[A-Z]{3}$`, `lang` enum (default ja). 200: `branch_code, branch_name, accepting, not_accepting_reason ∈ {temporarily_closed, reservations_disabled, global_disabled, null}, reopen_date, timezone:"Asia/Tokyo", rules{party_min, party_max, lead_time_minutes, booking_window_days, slot_interval_minutes, response_promise_minutes}, contact{phone_e164, phone_display}, days[{date, slots[], closed_reason, closed_label}], generated_at`. `Cache-Control: public, max-age=300`; purged on hours/closures/settings change. Never returns `internal_note`, recipients or staff data | 400 `invalid_param`, 404 `branch_not_found`, 429 `rate_limited`, 503 `service_unavailable` |
| `/wp-json/wp/v2/momo_branch[/{id}]` | GET only | public | public fields only; excludes TEST and closed_permanently | 405 `method_not_allowed` for writes; 401/403 `rest_forbidden` |
| `/wp-json/wp/v2/momo_offer`, `/momo_notice` | — | — | not registered (`show_in_rest` false) | 404 |
| `admin-post.php?action=momos_save_hours` | POST | staff, nonce, `momo_edit_branch(id,'hours')` | `branch_id`, `valid_from`, `valid_until`, `periods[weekday][n]{label, opens, closes, last_order}`, `confirm` | 403 (`wp_die`) + `access.denied`; validation → re-render with errors |
| `admin-post.php?action=momos_save_closure` | POST | same | `branch_id`, `date_from`, `date_to`, `type`, `periods[]`, `public_reason`, `internal_note`, `replace_existing`, `confirm` | same |
| `admin-post.php?action=momos_delete_closure` | POST | same | `branch_id`, `date` (≥ today), `confirm` | same; past date → "You cannot change past dates." |
| Core `post.php` for `momo_branch` / `momo_notice` / `momo_offer` | POST | caps per §5 | field guards §4.2, validators §4.1/4.8/4.10/4.11 | 403 on foreign object; crafted forbidden scope → 403 `forbidden_branch` semantics (`wp_die` 403 in admin) |

REST error envelope (any momos route): `{ "code", "message" (localised), "data": { "status", "fields": { "<field>": "required|invalid_format|too_long|out_of_range|not_allowed" } } }`.

## 10. Acceptance criteria and tests to automate

Fixtures (Doc 12 §1.5, fictional): QAA open full facts; QAB open split hours + closure today; QAC Friday 18:00–01:00 LO 00:30; QAD minimal fields; QAE isolation; QAY temporarily_closed with reopen date; QAZ closed_permanently; QAF created in test; TST hidden test branch. Offers O1 all-branches featured active, O2 QAB active, O3 QAC active not featured, O4 future, O5 expired, O6 QAB draft by BM awaiting approval, O7 QAY-scoped active. Test clock `momos_test_clock` (non-production only).

Key acceptance criteria (Given/When/Then condensed):

| AC | Criterion |
|---|---|
| AC-BR-01-01 | Directory shows A (open), B (temp closed "Temporarily closed — reopens 10 Dec 2026", no Request); C (closed_permanently) and TEST absent |
| AC-BR-01-02 | 11:00–15:00 LO 14:30 + 17:00–23:00 LO 22:30, viewed 15:30 JST from UTC−5 device → both periods with L.O. and "opens today 17:00" (`branch.closed_now`) |
| AC-BR-01-03 | All-day closure today reason `public_holiday` → "Closed today — Public holiday" in page language |
| AC-BR-01-04 / -05 | Clipboard = stored Japanese address, "Address copied" announced; Arabic cards mirrored, phone/times/JA address LTR |
| AC-BR-02-01 | smoking `unknown` + payment [cash, qr_payment] → no smoking row; payment "Cash, QR payments" |
| AC-BR-02-02 | Closure in 10 days shown; in 40 days not |
| AC-BR-02-03 | No request to `maps.googleapis.com` / `google.com/maps/embed` before click |
| AC-BR-02-04 / -05 / -06 | Request a table preselects branch; closed_permanently → 301 to same-language `/branches/`; no placeholder strings in any language |
| AC-BR-03-01 | Sixth branch appears in directory, menus, reservation selector, footer, sitemap, JSON-LD with no code change |
| AC-BR-03-02 / -05 | Publish without facts confirmation blocked; code change refused after reservations exist |
| AC-BR-03-03 / -07 | BM crafted phone change → unchanged + `access.denied`; BM settings tab read-only, POST rejected |
| AC-BR-03-04 | 2 confirmed future reservations → `closed_permanently` refused, both listed |
| AC-BR-03-06 | BM description + photo (alt) live after purge, in HO digest |
| AC-BR-04-01 | Sat 17:00–02:00 LO 01:30 → `closes_next_day = 1`, "Open now" at 01:00 Sunday JST |
| AC-BR-04-02 | Mon 11:00–15:00 + 14:00–18:00 → overlap error, nothing saved |
| AC-BR-04-03 | 3 confirmed on 31 Dec + all-day closure → dialog shows 3, flagged, status stays confirmed, no customer email |
| AC-BR-04-04 | API-02 for 31 Dec → 409 `slot_unavailable`, `fields.time = branch_closed_on_date` |
| AC-BR-04-05 | BM of A posts closure for B → 403 `forbidden_branch` + `access.denied`, B unchanged |
| AC-BR-04-06 / -07 | Digest lists BM hour change with before/after; range 31 Dec–3 Jan → 4 records, no slots those dates |
| AC-BR-05-01..04 | Notice hidden at 18:01 after 18:00 end (cached HTML too); BM cannot pick site-wide/unassigned; branch-D notice absent elsewhere; ja-only notice not shown on `/en/` |
| AC-CORP-09-01..11 | CE submit → pending, not public, no Publish; HO publish stamps + audit; start tomorrow 11:00 shown at 11:01 (cached); end 2026-12-31 23:59 absent at 2027-01-01 00:01 incl. sitemap; branch scoping; BM `all_branches` → 403 + audit; validity + conditions on every card; ja-only offer absent on `/en/offers/`; empty `/offers/` noindex + no home heading; no anonymous access via `?post_type=momo_offer&p=` or `wp/v2/momo_offer`; CE editing published → refused + "Duplicate as draft" |
| AC-RES-06-01 | window 30, lead 60, cutoff 40 → refused with cutoff message; cutoff 30 saves |
| AC-RES-06-02 / -03 | party_max 8→6: form ends at 6, crafted 7 → 422 `party_size_out_of_range`; `momo_res_enabled=0` → 422 `branch_not_accepting`, pending still processable |
| AC-RES-06-04 / -06 | `branch.a@gmail.com` refused; BM sees settings read-only |
| AC-RES-06-05 | TEST absent from site, sitemap, JSON-LD; staff form shows TEST banner; submission notifies only test inbox |

Test cases to automate (Doc 12 / test_cases.csv):

| TC | Title | Type | Gate |
|---|---|---|---|
| TC-BR-001 | Directory lists only eligible branches | Playwright | GATE-01 (P0) |
| TC-BR-002 / 003 | Card fields; open-now in JST (00:30 Sat, device zones New_York/Dhaka) | Playwright | — |
| TC-BR-004 / 005 | Detail content; unconfirmed fields not rendered | Playwright | GATE-01 |
| TC-BR-006 | Branch facts equal signed fact sheet | manual + Playwright | GATE-01 (P0) |
| TC-BR-007 / 008 | HO identity edit + validation + audit; settings HO-only (REST write 403) | Playwright + PHPUnit | — |
| TC-BR-009 / 010 | Hours editor incl. cross-midnight; closures/special hours (internal note never public, API-01 reflects) | PHPUnit + Playwright | — |
| TC-BR-011 / 012 | BM edits immediate/audited/digested; notices scope/severity/auto-expiry | Playwright + PHPUnit | — |
| TC-BR-013 / TC-RSA-016 | Closure blocks new requests and flags existing | PHPUnit + Playwright API | P0 |
| TC-BR-014 / 015 / 016 | Permanent closure; sixth branch; map facade | Playwright | — |
| TC-RES-008 / 012 / 013 / 038 | Party boundaries; cross-midnight slots; closures server-side; cutoff vs lead time | PHPUnit + Playwright | P0/P1 |
| TC-RSA-023 / TC-RES-037 / TC-SMK-001 / TC-ANL-009 | TEST branch behaviour, isolation, smoke, analytics exclusion | Playwright API + manual | GATE-10 |
| TC-ACL-009 / TC-ACL-021 | Isolation: branch data editing; offer scope for BMs | Playwright API | GATE-03 (P0) |
| TC-CORP-010..014 | Offer workflow; display; start/end + never indexed; informational only; translation no-fallback | Playwright + PHPUnit | — |
| TC-HOME-001 / TC-NAV-003 / TC-NAV-006 | Home offers section; footer branch list; conditional Offers nav | Playwright | — |
| TC-ADM-006 / 007 / 010 | Recipients verified; settings match approved values; recipient domain rule | manual / Playwright | GATE-10 |
| TC-WP-007 / 009 | Empty fields hide label; BM hours task ≤ ⟦5⟧ min without help | Playwright / manual | — |
| TC-E2E-007 / 013 | Private-event closure end-to-end; sixth branch end-to-end | manual | — |

Unit tests (PHPUnit, FrozenClock): `ScheduleService` (midnight crossing, 31 Dec → 1 Jan, closure on regular closed day, closure on X keeps X−1 tail, seasonal precedence, last-order boundary); `HoursValidator` (overlap with previous-day tail, LO bounds, same open/close); `SettingsValidator` (each boundary, BR-RES-15, domain lists); `BranchPublishValidator` (GATE-01, code immutability); `OfferQuery` active window at start = now / end = now, non-open scoped branch, sample flag; `NoticeQuery`; enum ↔ CHECK parity; `open-now.js` (Jest or Playwright clock) at ±1 min boundaries.

## 11. Out of scope (this spec)

- Halal/alcohol Dietary tab (G7), claim templates, certificate register — Dietary spec.
- Reservation form, API-01 slot generation code (`SlotGenerator`), API-02/12/13, inbox "Needs action" UI, flag clearing — Reservation spec (this module only provides data, the impact service and the flagger call).
- JSON-LD generation, sitemaps, hreflang, redirects from legacy site — SEO spec (consumes `BranchReader`/`ScheduleProvider`).
- HO daily digest e-mail (E10) and recipient resolution — Notification spec.
- Translation UI and language gate — i18n spec. Media alt-text per language and `IllustrativeImageGuard` — Kernel/media spec.
- Staff assignment UI (`momo_user_branches`) — Access spec. Dashboard widgets (F-ADM-01, S).
- Gallery page (F-CORP-10, C), News (F-CORP-05, C), GBP/directory updates (client operation, DEC-040), `phone_languages` field (IA Rec, not in TRD), linking a closure to a notice (`notice_id`, IA only), coupons/redemption/payment (BR-CORP-01), table inventory/capacity, Phase 2 integrations (`external_ref` reserved).

## 12. Open TBDs and documentation conflicts

### 12.1 TBD values (proposal used as default)

| Item | Default | Owner / ref |
|---|---|---|
| Branch list, names, codes, slugs, concept model | placeholders; model supports both | Owner — DEC-001 (BLOCKING), Q-001, DEC-052 |
| Reservation values per branch | 1–8 party, 120 min lead, 60 days, 30 min slots, cutoff 60, SLA 120, 3 pending, cancel cutoff 0 | Q-013..017, Q-022, Q-401/402 |
| SLA clock | `wall_clock` | DEC-013 / Q-402 |
| Min decision window | 30 | BR-RES-15 |
| Recipient domains (allow/block lists) | PRD block list | DEC-049 / Q-421 / PRV-010 |
| Featured offers on home | 3 | Q-414 |
| Payment method list | 5 enum values | Q-002 |
| Branch email public? | not displayed | Q-002 |
| TEST branch code | e.g. `TST` | DEC-052 |
| Closure history retention | 24 months | ENT-04 [Rec] |
| Notice/offer trash after end | HO may trash after 90 days | ENT-13/25 |
| Directory order | `menu_order` set by HO | DEC-001 |
| Code immutable after publish vs after reservations | both enforced | Q-422 |
| Maps Embed API key/terms | "Show map" hidden until key set | DEC-029 |

### 12.2 Conflicts found (resolution applied here)

1. **Facilities enums**: IA ENT-02 uses `smoking: separate_smoking_room/smoking_allowed`, `payment: qr_paypay/qr_other`, prayer without `nearby`, no `terrace`; PRD §18.3 + TRD G5 use `non_smoking/smoking_area`, `qr_payment/other`, `nearby`, `terrace`. → PRD/TRD (PRD §18.3 names TRD as single source).
2. **Closure storage**: IA ENT-04 has one row per date with `special_periods` JSON + `notice_id`; SDD uses one row per period (`period_no`). → SDD DDL.
3. **Period label**: PRD FR-BR-006 `lunch|dinner|all_day`; SDD/TRD/§18.3 add `other`. → include `other`.
4. **Reservation settings ranges/defaults**: `momo_res_enabled` default PRD true vs SDD/TRD 0 (IA false) → 0; `party_min` PRD 1–20 vs SDD 1–50 → 1–20; `response_sla` PRD 15–1440 vs SDD 0–1440 (0 = off) → 0 or 15–1440; IA lead time 0–2880 vs PRD/SDD 0–10080 → 0–10080.
5. **Recipient domain rule**: PRD = free-webmail block list; Doc 11 PRV-010 / TC-ADM-010 = allow-list `momo_allowed_recipient_domains`. → both (block list always; allow-list when configured).
6. **`branch_code` immutability**: PRD "once any reservation references" (Q-422 open) vs TRD/SDD "after first publish". → enforce both.
7. **Branch name length**: PRD/IA ≤ 80 vs TRD/SDD ≤ 100. → 80.
8. **Reopen date**: PRD/WF optional vs TRD/Doc 07 A03 required. → optional; added `branch.temp_closed_nodate`.
9. **Notice end date**: PRD "end optional only for HO; BM ≤ start + 60 days" vs TRD G15/IA "end required". → PRD rule.
10. **Notice a11y/dismiss**: PRD "warning uses role alert styling; banners dismissible" vs Doc 08 `role="region"`, dismiss only site-wide info. → Doc 08.
11. **Closure-impact event**: PRD "system `note` event", Doc 05 "`flagged` event + `flag_cleared`", SDD/M0002 CHECK `closure_conflict`, backlog TK-09-18 "event note". → `closure_conflict` (DB CHECK).
12. **Flag auto-clear**: PRD F-BR-04 A3 "not automatically removed" vs Doc 05 WF-EXC-07 step 5 auto-clear [Rec]. → no auto-clear.
13. **Kill switch flags**: SDD §5.16/TRD flag reservations on `reservations_enabled = 0`; PRD A1 only says pending remain processable. → flag (SDD), confirm with Owner.
14. **BM identity tamper response**: Doc 05 says 403 `forbidden_field`; PRD says field unchanged + audit (save continues). → PRD behaviour + `staff.err.forbidden_field` notice.
15. **Open-now strings**: PRD "Closed now" / "Opens at HH:MM" vs Doc 07 key `branch.closed_now` "Closed now · opens {day} {time}". → Doc 07 (register is authoritative); test ACs must assert via keys.
16. **Open-now computation**: PRD/TRD client-side from embedded JSON (today + tomorrow) vs backlog TK-05-08 "server-computed, cache TTL ≤ 15 min". → client-side; JSON extended to yesterday..today+7 to find the next opening.
17. **Map "Show map"**: PRD A3/TRD §4.6 interactive embed after click vs Doc 08 "optional, default off". → button only when `MOMOS_GMAPS_EMBED_KEY` is configured.
18. **`click_directions` on Show map**: TRD §4.7 includes "Show map"; PRD A3 "loading the map is not an analytics event". → PRD.
19. **Offer expiry purge**: PRD "job every 15 min", TRD "single actions at start/end + daily stamp", backlog "15 min". → all three (single actions + 15-min sweep + daily stamp) and hidden pre-render for upcoming items.
20. **Offer `menu` CTA target category**: PRD allows a chosen category; TRD G17 has no field. → added `momo_offer_cta_category`.
21. **Offer state labels**: PRD "Awaiting approval / Ended" vs Doc 07/09 "Pending review / Expired". → Doc 07.
22. **Seasonal schedules**: PRD "a set of weekly periods … at most one set valid on any date" vs SDD per-weekday replacement (a seasonal set cannot express a closed weekday). → SDD precedence; seasonal closed days via closures.
23. **Field names in backlog** (`momo_concept`, `momo_is_test`, `momo_phone`, `momo_email`) differ from TRD G4 (`momo_concept_label`, `momo_is_test_branch`, `momo_phone_e164`, `momo_branch_email`). → TRD G4 names.
24. **Facts-confirmation re-publish rule** (PRD FR-BR-004(b) "re-published after an identity change … or hours structure") is not specified technically anywhere; implemented for name/address/phone only (§4.1); hours excluded so BM self-service (DEC-025) is not blocked — confirm with Owner.
25. **Branch detail order**: PRD FR-BR-002 main workflow lists notices after the action row; Doc 06/07/08 put notices first. → Doc 07 §5.5 (declared authoritative, R2-22).
