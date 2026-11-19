<?php
/**
 * SEO title templates and brand label (bn) — Momos\Seo (i18n-seo-analytics.md §9.1, §15).
 * Bengali. DRAFT – needs native review (IMPL-003, DEC-027): machine-assisted drafts, not approved for launch. Western digits only (DEC-062).
 * Placeholders: {title} {brand} {branch} {area} {dish} {category} {n} — never concatenate fragments.
 */

defined( 'ABSPATH' ) || exit;

return [
	'seo.brand_short' => 'Momo\'s',
	'seo.title.default' => '{title} | {brand}',
	'seo.title.home' => '{brand}',
	'seo.title.branch' => '{branch} – {area}-এর কাছে হালাল রেস্তোরাঁ | {brand}',
	'seo.title.branch_no_area' => '{branch} | {brand}',
	'seo.title.dish' => '{dish} – {category} | {brand}',
	'seo.title.paged' => '{title} (পৃষ্ঠা {n})',
];
