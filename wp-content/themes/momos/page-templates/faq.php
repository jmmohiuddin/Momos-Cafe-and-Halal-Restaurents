<?php
/**
 * SCR-C13 FAQ (Should; screens.md §3.13, content-strings.md §8.4, FR-CORP-004, AC-CORP-04-01/02).
 * Banner · group anchor chips · per group H2 + CMP-Accordion (questions/answers from `momo_faq_items`
 * of the approved page translation; empty groups hidden; `#q-{id}` opens its item) · page body (optional)
 * · contact link. Halal/allergen answers link to governed pages instead of restating claims (content rule).
 * Expanding a "Halal & dietary" item is exposed to the analytics module via `data-group` (§12.2).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id     = (int) get_queried_object_id();
$momos_groups = \Momos\Presentation\View\Content::faq( $momos_id );
$momos_body   = \Momos\Presentation\View\Content::body( $momos_id );
$momos_title  = \Momos\Presentation\View\Content::title( $momos_id );
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => '' !== $momos_title ? $momos_title : momos_s( 'nav.faq' ), 'lead' => \Momos\Presentation\View\Content::excerpt( $momos_id ) ] );
?>
<div class="momo-container momo-section momo-faq">
	<?php if ( $momos_groups ) : ?>
		<?php if ( count( $momos_groups ) > 1 ) : ?>
			<?php momos_component( 'chip-group', [ 'type' => 'link', 'label' => momos_s( 'faq.groups.label' ), 'scroll' => true, 'items' => array_map( static fn( $g ) => [ 'label' => momos_s( 'faq.group.' . $g ), 'url' => '#faq-' . $g ], array_keys( $momos_groups ) ) ] ); ?>
		<?php endif; ?>
		<?php foreach ( $momos_groups as $momos_g => $momos_items ) : ?>
			<section class="momo-faq__group" id="faq-<?php echo esc_attr( $momos_g ); ?>" aria-labelledby="faq-<?php echo esc_attr( $momos_g ); ?>-h">
				<h2 id="faq-<?php echo esc_attr( $momos_g ); ?>-h"><?php echo esc_html( momos_s( 'faq.group.' . $momos_g ) ); ?></h2>
				<?php momos_component( 'accordion', [ 'items' => $momos_items, 'h' => 3, 'group' => $momos_g ] ); ?>
			</section>
		<?php endforeach; ?>
	<?php elseif ( '' === $momos_body ) : ?>
		<?php momos_component( 'empty-state', [ 'title' => momos_s( 'faq.empty' ), 'icon' => 'help-circle' ] ); ?>
	<?php endif; ?>
	<?php if ( '' !== $momos_body ) : ?>
		<div class="momo-prose momo-mt-8"><?php echo $momos_body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content filters. ?></div>
	<?php endif; ?>
	<?php $momos_contact = \Momos\Presentation\SystemPages::url( 'contact' ); ?>
	<?php if ( null !== $momos_contact ) : ?>
		<p class="momo-faq__contact momo-mt-8"><?php echo esc_html( momos_s( 'faq.contact' ) ); ?> <?php momos_component( 'link', [ 'label' => momos_s( 'nav.contact' ), 'url' => $momos_contact, 'more' => true ] ); ?></p>
	<?php endif; ?>
</div>
<?php
get_footer();
