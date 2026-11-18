<?php
/**
 * SCR-C19 404 (ui-design-system.md §8.11, FR-CORP-007, AC-CORP-07-01): HTTP 404 + noindex, H1 `404.title`,
 * one sentence `404.body`, four link cards (Home · Branches · Menu · Request a table) in the URL language;
 * 1 column → 4 in a row ≥ 1024 px. Mobile action bar: Request · Branches.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
get_template_part(
	'template-parts/frame/page-banner',
	null,
	[
		'title'       => momos_s( '404.title' ),
		'lead'        => momos_s( '404.body' ),
		'breadcrumbs' => false,
		'center'      => true,
	]
);
$momos_cards = [
	[ 'bc.home', momos_theme_url( '/' ), 'home' ],
	[ 'nav.branches', momos_page_url( 'branches' ), 'store' ],
	[ 'nav.menu', momos_page_url( 'menu' ), 'utensils' ],
	[ 'cta.request_table', momos_page_url( 'reservation' ), 'calendar' ],
];
?>
<section class="momo-container momo-section" aria-labelledby="momo-404-links">
	<h2 id="momo-404-links" class="momo-visually-hidden"><?php echo esc_html( momos_s( '404.links' ) ); ?></h2>
	<ul class="momo-grid momo-grid--2 momo-grid--4-lg momo-list-reset" role="list">
		<?php foreach ( $momos_cards as [ $momos_key, $momos_url, $momos_icon ] ) : ?>
			<li>
				<?php
				momos_component(
					'card',
					[
						'title'   => momos_s( $momos_key ),
						'url'     => $momos_url,
						'icon'    => $momos_icon,
						'variant' => 'link',
						'h'       => 3,
						'more'    => '',
						'link_attrs' => 'cta.request_table' === $momos_key ? [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => 'error_page' ] : [],
					]
				);
				?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
<?php
get_footer();
