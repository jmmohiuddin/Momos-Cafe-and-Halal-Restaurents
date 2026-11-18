<?php
/**
 * Frame notices above the header: "not yet available in {language}" (switcher fallback, FR-I18N-002) and
 * active site-wide notices (CMP-NoticeBanner, FR-BR-009). Upcoming notices (≤ 24 h) are rendered hidden
 * with data-start/end so a cached page reveals them at the boundary.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

$momos_lang = momos_theme_lang();

if ( class_exists( \Momos\Presentation\View\LanguageSwitcher::class ) && \Momos\Presentation\View\LanguageSwitcher::untranslated_notice() && is_front_page() ) {
	momos_component(
		'notice',
		[
			'severity'    => 'info',
			'body'        => momos_s( 'lang.not_available', [ 'language' => \Momos\Kernel\I18n\Lang::native( $momos_lang ) ] ),
			'dismissible' => true,
			'scope'       => 'site',
		]
	);
}

if ( ! class_exists( \Momos\Branch\Query\NoticeQuery::class ) ) {
	return;
}
try {
	$momos_notices = \Momos\Branch\Query\NoticeQuery::site_wide( $momos_lang, 24 );
} catch ( \Throwable $e ) {
	$momos_notices = [];
}
foreach ( $momos_notices as $momos_notice ) {
	momos_component(
		'notice',
		[
			'id'          => (int) $momos_notice['id'],
			'severity'    => (string) $momos_notice['severity'],
			'title'       => (string) $momos_notice['title'],
			'body'        => (string) $momos_notice['body'],
			'link_url'    => (string) $momos_notice['link_url'],
			'link_label'  => (string) $momos_notice['link_label'],
			'dismissible' => ! empty( $momos_notice['dismissible'] ),
			'upcoming'    => ! empty( $momos_notice['upcoming'] ),
			'start_ms'    => $momos_notice['start_ms'] ?? null,
			'end_ms'      => $momos_notice['end_ms'] ?? null,
			'scope'       => 'site',
		]
	);
}
