<?php
/**
 * CMP-LangSuggestionBar (FR-I18N-003, i18n-seo-analytics.md §5.2). Client-side only: frame.js reads
 * navigator.languages + the embedded JSON and reveals the bar; the server never redirects. Only
 * languages in which this page exists are embedded. Cookie `momo_lang_choice` suppresses it.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Momos\Presentation\View\LanguageSwitcher::class ) ) {
	return;
}
$momos_suggest = \Momos\Presentation\View\LanguageSwitcher::suggestions();
if ( ! $momos_suggest ) {
	return;
}
?>
<div class="momo-langbar" role="region" data-momo-langbar hidden>
	<div class="momo-container momo-langbar__inner">
		<p class="momo-langbar__text" data-momo-langbar-text></p>
		<a class="momo-btn momo-btn--secondary momo-btn--sm" href="#" data-momo-langbar-switch></a>
		<button type="button" class="momo-btn momo-btn--icon momo-btn--sm" data-momo-langbar-dismiss><?php momos_the_icon( 'x' ); ?></button>
	</div>
</div>
<script type="application/json" id="momo-lang-data"><?php echo wp_json_encode( [ 'page' => momos_theme_lang(), 'langs' => $momos_suggest ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
