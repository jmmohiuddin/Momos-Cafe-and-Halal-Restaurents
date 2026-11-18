<?php
/**
 * SCR-C02 About (screens.md §3.2, content-strings.md §8.2, FR-CORP-002). Banner (breadcrumb · H1 · lead) ·
 * editorial body from the approved page translation (CE blocks; real photos only — illustrative images are
 * refused on save, DEC-047) · closing band with menu / branches / halal links. No claims typed here.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id   = (int) get_queried_object_id();
$momos_body = \Momos\Presentation\View\Content::body( $momos_id );
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => \Momos\Presentation\View\Content::title( $momos_id ), 'lead' => \Momos\Presentation\View\Content::excerpt( $momos_id ) ] );
?>
<?php if ( '' !== $momos_body ) : ?>
	<div class="momo-container momo-section">
		<div class="momo-prose momo-prose--editorial"><?php echo $momos_body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content filters (kses on save). ?></div>
	</div>
<?php endif; ?>
<?php
momos_component(
	'closing-cta',
	[
		'cta'   => 'home_section',
		'links' => [
			[ 'label' => momos_s( 'cta.view_menu' ), 'url' => momos_page_url( 'menu' ) ],
			[ 'label' => momos_s( 'cta.find_branch' ), 'url' => momos_page_url( 'branches' ) ],
			[ 'label' => momos_s( 'cta.halal_info' ), 'url' => momos_page_url( 'halal' ) ],
		],
	]
);
get_footer();
