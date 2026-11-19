<?php
/**
 * SCR-C22 Gallery (Could; screens.md §3.18, FR-CORP-012). Banner · filter chips (All · branches ·
 * categories; empty chips hidden; client-side) · CMP-Gallery grid with **visible captions** (never
 * hover-only) · closing band. Photos: attachments with `momo_gallery_include = 1` ([Proposed] keys),
 * caption/alt per language; demo-flagged (illustrative) images are never shown (DEC-047).
 * Lightbox (FR-CORP-012(c), AC-CORP-10-01, Doc 07 §5 C22 / Doc 08 CMP-Gallery): every photo is a link to the
 * full-size image (works without JS); assets/js/gallery.js (loaded on this page only) opens it in a native
 * `<dialog>` on Enter/click — labelled previous/next buttons, arrow keys (mirrored in RTL), swipe, Esc closes
 * and focus returns to the photo link; the caption is the visible caption in the page language.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

get_header();
$momos_id     = (int) get_queried_object_id();
$momos_items  = \Momos\Presentation\View\Content::gallery();
$momos_title  = \Momos\Presentation\View\Content::title( $momos_id );
$momos_filter = [];
foreach ( $momos_items as $momos_i ) {
	if ( '' !== $momos_i['branch'] ) {
		$momos_filter[ 'b-' . $momos_i['branch'] ] = $momos_i['branch_name'];
	}
	if ( '' !== $momos_i['category'] ) {
		$momos_filter[ 'c-' . $momos_i['category'] ] = $momos_i['category_name'];
	}
}
get_template_part( 'template-parts/frame/page-banner', null, [ 'title' => '' !== $momos_title ? $momos_title : momos_s( 'nav.gallery' ), 'lead' => \Momos\Presentation\View\Content::excerpt( $momos_id ), 'center' => true ] );
?>
<div class="momo-container momo-section momo-gallery-page">
	<?php if ( $momos_items ) : ?>
		<?php if ( count( $momos_filter ) > 1 ) : ?>
			<div class="momo-gallery__filter" role="group" aria-label="<?php echo esc_attr( momos_s( 'gallery.filter.label' ) ); ?>" data-momo-gallery-filter hidden>
				<ul class="momo-chips momo-chips--scroll">
					<li><button type="button" class="momo-chip" aria-pressed="true" data-filter=""><?php echo esc_html( momos_s( 'gallery.filter.all' ) ); ?></button></li>
					<?php foreach ( $momos_filter as $momos_k => $momos_label ) : ?>
						<li><button type="button" class="momo-chip" aria-pressed="false" data-filter="<?php echo esc_attr( $momos_k ); ?>"><?php echo esc_html( (string) $momos_label ); ?></button></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<ul class="momo-gallery momo-list-reset" data-momo-gallery>
			<?php foreach ( $momos_items as $momos_i ) : ?>
				<li data-keys="<?php echo esc_attr( trim( ( '' !== $momos_i['branch'] ? 'b-' . $momos_i['branch'] : '' ) . ' ' . ( '' !== $momos_i['category'] ? 'c-' . $momos_i['category'] : '' ) ) ); ?>">
					<figure>
						<?php
						$momos_cap  = trim( $momos_i['caption'] . ( '' !== $momos_i['branch_name'] ? ' · ' . $momos_i['branch_name'] : '' ), ' ·' );
						$momos_full = (string) wp_get_attachment_image_url( (int) $momos_i['id'], 'full' );
						// Link name = image alt; a decorative photo (alt '') is named by its caption, else "Open photo".
						$momos_name = '' !== (string) $momos_i['alt'] ? null : ( '' !== $momos_cap ? $momos_cap : momos_s( 'gallery.open_photo' ) );
						?>
						<a class="momo-gallery__open" href="<?php echo esc_url( $momos_full ); ?>" data-momo-lb<?php echo momos_attrs( [ 'aria-label' => $momos_name ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo wp_get_attachment_image( (int) $momos_i['id'], 'momo-card', false, [ 'alt' => (string) $momos_i['alt'], 'loading' => 'lazy', 'decoding' => 'async' ] ); ?></a>
						<?php if ( '' !== $momos_cap ) : ?>
							<figcaption><?php echo esc_html( $momos_cap ); ?></figcaption>
						<?php endif; ?>
					</figure>
				</li>
			<?php endforeach; ?>
		</ul>
		<dialog class="momo-lightbox" aria-label="<?php echo esc_attr( momos_s( 'gallery.lightbox.label' ) ); ?>" data-momo-lightbox>
			<button type="button" class="momo-btn momo-btn--icon momo-lightbox__close" data-momo-lb-close aria-label="<?php echo esc_attr( momos_s( 'a11y.close' ) ); ?>"><?php momos_the_icon( 'x' ); ?></button>
			<figure class="momo-lightbox__fig">
				<img src="data:," alt="" data-momo-lb-img>
				<figcaption data-momo-lb-cap></figcaption>
			</figure>
			<div class="momo-lightbox__nav">
				<button type="button" class="momo-btn momo-btn--icon" data-momo-lb-step="-1" aria-label="<?php echo esc_attr( momos_s( 'gallery.prev' ) ); ?>"><?php momos_the_icon( 'chevron-start' ); ?></button>
				<p class="momo-lightbox__count" aria-live="polite" data-momo-lb-count data-template="<?php echo esc_attr( momos_s( 'gallery.count', [ 'n' => '{n}', 'total' => '{total}' ] ) ); ?>"></p>
				<button type="button" class="momo-btn momo-btn--icon" data-momo-lb-step="1" aria-label="<?php echo esc_attr( momos_s( 'gallery.next' ) ); ?>"><?php momos_the_icon( 'chevron-end' ); ?></button>
			</div>
		</dialog>
	<?php else : ?>
		<?php momos_component( 'empty-state', [ 'title' => momos_s( 'gallery.empty' ), 'icon' => 'image' ] ); ?>
	<?php endif; ?>
</div>
<?php
momos_component( 'closing-cta', [ 'cta' => 'home_section', 'id' => 'momo-gallery-closing' ] );
get_footer();
