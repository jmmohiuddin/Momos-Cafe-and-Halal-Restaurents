# Kernel

**Purpose.** Shared infrastructure every module relies on (FR-FND-003): module boot (`Plugin::MODULES`),
migrations, table names, transactions and named locks, after-commit events, clock (Asia/Tokyo, frozen clock for
tests), logging with PII redaction, environment/secrets, job scheduling on Action Scheduler, cache tags and purge,
the field layer and options pages, i18n (language routing, string catalogs, translation storage, translation
editor, gaps), monitoring, and core WP-CLI commands. `docs/ENGINEERING.md` is the binding description.

## Public services / functions
- `src/functions.php`: `momos_t()`, `momos_e()`, `momos_lang()`, `momos_is_rtl()`, `momos_url()`, `momos_tr()`,
  `momos_tr_term()`, `momos_bdi()`, `momos_audit()`, `momos_user_branch_ids()`, `momos_user_can_branch()`.
- `Db\Tables`, `Db\Transaction`, `Db\NamedLock`, `Events\AfterCommit`, `Clock\Clock`, `Env`, `Log\Logger`.
- `Jobs\Schedule` (`recurring()`, `daily_at()`, `at()`, `beat()`, `health()`), `Cache\CacheInvalidator`
  (`tag()`, `purge()`; listens to `momos/cache/purge`).
- `Fields\FieldRegistry`, `Fields\OptionsPage`, `Fields\Sanitizer` — field groups with per-field capability,
  nonce per group, validation and audit.
- `I18n\Strings` (customer/email catalogs `languages/strings/{lang}/<module>.php`, overrides, placeholders,
  review status), `I18n\Translations` (per-language post/term/option fields), `I18n\Router`/`Locale`/`Url`
  (language prefixes, `determine_locale`), `I18nModule::load_textdomain()` (gettext `momos-core`).

## Hooks
Fires: `momos/modules`, `momos/migrations`, `momos/cache/purge|purged|cacheable`, `momos/fields/*`,
`momos/options_page/*`, `momos/strings/*`, `momos/i18n/*`, `momos/monitoring/alert`, `momos/log/critical`,
`momos/cli`, `momos/sanitize`. Consumes the domain change hooks of Branch/Menu/Dietary to purge cache tags.

## Data
Tables (migrations M0001/M0002): `momo_branch_hours`, `momo_branch_closures`, `momo_branch_menu_items`,
`momo_reservations`, `momo_reservation_events`, `momo_notifications`, `momo_user_branches`, `momo_audit_log`.
Options: `momos_languages_enabled`, `momos_strings_overrides`, `momos_option_i18n`, `momos_opt_<page>`,
`momos_jobs_*`, `momos_heartbeat`, `momos_cache_purge_queue`, `momo_alert_recipients`.

## Capabilities
`momo_translate`, `momo_approve_translations`, `momo_preview_languages`, `momo_manage_languages` (i18n screens).

## Admin screens
**Momo's → Interface strings** (`momos-strings`), **Translation gaps** (`momos-translation-gaps`), translation
editor (`admin.php?page=momos-translate&type=post|term|option&id=N&lang=xx` and the "Translations" box on editors).

## CLI
`wp momos migrate`, `wp momos jobs`, `wp momos cache`, `wp momos smoke`, `wp momos sanitize`,
`wp momos i18n gaps|strings-missing|strings-duplicates`.

## Tests
`KernelTest`, `I18n*Test.php`, `ToolingI18nPoTest` (admin `.po/.mo`).
