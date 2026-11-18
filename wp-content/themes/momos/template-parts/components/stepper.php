<?php
/**
 * CMP-Stepper (§7.2): − · numeric input (min..max) · +; buttons announce `a11y.stepper.*`; at a limit the
 * button gets aria-disabled. Native digits are normalised by frame.js on blur.
 *
 * @package momos
 * @var array{id:string,name?:string,label:string,min:int,max:int,value?:int} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_id  = (string) $args['id'];
$momos_min = (int) $args['min'];
$momos_max = (int) $args['max'];
$momos_val = max( $momos_min, min( $momos_max, (int) ( $args['value'] ?? $momos_min ) ) );
?>
<div class="momo-field">
	<label class="momo-field__label" for="<?php echo esc_attr( $momos_id ); ?>"><?php echo esc_html( (string) $args['label'] ); ?></label>
	<div class="momo-stepper" data-momo-stepper>
		<button type="button" class="momo-btn momo-btn--icon" data-step="-1" aria-label="<?php echo esc_attr( momos_s( 'a11y.stepper.dec' ) ); ?>" aria-controls="<?php echo esc_attr( $momos_id ); ?>"<?php echo $momos_val <= $momos_min ? ' aria-disabled="true"' : ''; ?>><?php momos_the_icon( 'minus' ); ?></button>
		<input class="momo-input" id="<?php echo esc_attr( $momos_id ); ?>" name="<?php echo esc_attr( (string) ( $args['name'] ?? $momos_id ) ); ?>" type="text" inputmode="numeric" pattern="[0-9]*" dir="ltr" value="<?php echo esc_attr( (string) $momos_val ); ?>" data-min="<?php echo esc_attr( (string) $momos_min ); ?>" data-max="<?php echo esc_attr( (string) $momos_max ); ?>">
		<button type="button" class="momo-btn momo-btn--icon" data-step="1" aria-label="<?php echo esc_attr( momos_s( 'a11y.stepper.inc' ) ); ?>" aria-controls="<?php echo esc_attr( $momos_id ); ?>"<?php echo $momos_val >= $momos_max ? ' aria-disabled="true"' : ''; ?>><?php momos_the_icon( 'plus' ); ?></button>
	</div>
</div>
