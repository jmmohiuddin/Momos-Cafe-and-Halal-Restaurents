<?php
/**
 * SEO title templates and brand label (en) — Momos\Seo (i18n-seo-analytics.md §9.1, §15).
 * English (source of the drafts). Wording per i18n-seo-analytics.md §9.1/§15 (PRD FR-SEO-005; ⟦brand/SEO review⟧).
 * Placeholders: {title} {brand} {branch} {area} {dish} {category} {n} — never concatenate fragments.
 */

defined( 'ABSPATH' ) || exit;

return [
	'seo.brand_short' => 'Momo\'s',
	'seo.title.default' => '{title} | {brand}',
	'seo.title.home' => '{brand}',
	'seo.title.branch' => '{branch} – Halal restaurant near {area} | {brand}',
	'seo.title.branch_no_area' => '{branch} | {brand}',
	'seo.title.dish' => '{dish} – {category} | {brand}',
	'seo.title.paged' => '{title} (page {n})',
];
