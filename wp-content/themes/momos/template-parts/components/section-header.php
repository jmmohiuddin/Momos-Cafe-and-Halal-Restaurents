<?php
/**
 * CMP-SectionHeader: eyebrow (section/category names only — no claims, UI-CHG-013) · H2 · lead · end link.
 *
 * @package momos
 * @var array{title:string,id?:string,eyebrow?:string,lead?:string,link?:array{label:string,url:string},center?:bool,h?:int} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_h = max( 2, min( 3, (int) ( $args['h'] ?? 2 ) ) );
?>
<div class="momo-section-head<?php echo ! empty( $args['center'] ) ? ' momo-section-head--center' : ''; ?>">
	<div class="momo-section-head__text">
		<?php if ( ! empty( $args['eyebrow'] ) ) : ?>
			<span class="momo-eyebrow"><?php echo esc_html( (string) $args['eyebrow'] ); ?></span>
		<?php endif; ?>
		<h<?php echo (int) $momos_h; ?><?php echo ! empty( $args['id'] ) ? ' id="' . esc_attr( (string) $args['id'] ) . '"' : ''; ?>><?php echo esc_html( (string) $args['title'] ); ?></h<?php echo (int) $momos_h; ?>>
		<?php if ( ! empty( $args['lead'] ) ) : ?>
			<p class="momo-muted"><?php echo esc_html( (string) $args['lead'] ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $args['link'] ) ) : ?>
		<?php momos_component( 'link', [ 'label' => $args['link']['label'], 'url' => $args['link']['url'], 'more' => true ] ); ?>
	<?php endif; ?>
</div>
