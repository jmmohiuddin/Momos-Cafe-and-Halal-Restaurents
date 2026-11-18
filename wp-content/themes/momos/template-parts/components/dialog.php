<?php
/**
 * CMP-Dialog / bottom sheet (§7.7): native <dialog> (showModal via `data-momo-dialog-open="{id}"`),
 * bottom sheet < 768 px, centred ≥ 768; heading receives focus; Esc + close button; focus returns to the
 * opener. One primary action.
 *
 * @package momos
 * @var array{id:string,title:string,body?:string,body_html?:string,primary?:array{label:string,url?:string,attrs?:array},secondary_label?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_id = sanitize_html_class( (string) $args['id'] );
?>
<dialog class="momo-dialog" id="<?php echo esc_attr( $momos_id ); ?>" aria-labelledby="<?php echo esc_attr( $momos_id ); ?>-title" data-momo-dialog>
	<div class="momo-dialog__head">
		<h2 id="<?php echo esc_attr( $momos_id ); ?>-title" tabindex="-1"><?php echo esc_html( (string) $args['title'] ); ?></h2>
		<button type="button" class="momo-btn momo-btn--icon" data-momo-dialog-close aria-label="<?php echo esc_attr( momos_s( 'a11y.close' ) ); ?>"><?php momos_the_icon( 'x' ); ?></button>
	</div>
	<div class="momo-dialog__body">
		<?php if ( isset( $args['body_html'] ) ) : ?>
			<?php echo wp_kses_post( (string) $args['body_html'] ); ?>
		<?php else : ?>
			<p><?php echo esc_html( (string) ( $args['body'] ?? '' ) ); ?></p>
		<?php endif; ?>
	</div>
	<div class="momo-dialog__foot">
		<button type="button" class="momo-btn momo-btn--secondary" data-momo-dialog-close><?php echo esc_html( (string) ( $args['secondary_label'] ?? momos_s( 'a11y.close' ) ) ); ?></button>
		<?php
		if ( ! empty( $args['primary'] ) ) {
			momos_component( 'button', array_merge( [ 'variant' => 'primary' ], (array) $args['primary'] ) );
		}
		?>
	</div>
</dialog>
