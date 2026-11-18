<?php
/**
 * CMP-Choice (§7.2): native checkbox/radio row; the whole row is the label (≥ 48 px). Consent boxes
 * are unchecked by default. `label_html` allows links (e.g. "(opens in a new tab)") — sanitised.
 *
 * @package momos
 * @var array{id:string,name:string,type?:string,value?:string,label?:string,label_html?:string,checked?:bool,required?:bool,error?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_id = (string) $args['id'];
?>
<div class="momo-field">
	<label class="momo-choice" for="<?php echo esc_attr( $momos_id ); ?>">
		<input<?php echo momos_attrs( [ 'type' => 'radio' === ( $args['type'] ?? '' ) ? 'radio' : 'checkbox', 'id' => $momos_id, 'name' => (string) $args['name'], 'value' => (string) ( $args['value'] ?? '1' ), 'checked' => ! empty( $args['checked'] ), 'required' => ! empty( $args['required'] ), 'aria-invalid' => ! empty( $args['error'] ) ? 'true' : null, 'aria-describedby' => ! empty( $args['error'] ) ? $momos_id . '-error' : null ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
		<span><?php echo isset( $args['label_html'] ) ? wp_kses( (string) $args['label_html'], [ 'a' => [ 'href' => true, 'target' => true, 'rel' => true ], 'span' => [ 'class' => true ] ] ) : esc_html( (string) ( $args['label'] ?? '' ) ); ?></span>
	</label>
	<?php if ( ! empty( $args['error'] ) ) : ?>
		<span class="momo-field__error" id="<?php echo esc_attr( $momos_id ); ?>-error"><?php momos_the_icon( 'alert-circle', 'momo-icon--sm' ); ?><span><?php echo esc_html( (string) $args['error'] ); ?></span></span>
	<?php endif; ?>
</div>
