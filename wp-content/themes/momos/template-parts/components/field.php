<?php
/**
 * CMP-Field (§7.2): bound <label> + "(required)/(optional)" text · hint (aria-describedby) · control ·
 * inline error (icon + text, aria-invalid) · counter from 90 % of the limit. Email/tel/url are LTR;
 * names/notes `dir="auto"`. No placeholder-only labels; font ≥ 16 px.
 *
 * @package momos
 * @var array{id:string,name?:string,label:string,type?:string,value?:string,required?:bool,help?:string,error?:string,autocomplete?:string,maxlength?:int,options?:array<string,string>,rows?:int,inputmode?:string,attrs?:array<string,string>} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_id   = (string) $args['id'];
$momos_type = (string) ( $args['type'] ?? 'text' );
$momos_req  = ! empty( $args['required'] );
$momos_desc = [];
if ( ! empty( $args['help'] ) ) {
	$momos_desc[] = $momos_id . '-help';
}
if ( ! empty( $args['error'] ) ) {
	$momos_desc[] = $momos_id . '-error';
}
$momos_max  = isset( $args['maxlength'] ) ? (int) $args['maxlength'] : 0;
$momos_dir  = in_array( $momos_type, [ 'email', 'tel', 'url' ], true ) ? 'ltr' : ( in_array( $momos_type, [ 'text', 'textarea' ], true ) ? 'auto' : null );
$momos_attr = array_merge(
	[
		'id'                => $momos_id,
		'name'              => (string) ( $args['name'] ?? $momos_id ),
		'class'             => 'momo-input',
		'required'          => $momos_req,
		'aria-invalid'      => ! empty( $args['error'] ) ? 'true' : null,
		'aria-describedby'  => $momos_desc ? implode( ' ', $momos_desc ) : null,
		'autocomplete'      => $args['autocomplete'] ?? null,
		'maxlength'         => $momos_max ? (string) $momos_max : null,
		'dir'               => $momos_dir,
		'inputmode'         => $args['inputmode'] ?? null,
		'data-momo-counter' => $momos_max ? (string) $momos_max : null,
	],
	(array) ( $args['attrs'] ?? [] )
);
?>
<div class="momo-field">
	<label class="momo-field__label" for="<?php echo esc_attr( $momos_id ); ?>"><?php echo esc_html( (string) $args['label'] ); ?> <span class="momo-field__req"><?php echo esc_html( momos_s( $momos_req ? 'form.required' : 'form.optional' ) ); ?></span></label>
	<?php if ( ! empty( $args['help'] ) ) : ?>
		<span class="momo-field__help" id="<?php echo esc_attr( $momos_id ); ?>-help"><?php echo esc_html( (string) $args['help'] ); ?></span>
	<?php endif; ?>
	<?php if ( 'textarea' === $momos_type ) : ?>
		<textarea<?php echo momos_attrs( $momos_attr + [ 'rows' => (string) ( $args['rows'] ?? 4 ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_textarea( (string) ( $args['value'] ?? '' ) ); ?></textarea>
	<?php elseif ( 'select' === $momos_type ) : ?>
		<select<?php echo momos_attrs( $momos_attr ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<?php foreach ( (array) ( $args['options'] ?? [] ) as $momos_v => $momos_l ) : ?>
				<option value="<?php echo esc_attr( (string) $momos_v ); ?>"<?php selected( (string) ( $args['value'] ?? '' ), (string) $momos_v ); ?>><?php echo esc_html( (string) $momos_l ); ?></option>
			<?php endforeach; ?>
		</select>
	<?php else : ?>
		<input<?php echo momos_attrs( $momos_attr + [ 'type' => $momos_type, 'value' => (string) ( $args['value'] ?? '' ) ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<?php endif; ?>
	<?php if ( ! empty( $args['error'] ) ) : ?>
		<span class="momo-field__error" id="<?php echo esc_attr( $momos_id ); ?>-error"><?php momos_the_icon( 'alert-circle', 'momo-icon--sm' ); ?><span><span class="momo-visually-hidden"><?php echo esc_html( momos_s( 'form.error_prefix' ) ); ?> </span><?php echo esc_html( (string) $args['error'] ); ?></span></span>
	<?php endif; ?>
	<?php if ( $momos_max ) : ?>
		<span class="momo-field__counter" aria-live="polite" data-momo-counter-for="<?php echo esc_attr( $momos_id ); ?>" data-template="<?php echo esc_attr( momos_s( 'form.counter' ) ); ?>" hidden></span>
	<?php endif; ?>
</div>
