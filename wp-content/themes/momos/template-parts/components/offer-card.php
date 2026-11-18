<?php
/**
 * CMP-OfferCard (§7.3, BR-CORP-01): image 4:3 (+ "Image for illustration"), eyebrow, title, summary,
 * validity (dates isolated; JST label non-ja), scope, conditions (> 3 lines → "Show all conditions"
 * disclosure), optional price (tax incl.), CTA. No countdown, coupon code or "ends soon".
 *
 * @package momos
 * @var array{offer?:array<string,mixed>,offer_id?:int,h?:int} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_o = $args['offer'] ?? null;
if ( null === $momos_o && ! empty( $args['offer_id'] ) && class_exists( \Momos\Presentation\View\Cards::class ) ) {
	$momos_o = \Momos\Presentation\View\Cards::offer( (int) $args['offer_id'] );
}
if ( ! is_array( $momos_o ) ) {
	return;
}
$momos_h   = max( 2, min( 4, (int) ( $args['h'] ?? 3 ) ) );
$momos_id  = 'momo-offer-' . (int) ( $momos_o['id'] ?? wp_rand() );
$momos_lng = momos_theme_lang();
?>
<article class="momo-card momo-offer-card" id="<?php echo esc_attr( $momos_id ); ?>">
	<?php if ( ! empty( $momos_o['image_id'] ) ) : ?>
		<div class="momo-offer-card__media"><?php echo wp_get_attachment_image( (int) $momos_o['image_id'], 'momo-card', false, [ 'loading' => 'lazy', 'decoding' => 'async' ] ); ?></div>
		<?php if ( ! empty( $momos_o['illustrative'] ) ) : ?>
			<span class="momo-illustration momo-offer-card__caption"><?php echo esc_html( momos_s( 'media.illustration' ) ); ?></span>
		<?php endif; ?>
	<?php endif; ?>
	<div class="momo-offer-card__body">
		<span class="momo-eyebrow"><?php echo esc_html( momos_s( 'offers.eyebrow' ) ); ?></span>
		<h<?php echo (int) $momos_h; ?> class="momo-card__title"><?php echo esc_html( (string) $momos_o['title'] ); ?></h<?php echo (int) $momos_h; ?>>
		<?php if ( ! empty( $momos_o['summary'] ) ) : ?>
			<p><?php echo esc_html( (string) $momos_o['summary'] ); ?></p>
		<?php endif; ?>
		<dl>
			<?php if ( ! empty( $momos_o['valid_html'] ) ) : ?>
				<dd><?php echo wp_kses( (string) $momos_o['valid_html'], [ 'bdi' => [ 'dir' => true ] ] ); ?><?php echo 'ja' !== $momos_lng ? ' ' . esc_html( momos_s( 'offers.jst' ) ) : ''; ?></dd>
			<?php endif; ?>
			<?php if ( ! empty( $momos_o['scope'] ) ) : ?>
				<dd><?php momos_the_icon( 'store', 'momo-icon--sm' ); ?> <?php echo esc_html( implode( momos_s( 'diet.list.separator' ), (array) $momos_o['scope'] ) ); ?></dd>
			<?php endif; ?>
			<?php if ( ! empty( $momos_o['conditions'] ) ) : ?>
				<dt><?php echo esc_html( momos_s( 'offers.conditions' ) ); ?></dt>
				<dd><p class="momo-clamp-3" id="<?php echo esc_attr( $momos_id ); ?>-cond" data-momo-clamp><?php echo nl2br( esc_html( (string) $momos_o['conditions'] ) ); ?></p>
					<button type="button" class="momo-btn momo-btn--text" aria-expanded="false" aria-controls="<?php echo esc_attr( $momos_id ); ?>-cond" data-momo-clamp-toggle hidden><?php echo esc_html( momos_s( 'offers.show_all' ) ); ?></button></dd>
			<?php endif; ?>
		</dl>
		<?php if ( ! empty( $momos_o['price_html'] ) ) : ?>
			<?php momos_component( 'price', [ 'html' => (string) $momos_o['price_html'] ] ); ?>
		<?php endif; ?>
		<?php
		$momos_cta = $momos_o['cta'] ?? null;
		if ( is_array( $momos_cta ) ) {
			$momos_req = 'request_table' === $momos_cta['type'];
			momos_component( 'button', [ 'label' => (string) $momos_cta['label'], 'url' => (string) $momos_cta['url'], 'variant' => $momos_req ? 'primary' : 'secondary', 'attrs' => $momos_req ? [ 'data-momo-event' => 'click_reservation', 'data-momo-cta' => 'offer_card' ] : [] ] );
		}
		?>
	</div>
</article>
