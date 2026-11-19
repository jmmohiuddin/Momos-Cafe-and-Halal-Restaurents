<?php
/**
 * CMP-LangSuggestionBar (FR-I18N-003, i18n-seo-analytics.md §5.2). Client-side only: a tiny inline script reads
 * navigator.languages + the embedded JSON and reveals the bar before first paint; the server never redirects. Only
 * languages in which this page exists are embedded. Cookie `momo_lang_choice` suppresses it. Announced politely
 * (FR-I18N-003(d)): the suggestion is written into a role=status / aria-live=polite region after the load event, so
 * screen readers announce it once without moving focus; the visible bar is laid out before first paint (no CLS).
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
		<span class="momo-visually-hidden" role="status" aria-live="polite" data-momo-langbar-live></span>
		<a class="momo-btn momo-btn--secondary momo-btn--sm" href="#" data-momo-langbar-switch></a>
		<button type="button" class="momo-btn momo-btn--icon momo-btn--sm" data-momo-langbar-dismiss data-momo-to="<?php echo esc_attr( momos_theme_lang() ); ?>"><?php momos_the_icon( 'x' ); ?></button>
	</div>
</div>
<script type="application/json" id="momo-lang-data"><?php echo wp_json_encode( [ 'page' => momos_theme_lang(), 'langs' => $momos_suggest ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
<?php // Decided synchronously before first paint (no layout shift when the bar appears, NFR-PERF-01 CLS); frame.js stores the choice on click (data-momo-to). ?>
<script>(function(d,n){var b=d.querySelector('[data-momo-langbar]'),j=d.getElementById('momo-lang-data'),c=null;if(!b||!j||/(?:^|;\s*)momo_lang_choice=/.test(d.cookie)){return;}try{c=JSON.parse(j.textContent);}catch(e){return;}var ls=n.languages&&n.languages.length?n.languages:[n.language||''],best=null;for(var i=0;i<ls.length;i++){var k=String(ls[i]||'').toLowerCase().split('-')[0];if(k===c.page){break;}if(c.langs[k]){best=k;break;}}if(!best){return;}var L=c.langs[best],s=b.querySelector('[data-momo-langbar-switch]');b.setAttribute('lang',best);b.setAttribute('dir',L.dir);b.setAttribute('aria-label',L.suggest);b.setAttribute('data-momo-best',best);b.querySelector('[data-momo-langbar-text]').textContent=L.suggest;s.textContent=L['switch'];s.href=L.url;s.setAttribute('hreflang',best);s.setAttribute('data-momo-to',best);b.querySelector('[data-momo-langbar-dismiss]').setAttribute('aria-label',L.dismiss);b.hidden=false;window.addEventListener('load',function(){setTimeout(function(){b.querySelector('[data-momo-langbar-live]').textContent=L.suggest;},150);});})(document,navigator);</script>
