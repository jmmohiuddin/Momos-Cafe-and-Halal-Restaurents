# Spec gaps — implementation notes for requirements not covered by the area specs

Produced by the completeness review of `docs/specs/*.md` against `csv/requirements.csv` (206 rows), Doc 03 §2.7
(58 features) and Doc 04 Appendix A. Registry: `docs/REQUIREMENTS_REGISTRY.md` §3.

Build constraint: IMPL-001…005 (`docs/IMPLEMENTATION_DECISIONS.md`) — no Elementor Pro, ACF Pro, WPML, Rank Math,
LiteSpeed Cache, UpdraftPlus etc. Everything below is implemented in `momos-core` (namespace `Momos\`), the mu-loader,
the theme `momos`, `bin/` scripts and `config/`. Markers: **[Doc]** = from the documentation package,
**[IMPL]** = adaptation required by IMPL-001…004, **[Prop]** = proposal of this review (needs tech-lead OK).

| ID | Priority | Gap type | What was missing | Section |
|---|---|---|---|---|
| FR-FND-003 | Must | Full | No spec covers the loader, module set, migration runner, job registry, heartbeats or the "theme holds no logic" rule | §1 |
| FR-FND-006 | Must | Full | No spec covers the plugin register, the ≤ 15 cap or its display on SCR-A15 | §2 |
| FR-FND-002 | Must | Partial | Staging rules are covered (access-security-privacy §9.3); deploy-by-tag pipeline, environment matrix and the production→staging sanitisation script are not | §3 |
| NFR-PERF-02 | Must | Partial | Only measured in `qa.md`; page cache, cache tags and purge are referenced (`Kernel\Cache\CacheInvalidator`, screens.md "Cache" row, branch.md purge table) but never specified, and the TRD's mechanism (LiteSpeed Cache plugin) is removed by IMPL-004 | §4 |
| NFR-MNT-01 | Must | Partial | Only tested in `qa.md`; module README, version pinning, static-analysis and coverage gates are not specified for implementers | §5 |

Requirements checked and **not** treated as gaps (covered by ID and with implementation detail): all other 201 rows,
including NFR-MNT-02 (`qa.md` §1/§3: `wp momos seed|reset`, `momos_frozen_now`/`MOMOS_FROZEN_NOW`, disabled in
production), NFR-AVL-01/NFR-OBS-01/NFR-BCK-01 (access-security-privacy §12 and backups row), FR-FND-001
(i18n-seo-analytics), FR-FND-004 (access-security-privacy), FR-FND-005 (content-strings §1, ui-design-system brand
table, screens A15).

---

## 1. FR-FND-003 — momos-core structure, loader, migrations and jobs (Must, F-FND-01)

**Statement [Doc 04 §16.1]:** (a) momos-core is loaded by a must-use loader so it cannot be deactivated;
(b) modules Branch, Menu, Dietary, Reservation, Notification, Access, Audit, API, Presentation, Privacy, each with a
README; (c) the theme holds no business logic — switching to a default theme leaves CPTs, taxonomies, custom tables,
REST routes and jobs working; (d) the 8 custom tables (DEC-009) are created/migrated by a versioned, idempotent runner;
(e) jobs run on Action Scheduler driven by a real system cron every minute, `DISABLE_WP_CRON` true; (f) each job records
a heartbeat shown on SCR-A15 and monitored.

### 1.1 Loader and bootstrap
| Item | Rule |
|---|---|
| Loader | `wp-content/mu-plugins/momos-loader.php` requires `wp-content/plugins/momos-core/momos-core.php` and nothing else; skips while WordPress is installing (already implemented). momos-core must **not** be activated additionally as a normal plugin; the Plugins screen shows it under "Must-Use" only. |
| Bootstrap | `momos-core.php`: header `Requires PHP: 8.3`; constants `MOMOS_CORE_VERSION`, `MOMOS_CORE_DIR`, `MOMOS_CORE_URL`; loads `vendor/autoload.php` and Action Scheduler **before** `plugins_loaded` (library version negotiation). |
| Dependency check | `plugins_loaded` priority 5: PHP ≥ 8.3, extensions `intl`, `mbstring`, `sodium`, `openssl`, `json`; required constants in production (`MOMOS_TOKEN_KEY_V1`, hash pepper, Turnstile keys). Failure → dependent modules not booted + SA-only admin notice; never a fatal on the public site. [IMPL: no ACF/WPML/Elementor checks] |
| Module order | `Kernel\Plugin::MODULES` (implemented): I18n (kernel) → Access → Audit → Branch → Menu → Dietary → Reservation → Notification → Api → Privacy → Seo → Presentation → Admin. Missing classes are skipped. |
| Module contract | `ModuleInterface::register()` — hooks only, no queries/output. Context-aware: admin classes only on `is_admin()`; REST controllers on `rest_api_init`; front-end assets only on templates that use them. |
| Hooks between modules | `momos/<module>/<event>` actions fired after commit (`Kernel\Events\AfterCommit`), per SDD §2.5 catalogue. |

Module set vs FR-FND-003(b): the 10 documented modules map 1:1 to `src/{Branch,Menu,Dietary,Reservation,Notification,
Access,Audit,Api,Presentation,Privacy}`. The code adds `Seo` (Rank Math replacement, IMPL-001), `Admin` (settings/menu
shell) and the kernel `I18n` module (WPML replacement, IMPL-002) — an allowed superset; each also needs a README (§5).

### 1.2 Theme independence (FR-FND-003(c), AC-FND-01-04)
- CPTs, taxonomies, rewrite rules, REST routes, admin pages, jobs, emails and custom tables are registered **only** in
  momos-core. The theme `momos` contains templates, template parts, CSS/JS and presentational helpers that call
  momos-core public functions (`momos_t`, `momos_tr`, `momos_url`, query services). No `register_post_type`,
  `register_rest_route`, `add_action('momos_job_*')`, `$wpdb` or `wp_mail` calls in the theme (CI grep check [Prop]).
- Front-end shortcodes/blocks needed by editors are registered by `Presentation`, so content keeps rendering (unstyled)
  under a default theme.
- Test (AC-FND-01-04): on staging, `wp theme activate twentytwentyfive` → API-01/API-02 work, SCR-A08 inbox works,
  jobs run; then re-activate `momos`.

### 1.3 Migrations (FR-FND-003(d), AC-FND-01-05)
| Rule | Value |
|---|---|
| Runner | `Kernel\Migration\Runner`; version in option `momos_db_version` (int); applied steps in `momos_migrations_applied` (list `{id, applied_at_utc}`). |
| Trigger | Only `wp momos migrate [--yes]` (deploy step) or plugin bootstrap on **local**; never on a production web request. If code expects a higher version than the DB → SA-only admin notice "Database update required — run wp momos migrate". |
| Lock | `GET_LOCK('momos_migrate', 0)`; second runner exits with "already running". |
| Idempotency | Every step safe to re-run (DDL auto-commits; check `information_schema` before `ALTER`). Second run prints "up to date" and changes nothing (AC-FND-01-05). CI: migrate twice on a fresh DB + once from previous tag's schema; non-`wp_` prefix job. |
| dbDelta rules | one column per line; `PRIMARY KEY  (id)` (two spaces); `KEY` not `INDEX`; FKs/CHECKs added in a separate step; table names from `Kernel\Db\Tables`; charset from `$wpdb->get_charset_collate()`. |
| ID ranges | As `docs/ENGINEERING.md` §3 (M0001–M0002 kernel tables/constraints; module ranges M0010–M0079). Seed/demo data never via migrations. |
| Expand–contract | Additive, backward-compatible for one release; destructive steps need CTO sign-off + fresh backup + written rollback SQL; no automatic down-migrations. |

### 1.4 Jobs and heartbeats (FR-FND-003(e)(f), AC-FND-01-06)
| Job hook (Action Scheduler group `momos`) | Schedule | Owner spec | Heartbeat key |
|---|---|---|---|
| `momos_job_reservation_expiry` | every 5 min | reservation.md | `expiry` |
| `momos_job_sla` | every 5 min | reservation.md / notifications.md | `sla` |
| Notification queue dispatch | continuous (single actions + sweeper every 1 min) | notifications.md | `queue` |
| `momos_job_soldout_reset` | every 15 min | menu-dietary.md | `soldout` |
| `momos_job_certificate_expiry` | daily 00:10 JST | menu-dietary.md / notifications.md | `certificate` |
| `momos_job_offer_expiry` (+ start/end single actions, 15-min safety sweep) | daily 00:05 JST | branch.md | `offers` |
| `momos_job_retention` | daily 03:15 JST | access-security-privacy.md | `retention` |
| Daily digest E10 | per setting (daily) | notifications.md | `digest` |
| Access expiry (vendor accounts) | every 5 min [Prop] | access-security-privacy.md | `access_expiry` |

- Use the job names already fixed in the owner specs where they differ from this table; this table only fixes the
  **mechanism**: register with `Kernel\Jobs\Schedule::recurring()` in `register()`; `wp momos jobs sync` (re)registers
  all recurring actions on every deploy; each handler takes a named lock (`momos_job_<name>`) and is idempotent.
- Cron: `DISABLE_WP_CRON=true` on staging/production; system crontab
  `* * * * * cd <docroot> && wp action-scheduler run --group=momos --quiet && wp cron event run --due-now --quiet`
  (line in `config/wp-config.production.sample.php`). Local may keep WP-Cron.
- Heartbeat: after each **successful** run write option `momos_heartbeat[<key>] = <UTC ISO>` and call
  `Monitoring\HeartbeatPinger` (non-blocking GET to `MOMOS_HEARTBEAT_URL_<KEY>`, 2 s timeout, failure = WARNING log).
- SCR-A15 Settings › Monitoring tab shows a table: Job · Schedule · Last success (JST) · State (OK if age ≤ 2 × interval,
  "Late" otherwise, red if expiry/queue > 30 min — NFR-OBS-01). Read-only; cap `manage_options` (SA) and ROLE-02.
- `wp momos smoke` checks: tables + constraints present, roles present, required settings present, expiry/queue
  heartbeat < 2 min, Action Scheduler has no `momos` actions failed in the last hour, sample-check = 0.

**Acceptance / tests:** AC-FND-01-04, AC-FND-01-05, AC-FND-01-06; TC-SMK-*; PHPUnit for Runner idempotency.

---

## 2. FR-FND-006 — Plugin inventory and cap (Must, F-FND-01)

**Statement [Doc 04 §16.1, Doc 09 §1.3, Doc 11 SEC-060]:** (a) a plugin register (name, pinned version, purpose,
owner, licence, data transmitted to third parties, last review date) is maintained and mirrored read-only on SCR-A15;
(b) active plugins incl. momos-core ≤ 15; adding one requires ROLE-01 approval against the LC-001 H5 checklist;
(c) no Elementor add-on packs, no nulled/unlicensed software.

**Phase 1 inventory under IMPL-001/004 [IMPL]:**
| # | Component | Type | Version pin | Purpose | Data sent to third parties | Owner |
|---|---|---|---|---|---|---|
| 1 | `momos-core` (via mu-loader `momos-loader.php`) | Custom, Git tag | `MOMOS_CORE_VERSION` = release tag | All business logic | Turnstile (token, IP — Cloudflare), email provider (recipient, message), GA4 via GTM (no PII, consent-gated), Google Maps embed after click | Tech lead |
| 2 | Theme `momos` | Custom, Git tag | same tag | Presentation only | none (self-hosted fonts) | Tech lead |
| — | Bundled libraries (not plugins): Action Scheduler, libphonenumber | Composer, `composer.lock` | locked | Jobs; phone validation | none | Tech lead |

Active plugin count = 1 (cap 15). Host-level services (WAF/CDN, backups storage, uptime monitor, SMTP/ESP) are listed
in the **External services** part of the same register (they are processors for the Privacy Policy / External
Transmission page) but do not count toward the plugin cap.

**Implementation:**
- Register source of truth: `config/plugin-inventory.php` [Prop] in momos-core returning rows
  `{slug, name, type: custom|free|premium|library|service, version, purpose, owner, licence, third_party_data, last_review (Y-m-d), counts_toward_cap: bool}`.
  Updated in the same PR that adds/updates a component; `last_review` updated at each monthly update cycle (FR-SEC-009).
- SCR-A15 › Security tab: read-only table of the register + live line "Active plugins: n / 15" computed from
  `get_option('active_plugins')` + `wp_get_mu_plugins()` (mu-loader counts as momos-core, not twice). If an active
  plugin is **not** in the register, or the count exceeds 15 → red admin notice to SA/ROLE-02
  "Unapproved plugin active: {name}" and audit `security.plugin_unregistered` (daily check in `smoke`/integrity job).
- Production: `DISALLOW_FILE_MODS=true` (FR-SEC-004) removes install/activate/edit UI (AC-FND-01-09). Adding a plugin =
  PR + ROLE-01 approval recorded in the PR (H5 checklist: maintained ≤ 6 months, no known unpatched CVE, licence valid,
  data flows documented, one product per function), then pipeline deploy.
- `wp momos smoke` fails if any active plugin is missing from the register.

**Acceptance / tests:** AC-FND-01-09 (no install/activate/edit actions for ROLE-02 in production; active count ≤ 15);
TC-SEC-009 (qa.md: inventory equals active list); Doc 11 K30 at GATE-07.

---

## 3. FR-FND-002 — Environments and deploy by tag (Must, F-FND-01) — partial gap

Covered elsewhere: staging Basic auth + `noindex`, separate DB/keys, mail capture, synthetic data
(access-security-privacy §9.3); `DISALLOW_FILE_EDIT/MODS` (FR-SEC-004). Missing pieces:

### 3.1 Environment matrix [Doc TRD §6.4, adapted IMPL]
| Constant / setting | Local | Staging | Production |
|---|---|---|---|
| `WP_ENVIRONMENT_TYPE` (+ `MOMOS_ENV`) | `local` | `staging` | `production` |
| `DISABLE_WP_CRON` | false | true | true |
| `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` | true / false | true / true | true / true |
| `AUTOMATIC_UPDATER_DISABLED`, `WP_AUTO_UPDATE_CORE=false` | — | true | true |
| `WP_DEBUG` / `_DISPLAY` / `_LOG` | true/true/path | true/false/path | false/false/path outside web root |
| `FORCE_SSL_ADMIN` | false | true | true |
| `WP_CACHE` | false | true | true (page-cache drop-in or server cache, §4) |
| Mail | catcher (`MOMOS_MAIL_SANDBOX`) | sandbox/allow-listed QA inboxes | real ESP; `MOMOS_MAIL_SANDBOX` **undefined** |
| Turnstile keys | test | test/staging | live |
| Languages enabled | all 6 | all 6 (QA) | ja + en until GATE-04 per language (IMPL-003) |
| Seed/reset/test clock | on | on | **refused** (NFR-MNT-02) |
| Data | synthetic | synthetic only | real |

### 3.2 Deploy by tag [Doc TRD §6.2/§6.7]
1. Branching: trunk-based; `main` protected (PR + 1 review + CI green). `vX.Y.Z-rc.N` tags deploy to staging
   automatically; production only from `vX.Y.Z` with manual approval (approver: CTO ⟦+ Owner representative for major
   releases⟧).
2. Artefact: `bin/build-release.sh <version>` (implemented) → `build/momos-<v>.zip` + `.sha256` containing
   mu-plugins, momos-core with production `vendor/`, theme `momos`. `MOMOS_CORE_VERSION` must equal the tag (CI check [Prop]).
3. Production steps: confirm last backup < 4 h → pre-deploy `wp db export` to a non-public dir (30 days) → maintenance
   mode **only** for non-additive migrations (503 + `Retry-After`, FR-CORP-007) → upload to `releases/{tag}/`, verify
   checksum, switch symlinks atomically → `wp momos migrate --yes` → `wp momos jobs sync` → `wp cache flush` + full
   page-cache/CDN purge (§4) → maintenance off → `wp momos smoke` + HTTP checks (home in each enabled language, a branch,
   a dish, API-01, login) + TEST-branch reservation smoke (DEC-055) → record tag/time/approver in `RELEASES.md`.
4. Rollback: re-point symlinks to previous release (≤ 15 min); schema stays (expand–contract).
5. **Never** after launch: copy the staging DB to production; edit code on production.

### 3.3 Production → staging copy sanitisation (FR-FND-002(b), AC-FND-01-08)
Default: staging uses `wp momos seed` only. If a production copy is ever needed (e.g. a migration rehearsal), it must
pass through `wp momos sanitize --yes` [Prop command name] **before** import is made reachable, executed on the copy:
| Data | Action |
|---|---|
| `momo_reservations` | `guest_name` → `Guest {id}`; `email` → `res{id}@example.com`; `phone_e164`, `special_request`, `message_to_customer`, `staff_note`/note texts → NULL/''; all `*_hash` and token hashes → recomputed with the staging pepper or NULL |
| `momo_reservation_events` | free-text payloads cleared |
| `momo_notifications` | recipient → `masked@example.com`; provider IDs cleared |
| `momo_audit_log` | `ip` cleared; before/after values of PII fields replaced by `[redacted]` |
| `wp_users` | `user_email` → `user{ID}@example.com`; passwords reset to random; 2FA secrets cleared (re-enrol); sessions destroyed |
| Options | notification recipient lists, HO lists, SMTP/ESP settings, GTM ID, heartbeat URLs → staging values |
| Evidence files | not copied |
The command refuses to run when `wp_get_environment_type() === 'production'`, writes a summary (row counts per table,
no values) and finishes with a check that no email outside `example.com`/the project test domain remains
(AC-FND-01-08 query). Audit `environment.sanitized` (actor system).

---

## 4. NFR-PERF-02 — Server response from Japan: page cache and purge (Must) — partial gap

**Target [Doc]:** cached HTML TTFB ≤ 800 ms p75 from Tokyo/Osaka (gate), ⟦target ≤ 600 ms⟧; uncached ≤ 1.8 s p75.
Supporting budgets [Doc TRD §6.12]: ≤ 60 DB queries per uncached front-end page, ≤ 100 per admin page; no queries in
template loops (prime caches with `update_meta_cache` / `_prime_post_caches`); momos-core JS ≤ 40 KB gzip on the
reservation page (excl. Turnstile), ≤ 10 KB elsewhere; > 20 % regression blocks a release. Measurement: `qa.md`
TC-PERF-002.

### 4.1 What is cached [Doc TRD §2.9.5]
| Response | Cache rule |
|---|---|
| Anonymous GET HTML (all languages; key = full URL incl. language prefix and query) | `Cache-Control: public, max-age=0, s-maxage=86400` [Prop TTL]; tagged (§4.2) |
| Filtered menu variants `?diet[]=…&max_spice=…`, `?branch=…` | cached per full URL, `s-maxage=300` (cannot be purged by tag on all CDNs) |
| API-01 (reservation rules) | `Cache-Control: public, max-age=300, s-maxage=300`; purged on hours/closures/settings change |
| Never cached (`Cache-Control: no-store, private`) | wp-admin, any logged-in request (cookie `wordpress_logged_in_*`), hidden-language preview, `/reservation/submitted/`, `/reservation/status/*`, all `/wp-json/momos/v1/*` except API-01, POST, 404 responses [Prop], `?preview=` |
| Pages are cache-safe | No per-visitor server branching on public pages: open-now, language-suggestion bar, analytics opt-out, GPC and token pages are client-side (owner specs). |

### 4.2 `Kernel\Cache\CacheInvalidator` [IMPL]
- API: `tag(string ...$tags)` during render; `purgeTags(array $tags)`; `purgeUrls(array $urls)`; `purgeAll()`.
  Purges are collected per request and executed once on `shutdown` (de-duplicated). From CLI/jobs they execute at the end
  of the job.
- Tags: `momo-global`, `momo-home`, `momo-branch-{id}`, `momo-dish-{id}`, `momo-cat-{term_id}`, `momo-halal`,
  `momo-offers` (purge triggers per event: `branch.md` purge table / TRD §2.9.5).
- Adapter chosen by constant `MOMOS_CACHE_ADAPTER` ∈ `litespeed | cloudflare | urllist | null` (default `null` local):

| Adapter | Use when | Tagging | Purge |
|---|---|---|---|
| `litespeed` | Host runs LiteSpeed (Hostinger Option A) — server cache works **without** the LSCache plugin | send `X-LiteSpeed-Cache-Control: public,max-age=86400` and `X-LiteSpeed-Tag: <tags>` on cacheable responses; `X-LiteSpeed-Cache-Control: no-cache` otherwise | within a web request: `X-LiteSpeed-Purge: tag=<t1>, tag=<t2>` (or `*`); from CLI/jobs: queue tags in option `momos_cache_purge_queue` and flush via a loopback POST to an internal admin-ajax action authenticated by an HMAC of the queue [Prop]; verify on staging (PROTO P8) |
| `cloudflare` | Cloudflare is the CDN | tags not available on non-Enterprise plans → map tags to URLs (below) | `POST /zones/{zone}/purge_cache` with `{files:[…]}` (≤ 30 URLs/call, batch) using `MOMOS_CF_API_TOKEN` (Zone.Cache Purge only); `momo-global` → `purge_everything` |
| `urllist` | Nginx/Apache cache or host CDN with URL purge | tags → URLs | host-specific HTTP PURGE per URL [Prop] |
| `null` | local / no edge cache | none | none — then pages must use `s-maxage=60` so stale claim/price data is bounded at ≤ 60 s |

- Tag → URL map (for URL-based adapters): for every **enabled** language L, `momo-branch-{id}` → branch page,
  `/branches/`, home; `momo-dish-{id}` → dish page + its category pages; `momo-cat-{t}` → category page + `/menu/`;
  `momo-halal` → `/halal/`; `momo-offers` → `/offers/`; `momo-home` → home. Use `momos_url($path, $lang)` so the ja
  root and `/xx/` prefixes are both covered.
- Freshness targets: data save → affected URLs purged in all languages ≤ 60 s (Doc 04 §16.0.2); claim change (halal,
  allergen, certificate) ≤ ⟦TBD → 5 min⟧ at worst, normally on save (TC-DIET-019 must pass).
- Deploy: `wp momos cache purge --all` [Prop] = `CacheInvalidator::purgeAll()`; also run by the deploy script.
- Failures of the purge call: log WARNING with adapter and tag count (no URLs with query strings), retry once via a
  single Action Scheduler action after 60 s; never block the save.

### 4.3 Server/runtime settings [Doc TRD H1–H11]
PHP 8.3+ with OPcache; persistent object cache (Redis/Memcached/LSMCD) **Should** — when present, momos-core uses
`wp_cache_*` for request-independent lookups (branch settings DTO, schedule JSON, translation status); HTTP/2+, Brotli or
gzip; origin or CDN PoP in Japan (DEC-019); images WebP with `srcset` (NFR-PERF-03, ui-design-system).

**Acceptance / tests:** TC-PERF-002 (WebPageTest Tokyo + Osaka, 9 runs per template after priming, then after purge);
TC-DIET-019 (no stale claims); Query Monitor budget check on staging; GATE-08.

---

## 5. NFR-MNT-01 — Maintainability (Must) — partial gap

**Statement [Doc 04 §15 / TRD §6.12]:** pinned versions (`composer.lock`); monthly updates via staging; README per
momos-core module; WPCS + PSR-4; ⟦≥ 70 %⟧ line coverage for Reservation and Access; PHPStan level 6 with 0 errors.

| Item | Implementation rule |
|---|---|
| Version pinning | `composer.lock` committed (root + momos-core); `RELEASES.md` per tag lists WordPress core, PHP, DB, momos-core, theme, bundled libraries (exact versions). No floating `*` constraints in momos-core runtime deps. |
| Monthly update cycle | First week of each month: dependency PR → staging RC → TRD §6.10 regression checklist → production tag; critical security patches ≤ 72 h (FR-SEC-009). Record in `RELEASES.md`. |
| README per module | `wp-content/plugins/momos-core/src/<Module>/README.md` for every module in `Plugin::MODULES` (incl. Seo, Admin, Kernel). Sections: Purpose & requirement IDs · Public services/functions · Hooks fired/consumed (`momos/<module>/*`) · Data (CPTs, meta keys, tables, options) · Capabilities checked · Jobs · REST routes · Settings · Tests · Known TBDs. CI fails if a module directory lacks README.md [Prop]. |
| Coding standard | `phpcs.xml.dist` at repo root: WPCS 3.x `WordPress-Extra` + `WordPress.Security` sniffs + `PHPCompatibilityWP` (testVersion 8.3-), text domain `momos-core`, prefixes `momos`, `Momos`, `momo_`. Classes PSR-4 (`Momos\` → `src/`), one class per file, `declare(strict_types=1)`, `final` by default. |
| Static analysis | PHPStan level 6 on `src/` with `szepeviktor/phpstan-wordpress`; baseline allowed only for third-party stubs. |
| Tests & coverage | PHPUnit unit + WP integration suites (`tests/unit`, `tests/integration`); PCOV coverage in CI; **fail when line coverage of `src/Reservation` or `src/Access` < 70 %** ⟦proposal⟧. Others report only. |
| CI stages | `php -l` → PHPCS → PHPStan → PHPUnit (unit) → integration (MariaDB 10.11 + MySQL 8.x) incl. migrations twice → build artefact → stylelint (logical properties) / ESLint → wording scan (BR-RES-01, claim terms). Matches qa.md pipeline table. |
| Dev deps (CTO approval per ENGINEERING.md §5) | `wp-coding-standards/wpcs`, `phpcompatibility/phpcompatibility-wp`, `phpstan/phpstan`, `szepeviktor/phpstan-wordpress` — dev only, never in the release artefact. |

**Acceptance / tests:** CI reports per PR (TC-REG-*); GATE-05 supporting evidence.

---

## 6. Doc conflicts found during the review (resolution used)
| # | Conflict | Resolution |
|---|---|---|
| 1 | `momo_trading_name` max length: TRD G1 / content-strings.md ≤ 100 vs Doc 06 IA / ui-design-system.md ≤ 80 | Store ≤ 100 (TRD field table); design must survive 100 characters. |
| 2 | Logo upload format: Doc 04 FR-FND-005 / TRD (SVG uploads rejected for every role) vs ui-design-system.md "SVG (sanitised)/PNG" (also screens.md C-13) | No SVG uploads (security); the provisional SVG wordmark ships in the theme as code. |
| 3 | Page cache: TRD §2.9.5 and Doc 11 SEC-060 depend on the LiteSpeed Cache plugin vs IMPL-004 (plugin not required) | `CacheInvalidator` adapters in §4 (server-native LiteSpeed headers / Cloudflare API / URL list / null + short TTL). |
| 4 | Test clock name: SDD/TRD option `momos_test_clock` vs kernel and qa.md `momos_frozen_now` / `MOMOS_FROZEN_NOW` | Kernel names (already implemented); SDD name is an alias only if needed. |
| 5 | FR-FND-003(b) lists 10 modules vs code `Plugin::MODULES` (adds kernel I18n, Seo, Admin) | Allowed superset (IMPL-001/002); all need READMEs. |
| 6 | FR-FND-002(d) / FR-FND-006: Git must hold `wpml-config.xml`, ACF JSON; inventory of 14 plugins incl. premium | Not applicable under IMPL-001; inventory = momos-core + theme (§2). |
| 7 | Hash pepper constant: docs `MOMOS_HASH_PEPPER` vs `config/wp-config.production.sample.php` `MOMOS_HMAC_KEY` (also access-security-privacy.md conflict 16) | Kernel constant name wins; document the alias in the sample config. |
| 8 | Backups: TRD/Doc 11 use UpdraftPlus vs IMPL-004 (not required) | Host backups + `bin/backup.sh`/`bin/restore.sh` to Momo's-owned off-site storage; DEC-033 targets unchanged (access-security-privacy.md). |
