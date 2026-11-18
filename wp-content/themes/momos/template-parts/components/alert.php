<?php
/**
 * Inline message (Doc 08 §2.2.6 colour set): info · warning · error · success. `role` per use:
 * error summary on submit → alert; success → status; static info → note/none.
 *
 * @package momos
 * @var array{type?:string,title?:string,body?:string,role?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_type  = in_array( $args['type'] ?? 'info', [ 'info', 'warning', 'error', 'success' ], true ) ? (string) ( $args['type'] ?? 'info' ) : 'info';
$momos_icons = [ 'info' => 'info', 'warning' => 'alert-triangle', 'error' => 'alert-circle', 'success' => 'check-circle' ];
?>
<div class="momo-alert momo-alert--<?php echo esc_attr( $momos_type ); ?>"<?php echo ! empty( $args['role'] ) ? ' role="' . esc_attr( (string) $args['role'] ) . '"' : ''; ?>>
	<?php momos_the_icon( $momos_icons[ $momos_type ] ); ?>
	<div>
		<?php if ( ! empty( $args['title'] ) ) : ?>
			<strong class="momo-alert__title"><?php echo esc_html( (string) $args['title'] ); ?></strong>
		<?php endif; ?>
		<?php if ( ! empty( $args['body'] ) ) : ?>
			<p><?php echo esc_html( (string) $args['body'] ); ?></p>
		<?php endif; ?>
	</div>
</div>
