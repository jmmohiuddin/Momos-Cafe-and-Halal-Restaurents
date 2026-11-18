<?php
/**
 * SCR-C14 News list (Could; screens.md §3.14, FR-CORP-005, AC-CORP-05-01). Posts page `news`: banner
 * (Home › News) · cards (title, `<time>` date per locale format, excerpt, optional 4:3 image) 1/2/3
 * columns · 10 per page, newest first · pagination. Only posts approved in the language (no mixed
 * language). Empty → `news.empty`.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_page  = max( 1, (int) get_query_var( 'paged' ) );
$momos_news  = \Momos\Presentation\View\Content::news( $momos_page );
$momos_pid   = (int) get_option( 'page_for_posts' );
$momos_title = $momos_pid ? \Momos\Presentation\View\Content::title( $momos_pid ) : '';
$momos_base  = (string) ( \Momos\Presentation\SystemPages::url( 'news' ) ?? momos_theme_url( '/news/' ) );
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => '' !== $momos_title ? $momos_title : momos_s( 'nav.news' ) ] );
?>
<div class="momo-container momo-section">
	<?php if ( $momos_news['items'] ) : ?>
		<ul class="momo-grid momo-grid--3 momo-list-reset">
			<?php foreach ( $momos_news['items'] as $momos_n ) : ?>
				<li>
					<article class="momo-card momo-card--interactive momo-news-card">
						<?php if ( $momos_n['image_id'] ) : ?>
							<div class="momo-news-card__media"><?php echo wp_get_attachment_image( (int) $momos_n['image_id'], 'momo-card', false, [ 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async' ] ); ?></div>
						<?php endif; ?>
						<time class="momo-small momo-muted" datetime="<?php echo esc_attr( (string) $momos_n['date_iso'] ); ?>"><?php echo esc_html( (string) $momos_n['date'] ); ?></time>
						<h2 class="momo-card__title"><a class="momo-card__link" href="<?php echo esc_url( (string) $momos_n['url'] ); ?>"><?php echo esc_html( (string) $momos_n['title'] ); ?></a></h2>
						<?php if ( '' !== $momos_n['excerpt'] ) : ?>
							<p class="momo-muted momo-small"><?php echo esc_html( (string) $momos_n['excerpt'] ); ?></p>
						<?php endif; ?>
					</article>
				</li>
			<?php endforeach; ?>
		</ul>
		<div class="momo-mt-8">
			<?php momos_component( 'pagination', [ 'current' => $momos_page, 'total' => (int) $momos_news['pages'], 'url' => static fn( int $n ): string => 1 === $n ? $momos_base : trailingslashit( $momos_base ) . 'page/' . $n . '/' ] ); ?>
		</div>
	<?php else : ?>
		<?php momos_component( 'empty-state', [ 'title' => momos_s( 'news.empty' ), 'icon' => 'newspaper' ] ); ?>
	<?php endif; ?>
</div>
<?php
get_footer();
