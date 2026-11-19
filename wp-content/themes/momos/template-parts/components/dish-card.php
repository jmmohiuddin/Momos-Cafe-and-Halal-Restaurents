<?php
/**
 * CMP-DishCard (§7.3, UI-CHG-003/041/048/050/065). One link (the dish name) with a stretched hit area; list
 * layout on mobile, plate-over-card grid ≥ 768 px. No add/cart button, no halal badge, no opacity fade.
 * No photo → tint circle with the dish initial. "Image for illustration" under flagged photos (DEC-047).
 *
 * @package momos
 * @var array{card?:array<string,mixed>,dish_id?:int,branch_id?:int,eyebrow?:string,h?:int,variant?:string,sample?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_c = $args['card'] ?? null;
if ( null === $momos_c && ! empty( $args['dish_id'] ) && class_exists( \Momos\Menu\Service\MenuQueryService::class ) ) {
	$momos_c = \Momos\Menu\Service\MenuQueryService::card( (int) $args['dish_id'], momos_theme_lang(), isset( $args['branch_id'] ) ? (int) $args['branch_id'] : null );
}
if ( ! is_array( $momos_c ) || '' === (string) ( $momos_c['name'] ?? '' ) ) {
	return; // Not translated / module absent: hidden, never mixed language.
}
$momos_h    = max( 2, min( 4, (int) ( $args['h'] ?? 3 ) ) );
$momos_name = (string) $momos_c['name'];
$momos_img  = (int) ( $momos_c['image_id'] ?? 0 );
// D5 (content-strings §10.1): demo items carry a visible SAMPLE badge outside production (DEF-011).
$momos_smp  = ! empty( $args['sample'] ) || ( class_exists( \Momos\Presentation\View\Samples::class ) && \Momos\Presentation\View\Samples::is_marked( (int) ( $momos_c['id'] ?? 0 ) ) );
$momos_data = [];
foreach ( (array) ( $momos_c['data'] ?? [] ) as $momos_k => $momos_v ) {
	if ( str_starts_with( (string) $momos_k, 'data-' ) ) {
		$momos_data[ $momos_k ] = (string) $momos_v;
	}
}
?>
<article class="momo-dish-card<?php echo ! empty( $args['variant'] ) ? ' momo-dish-card--' . esc_attr( (string) $args['variant'] ) : ''; ?>"<?php echo momos_attrs( $momos_data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<div class="momo-dish-card__media">
		<div class="momo-plate">
			<?php
			if ( $momos_img ) {
				echo wp_get_attachment_image( $momos_img, 'momo-thumb', false, [ 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => '130px' ] );
			} else {
				echo '<span aria-hidden="true">' . esc_html( mb_substr( $momos_name, 0, 1 ) ) . '</span>';
			}
			?>
		</div>
		<?php if ( $momos_img && ! empty( $momos_c['image_illustrative'] ) ) : ?>
			<span class="momo-illustration"><?php echo esc_html( momos_s( 'media.illustration' ) ); ?></span>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $args['eyebrow'] ) ) : ?>
		<span class="momo-eyebrow"><?php echo esc_html( (string) $args['eyebrow'] ); ?></span>
	<?php endif; ?>
	<h<?php echo (int) $momos_h; ?> class="momo-dish-card__title"><a class="momo-card__link" href="<?php echo esc_url( (string) $momos_c['url'] ); ?>"><?php echo esc_html( $momos_name ); ?></a></h<?php echo (int) $momos_h; ?>>
	<?php if ( ! empty( $momos_c['original_name'] ) && $momos_c['original_name'] !== $momos_name ) : ?>
		<span class="momo-dish-card__orig" lang="ja"><bdi><?php echo esc_html( (string) $momos_c['original_name'] ); ?></bdi></span>
	<?php endif; ?>
	<?php if ( ! empty( $momos_c['short_description'] ) && 'related' !== ( $args['variant'] ?? '' ) ) : ?>
		<p class="momo-dish-card__desc"><?php echo esc_html( (string) $momos_c['short_description'] ); ?></p>
	<?php endif; ?>
	<?php momos_component( 'dish-labels', [ 'card' => $momos_c ] ); ?>
	<?php if ( ! empty( $momos_c['price_html'] ) ) : ?>
		<div class="momo-dish-card__foot"><?php momos_component( 'price', [ 'html' => (string) $momos_c['price_html'], 'sample' => $momos_smp ] ); ?></div>
	<?php elseif ( $momos_smp ) : ?>
		<div class="momo-dish-card__foot"><span class="momo-sample"><?php echo esc_html( momos_s( 'demo.sample_badge' ) ); ?></span></div>
	<?php endif; ?>
</article>
