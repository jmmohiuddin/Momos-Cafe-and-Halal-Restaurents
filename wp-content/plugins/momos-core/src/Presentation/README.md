# Presentation

**Purpose.** View layer for the `momos` theme (SDD §2.1, `docs/specs/ui-design-system.md`): asset loading, body
classes, template routing for system pages, the component renderer and view models. It contains **no business
rules** — every fact comes from the Branch, Menu, Dietary and Reservation services (DEC-008).

## Public services
- `Components::render( $slug, $args )` / `Components::output()` — theme `template-parts/components/*`.
- View models: `View\BranchView`, `View\MenuView`, `View\Cards`, `View\Navigation`, `View\LanguageSwitcher`,
  `View\ActionBar`, `View\Brand`, `View\Content`, `View\BranchLinks`, `View\AnalyticsHooks`, `View\Samples`
  (style guide only).
- Formatters: `Format\DateFormatter`, `Format\PriceFormatter`, `Format\PhoneFormatter`; `Helpers\Bdi`
  (bidi isolation for Japanese addresses/phones inside RTL pages).
- `Templates\TemplateRouter`, `SystemPages` (+ migrations M0007 system pages), `PageContext`, `Assets`.
- `Contact\ContactForm` (admin-post `momos_contact`, nonce + rate limit), `Maintenance\MaintenancePage`,
  `Maintenance\MaintenanceMode` (ROLE-01 toggle `momo_maintenance_mode`, Settings → Monitoring; FR-CORP-007 A1).
- `View\Home` (halal summary fallback, response promise — FR-CORP-001(e)(f)), `View\HalalPage` (per-language
  gate of /{lang}/halal/, AC-CORP-03-03), `Admin\BrandSettings` (registered address, incomplete-brand notice,
  `momo-global` purge on save — FR-FND-005).

## Hooks
Fires filters `momos/presentation/page_type`, `momos/presentation/offers_active`,
`momos/presentation/switcher_visible`. Consumes only core template hooks.

## Data
System pages (created by M0007), the `news` post category (M0008_NewsCategory) and `momos_opt_*` brand options.

## Capabilities
None of its own; the living style guide page template is staff-only.

## Jobs · REST · CLI
None.

## Tests
`PresentationTest`, `PresentationScreensTest`, `PresentationThemeFixesTest`, E2E `theme-smoke.spec.ts`,
`theme-frame.spec.ts`, `theme-presentation-fixes.spec.ts`.
