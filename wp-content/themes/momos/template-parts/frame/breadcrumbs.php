<?php
/**
 * CMP-Breadcrumbs (FR-SEO-007, ui-design-system.md §8.8). Trail from `Momos\Seo\Breadcrumbs::items()`
 * when the SEO module exists (same source as BreadcrumbList JSON-LD), else from `$args['items']`, else
 * Home › {current title}. Current item is not a link; separator by CSS (mirrored in RTL).
 *
 * @package momos
 * @var array{items?:list<array{name:string,url?:string}>} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_items = $args['items'] ?? null;
if ( null === $momos_items && class_exists( '\Momos\Seo\Breadcrumbs' ) ) {
	$momos_items = \Momos\Seo\Breadcrumbs::items();
}
if ( null === $momos_items ) {
	$momos_items = [ [ 'name' => momos_s( 'bc.home' ), 'url' => momos_theme_url( '/' ) ] ];
	if ( is_singular() ) {
		$momos_title = function_exists( 'momos_tr' ) ? momos_tr( get_queried_object_id(), 'title' ) : get_the_title();
		if ( '' !== $momos_title ) {
			$momos_items[] = [ 'name' => $momos_title ];
		}
	}
}
if ( count( $momos_items ) < 2 ) {
	return;
}
$momos_last = count( $momos_items ) - 1;
?>
<nav class="momo-breadcrumbs" aria-label="<?php echo esc_attr( momos_s( 'a11y.breadcrumb' ) ); ?>">
	<ol>
		<?php foreach ( $momos_items as $momos_n => $momos_crumb ) : ?>
			<li>
				<?php if ( $momos_n < $momos_last && ! empty( $momos_crumb['url'] ) ) : ?>
					<a href="<?php echo esc_url( $momos_crumb['url'] ); ?>"><?php echo esc_html( $momos_crumb['name'] ); ?></a>
				<?php else : ?>
					<span aria-current="page"><?php echo esc_html( $momos_crumb['name'] ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</nav>
