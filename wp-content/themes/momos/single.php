<?php
/**
 * SCR-C14 News item (Could; screens.md §3.14): breadcrumb (Home › News › {Title}) · H1 · date · body at
 * reading width · back link `news.back`. Title/body come from the approved translation in the URL
 * language; an untranslated item is a 404 (TemplateRouter::guard_untranslated).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id    = (int) get_queried_object_id();
$momos_title = \Momos\Presentation\View\Content::title( $momos_id );
$momos_news  = \Momos\Presentation\SystemPages::url( 'news' );
$momos_trail = [ [ 'name' => momos_s( 'bc.home' ), 'url' => momos_theme_url( '/' ) ] ];
if ( null !== $momos_news ) {
	$momos_trail[] = [ 'name' => momos_s( 'nav.news' ), 'url' => $momos_news ];
}
$momos_trail[] = [ 'name' => $momos_title ];
$momos_iso     = (string) get_post_time( 'c', true, $momos_id );
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => $momos_title, 'breadcrumbs' => $momos_trail ] );
?>
<div class="momo-container momo-section">
	<article class="momo-reading momo-stack">
		<time class="momo-muted" datetime="<?php echo esc_attr( $momos_iso ); ?>"><?php echo esc_html( \Momos\Presentation\Format\DateFormatter::long( new \DateTimeImmutable( $momos_iso ) ) ); ?></time>
		<?php if ( has_post_thumbnail( $momos_id ) ) : ?>
			<div class="momo-news-card__media"><?php echo get_the_post_thumbnail( $momos_id, 'momo-hero', [ 'alt' => '', 'loading' => 'eager', 'decoding' => 'async' ] ); ?></div>
		<?php endif; ?>
		<div class="momo-prose"><?php echo \Momos\Presentation\View\Content::body( $momos_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- the_content filters. ?></div>
		<?php if ( null !== $momos_news ) : ?>
			<p><a class="momo-link" href="<?php echo esc_url( $momos_news ); ?>"><?php momos_the_icon( 'arrow-start', 'momo-icon--sm' ); ?><?php echo esc_html( momos_s( 'news.back' ) ); ?></a></p>
		<?php endif; ?>
	</article>
</div>
<?php
get_footer();
