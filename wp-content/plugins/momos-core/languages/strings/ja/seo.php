<?php
/**
 * SEO title templates and brand label (ja) — Momos\Seo (i18n-seo-analytics.md §9.1, §15).
 * Japanese. DRAFT – needs native/SEO review (i18n-seo-analytics.md §9.1 JA drafts).
 * Placeholders: {title} {brand} {branch} {area} {dish} {category} {n} — never concatenate fragments.
 */

defined( 'ABSPATH' ) || exit;

return [
	'seo.brand_short' => 'Momo\'s',
	'seo.title.default' => '{title}｜{brand}',
	'seo.title.home' => '{brand}',
	'seo.title.branch' => '{branch}｜{area}のハラールレストラン｜{brand}',
	'seo.title.branch_no_area' => '{branch}｜{brand}',
	'seo.title.dish' => '{dish}（{category}）｜{brand}',
	'seo.title.paged' => '{title}（{n}ページ目）',
];
