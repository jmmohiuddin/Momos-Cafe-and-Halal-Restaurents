# Engineering conventions — Momo's platform

Read this before writing code. It is the contract that lets modules be built in parallel.
Business requirements live in `docs/specs/*.md` (digested from the documentation package) and
`docs/REQUIREMENTS_REGISTRY.md`. Platform decisions: `docs/IMPLEMENTATION_DECISIONS.md`.

## 1. Stack and layout
- WordPress 7.1.x single site, PHP 8.3 (`declare(strict_types=1)`), MariaDB 10.11 / MySQL 8, InnoDB, utf8mb4.
- **No paid plugins** (IMPL-001). All business logic in `wp-content/plugins/momos-core` (namespace `Momos\`,
  PSR-4 `src/`), loaded by `wp-content/mu-plugins/momos-loader.php`. Presentation in the theme
  `wp-content/themes/momos` (no business logic in the theme, DEC-008).
- Composer runtime deps (plugin): Action Scheduler (`as_*` functions), libphonenumber-lite (`\libphonenumber\PhoneNumberUtil`).

```
momos-core/
  momos-core.php        bootstrap          src/functions.php   global helpers (momos_t, momos_url, momos_tr, momos_audit, momos_user_branch_ids…)
  config/capabilities.php  role→cap table (authoritative, TRD §2.7.2)
  languages/strings/{ja,en,ar,ur,hi,bn}/{module}.php   customer/email string catalogs (one file per module)
  templates/            email + admin view partials     assets/{js,css}
  src/Kernel/           shared infrastructure (do not modify without the CTO; ask in your report)
  src/{Access,Audit,Branch,Menu,Dietary,Reservation,Notification,Api,Privacy,Seo,Presentation,Admin}/
```

## 2. Modules
- Each module has `src/<Module>/<Module>Module.php` implementing `Momos\Kernel\ModuleInterface::register()`
  (hooks only — no queries, no output). The class names are already listed in `Momos\Kernel\Plugin::MODULES`;
  a missing class is skipped, so you only need to create your module class.
- Modules communicate through **WordPress actions/filters named `momos/<module>/<event>`** (SDD §2.5 domain hook
  catalogue) and small public service classes. Fire domain events after commit with
  `Momos\Kernel\Events\AfterCommit::dispatch( 'momos/reservation/created', $id )`.
- Admin screens: submenus of the top-level menu slug `momos` (`Momos\Admin\AdminModule::MENU`) added on
  `admin_menu` priority ≥ 20. CPT menus are their own top-level items (TRD §2.1).

## 3. Kernel APIs (use these, do not re-implement)
| Need | API |
|---|---|
| Now / time zones | `Momos\Kernel\Clock\Clock::utc()`, `::jst()`, `::mysql_utc()`, `::from_mysql_utc()`, `::jst_at($date,$time)`. Never call `time()`/`date()` in business logic (tests freeze the clock with option `momos_frozen_now` or constant `MOMOS_FROZEN_NOW` in non-production). |
| Table names | `Momos\Kernel\Db\Tables::reservations()` etc. Always `$wpdb->prepare()`; ORDER BY from whitelists. |
| Transactions | `Momos\Kernel\Db\Transaction::run(fn() => …)`; named locks `NamedLock::acquire/release`. |
| Migrations | Implement `Momos\Kernel\Migration\MigrationInterface` (idempotent `up()`), add with filter `momos/migrations`. ID ranges: Dietary M0003, Access M0004, Admin/settings M0005, Presentation pages M0007, Branch M0010–19, Menu M0020–29, Reservation M0030–39, Notification M0040–49, Privacy M0050–59, Seo M0060–69, Audit M0070–79, seed/demo never via migration (use WP-CLI `wp momos seed`). |
| Fields (ACF replacement) | `Momos\Kernel\Fields\FieldRegistry::group([...])` — see the class docblock for field keys/types. Post meta key = field `name` (keep the documented `momo_*` names). Per-field `edit_cap`, `required` (`true` or `'publish'`), `translatable`, `validate` callable, `show_if`. Module publish rules: filter `momos/fields/publish_errors/{post_type}`; cross-field rules: filter `momos/fields/validate_group/{group_key}`. Read values with `get_post_meta()` or `FieldRegistry::get_post_field()` (applies defaults). Options pages: `Momos\Kernel\Fields\OptionsPage::add($slug,$title,$cap)` + groups with `'options_page' => $slug`; read with `Momos\Kernel\Settings::get('momo_x')`. |
| Customer/email strings | `momos_t('res.title', ['name' => …], $lang)` — keys from Doc 07 §6 where they exist, otherwise `<module>.<area>.<item>`. Add keys to `languages/strings/<lang>/<module>.php` for **all six** languages (ar/ur/hi/bn = draft translations, mark the file header "DRAFT – needs native review", DEC-027). Never concatenate translated fragments; use placeholders `{name}`. |
| Admin strings | `__( 'Text', 'momos-core' )` (admin is ja/en, DEC-037; Japanese .po compiled later). |
| Languages / URLs | `Momos\Kernel\I18n\Lang` (codes, rtl, enabled), `Locale::current()`, `Locale::with($lang, fn)`, `momos_url('/menu/', $lang)`, `Url::localize($url, $lang)`. Japanese at root; `/en/ /ar/ /ur/ /hi/ /bn/`. |
| Translated content | Register translatable fields (`'translatable' => true` on a field, or `Translations::register('post:momo_dish', [...])` for core fields `title`/`content`/`excerpt`). Read with `momos_tr($post, 'title')` / `Translations::post(...)`; visibility `Translations::post_visible($post, $lang)`; WP_Query: add `Translations::meta_query($lang)` to `meta_query`. An item without an approved translation is **hidden** in that language (DEC-027). |
| Audit | `momos_audit( 'branch.hours_updated', 'branch', $id, $branch_id, $changes )`. No PII in `$changes`. |
| Branch scope | `momos_user_branch_ids()` (null = all branches), `momos_user_can_branch($branch_id)`; object-level caps via `current_user_can('momo_view_reservation', $id)` etc. (Access module, TRD §2.7.3). |
| Jobs | `Momos\Kernel\Jobs\Schedule::recurring('momos_job_x', 300)` in `register()`; implement with `add_action('momos_job_x', …)`. Jobs must be idempotent and safe to run concurrently (named lock). |
| Logging | `Momos\Kernel\Log\Logger::info/warning/error/critical($event, $context)` — PII is redacted but do not pass it anyway. |
| Secrets | `Momos\Kernel\Env::secret('MOMOS_TOKEN_KEY_V1')` — constants/env only, never options. `Env::is_production()`. |
| Hashing / refs | `Kernel\Support\Hash::hmac/truncate_ip/mask_email`, `Crockford::random/display/normalize`, `Uuid::v4`. |

## 4. Security rules (MUST — Doc 11, SDD §6)
- Validate and sanitise every input server-side; escape every output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Capability check + nonce on every admin action, admin-post handler and AJAX call; REST routes need a
  `permission_callback` (never `__return_true` for staff routes; public routes validate origin/rate limits).
- Branch isolation: every staff query on reservations/branch data filters by `momos_user_branch_ids()` and
  every object action checks the meta capability. Deny → `momos_audit('access.denied', …)`.
- No PII in logs, analytics, URLs, audit `changes`, or staff email bodies beyond DEC-017 minimum.
- Use `$wpdb->prepare()` for every query with variables.

## 5. Coding style
WordPress Coding Standards (tabs, spaces inside parentheses, Yoda conditions optional), typed properties and
return types, small classes, final by default. Front-end CSS uses **logical properties only** (RTL). No new
Composer/npm dependencies without the CTO.

## 6. Local environment
```bash
bin/dev-setup.sh            # MariaDB + WordPress in .wp/ linked to the repo; runs migrations
bin/serve.sh                # http://127.0.0.1:8080  (superadmin / Momos-Dev-2026!)
bin/wp momos migrate        # WP-CLI against the dev install
```
Parallel work: use your own database and port, e.g.
`MOMOS_DB_NAME=momos_branch MOMOS_PORT=8091 MOMOS_URL=http://127.0.0.1:8091 bin/dev-setup.sh --no-seed`
and prefix every `bin/wp`/`bin/serve.sh` call with the same variables.
Always run `php -l` on changed files and exercise your code through `bin/wp eval` / the browser before reporting.

## 7. Definition of Done (per task)
Implemented to spec (IDs referenced in docblocks), `php -l` clean, exercised end-to-end locally, capability and
branch-scope checks in place, strings in all six catalogs, audit events emitted, no PII leaks, PHPUnit tests for
domain logic where the task says so, notes on any deviation in your final report.
