# Seo

**Purpose.** SEO and analytics without third-party plugins (IMPL-001; `docs/specs/i18n-seo-analytics.md`
§8–§12): per-language head tags, sitemaps, routing rules, JSON-LD, breadcrumbs, redirects with a 404 log and
weekly review, and the GTM loader with consent/opt-out.

## Public services
- `Head` — title, description, OG/Twitter, canonical, hreflang (+ x-default), robots (FR-SEO-002/003/005).
- `Sitemap` — `/sitemap.xml` index + per-language/type children; core sitemaps off (FR-SEO-004).
- `Routing` — robots.txt, search/attachment 301, archives/feeds 404 (FR-SEO-009, DEC-045).
- `Schema\JsonLd` — Organization, WebSite, Restaurant (+ `Schema\OpeningHours`), Menu/MenuSection/MenuItem,
  BreadcrumbList, FAQPage (FR-SEO-006); `Breadcrumbs::items()` for the theme (FR-SEO-007).
- `Redirects\RedirectStore` / `RedirectMatcher` (no chains, loops, external targets or personal data; 410),
  `Redirects\NotFoundLog` (PII-redacted paths) (FR-SEO-008).
- `Analytics\TagLoader` + theme `datalayer.js` — GTM only where allowed, opt-out and GPC respected
  (FR-ANL-001/002, DEC-066).
- `SeoFields` — per-item SEO title/description (translated through the translation editor).

## Hooks
Fires filters `momos/schema/graph`, `momos/seo/breadcrumbs`, `momos/analytics/allowed`. Consumes `momos/cli`,
`momos/sanitize` (staging copy clean-up).

## Data
Options for the redirect map, 404 log, weekly reviews and SEO settings (`SeoSettings`); post/term meta for SEO
title/description.

## Capabilities
`momo_manage_redirects` (SA/HO) for **Momo's → Redirections** (`admin.php?page=momos-seo`, tabs redirects /
404 log / reviews / settings); `momo_edit_seo_meta` (SA/HO/MM/CE) for SEO fields.

## Jobs · CLI
- `momos_job_seo_404_retention` (daily 04:20 JST, `log_retention_days`).
- `wp momos seo redirects import <csv> [--verify] | verify | export` (non-zero exit on refused rows).

## Tests
`SeoHeadTest`, `SeoSitemapSchemaTest`, `SeoRedirectsAnalyticsTest`; E2E `seo-head.spec.ts`.
