# Spec — Menu & Dietary (F-MENU-01..05, F-DIET-01/02)

Implementation-ready specification for the `Momos\Menu` and `Momos\Dietary` modules of `momos-core` and the menu/dietary templates of the `momos` theme. Developers should not need the original documentation package. Sources: Doc 04 §16.4–16.5, §17, §18; Doc 04A US-MENU-*/US-DIET-*; Doc 05 WF-CUS-04/05, WF-ADM-04..07/20/21, WF-XBR-05, WF-EXC-08/10; Doc 06 ENT-05..12; Doc 07 §4.2/4.3/4.9/4.13, §5.3/5.6/5.7/5.20–5.22/5.27, §6.4–6.6; Doc 08 CMP-PriceBlock/DietaryIcon/AvailabilityLabel/AllergenTable/HalalStatement/BranchMenuRow; Doc 09 §2.2–2.5 (G2, G7–G14, G18), §2.7, §3.3–3.5, §3.14, API-14/15; Doc 10 §2.4.2/2.4.3, §3.2, §3.3; Doc 11 §8–9; Doc 12 TC-MENU/DISH/DIET; Doc 17 DEC-020..023, DEC-047.

Labels: **[Doc]** = documented value; **[TBD → default]** = open decision, build the documented proposal default as configuration; **[Proposed]** = added by this spec where the docs are silent (review before release); ⟦…⟧ = placeholder for a business fact that must not be invented.

---

## 0. Build adaptation (IMPL-001/002) — how documented mechanisms map to this build

| Documented mechanism | This build |
|---|---|
| ACF field groups G2, G7–G14, G18 (fixed keys) | Native meta registered in code (`register_post_meta` / `register_term_meta`, classic meta boxes, admin tabs rendered by `momos-core`). **Meta keys = documented ACF field names** (e.g., `momo_dish_sku`, `momo_alg_wheat`). ACF "options page" → WordPress option (`momos_dietary_statements`, etc.) |
| ACF `acf/prepare_field` read-only + `acf/update_value` guard | Meta box renders read-only (CMP-LockedField) **and** server-side guards on `save_post_{type}`, `update_post_metadata`/`add_post_metadata` filters and REST `auth_callback` reject unauthorised changes (keep old value, audit `access.denied`) |
| WPML "copy" (C) field | Stored once on the post (language-independent). Never in `_momos_i18n_*` |
| WPML "translate" (T) field | Japanese source in native field/meta; other languages in post/term meta `_momos_i18n_{lang}` (JSON object of translatable fields) + `_momos_i18n_status_{lang}` ∈ `missing, draft, needs_review, approved` |
| "Published and translated in the language" (BR-MENU-05) | `post_status = publish` AND (`lang = ja` OR `_momos_i18n_status_{lang} = approved`). Same rule for terms (category, ingredient, dietary tag, allergen) |
| WPML canonical JA post ID (`wpml_object_id`) | Single post per entity → `branch_id`/`dish_id` = the post ID. Repositories still verify post type + status before writes |
| WPML "needs update" on source change | When a T field of the JA source changes on a published item, set every `approved` language to `needs_review`, **but keep rendering the last approved JSON** until re-approved (no mixed language) |
| Elementor Loop Grid queries (`momo_category_dishes`, `momo_featured_dishes`, `momo_related_dishes`) | Theme templates call `Menu\Service\MenuQueryService` methods of the same names |
| Elementor dynamic tags/widgets (`momos-dish-price`, `momos-allergen-table`, …) | Theme template parts / `momos-core` render functions with the same names (`templates/widgets/*.php`) |
| Rank Math canonical / robots | `Presentation\Seo` filters (canonical without query, `noindex,follow` on query variants) |
| Action Scheduler recurring actions | `Kernel\Jobs\ScheduleRegistry` (hooks and schedules below; real server cron) |
| `wpml-config.xml` | Field registry declares `translatable: bool` per field; CI asserts it matches §2 |

Languages: `ja` (source, root URL), `en`, `ar`, `ur`, `hi`, `bn` (prefixes `/en/` …). Slugs are shared Latin across languages. `ar`/`ur` are RTL.

---

## 1. Requirement coverage (traceability)

### 1.1 Features, FRs and BRs
| ID | Statement (one line) | Priority |
|---|---|---|
| F-MENU-01 / FR-MENU-001 | Menu hub `/menu/`: categories in Menu Manager order, only those with ≥ 1 displayable dish; branch selector via `?branch={code}`, kept on all menu links, canonical without query | Must |
| FR-MENU-002 | Category page dish cards: photo, name, short description, price block, dietary icons, spice 0–3 with text, availability and advance-order labels; ≤ 60 dishes without pagination | Must |
| F-MENU-02 / FR-MENU-003 | Dish detail page: name, gallery, price (single or per-branch table), branches offering it, tags, spice, halal basis, key ingredients, allergen block, advance-order notice, related dishes, "Request a table" CTA; no cart/add-on controls | Must |
| FR-MENU-009 | Draft/pending/private/retired dishes never public (pages, lists, sitemap, JSON-LD, REST); retired → 301 to category; offered nowhere → "Not currently available" | Must |
| F-MENU-03 / FR-MENU-004 | Dish administration fields, lifecycle draft → pending → publish → retire, publish validation, price audit, sample-dish guard | Must |
| FR-MENU-005 | Category administration: create/edit/reorder/translate; top-level only; delete blocked with dishes | Must |
| F-MENU-04 / FR-MENU-006 | Branch menu grid (`momo_branch_menu_items`): offered, price override, availability, sold_out_until, reason, version; field-level permissions | Must |
| FR-MENU-007 | Sold-out auto-reset every 15 min; default sold_out_until = end of branch business day JST | Must |
| F-MENU-05 / FR-MENU-008 | Client-side filters: dietary tags (AND), max spice (S); conservative allergen exclusion (C) | Should / Could |
| F-DIET-01 / FR-DIET-001 | Private halal claim/certificate register `momo_certificate` with evidence, validity, status, stamps | Must |
| FR-DIET-002 | Display rules: certified wording only for active + in-validity + verified record; mark only with permission; daily expiry job; E11 alerts | Must |
| FR-DIET-003 | Statements only from approved templates; no free-text halal field anywhere; dish `halal_basis` | Must |
| FR-DIET-004 | Alcohol policy per branch (served / used in cooking), `unknown` hidden | Must |
| FR-DIET-008 | Audit of every claim change with before/after and source document | Must |
| FR-DIET-009 | Admin claim-term scan of published content (outside register-driven blocks) | Should |
| F-DIET-02 / FR-DIET-005 | 29-item allergen list + custom items; 4 states per dish; source fields; CAP-DCA stamps; review flag; variants own data | Must |
| FR-DIET-006 | Allergen display: summary, grouped table, legend, banner, last verified, disclaimers | Must |
| FR-DIET-007 | Key ingredients under "Key ingredients (not a complete list)" | Must |
| BR-MENU-01 | Dish facts central; branch rows only control offered, price override, availability | Must |
| BR-MENU-02 | Displayed price = branch override if set, else reference; JPY, tax-inclusive, "(tax incl.)" (DEC-022) | Must |
| BR-MENU-03 | Only MM/HO (and SA) change prices; every change audited (DEC-023) | Must |
| BR-MENU-04 | Sold-out resets automatically at sold_out_until (default end of business day JST) | Must |
| BR-MENU-05 | Dish shown in a language only if published and translated (approved) there | Must |
| BR-MENU-06 | Advance-order dishes show lead time + "mention in special request"; never ordered online | Must |
| BR-MENU-07 | Branch recipe differences are variant dishes (child of base, own SKU and claim data) | Must |
| BR-DIET-01 | Halal wording/marks per DEC-020; "100% halal"/seal only with active certified record + mark permission | Must |
| BR-DIET-02 | Expired/revoked certificates hide claims automatically; warnings at 30/7 days and on expiry | Must |
| BR-DIET-03 | Never "allergen-free", "safe for allergies" or equivalents | Must |
| BR-DIET-04 | Only CAP-DCA edits claim fields; kitchen source document (two-person rule) | Must |
| BR-DIET-05 | Claim text never from unreviewed machine translation | Must |
| BR-DIET-06 | Reported wrong claim contained within [TBD → 2 h during opening hours], corrected within [TBD → 24 h] | Must |
| BR-DIET-07 | Ingredient/recipe change sets `momo_allergen_review_required` → banner until CAP-DCA re-save | Must |
| BR-CORP-03 | No sample dishes/prices public; demo photos only on dish/offer images with "Image for illustration" | Must |
| BR-CNT-02 | Content Editors cannot publish or change claim fields or prices | Must |
| BR-I18N-02 | No mixed-language fallback; untranslated items hidden | Must |
| FR-SEO-006(c)(d) | Menu/MenuSection/MenuItem JSON-LD with reference price; `suitableForDiet: HalalDiet` only for active `certified_item` | Must |

### 1.2 User stories, screens, APIs, compliance
| Group | IDs |
|---|---|
| User stories | US-MENU-001..010, US-DIET-001..007, US-CORP-006 (halal page data) |
| Customer screens | SCR-C06 Menu hub, SCR-C07 Menu category, SCR-C08 Dish detail; data supplied to SCR-C03 Halal page, SCR-C05 Branch page halal/alcohol block, SCR-C01 home featured dishes |
| Admin screens | SCR-A05 Categories, SCR-A06 Dish list/edit, SCR-A07 Branch menu grid, SCR-A12 Halal/certificate register, SCR-A03 Branch "Dietary" tab, SCR-A15 Dietary statements/settings, SCR-A16 audit (claim history filter) |
| Email | SCR-E11 certificate expiry (`cert_30`, `cert_7`, `cert_expired`) |
| API | API-14 `GET /momos/v1/staff/branches/{code}/menu-items`, API-15 `PATCH /momos/v1/staff/branches/{code}/menu-items/{dish_id}`; core `wp/v2/momo_dish` GET-only |
| Compliance / security | CMP-010..028 (halal + allergen governance), CMP-040 (prices in offers tax-incl.), CMP-044/045 (demo content, seal clean-up), SEC-023 (route permission callbacks; `momo_certificate` not in REST), SEC-027 (prepared SQL), SEC-028 (private evidence store) |
| Backlog | EP-06 TK-06-01..15, EP-07 TK-07-01..15 |
| Tests | TC-MENU-001..013, TC-DISH-001..006, TC-DIET-001..028, TC-NOT-017, TC-ACL-005/023/024, TC-A11Y-008, TC-WP-004, TC-E2E-003/010/014/015, TC-UAT-002/007/008, TC-HOME-002/003, TC-CORP-002, TC-REG-001 |

---

## 2. Data model

### 2.1 Storage overview
| Entity | Storage | Owner module |
|---|---|---|
| ENT-05 Menu category | Taxonomy `momo_menu_category` (hierarchical registered, Phase 1 top-level only) + term meta | Menu |
| ENT-06 Dish (+ variants) | CPT `momo_dish` + post meta; variant link in `post_parent` | Menu (content/price), Dietary (claim meta) |
| ENT-07 Branch menu item | Custom table `{prefix}momo_branch_menu_items` | Menu |
| ENT-08 Ingredient | Taxonomy `momo_ingredient` | Menu |
| ENT-09 Allergen | Taxonomy `momo_allergen` + term meta (29 seeded + custom) | Dietary |
| ENT-10 Dish allergen state | Post meta on dish `momo_alg_{code}`, `momo_alg_custom`, source + stamp fields | Dietary |
| ENT-11 Dietary tag | Taxonomy `momo_dietary_tag` + term meta | Menu (claim tags: Dietary) |
| ENT-12 Claim/certificate record | Private CPT `momo_certificate` + post meta + private evidence files | Dietary |
| ENT-02 Branch dietary group | Post meta on `momo_branch` (G7) | Dietary |
| ENT-01 Dietary statements | Option `momos_dietary_statements` (G2) + `momo_allergen_cross_contact_confirmed` | Dietary |

### 2.2 Enumerations (PHP 8.3 backed enums; DB CHECKs mirror them; labels translated)
| Enum class | Values |
|---|---|
| `Menu\Domain\DishStatus` (WP status) | `draft`, `pending`, `publish` (+ flag `momo_retired`; retired dish = `draft` + `momo_retired=1`) |
| `Menu\Domain\SpiceLevel` | `0` not spicy, `1` mild, `2` medium, `3` hot |
| `Menu\Domain\Availability` | `available`, `sold_out`, `unavailable` |
| `Menu\Domain\AvailabilityReason` | `sold_out_today`, `seasonal`, `ingredient_unavailable`, `advance_order_only`, `other` |
| `Menu\Domain\IngredientClass` | `meat`, `poultry`, `seafood`, `dairy`, `egg`, `plant`, `alcohol_derived`, `other` (TRD; see §11 C9) |
| `Dietary\Domain\HalalBasis` | `branch_default` (default), `certified_item`, `halal_ingredients`, `no_claim` |
| `Dietary\Domain\ClaimType` | `certified`, `halal_ingredients`, `no_claim` (statement rows also allow `alcohol`) |
| `Dietary\Domain\CertificateScope` | `whole_kitchen`, `specific_menu`, `specific_ingredients` |
| `Dietary\Domain\ClaimStatus` | `pending_verification`, `active`, `expired`, `revoked` |
| `Dietary\Domain\AllergenState` | `contains`, `may_contain`, `not_contained`, `not_verified` (default) |
| `Dietary\Domain\AllergenClass` | `mandatory`, `recommended`, `other` |
| `Dietary\Domain\AlcoholValue` | `yes`, `no`, `unknown` (unknown = not displayed) |

### 2.3 Dish `momo_dish` — fields
CPT args: `public`/`publicly_queryable` true, `show_in_rest` true **GET only** (writes blocked by `Api\CoreRestGuard` → 405 `method_not_allowed`), `hierarchical` false (variants via `post_parent` written from `momo_variant_of`), `has_archive` false, `rewrite` false (custom rules §3.1), `query_var` `momo_dish`, supports title/editor/thumbnail/revisions, `capability_type` `['momo_dish','momo_dishes']`, `map_meta_cap` true, `create_posts => create_momo_dishes`, `exclude_from_search` true, block editor disabled. Core REST exposes only public fields of published, non-retired, non-sample dishes; never claim stamps, source fields, `momo_is_sample`, `momo_external_ref`.

**Tab Content (G8)** — edit cap `edit_post` unless stated.
| Meta key | Type | Req | i18n | Validation / default |
|---|---|---|---|---|
| `post_title` | string | Y | T | 1–100 chars |
| `post_content` (long description) | rich text | — | T | ≤ 1,500 chars (plain-text length); 120–250 words recommended for signature dishes |
| `momo_short_description` | string | to publish | T | ≤ 160 chars; cards clamp to 2 lines |
| `momo_original_name` | string | — | C | ≤ 80; culinary name in origin script/romanisation, rendered with its `lang` attribute [IA ENT-06; not in TRD G8 — see §11 C8] |
| `momo_dish_sku` | string | Y | C | `^D\d{4}$`, unique across all dishes, **immutable after first publish** (DEC-052) |
| categories (`momo_menu_category` terms) | term IDs | ≥ 1 | — | assignable with `edit_momo_dishes` |
| `momo_primary_category` | term ID | Y | C | must be one of the assigned categories; defines canonical URL and breadcrumb |
| `momo_variant_of` | post ID | — | C | published `momo_dish` that is not itself a variant (one level); writes `post_parent` |
| featured image (`_thumbnail_id`) + `momo_gallery` | attachment IDs | ≥ 1 image to publish | C | gallery 1–6 images total; Japanese alt text required on featured image; demo images allowed only if flagged `momo_image_illustrative` |
| `momo_featured` | bool | — | C | default 0; home featured loop |
| `momo_spice_level` | int | Y | C | 0–3 |
| dietary tags (`momo_dietary_tag`) | term IDs | — | — | claim tags only by CAP-DCA (§3.12) |
| key ingredients (`momo_ingredient`) | term IDs | — | — | change on a published dish triggers BR-DIET-07 |
| `momo_advance_order_required` | bool | — | C | default 0 |
| `momo_advance_order_lead_hours` | int | if required | C | 1–168 |
| `momo_external_ref` | string | — | C | ≤ 64; never displayed; edit `manage_options` (DEC-056 seam) |
| `menu_order` | int | — | C | dish order within category (default 0) |
| `momo_retired` | bool | system | C | read-only; set by Retire action (`publish_momo_dishes`) |
| `momo_is_sample` | bool | Y (default 0) | C | set/cleared only by ROLE-01/02 (`momo_manage_brand`); publish refused in production while 1 |

**Tab Pricing (G9)**
| Meta key | Type | Req | i18n | Validation |
|---|---|---|---|---|
| `momo_reference_price_jpy` | int | to publish | C | integer 0–100,000, tax-inclusive; edit `momo_edit_branch_prices`; change > 30 % requires explicit confirmation |
| `momo_price_tax_label` | enum | fixed | C | `tax_included` (display only) |

**Tab Dietary & allergens (G10)** — whole group edit = `momo_edit_dietary_claims` (CAP-DCA).
| Meta key | Type | Req | i18n | Validation / default |
|---|---|---|---|---|
| `momo_alg_{code}` × 29 (e.g., `momo_alg_wheat`, `momo_alg_cashew_nut`) | AllergenState | Y | C | default `not_verified` |
| `momo_alg_custom` | JSON list `[{term_id, state}]` | — | C | allergen term must have class `other`; unique per row; any custom term without a row is treated as `not_verified` |
| `momo_alg_source_document` | string | if any state ≠ not_verified | C | ≤ 120 (see §11 C5), e.g., "Recipe card D0123 v2, 20 Nov 2026" |
| `momo_alg_sourced_by` | string | with source document | C | ≤ 80; person 1 (kitchen); must not equal approver's display name |
| `momo_alg_attest_not_source` | bool | on save with states | — | checkbox "I am not the source person for this record" [Doc 05 WF-ADM-21, Rec] |
| `momo_dish_halal_basis` | HalalBasis | to publish | C | default `branch_default` |
| `momo_dish_claim_record` | post ID | if basis ∈ certified_item, halal_ingredients | C | active `momo_certificate`: `certified` + scope `specific_menu` linking this dish (for `certified_item`); `halal_ingredients` record linking this dish |
| `momo_allergen_review_required` | bool | system | C | set by IngredientChangeWatcher; cleared only by CAP-DCA save of G10 |
| `momo_claims_verified_by` / `momo_claims_verified_at` | user ID / UTC datetime | system | C | stamped on every CAP-DCA save of G10 |

Variants: own SKU, own G10 data (no inheritance), own branch rows. A parent cannot be retired/trashed while it has published variants ("Retire or move the {n} variants first.").

### 2.4 Taxonomies and term meta
| Taxonomy | Public | Caps (manage / assign) | Term meta | i18n |
|---|---|---|---|---|
| `momo_menu_category` | yes (archive via custom rewrite `/menu/{slug}/`), REST GET only | `momo_manage_menu_categories` / `edit_momo_dishes` | `momo_cat_order` (int, C), `momo_cat_image` (attachment ID, C, alt required) | name ≤ 40, description (intro) ≤ 300: T |
| `momo_ingredient` | no | `momo_manage_menu_categories` / `edit_momo_dishes` | `momo_ing_class` (IngredientClass, C) | name ≤ 60: T |
| `momo_dietary_tag` | no | `momo_manage_menu_categories` / `edit_momo_dishes` (claim tags: CAP-DCA) | `momo_tag_code` (C), `momo_tag_icon` (C), `momo_tag_is_claim` (bool, C, edit CAP-DCA) | name, description (legend): T |
| `momo_allergen` | no; `meta_box_cb` false | `momo_edit_dietary_claims` / `momo_edit_dietary_claims` | `momo_alg_code` (`^[a-z_]{2,30}$`, unique, immutable when seeded), `momo_alg_class`, `momo_alg_icon`, `momo_alg_sort`, `momo_alg_seeded` (read-only) | name: T (human-reviewed only) |

Integrity: deleting a category with ≥ 1 dish (any status) → blocked; deleting a used ingredient/tag → blocked; seeded allergens cannot be deleted or have code/class changed. Dietary tag list: [TBD Q-408 → research candidates `vegetarian`, `vegan`, `contains_alcohol_in_recipe` (claim), `no_pork_ingredients` (claim), `no_alcohol_ingredients` (claim)]; vegetarian/vegan definitions approved by CAP-DCA [Rec].

### 2.5 Allergen reference seed (`config/allergens.php`, migration `M0003_SeedAllergens`)
Exactly 29 items; QA-004's Western list and the older "8 + 20" list are rejected. Japanese = source term name; English = `en` translation; other languages by human reviewers.
| # | Code | English | Japanese | Class |
|---|---|---|---|---|
| 1 | `shrimp` | Shrimp/prawn | えび | mandatory |
| 2 | `cashew_nut` | Cashew nut | カシューナッツ | mandatory (transition to 31 Mar 2028 — internal note only) |
| 3 | `crab` | Crab | かに | mandatory |
| 4 | `walnut` | Walnut | くるみ | mandatory |
| 5 | `wheat` | Wheat | 小麦 | mandatory |
| 6 | `buckwheat` | Buckwheat | そば | mandatory |
| 7 | `egg` | Egg | 卵 | mandatory |
| 8 | `milk` | Milk | 乳 | mandatory |
| 9 | `peanut` | Peanut | 落花生 | mandatory |
| 10 | `almond` | Almond | アーモンド | recommended |
| 11 | `abalone` | Abalone | あわび | recommended |
| 12 | `squid` | Squid | いか | recommended |
| 13 | `salmon_roe` | Salmon roe | いくら | recommended |
| 14 | `orange` | Orange | オレンジ | recommended |
| 15 | `kiwifruit` | Kiwifruit | キウイフルーツ | recommended |
| 16 | `beef` | Beef | 牛肉 | recommended |
| 17 | `sesame` | Sesame | ごま | recommended |
| 18 | `salmon` | Salmon | さけ | recommended |
| 19 | `mackerel` | Mackerel | さば | recommended |
| 20 | `soybean` | Soybean | 大豆 | recommended |
| 21 | `chicken` | Chicken | 鶏肉 | recommended |
| 22 | `banana` | Banana | バナナ | recommended |
| 23 | `pistachio` | Pistachio | ピスタチオ | recommended |
| 24 | `pork` | Pork | 豚肉 | recommended |
| 25 | `macadamia_nut` | Macadamia nut | マカダミアナッツ | recommended |
| 26 | `peach` | Peach | もも | recommended |
| 27 | `yam` | Yam | やまいも | recommended |
| 28 | `apple` | Apple | りんご | recommended |
| 29 | `gelatin` | Gelatin | ゼラチン | recommended |

`momo_alg_sort` = # above. Seed is idempotent (lookup by code). Custom items (e.g., mustard, celery, lupin, molluscs, sulphites) are added by CAP-DCA with class `other`; every existing dish then reads `not_verified` for them. The `pork` allergen state is an allergen fact and is **never** rendered as a halal or "no pork" claim.

### 2.6 `momo_branch_menu_items` (SDD §3.3.1 M0001 + M0002, verbatim)
```sql
CREATE TABLE {$prefix}momo_branch_menu_items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  branch_id bigint(20) unsigned NOT NULL,
  dish_id bigint(20) unsigned NOT NULL,
  is_offered tinyint(1) unsigned NOT NULL DEFAULT 1,
  price_override_jpy int(10) unsigned DEFAULT NULL,
  availability varchar(20) NOT NULL DEFAULT 'available',
  sold_out_until datetime DEFAULT NULL,
  availability_reason varchar(30) DEFAULT NULL,
  sort_order smallint(6) DEFAULT NULL,
  external_ref varchar(64) DEFAULT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_branch_dish (branch_id,dish_id),
  KEY idx_branch_offered (branch_id,is_offered,availability),
  KEY idx_dish (dish_id),
  KEY idx_soldout (availability,sold_out_until),
  KEY idx_external_ref (external_ref)
) ENGINE=InnoDB {$charset_collate};

ALTER TABLE {$prefix}momo_branch_menu_items
  ADD CONSTRAINT chk_momo_bmi_availability CHECK (availability IN ('available','sold_out','unavailable')),
  ADD CONSTRAINT chk_momo_bmi_reason CHECK (availability_reason IS NULL OR availability_reason IN
    ('sold_out_today','seasonal','ingredient_unavailable','advance_order_only','other')),
  ADD CONSTRAINT chk_momo_bmi_price CHECK (price_override_jpy IS NULL OR price_override_jpy <= 100000),
  ADD CONSTRAINT chk_momo_bmi_until CHECK (sold_out_until IS NULL OR availability = 'sold_out');
```
Rules: all datetimes UTC (display JST); absence of a row = not offered; rows are never deleted (ended by `is_offered = 0`); `branch_id` must be a `momo_branch` post, `dish_id` a published `momo_dish` (app-level FK, nightly integrity check); the app always writes `is_offered` explicitly on insert (see §11 C6). `sort_order` reserved (not used by Phase 1 UI). `external_ref` never displayed. Lost updates prevented by `version` + conditional UPDATE.

### 2.7 Branch dietary group (G7, on `momo_branch`, tab "Dietary") — edit `momo_edit_dietary_claims`
| Meta key | Type | Validation / default | i18n |
|---|---|---|---|
| `momo_halal_claim_type` | ClaimType | default `no_claim` [FR-DIET-003(a); not in TRD G7 — §11 C3] | C |
| `momo_halal_claim_record` | post ID → `momo_certificate` | required for `certified`/`halal_ingredients`; record must be `active`, of the same claim type, and list this branch | C |
| `momo_halal_statement_key` | statement key | approved G2 row of the same claim type (and, for `certified`, `certified.{record scope}`), translated + approved in every **enabled** language | C |
| `momo_alcohol_served` | AlcoholValue | default `unknown` | C |
| `momo_alcohol_in_cooking` | AlcoholValue | default `unknown`; `no` only with specification-sheet evidence (attested by CAP-DCA) | C |
| `momo_alcohol_note_key` | statement key of type `alcohol` | optional | C |
| `momo_claims_verified_by` / `_at` | user / UTC datetime | stamped on every save of this group | C |

No free-text halal field exists on branch, dish, notice, offer or page models (AC-DIET-01-08).

### 2.8 Claim/certificate record `momo_certificate` (G14)
CPT: `public` false, `publicly_queryable` false, `show_in_rest` **false**, `exclude_from_search` true, not translated, excluded from sitemaps and theme queries, UI under "Momo's" menu; caps `['momo_certificate','momo_certificates']` (effective only with CAP-DCA, M4); never deleted (trash blocked → revoke).
| Meta key | Type | Req | Validation |
|---|---|---|---|
| `post_title` | string | Y | internal label |
| `momo_cert_claim_type` | `certified` / `halal_ingredients` / `no_claim` | Y | — |
| `momo_cert_statement_key` | G2 key | Y | approved row of the same claim type |
| `momo_cert_certifier_name` | string ≤ 120 | certified | issuer of supplier certificate for halal_ingredients (optional) |
| `momo_cert_scope` | CertificateScope | certified, halal_ingredients | — |
| `momo_cert_number` | string ≤ 60 | certified | — |
| `momo_cert_valid_from` / `momo_cert_valid_until` | date (JST) | certified | until ≥ from |
| `momo_cert_evidence` | `{path, sha256, mime}` | to activate | PDF/JPEG/PNG ≤ 10 MB in `MOMOS_EVIDENCE_DIR` (outside web root or deny-all dir, covered by backups); random file name |
| `momo_cert_covered_ingredients` | `momo_ingredient` term IDs | halal_ingredients; scope specific_ingredients | fills `{covered_ingredients}` |
| `momo_cert_public_register_url` | url | — | https only |
| `momo_cert_source_document` | string ≤ 200 | to activate | document name/version + person 1 |
| `momo_cert_mark_permission` | bool | — | default 0 |
| `momo_cert_permission_doc` | `{path, sha256, mime}` | if mark permission | same store |
| `momo_cert_mark_image` | attachment ID (PNG/WebP) | if mark permission | attachment flagged `momo_protected_mark = 1` |
| `momo_cert_branches` / `momo_cert_dishes` | post ID lists | ≥ 1 branch for certified/halal_ingredients; dishes required for scope specific_menu | — |
| `momo_cert_status` | ClaimStatus | Y | default `pending_verification` |
| `momo_cert_revocation_reason` | string ≤ 255 | if revoked | — |
| `momo_cert_verified_by` / `_at` | user / UTC datetime | system | stamped when set to `active` and on quarterly re-attestation |
| `momo_cert_internal_note` | text | — | never rendered publicly |

State machine: `pending_verification → active` (evidence complete, approved) · `active → pending_verification` (supplier/recipe/scope change — hides wording immediately) · `active → expired` (job) · `active → revoked`, `pending_verification → revoked`. `expired` and `revoked` are terminal; renewal = new record.

### 2.9 Dietary statements option (G2) — page cap `momo_edit_dietary_claims`
Option `momos_dietary_statements`: list of rows `{key (^[a-z0-9_.]{3,40}$, unique), claim_type (certified|halal_ingredients|no_claim|alcohol|neutral), text_ja (≤ 400), approved (bool), approved_by, approved_at}`; translations in `_momos_i18n_{lang}`-equivalent option structure `{lang: {text, status}}` (human translation only). Seeded by `M0005` with the keys and JA/EN drafts of §6.2. Factual elements are placeholders only. Option `momo_allergen_cross_contact_confirmed` = `{confirmed: bool, confirmed_by, confirmed_at}` (CAP-DCA; brand-wide [TBD → brand-wide unless Food-safety owner decides per branch]).

---

## 3. Business rules and algorithms

### 3.1 URLs and redirects (`Menu\Rewrite\MenuRewrite`)
| Pattern (after language prefix) | Resolves to |
|---|---|
| `/menu/` | Page `menu` (hub, SCR-C06) |
| `^menu/([^/]+)/?$` | `momo_menu_category={slug}` (SCR-C07) |
| `^menu/([^/]+)/([^/]+)/?$` | `momo_dish={slug}` if `{category}` = primary category, else **301** to canonical dish URL |
Dish rule registered before category rule on `init` priority 20; flush only in migrations. Retired dish (any language URL) → **301** to its primary category URL in the same language. Unpublished/draft dish → 404 (anonymous preview returns nothing). Category slug change → 301 from old category and dish URLs [Doc 05 Rec]. `?branch=`, `?diet[]=`, `?max_spice=`, `?exclude[]=` variants: canonical without query, `noindex,follow`.

### 3.2 Displayability (`MenuQueryService`)
- `isDisplayable(dish, lang)` = `post_status=publish` ∧ `momo_retired=0` ∧ ¬(production ∧ `momo_is_sample=1`) ∧ (`lang=ja` ∨ `_momos_i18n_status_{lang}=approved`).
- Category listed on hub iff its term is approved in `lang` and has ≥ 1 displayable dish in the current context (with branch context: ≥ 1 displayable dish offered at that branch). Hub order: `momo_cat_order` ASC, then name.
- Category page order: `menu_order` ASC, then title (in `lang`). ≤ 60 dishes render without pagination; prime post/meta caches in batch (no N+1).
- `?branch={code}` is valid iff branch is published, not TEST, operating status ≠ `closed_permanently` (and, for the selector list, ≠ `temporarily_closed` per PRD test note). Invalid → ignored (treated as "All branches"), no error.
- With branch context: list only dishes with an `is_offered=1` row at that branch. Without: all displayable dishes.
- Featured loop (home): displayable ∧ `momo_featured=1` ∧ offered at ≥ 1 branch; count [TBD Q-414 → 6].
- Related dishes: same primary category, displayable, exclude current, limit 4.

### 3.3 PriceResolver (BR-MENU-02, DEC-022/023)
```php
interface PriceResolverInterface {
  public function effectivePrice(int $dishId, ?int $branchId): ?Price; // null = not offered / no price
  /** @return array<string, Price> branch_code => price; only is_offered=1 rows of published, non-test, non-closed_permanently branches */
  public function pricesByBranch(int $dishId): array;
}
```
- With branch: row missing or `is_offered=0` → `null`; else `price_override_jpy ?? momo_reference_price_jpy`.
- Without branch: reference price.
- Card (no branch): reference price; if `pricesByBranch` has ≥ 2 distinct values or any differs from reference → append "Prices may differ by branch".
- Dish page (no branch): offered at 0 branches → no price, "Not currently available"; all offering branches same effective price → one line "¥X at all branches" [Doc 07 §4.3]; ≥ 2 distinct prices → per-branch table (Branch · Price · Availability).
- `PriceFormatter`: `<bdi dir="ltr">¥1,500</bdi>` + translated label (`price.tax_incl`): ja `¥1,500（税込）`, en `¥1,500 (tax incl.)`; Western digits, thousands separator, integer only; no tax-rate text, no strike-through prices. `¥0` renders as `¥0` (allowed only when explicitly entered — [TBD → confirm with MM]).
- JSON-LD MenuItem Offer uses reference price (`priceCurrency JPY`); branch prices are not marked up.

### 3.4 Availability label (render-time, cache-safe)
| Context | Row state | Label (key) |
|---|---|---|
| No branch, dish offered nowhere | — | `menu.not_available` "Not currently available" (dish page hides CTA) |
| No branch, offered somewhere | — | none on card; dish page "Available at" list shows per-branch labels |
| Branch | `available`, or `sold_out` with `sold_out_until ≤ now` | `menu.available_today` "Available today" [Proposed key; Doc 08 variant] |
| Branch | `sold_out` and `sold_out_until > now` | `menu.sold_out` "Sold out today" |
| Branch | `unavailable` | `menu.unavailable` "Not available at this branch now" + `availability_reason.{code}` label (omit for `other`) |
Card is never hidden because of sold-out (WF-EXC-08). Because pages are cached, the job (§3.6) purges caches; rendering also treats an expired `sold_out_until` as available (no dependence on the job alone).

### 3.5 SoldOutPolicy (BR-MENU-04)
- Default `sold_out_until` when not supplied with `availability=sold_out`: close time of the branch's last opening period of the current JST business day, including `closes_next_day` (e.g., closes 02:00 → next calendar day 02:00); if the branch has no period today → 23:59 JST today. [Proposed edge rule] If now is inside a period that started on the previous day and closes after midnight, that period's close is used; if the computed value ≤ now → 23:59 JST today. Hours come from `Branch\Service\ScheduleService` (weekly periods + closures/special hours).
- Supplied value: must be > now and ≤ now + 7 days, else 400 `invalid_param` (`fields.sold_out_until=out_of_range`; staff message "Choose a time within the next 7 days.").
- `availability_reason` default `sold_out_today` for sold_out; required (any value) for `unavailable`.
- Setting `available` clears `sold_out_until` and `availability_reason`. `unavailable` never auto-resets.

### 3.6 SoldOutResetJob (FR-MENU-007)
Hook `momos_job_soldout_reset`, every 15 min:
```sql
UPDATE {p}momo_branch_menu_items
SET availability='available', sold_out_until=NULL, availability_reason=NULL, version=version+1, updated_at=UTC_TIMESTAMP(), updated_by=NULL
WHERE availability='sold_out' AND sold_out_until <= %s  -- now UTC from Kernel\Clock (test clock honoured outside production)
```
Select affected IDs first (same predicate, `idx_soldout`), update with the predicate (idempotent), then per row audit `menu_item.auto_reset` (actor_type `system`) and purge `momo-dish-{id}` + its categories (+ `momo-home` if featured). Failure → rows stay sold out (safe), retried next run.

### 3.7 Branch menu writes (`BranchMenuItemRepository::upsert`, API-15)
1. Resolve branch by code (404 `branch_not_found` if unknown/unpublished; TEST visible to staff only); `dish_id` must be a published `momo_dish` (404 `not_found`).
2. Authorise: assigned(user, branch) else 403 `forbidden_branch`; per changed field (M10): `offered` → `momo_edit_branch_menu`; `price_override_jpy` → `momo_edit_branch_prices`; `availability`/`sold_out_until`/`availability_reason` → `momo_edit_branch_availability`; any missing → 403 `forbidden_field` (nothing written, audit `access.denied`).
3. `version: 0` → INSERT (409 `version_conflict` if row exists). Insert requires `offered` (i.e., `momo_edit_branch_menu`); ROLE-06/07 cannot create rows — PATCH on a missing row without `offered` → 404 `not_found` [Proposed; see §11 C6].
4. Otherwise conditional `UPDATE … SET …, version=version+1 WHERE id=? AND version=?`; 0 rows → 409 `version_conflict` with current row in `data`.
5. Price change > 30 % vs previous effective price without `confirm_large_change: true` → 400 `invalid_param` (`fields.confirm_large_change="required"`).
6. Saving `offered=true` where the dish's base/variant is also offered at that branch → warning requiring confirmation (UI) — API: 400 `invalid_param` (`fields.offered="not_allowed"`) unless `confirm_variant_overlap: true` [Proposed field; documented as "warning requiring confirmation"].
7. One transaction: row write + audit per changed field (`menu_item.offered_changed`, `menu_item.price_changed`, `menu_item.availability_changed`) with before/after; after commit emit `momos/branch_menu_item/updated` → cache purge (≤ 60 s).

### 3.8 Dish publish validation (`DishPublishValidator`) and lifecycle (`DishLifecycle`)
Publish (`pending|draft → publish`) is refused with "Complete required fields before publishing: {list}." unless: title; short description; ≥ 1 category with primary among them; featured image with Japanese alt; `momo_dish_sku` valid + unique; `momo_reference_price_jpy` set (0–100,000); `momo_spice_level` set; `momo_dish_halal_basis` set (default satisfies); G10 present (states may be `not_verified`); `momo_advance_order_lead_hours` 1–168 when required; in production `momo_is_sample = 0` ("Sample dishes cannot be published on the live site"). If any allergen is `not_verified` the publisher sees a warning dialog listing them (publish still allowed).
| Error (staff, ja/en) | Condition |
|---|---|
| "Dish code must be D followed by 4 digits and unique." | SKU pattern/duplicate |
| "Dish code cannot be changed after publication." | SKU edit after first publish |
| "Price must be between 0 and 100,000 yen." | price range |
| "Enter the advance-order time in hours (1–168)." | lead hours missing/out of range |
| "Retire or move the {n} variants first." | retire/trash parent with published variants |
| "This category contains {n} dishes. Move them before deleting." | category delete |
Lifecycle: `draft → pending` ("Submit for review", any `edit_momo_dishes`) → `publish` (`publish_momo_dishes` only; others are forced to `pending` by core/REST 403). Retire (`publish_momo_dishes`): status `draft`, `momo_retired=1`, all branch rows `is_offered=0` (one transaction, audited `dish.retired`), permalinks of all languages recorded for 301. Never hard-delete a dish once published, or while it has `is_offered=1` rows or published variants. Price changes audit `dish.reference_price_changed` {before, after}. Edits of published non-claim fields by MM go live on Update (audited `dish.updated`).

### 3.9 Variants (BR-MENU-07)
Price-only differences use `price_override_jpy`, never a variant. Variant = new dish with `momo_variant_of` = base; no inheritance (category suggested in UI only). Base and variant should not both be offered at one branch (warning). Each dish page lists only branches offering that dish.

### 3.10 ClaimResolver (halal display; DEC-020)
`resolve(context: branch|dish|brand, id, lang): ?ClaimView` → `{templateKey, text (variables filled), certifier, scope, certNo, validUntil, markImageId|null, verifiedAt, registerUrl}`.
- **Record is displayable** iff `status=active` ∧ `valid_from ≤ today_JST ≤ valid_until` (valid_until today = still valid) ∧ `verified_by` set. Checked at render time (independent of the job).
- **Mark** additionally requires `mark_permission=1` ∧ mark image ∧ permission document.
- **Branch**: claim type `certified`/`halal_ingredients` with a displayable linked record → its template; otherwise **`no_claim`** template (never auto-downgrade to `halal_ingredients`). The stored selection is kept for audit.
- **Dish** by `momo_dish_halal_basis`: `branch_default` → `branch_default` template with `{branch_links}` = branches offering the dish; `certified_item` → `certified.specific_menu` from the linked displayable record else `no_claim`; `halal_ingredients` → `halal_ingredients` template from the linked record else `no_claim`; `no_claim` → `no_claim`. The dish halal line is never omitted.
- **Brand** (home/about): approved brand statement or `brand.neutral`.
- Template text in `lang` must be human-translated and approved; otherwise the statement is not rendered in that language and the page itself is blocked from that language via the LCCS/language gate (BR-DIET-05). Any exception → render nothing, log ERROR.
- Every halal block shows `last_verified` "Last verified: {date}" from `verified_at`; on SCR-C03 the date = later of certificate `verified_at` and branch `momo_claims_verified_at`.
- No "all branches certified" wording unless every published branch has a displayable `certified` record.
- JSON-LD: `suitableForDiet: https://schema.org/HalalDiet` only for dishes with `certified_item` + displayable record; never for tags; vegetarian/vegan not emitted in Phase 1.

### 3.11 Alcohol policy (FR-DIET-004)
`momo_alcohol_served`: yes → `alcohol.served.yes`, no → `alcohol.served.no`, unknown → nothing. `momo_alcohol_in_cooking`: yes → `alcohol.cooking.yes`, no → `alcohol.cooking.no`, unknown → nothing. Rendered on branch page (C05) and halal page (C03). Dish tag `contains_alcohol_in_recipe` (claim tag) shows its translated label on dish pages.

### 3.12 Guards (Dietary\Guard)
| Guard | Hook (WP core) | Behaviour |
|---|---|---|
| `ClaimFieldGuard` | `save_post_momo_dish`, `save_post_momo_branch`, `save_post_momo_certificate`, `update_post_metadata`/`add_post_metadata`, option update of G2 | Without CAP-DCA: changes to G7/G10/G14/G2 keys are discarded, old value kept, audit `access.denied` (never the request body). With CAP-DCA on G10: if any state ≠ `not_verified` require source document + sourced_by + attestation, sourced_by ≠ approver display name ("Record the kitchen source document and who provided it."); stamp `momo_claims_verified_by/at`; clear `momo_allergen_review_required`; audit `dietary.dish_claims_updated` {before, after, source_document}. On G7 save: stamp, validate record/template, audit `dietary.branch_claims_updated`. |
| `ClaimTagGuard` | `set_object_terms` (`momo_dietary_tag`) | Compare old/new tt_ids; non-CAP-DCA add/remove of tags with `momo_tag_is_claim=1` is reverted + `access.denied`; CAP-DCA change audited `dietary.dish_claims_updated` |
| `IngredientChangeWatcher` | `set_object_terms` (`momo_ingredient`), `post_parent`/`momo_variant_of` change on a **published** dish | Set `momo_allergen_review_required=1`, audit `dietary.review_required_set` (changed terms, actor), emit `momos/dietary/review_required`, purge dish caches |
| `WordingGuard` | `wp_insert_post_data` on dish/branch/offer/page/notice; G2 text save | `check(text, lang): list<Violation>` against `config/claim-keywords.php`. G2 rows: block keywords ("100%", "完全", seal wording) unless type `certified`. Content Editors: save-time warning (non-blocking) listing matched terms. Dish/branch/offer free text containing halal-claim terms: warning + listed by the scan |
| Mark protection | `ajax_query_attachments_args` | Attachments with `momo_protected_mark=1` hidden from media picker for users without CAP-DCA |
| Delete guards | `pre_delete_post`, `wp_trash_post`, `pre_delete_term` | Block dish with offered rows/published variants or once published; any certificate (revoke instead); categories with dishes; seeded allergens |

### 3.13 AllergenPresenter (FR-DIET-006)
`forDish(dishId, lang): AllergenTable` in this order:
1. Summary: `allergen.summary.contains` ({list} of `contains` names) and `allergen.summary.may_contain` (each omitted when empty).
2. Banner `allergen.banner.not_verified` (`role="note"`) when any item (29 + custom terms) is `not_verified` **or** `momo_allergen_review_required=1`.
3. Legend (always above table): four `allergen.state.*` labels with icons.
4. Table: groups `allergen.group.mandatory` (9) → `allergen.group.recommended` (20) → `allergen.group.other` (only if custom items exist); rows ordered by `momo_alg_sort`; icon (aria-hidden) + name + state text; colour never the only indicator; mobile toggle "Show only contains / may contain" (default off).
5. `allergen.last_verified` from `momo_claims_verified_at` (omitted if never verified).
6. `allergen.disclaimer.voluntary` — always. 7. `allergen.cross_contact` — only if `momo_allergen_cross_contact_confirmed.confirmed`. 8. `allergen.tell_staff` — always.
A dish whose states are all `not_contained` still shows disclaimer + tell-staff and is never summarised as "free from". Missing data → all `not_verified` (banner). Variant pages use the variant's own data.

### 3.14 CertificateExpiryJob (FR-DIET-002(b)(c))
Hook `momos_job_certificate_expiry`, daily **00:10 JST** (heartbeat; alert if no run for 26 h):
1. `today = Clock::todayJst()`. For each `active` record with `valid_until < today`: conditional status change → `expired`, audit `certificate.expired`, emit `momos/certificate/status_changed`, purge `momo-halal`, linked branches/dishes, `momo-home`; queue E11 `cert_expired`.
2. For each `active` record with `valid_until = today + 30` → E11 `cert_30`; `= today + 7` → E11 `cert_7`.
3. Recipients: HO notification list (settings) **and** every user with CAP-DCA; each email in the recipient's admin locale (ja/en). Dedupe key `cert:{id}:{30|7|0}:{recipient}` (UNIQUE) — a re-run sends nothing new. Independent of the E10 digest.
E11 content: certificate number, certifier, linked branches, valid until, link to SCR-A12 record; `cert_expired` states that certified wording and mark are hidden until a new record is active; no customer data. Subjects: `cert_30`/`cert_7` "[Halal] Certificate {no} expires {date}"; `cert_expired` "[Halal] Certificate {no} expired — claims hidden".
`revoked` and `active → pending_verification` take effect on save (purge immediately).

### 3.15 Evidence store (`Storage\EvidenceStore`, SEC-028)
Upload via custom meta box (CAP-DCA + `momo_manage_certificates`); MIME allow-list PDF/JPEG/PNG (mark image PNG/WebP via media library), ≤ 10 MB; store relative path + SHA-256 + MIME; random names; direct HTTP to the directory returns 403/404. Download only via `admin-post.php?action=momos_evidence&id=` (M11: `momo_manage_certificates` + CAP-DCA; nonce; `Content-Disposition: attachment`; `X-Content-Type-Options: nosniff`); audit `certificate.evidence_downloaded`.

### 3.16 Re-verification and correction
- Dashboard lists (F-ADM-01 interface): certificates expiring ≤ 30 days; active records with `verified_at` older than **92 days** (quarterly attestation re-stamps `verified_at`); dishes with `momo_claims_verified_at` older than **183 days**; dishes with any `not_verified` or review flag (KPI-14).
- BR-DIET-06 containment: CAP-DCA sets record `revoked`/`pending_verification` or affected states `not_verified` → caches purged in all languages ≤ 60 s; targets [TBD → 2 h during opening hours / correction 24 h]; claim-term scan same day.

### 3.17 Claim-term scan (FR-DIET-009, Should) — `Dietary\Admin\ClaimTermScanPage`
Access: SA, HO, CAP-DCA (others 403 + `access.denied`). Scans published posts, pages, offers, notices, dish titles/descriptions, branch texts, every `_momos_i18n_{lang}` JSON, image file names and alt texts, in all languages, for the master list (§6.4) plus base terms `halal`, `ハラール`, `ハラル`, `حلال`, `हलाल`, `হালাল`, `certified`, `認証`, `100%`, file/alt containing `halal`/`seal`; excludes register-driven blocks (statements rendered by ClaimResolver are not stored in content). Result rows: object type, title, language, field, matched term, edit link. No CSV.

### 3.18 Cache purge and audit summary
Purge ≤ 60 s after commit (tags `momo-dish-{id}`, `momo-cat-{term}`, `momo-home`, `momo-halal`, `momo-branch-{id}`): branch menu change/auto-reset → dish + its categories (+home if featured); dish publish/update/retire → dish + categories (+home); claim/certificate/G2 change → `momo-halal`, linked branches/dishes, `momo-home`. Audit actions used (SDD catalogue — no others): `dish.published`, `dish.updated`, `dish.retired`, `dish.reference_price_changed`, `menu_item.offered_changed`, `menu_item.price_changed`, `menu_item.availability_changed`, `menu_item.auto_reset`, `dietary.statement_updated`, `dietary.branch_claims_updated`, `dietary.dish_claims_updated`, `dietary.review_required_set`, `certificate.created`, `certificate.updated`, `certificate.status_changed`, `certificate.expired`, `certificate.evidence_downloaded`, `access.denied`. Audit write failure inside a transaction fails the whole save.

---

## 4. Permissions

Legend: C create, R read, U update, P publish, (b) assigned branches only, — none. CAP-DCA is a per-user capability `momo_edit_dietary_claims`, grantable only to ROLE-01/02 users (default one HO admin + Owner; never a vendor account).

| Resource | ROLE-01 SA | ROLE-02 HO | ROLE-03 MM | ROLE-04 CE | ROLE-05 TR | ROLE-06 BM | ROLE-07 RS | CAP-DCA adds |
|---|---|---|---|---|---|---|---|---|
| Public menu/dish pages | R | R | R | R | R | R | R | — |
| Categories, ingredients, non-claim tags (`momo_manage_menu_categories`) | CRUD | CRUD | CRUD | — | translate | — | — | — |
| Dish non-claim fields | CRUDP | CRUDP | CRU + submit; P/retire only if granted `publish_momo_dishes` (opt) | — | translate T fields only | — | — | — |
| Reference price (`momo_edit_branch_prices`) | U | U | U | — | — | — | — | — |
| `momo_is_sample` (`momo_manage_brand`) | U | U | R | — | — | — | — | — |
| Branch menu: offered (`momo_edit_branch_menu`) | U | U | U | — | — | R(b) | R(b) | — |
| Branch menu: price override (`momo_edit_branch_prices`) | U | U | U | — | — | R(b) | R(b) | — |
| Branch menu: availability (`momo_edit_branch_availability`) | U | U | U | — | — | U(b) | U(b) [TBD Q-405 → yes] | — |
| Dish G10 (allergens, halal basis), claim tags | R | R | R | — | — | — | — | U |
| Branch G7 (claim type/record/template, alcohol) | R | R | R | — | — | R(b) | — | U |
| Certificate register (`*_momo_certificates`, `momo_manage_certificates`) | R (CRUD with CAP-DCA) | R (CRUD with CAP-DCA) | R (summary, no evidence) | — | — | R(b) summary, no evidence (M12) | — | CRUD, activate, revoke, evidence download |
| Statement templates G2 | R | R | — | — | translate approved texts | — | — | CRUD, approve |
| Allergen terms (custom items) | R | R | R | — | translate names | — | — | C/U (seeded immutable) |
| Claim-term scan | R | R | — | — (save-time warning only) | — | — | — | R |
| Audit claim history (SCR-A16) | R | R | — | — | — | — | — | — |

Every admin screen, REST route, AJAX and admin-post action checks capability **and** branch scope server-side; denials write `access.denied`. Hiding UI is cosmetic only.

---

## 5. Screens

### 5.1 SCR-C06 Menu hub / SCR-C07 Menu category (customer)
| Aspect | C06 hub | C07 category |
|---|---|---|
| Content order | Breadcrumb Home › Menu · H1 · branch selector · category tiles (image + alt, name, intro) · closing CTA "Request a table" | Breadcrumb Home › Menu › {Category} · H1 + intro · branch selector · filters (S) · result count · dish cards · closing CTA |
| Dish card | — | Photo (+ `media.illustration` caption if flagged; no photo → initial-tint circle, no stock image), eyebrow (category or tag), localised name (link; whole card clickable), original name (`lang` attr), short description (2-line clamp), price block, dietary icons + text, spice (three chili icons + text), availability label (branch context only), advance-order label. No cart/add/halal badge |
| Branch selector | Label "Show prices for"; native `<select>`; first option `menu.branch_select.all`; then published branches (no TEST, closed, temporarily closed); change → same URL with/without `?branch=` without losing scroll; all internal menu links keep `?branch=` (no cookie/session) | same |
| Empty | Category with no displayable dish → not listed | Zero filter matches → `menu.filters.empty` + `menu.filters.clear`; branch offers none here → "This branch doesn't offer dishes from this category." + link to all branches; no branch context and no translated dish → 404 |
| Loading/error | Server-rendered; filtering client-side over the rendered list (no spinner) | same |
| Responsive | 360: 2-col tiles; 1024+: 3–4 | 360: 1 col (image start, text end; mirrored RTL); 768: 2; 1024+: 3 |
| A11y | — | Filters in a disclosure "Filters ({n})" on mobile; chips keyboard operable; count via `role="status"` / `aria-live="polite"` |

**Filters (FR-MENU-008, client-side, `assets/js/menu-filters.js`)**: dietary tags multi-select, AND semantics; max spice radio 0–3 (dish spice ≤ value); (C) allergen exclusion multi-select: hide dish if any selected allergen state ∈ {`contains`, `may_contain`, `not_verified`} or review flag set, with always-visible note `menu.filter.allergen_note`. Cards carry data attributes (`data-tags`, `data-spice`, `data-alg` = codes whose state is contains/may_contain/not_verified). URL query `?diet[]=vegetarian&max_spice=1&exclude[]=peanut` (shareable; `noindex,follow`; canonical without query). Filters hidden if no tags in use. Never label results "allergen-free".

### 5.2 SCR-C08 Dish detail (customer)
Content order (Doc 07 §5.7): breadcrumb Home › Menu › {primary category} › {dish} · (1) H1 + original name · (2) gallery 1–6 (illustration caption below flagged images) · (3) price block + availability (branch: "¥X (tax incl.) at {branch} [Change]"; none: single line or per-branch table; offered nowhere: `menu.not_available`) · (4) description · (5) advance-order notice: `menu.advance_order` + "Mention it in your table request" link carrying `res.hint.advance_order` · (6) dietary tags + spice · (7) halal line (ClaimResolver dish context, never a seal) · (8) `ingredients.heading` + translated ingredient names (hidden if none) · (9) allergen section (§3.13) · (10) "Available at": offering branches (links to branch pages) with price/availability when prices differ · (11) up to 4 related dishes · (12) "Request a table" CTA: prefill `?branch={code}` when branch context exists or exactly one accepting branch offers the dish; hidden when offered nowhere. No cart/quantity/add-on/portion controls. Desktop ≥ 1024: gallery start column, summary (price, availability, advance order, tags, CTA) end column, allergen table full width. Allergen table `<caption>`, headers "Allergen"/"Status", group rows `<th scope="rowgroup">`, banner `role="note"`. Prices and certificate numbers isolated with `<bdi>`.

### 5.3 SCR-C03 / C05 data contract (halal page and branch page blocks; page shells belong to the corporate/branch specs)
Per published branch row: branch name link · claim statement (template) · claim-type label (`halal.label.*` only for displayable records) · certifier · scope label · certificate no. · valid until · alcohol lines · last verified · optional mark (alt "Halal certification mark of {certifier}") · optional "Verify with the certifier" link (public register URL). For `halal_ingredients`, certifier/scope/number cells show "Not applicable" (text). `no_claim`/non-displayable → `no_claim` template only. Page never shows an empty table; mobile = one card per branch. Analytics event `view_halal_information` (F-ANL-01).

### 5.4 SCR-A05 Menu categories (admin)
Native term screen + ordering list (drag handle **and** up/down buttons ≥ 24 px) writing `momo_cat_order`; fields name, slug (`^[a-z0-9-]+$`), intro, image (alt required); translation fields per language (T). Delete blocked: "This category contains {n} dishes. Move them before deleting." Success: standard notices.

### 5.5 SCR-A06 Dish list / edit (admin, ja/en)
List columns: image, name, SKU, primary category, status (draft / pending review / published / retired), allergen verification ("{n} not verified"; "Review required" badge), sample flag, translation completeness. Filter "Pending review". Edit tabs: **Content** (§2.3; Sample checkbox only for ROLE-01/02, others see badge "Sample — cannot be published on the live site"), **Pricing** (reference price, preview "¥1,500 (tax incl.)", > 30 % change confirm dialog), **Dietary & allergens** (29 + custom rows, each a radio group Contains / May contain / Not contained / Not verified with legend = allergen name; no bulk "set all"; halal basis + claim record; source document, sourced by, attestation; read-only stamps; banner "Ingredients changed — re-verify allergens" while flag set; non-CAP-DCA see lock notice "Only Dietary Claims Approvers can change these fields"), **Branches** (read-only summary of branch rows + link to A07), **Translations** (per-language T fields + status; Translators see only these; price/SKU/allergen fields not editable). Buttons: "Save draft", "Submit for review" (no publish cap), "Publish"/"Update", "Retire" (publish cap). States: field errors + error summary; publish warning listing not_verified allergens; success notices.

### 5.6 SCR-A07 Branch menu grid (admin, `assets/js/branch-menu-grid.js`, API-14/15)
Branch selector (default = only assigned branch) · category filter · search (name or SKU) · rows/cards: thumbnail 48 px, dish name (admin language else Japanese), SKU, offered checkbox (MM/HO), reference price (read-only), override input with ¥ prefix (MM/HO; locked "Head Office only" for BM/RS), displayed price, availability segmented control (Available / Sold out / Unavailable), sold out until (JST), reason select, row status ("Saved" / error + Retry). Quick action "Sold out today" (sets sold_out with default until): ≤ 3 taps from visible row on a 360–390 px screen; tap targets ≥ 44 px. Inline save per row (no bulk tool in Phase 1). 409 → row refreshes with current values and "Updated by {name} at {time}". 403 per row on forbidden field. Row status announced politely. Performance: 300 dishes usable (paged `per_page ≤ 100`).

### 5.7 SCR-A12 Halal / certificate register (admin)
List: claim type, certifier, scope, linked branches/dishes, valid until ("expires in {n} days", ≤ 30 highlighted with text), status. Edit: all §2.8 fields; evidence upload/download (CAP-DCA only); "Activate" (stamps) and "Revoke" (reason) actions. Read-only summary view for MM (all) and BM (linked to assigned branches; no evidence). Errors:
| Message (staff) | Condition |
|---|---|
| "Certified records need certifier, certificate number, validity dates and an evidence file." | activate/save certified incomplete |
| "Valid until must be on or after valid from." | range |
| "This certificate has expired and cannot be activated." | activate with valid_until < today |
| "Link an active certificate before selecting a certified statement." | branch G7 with non-active record |
| "Upload the mark image and the permission document." | mark permission without files |
Activation also requires source document, covered ingredients (halal_ingredients / specific_ingredients), ≥ 1 linked branch, dishes for specific_menu.

### 5.8 SCR-A03 Branch "Dietary" tab and SCR-A15 statements
A03 Dietary tab (CAP-DCA edit; others read-only with lock "Dietary Claims Approver only"): claim type, linked record (only active records listing this branch), statement template (approved, translated in every enabled language, matching type/scope), alcohol served, alcohol in cooking, alcohol note template, stamps. A15 "Dietary statements": template rows (key, type, JA text, approval) with per-language translation status; cross-contact confirmation toggle with stamp.

---

## 6. UI strings (keys from Doc 07 §6 / Doc 11 §8–9; all JA are drafts pending native/legal review; claim texts need counsel + CAP-DCA approval)

### 6.1 Menu strings
| Key | EN | JA |
|---|---|---|
| `price.tax_incl` | (tax incl.) | （税込） |
| `menu.branch_select.label` [Proposed key] | Show prices for | ⟦JA draft needed⟧ |
| `menu.branch_select.all` | All branches — reference prices | 全店舗（参考価格） |
| `menu.prices_may_differ` [Proposed key] | Prices may differ by branch | ⟦JA draft needed⟧ |
| `menu.price_all_branches` [Proposed key] | ¥{price} at all branches | ⟦JA draft needed⟧ |
| `menu.sold_out` | Sold out today | 本日売り切れ |
| `menu.unavailable` | Not available at this branch now | この店舗では現在ご提供していません |
| `menu.available_today` [Proposed key] | Available today | ⟦JA draft needed⟧ |
| `menu.not_available` | Not currently available | 現在ご提供していません |
| `menu.advance_order` | Order at least {hours} h ahead | {hours}時間前までに要予約注文 |
| `menu.advance_order.notice` [PRD A3] | This dish must be ordered at least {n} hours in advance. Please mention it in the special request when you request a table. | ⟦JA draft needed⟧ |
| `res.hint.advance_order` | {dish} needs to be ordered at least {hours} hours ahead. Mention it here. | {dish}は{hours}時間前までのご注文が必要です。こちらに… (Doc 07 §6.2) |
| `menu.filters.empty` / `.clear` | No dishes match these filters. / Clear filters | 条件に合う料理がありません。／条件をクリア |
| `menu.filters.count` [Proposed key] | {n} dishes shown (gettext plurals) | ⟦JA draft needed⟧ |
| `menu.filter.allergen_note` [PRD FR-MENU-008(e)] | Based on information provided voluntarily. Please always tell staff about allergies. | ⟦JA draft needed⟧ |
| `menu.category.branch_empty` [Doc 07 §5.6] | This branch doesn't offer dishes from this category. | ⟦JA draft needed⟧ |
| `spice.0..3` [PRD FR-MENU-002] | Not spicy / Mild / Medium / Hot (card: "Spice: {label}") | ⟦JA drafts needed⟧ |
| `availability_reason.{code}` [Proposed EN] | sold_out_today "Sold out today"; seasonal "Seasonal"; ingredient_unavailable "Ingredient unavailable"; advance_order_only "Advance order only"; other — (not shown) | ⟦JA drafts needed⟧ |
| `media.illustration` | Image for illustration | 写真はイメージです (counsel confirm) |
| `cta.request_table` | Request a table | 予約リクエスト |
| `nav.menu` / `cta.view_menu` | Menu / View menu | メニュー／メニューを見る |

### 6.2 Halal and alcohol templates (Doc 11 §8.1/§8.7 canonical; variables from register only)
| Key | EN | JA |
|---|---|---|
| `certified.whole_kitchen` | This branch is halal-certified by {certifier} (certificate no. {cert_no}, scope: whole kitchen, valid until {valid_until}). | 当店舗は{certifier}によるハラール認証を取得しています（認証番号：{cert_no}、範囲：厨房全体、有効期限：{valid_until}）。 |
| `certified.specific_menu` | Dishes marked on our menu are halal-certified by {certifier} (certificate no. {cert_no}, valid until {valid_until}). Other dishes are not covered by this certification. | メニューに表示した料理は{certifier}によるハラール認証の対象です（認証番号：{cert_no}、有効期限：{valid_until}）。その他の料理は本認証の対象外です。 |
| `certified.specific_ingredients` | At this branch, {covered_ingredients} are covered by halal certification from {certifier} (certificate no. {cert_no}, valid until {valid_until}). The kitchen and other ingredients are not covered by this certification. | 当店舗では、{covered_ingredients}が{certifier}によるハラール認証の対象です（認証番号：{cert_no}、有効期限：{valid_until}）。厨房およびその他の食材は本認証の対象外です。 |
| `halal_ingredients` | At this branch, {covered_ingredients} come from suppliers holding halal certification, as recorded in our halal register. This branch's kitchen is not halal-certified. Please ask our staff about preparation if you have specific requirements. | 当店舗では、{covered_ingredients}についてハラール認証を受けた仕入先のものを使用しています（ハラール登録記録による）。当店舗の厨房はハラール認証を取得していません。調理方法についてはスタッフにお尋ねください。 |
| `no_claim` | Please ask our staff at this branch about ingredients and preparation. | 食材や調理方法については、この店舗のスタッフにお尋ねください。 |
| `branch_default` | Halal information is shown for each branch. Branches offering this dish: {branch_links} | ハラールに関する情報は店舗ごとに表示しています。この料理を提供している店舗：{branch_links} |
| `brand.neutral` | Halal information is shown for each branch. | ハラールに関する情報は店舗ごとに表示しています。 |
| `last_verified` | Last verified: {date} | 最終確認日：{date} |
| `halal.label.certified` / `halal.label.ingredients` | Halal-certified / Halal ingredients | ハラール認証取得／ハラール食材使用 |
| `alcohol.served.yes` | Alcoholic drinks are served at this branch. | この店舗ではアルコール飲料を提供しています。 |
| `alcohol.served.no` | Alcoholic drinks are not served at this branch. | この店舗ではアルコール飲料を提供していません。 |
| `alcohol.cooking.yes` | Alcohol is used in cooking some dishes at this branch. Please ask our staff which dishes. | この店舗では一部の料理の調理にアルコールを使用しています。該当する料理はスタッフにお尋ねください。 |
| `alcohol.cooking.no` | Alcohol is not used in cooking at this branch. | この店舗では調理にアルコールを使用していません。 |

### 6.3 Allergen strings (Doc 11 §9.2/§9.4 canonical)
| Key | EN | JA |
|---|---|---|
| `allergen.state.contains` | Contains | 含む |
| `allergen.state.may_contain` | May contain (cross-contact) | 含む可能性あり（混入の可能性） |
| `allergen.state.not_contained` | Not contained (checked) | 含まない（確認済み） |
| `allergen.state.not_verified` | Not verified | 未確認 |
| `allergen.summary.contains` / `.may_contain` | Contains: {list} / May contain (cross-contact): {list} | 含む：{list}／混入の可能性あり：{list} |
| `allergen.banner.not_verified` | Allergen information not verified — please ask staff. | アレルゲン情報は未確認です。スタッフへお尋ねください。 |
| `allergen.disclaimer.voluntary` | Allergen information is provided voluntarily for reference. It may change when suppliers or recipes change, and ingredients may differ between branches. | アレルゲン情報は参考として任意で提供しています。仕入れ先やレシピの変更により変わる場合があり、店舗によって食材が異なることがあります。 |
| `allergen.cross_contact` | Our kitchen also handles other ingredients and may share equipment and frying oil, so we cannot guarantee the absence of trace amounts. | 同じ厨房で他の食材も扱っており、調理器具や揚げ油を共用する場合があるため、微量の混入がないことは保証できません。 |
| `allergen.tell_staff` | If you have a severe allergy, please tell our staff when you arrive and when ordering. We will tell you honestly if we cannot prepare a dish for you. | 重いアレルギーをお持ちの方は、ご来店時およびご注文時に必ずスタッフへお申し出ください。対応できない料理の場合は、その旨を正直にお伝えします。 |
| `allergen.last_verified` | Information last verified on {date}. | 最終確認日：{date} |
| `allergen.group.mandatory` | Specified ingredients (9 — mandatory for packaged food) | 特定原材料（9品目・容器包装食品の表示義務対象） |
| `allergen.group.recommended` | Recommended items (20) | 特定原材料に準ずるもの（20品目） |
| `allergen.group.other` | Other ingredients | その他 |
| `ingredients.heading` | Key ingredients (not a complete list) | 主な食材（すべての食材ではありません） |

### 6.4 Prohibited wording (`config/claim-keywords.php`, Doc 11 §8.6 + §9.5 master; HO maintains in SCR-A15; native reviewers add ar/ur/hi/bn)
| Class | EN | JA |
|---|---|---|
| Certification (allowed only via a `certified.*` template of a displayable record) | halal certified, certified halal, halal certified kitchen | ハラール認証, 認証取得, bare 認証 next to ハラール/ハラル |
| Absolute halal (never as free text) | 100% halal, 100% Halal Prepared, fully halal, strictly halal certified, halal guaranteed | 100%ハラール, 完全ハラール |
| Group claims | All branches halal, fully halal restaurant group | — |
| Practice claims | Pork-free kitchen, alcohol-free restaurant | 豚肉不使用の厨房 |
| Recognition / religious | Approved by JAKIM/BPJPH/MUIS (without documented recognition); permissible for all Muslims | — |
| Allergen (BR-DIET-03) | allergen-free, allergy-free, allergy-safe, safe for allergies, safe for allergy sufferers, 100% safe, no risk, free from {allergen}, gluten-free, nut-free, dairy-free | アレルゲンフリー, アレルギー対応 / アレルギー対応済み (safety sense), 完全除去, 安全です (allergy context), アレルギーの方も安心, 100%安全, リスクなし, 〇〇フリー, グルテンフリー |
| Ordering (UI-001 clean-up) | order now, order online, add to cart, add to order, checkout, place order | 今すぐ注文, カートに追加, 注文を確定 |
| Images | any halal seal/badge/certifier logo; file names/alt containing "halal"/"seal" outside the register mark | — |
Seed `ar` examples (Doc 09): `حلال معتمد`; `ur`: `حلال سرٹیفائیڈ`; rest ⟦TBD native reviewers — GATE-04⟧.

---

## 7. API

### 7.1 API-14 `GET /momos/v1/staff/branches/{code}/menu-items`
Auth: logged-in + REST nonce; any of `momo_edit_branch_menu`, `momo_edit_branch_prices`, `momo_edit_branch_availability`; assigned to branch. Query: `category` (slug), `offered` (true|false), `availability` (enum), `q` (name or SKU), `page`, `per_page` (≤ 100). Response 200:
```json
{ "branch_code": "⟦CODE⟧",
  "permissions": { "offered": false, "price": false, "availability": true },
  "items": [
    { "dish_id": 512, "dish_sku": "D0012", "name": "⟦dish name⟧", "category": "⟦slug⟧", "reference_price_jpy": 1200,
      "row": { "offered": true, "price_override_jpy": null, "effective_price_jpy": 1200, "availability": "available",
               "sold_out_until": null, "availability_reason": null, "version": 4 } },
    { "dish_id": 518, "dish_sku": "D0018", "name": "⟦dish name⟧", "category": "⟦slug⟧", "reference_price_jpy": 1500, "row": null } ] }
```
Lists every published dish (base and variants). `name` in user's admin language if approved, else Japanese. `row: null` = never configured. `sold_out_until` ISO 8601 with `+09:00`. Errors: 401/403 `rest_forbidden`, 403 `forbidden_branch`, 404 `branch_not_found`, 400 `invalid_param`, 429 `rate_limited`.

### 7.2 API-15 `PATCH /momos/v1/staff/branches/{code}/menu-items/{dish_id}`
Request (`application/json`, `additionalProperties: false`, required `version`):
```json
{ "version": {"type":"integer","minimum":0},
  "offered": {"type":"boolean"},
  "price_override_jpy": {"type":["integer","null"],"minimum":0,"maximum":100000},
  "availability": {"enum":["available","sold_out","unavailable"]},
  "sold_out_until": {"type":["string","null"],"format":"date-time"},
  "availability_reason": {"enum":["sold_out_today","seasonal","ingredient_unavailable","advance_order_only","other",null]},
  "confirm_large_change": {"type":"boolean"},
  "confirm_variant_overlap": {"type":"boolean"} }
```
Example `{ "version": 4, "availability": "sold_out", "availability_reason": "sold_out_today" }` → 200 with updated row (`sold_out_until` defaulted per §3.5, `version` 5). Rules in §3.7. Errors (canonical catalogue only): 400 `invalid_param` (fields: `required`, `invalid_format`, `out_of_range`, `not_allowed`), 400 `invalid_json`, 401/403 `rest_forbidden`, 403 `forbidden_branch`, 403 `forbidden_field`, 404 `branch_not_found` / `not_found`, 409 `version_conflict` (with current row), 413 `payload_too_large`, 415 `unsupported_media_type`, 429 `rate_limited`, 500 `internal_error`, 503 `service_unavailable`. Error body `{code, message (admin locale), data:{status, fields}}`. Staff messages: `forbidden_field` "You cannot change this field."; `forbidden_branch` "You do not have access to this branch."; `version_conflict` "This record was changed by someone else. The latest version is shown — please review and try again."

### 7.3 Other routes
- `GET /wp-json/wp/v2/momo_dish[/{id}]`, `/wp/v2/momo_menu_category`: GET only; non-GET → 405 `method_not_allowed`; drafts/pending/retired/sample(prod) never returned. `momo_certificate` has no REST route.
- `admin-post.php?action=momos_evidence&id=` — evidence download (§3.15); 403 otherwise.
- No public menu API in Phase 1 (pages are server-rendered).

---

## 8. Acceptance criteria and tests to automate

### 8.1 Acceptance criteria (Given/When/Then, abbreviated)
| AC | Criterion |
|---|---|
| AC-MENU-01-01 | X offered at A (¥1,600 override), not at B, reference ¥1,500: `?branch=A` → ¥1,600 (tax incl.); `?branch=B` → X absent; no branch → ¥1,500 + "Prices may differ by branch" |
| AC-MENU-01-02 | `?branch=A` kept hub → category → dish; both pages canonical without query + `noindex` |
| AC-MENU-01-03 | Y sold out at A until 22:00, viewed 20:00 JST with `?branch=A` → "Sold out today" |
| AC-MENU-01-04 | Category without Hindi dish translations not listed on `/hi/menu/`; no Japanese dish text |
| AC-MENU-01-05 | `?branch=ZZZ` → "All branches — reference prices", no error |
| AC-MENU-02-01..06 | Per-branch price table when prices differ; advance-order notice 24 h; retired EN URL 301 → `/en/menu/{category}/`; draft not via page or REST; single offering branch C preselected on CTA; illustrative image caption + no cart controls |
| AC-MENU-03-01..07 | MM without publish cap → `pending`, no Publish button; publish without price blocked listing "reference price"; SKU edit after publish refused; price 1,500→1,600 shown in all languages + audit before/after; category with 3 dishes not deletable; translator cannot edit price/SKU/allergens; sample dish not publishable in production |
| AC-MENU-04-01..06 | RS sold-out default = A's closing today, public ≤ 60 s; job at 22:45 resets 22:30 item, audit actor system; ROLE-06 price PATCH → 403 `forbidden_field`; concurrent save → 409; override ¥1,700 only at A; RS of A requesting B → 403 `forbidden_branch` |
| AC-MENU-05-01..03 | Vegetarian + Mild → exactly 3, "3 dishes shown" announced; (C) peanut `not_verified` hidden by peanut exclusion; zero results → empty state, Reset restores |
| AC-DIET-01-01..08 | MM cannot edit/POST claim fields (`access.denied`); expired at 00:10 job, no wording/mark in any language, one `cert_expired`; `cert_30`/`cert_7` once each to HO list + each CAP-DCA; mark hidden when permission false; revoke → neutral statement, no JSON-LD halal; evidence URL 403/404 for anonymous and ROLE-04; alcohol unknown→no audited and `alcohol.served.no` shown; no free-text halal field |
| AC-DIET-02-01..08 | New dish all 29 `not_verified` + banner; wheat=contains without source refused; fully verified → no banner, summary, 3 groups, last verified; MM adds "cashew" ingredient → banner in all languages until CAP-DCA re-save; Bengali page states = Japanese; variant states shown at branch B, base unchanged; no prohibited wording; Hindi key-ingredient heading + translated names |
| AC-CORP-03-01..04 | Halal page: A (active, mark permission) shows details + mark, B `no_claim` neutral only; expired yesterday → no details/mark/"certified"; untranslated Urdu template → page not published in Urdu; alcohol unknown → no text |
| US-DIET-007 | "100% halal" in an EN dish description listed by scan with language/field/term/edit link; register-rendered wording not listed; BM opening scan → denied + `access.denied` |

### 8.2 Key test cases (automate; P0 first)
| TC | What | Tool |
|---|---|---|
| TC-DIET-001 (P0) | Seed exactly 29 (codes, classes, ja/en names), cashew mandatory, pistachio recommended | PHPUnit |
| TC-DIET-002/024 (P0) | Register fields; activation blocked until evidence/source/covered ingredients; private evidence 403/404 | PHPUnit + HTTP |
| TC-DIET-003 (P0) | Display matrix (active/in-validity/verified × mark permission) on branch, `/halal/`, dish, JSON-LD in ja/en/ar | PHPUnit + Playwright |
| TC-DIET-004/006/025, TC-E2E-015 (P0/P1) | Expiry with test clock (valid_until today = valid; yesterday = expired), revoke immediate, `no_claim` fallback | PHPUnit (FrozenClock) + Playwright |
| TC-DIET-005, TC-NOT-017 | E11 variants, recipients, locale, dedupe, independence from E10 | PHPUnit |
| TC-DIET-009/010/017/028, TC-ACL-023/024 (P0) | CAP-DCA-only edits (UI, REST, crafted POST), two-person stamps, sourced_by ≠ approver, no bulk set, claim tags, mark picker, scoped register reads | PHPUnit |
| TC-DIET-011/012/027 (P0) | Allergen block order, groups, labels, legend, banner, disclaimers in 6 languages + RTL | Playwright + manual |
| TC-DIET-013/020 (P0) | Prohibited-wording and seal scan over all pages/emails per language | Crawler script |
| TC-DIET-014 (P0) | Claim text only from approved human translations | PHPUnit |
| TC-DIET-019 (P0) | Cache purge after allergen change, 6 languages (target ≤ 60 s) | Playwright |
| TC-DIET-021/022/023/026 | Claim-term scan; correction drill; ingredient-change flag; re-verification lists (92/183 days) | PHPUnit |
| TC-MENU-001..011 | Hub/selector, cards, price format (¥1,200 / ¥1,350, translated label, Western digits), categories, grid permissions, auto-reset, filters, retired/unoffered, central edit preserves rows, price audit, advance order | PHPUnit + Playwright |
| TC-MENU-012/013, TC-DISH-006 (P0/P1) | Sample-check scan, demo images only on dish/offer images, sample publish blocked | CLI + Playwright |
| TC-DISH-001..005 | Dish page content; lifecycle/publish cap; field validation (`D12`, duplicate SKU, price −1/100001, spice 4, lead time missing); variants; no language fallback | PHPUnit + Playwright |
| TC-ACL-005, TC-WP-004 | REST isolation (API-14/15 other branch); jobs run without traffic | PHPUnit |

Boundary/negative extras: sold-out across midnight (closes 02:00), 7-day upper bound, 1 and 60 dishes, 300-dish grid, RTL card mirroring with LTR prices, 60-char names, closed branch excluded from selector, `confirm_large_change` path.

---

## 9. Out of scope (Phase 1)
Online ordering, cart, checkout, takeout/delivery, quantities, add-ons, portion surcharges, payment, coupon redemption (DEC-042/057); POS/price sync and `external_ref` usage (DEC-056); bulk branch-menu tool ("offer all at reference price" — PRD says no bulk tool; see §11 C11); per-branch allergen overrides (use variants); system-enforced second CAP-DCA approval (maker-checker is procedural); vegetarian/vegan `suitableForDiet` JSON-LD; publishing certificate scans (Q-418); halal practice statements (utensils, oil, storage) without an approved template; PDF menus; quick-view dish modal; category hierarchy (reserved); allergen exclusion filter if Could is descoped; menu data import tool details (TK-06-08, separate spec); packaged-food labelling (CMP-028).

---

## 10. Open TBDs (build with the default as configuration)
| Item | Default to build | Owner / ref |
|---|---|---|
| RS (ROLE-07) may mark sold out | Yes (grant `momo_edit_branch_availability`) | Owner — Q-405 (TRD cites Q-023) |
| Dietary tag list and whether veg/vegan need CAP-DCA | Seed none in prod; candidates in §2.4; claim tags CAP-DCA-only | MM + CAP-DCA — Q-408 |
| Halal/alcohol template wording (6 languages) | Seed Doc 11 drafts (JA/EN), `approved=false` until counsel + certifier sign-off | Q-407, GATE-02 |
| Allergen disclaimer final wording | Doc 11 §9.4 drafts | Food-safety + counsel — Q-410 |
| Cross-contact note confirmation; brand-wide vs per branch | Off until confirmed; brand-wide flag | Food-safety owner — Q-050 |
| Wrong-claim containment / correction | 2 h (opening hours) / 24 h | Owner — BR-DIET-06, Q-049 |
| "(tax incl.)" / （税込） wording | As §6.1 | Counsel — Q-039 |
| "Image for illustration" wording/placement | Caption directly below image | Counsel — DEC-047 |
| ¥0 price allowed | Allowed when explicitly entered | MM |
| Featured dishes on home | 6 | Q-414 |
| Evidence file retention | Keep indefinitely while referenced [TBD 3 or 5 years after expiry] | Counsel — OQ11-12 |
| BM price-change request channel | Outside system | HO — DEC-023 |
| Per-language prohibited terms ar/ur/hi/bn | Empty lists + base terms in §3.17 | Native reviewers — GATE-04 |
| Real data: categories, dishes, prices, certificates, branches | ⟦to be supplied⟧ — never invent; staging uses `momo_is_sample` | HO — Q-005/006/009/011/012 |

---

## 11. Documentation conflicts found and resolution used in this spec
| # | Conflict | Resolution here |
|---|---|---|
| C1 | Dish halal line: Doc 07 §4.3 "no approved halal basis → section omitted" vs Doc 04 FR-DIET-003(e), Doc 07 §5.7, Doc 08 (`branch_default` line always shown) | Always shown (`branch_default`), per PRD/§5.7 |
| C2 | Sold-out/claim cache purge: PRD 60 s vs TC-DIET-019 ⟦≤ 5 min⟧ | 60 s |
| C3 | Branch Dietary fields: PRD FR-DIET-003(a) has claim type + record + template on branch; TRD G7 has only record + alcohol, and puts `statement_key` on the certificate (G14) | Both: branch has `momo_halal_claim_type`, `momo_halal_claim_record`, `momo_halal_statement_key`; certificate keeps `momo_cert_statement_key` as the default template |
| C4 | Alcohol field name: `momo_alcohol_in_cooking` (TRD/PRD) vs `used_in_cooking` (Doc 11) | `momo_alcohol_in_cooking` |
| C5 | Allergen source fields: PRD `source_document` ≤ 200 / `source_provided_by` ≤ 100 vs TRD/SDD/IA `momo_alg_source_document` ≤ 120 / `momo_alg_sourced_by` ≤ 80; IA uses `momo_allergen_{code}`, TRD `momo_alg_{code}` | TRD keys and lengths (ACF names kept as meta keys) |
| C6 | `is_offered` DDL DEFAULT 1 vs IA `offered` default false; API "version 0 creates row" without stating who may create | App writes `is_offered` explicitly; row creation requires `momo_edit_branch_menu` (prevents BM/RS from offering a dish via availability) |
| C7 | Price validation: PRD/TRD/IA 0–100,000 vs Doc 07 A06/A07 "integer > 0" | 0–100,000 (override 0–100,000 or empty) |
| C8 | `momo_original_name` shown in Docs 05/06/07/08 but absent from TRD G8/SDD ENT-06; short description stored as `post_excerpt` (IA) vs `momo_short_description` (TRD); card short description ≤ 120 (FR-MENU-002) vs field ≤ 160 | Add `momo_original_name` (C); use `momo_short_description` ≤ 160, cards clamp |
| C9 | Ingredient class enum differs (TRD 8 values vs IA 12 values); category order key `momo_cat_order` (TRD) vs `momo_display_order` (IA) | TRD values/keys |
| C10 | Categories per dish: Doc 05 WF-ADM-05 "exactly one category (Phase 1)" vs PRD/TRD "≥ 1 with one primary" | ≥ 1 with one primary |
| C11 | Backlog TK-06-06 bulk "offer all at reference price" vs PRD "no bulk tool in Phase 1" | No bulk tool |
| C12 | Filter URL: PRD `?diet=vegetarian&spice=1` vs TRD `?diet[]=…&max_spice=1` | TRD form (+ `exclude[]` for allergens, proposed) |
| C13 | JSON-LD diets: PRD FR-SEO-006(d) "no vegetarian/vegan in Phase 1" vs SDD ENT-11 mapping to VegetarianDiet/VeganDiet | HalalDiet only (PRD) |
| C14 | Certificate re-verification: Doc 11 §8.9 re-stamps `verified_at` quarterly vs Doc 05/06 separate `last_reverified_at` | Re-stamp `verified_at` (one field drives "last verified" and the 92-day list) |
| C15 | RS sold-out decision cited as Q-405 (PRD/Doc 04A) and Q-023 (TRD) | Same decision; default yes |
| C16 | No-photo card: PRD "no image block" vs Doc 08 "tint circle with dish initial" | Doc 08 (no stock image either way); publish requires an image so this is legacy-only |
| C17 | `menu.advance_order` "Order at least {hours} h ahead" (Doc 07) vs PRD card "Advance order: {n} h" | Doc 07 key; PRD long notice used on dish page |
| C18 | US-DIET-005 banner "…please ask staff before ordering" vs Doc 07/11 "…please ask staff." | Doc 11/07 key text |
| C19 | `momo_is_sample` edit: TRD G8 `momo_manage_brand` (some older text: `publish_momo_dishes`) | ROLE-01/02 only (`momo_manage_brand`) |
