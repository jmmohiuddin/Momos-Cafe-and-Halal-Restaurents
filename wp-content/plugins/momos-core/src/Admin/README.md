# Admin

**Purpose.** The admin shell: the top-level **Momo's** menu (slug `momos`, TRD §2.1), the role-aware dashboard
(SCR-A02), G1 Brand (`momo-brand`) and G3 Settings (`momo-settings`, tabbed SCR-A15: reservations,
notifications, languages/launch gate, privacy, map), menu clean-up per role, and migration M0005 (default
settings). `Admin\Content` (SCR-A14/A18) adds the FAQ editor, legal-page box and media fields the theme
consumes.

## Public services
- `AdminModule::MENU` (`momos`) — other modules add submenus on `admin_menu` priority ≥ 20.
- `Settings\LanguageGate` — the only writer of `momos_languages_enabled` (enable refused while LCCS gaps
  exist or the native-review sign-off is missing; DEC-002, GATE-04).
- `Settings\ReservationOptions`, `Settings\SettingsFields`, `Settings\BrandFields` — option field groups.
- `Content\FaqEditor`, `Content\LegalPageBox`, `Content\MediaFields`.

## Hooks
- Fires: `momos/admin/landing`, `momos/i18n/language_gate_errors` (filter), `momos/i18n/language_state_changed`,
  `momos/cache/purge`, `momos/content/faq_editor`, `momos/content/faq_saved`, `momos/options_page/after_fields`.
- Consumes: `momos/options_page/saved`, `momos/audit/field_save_action`, `momos/migrations`.

## Data
Options `momos_opt_momo-settings`, `momos_opt_momo-brand`, `momos_languages_enabled`, `momos_language_gate`,
`momo_consent_versions`. Page meta `momo_faq_items`, `momo_page_type`, `momo_legal_version`,
`momo_legal_last_updated`. Attachment gallery fields.

## Capabilities
`momo_manage_brand` (Brand, Settings), `momo_manage_languages` (language gate, SA/HO),
`momo_publish_legal_pages` (legal pages), `momo_translate` (FAQ/media translations). Admin-post handlers
`momos_language_gate`, `momos_save_reservation_settings` verify nonce + capability and audit.

## Jobs · REST · CLI
None.

## Tests
`AdminSettingsTest`, `ContentAdminTest`.
