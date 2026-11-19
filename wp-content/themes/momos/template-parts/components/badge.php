<?php
/**
 * CMP-StatusBadge (§7.6, DEC-011). Pill or block. pending never uses check icon or green;
 * completed / no_show → confirmed badge + `status.past` (customer view).
 *
 * @package momos
 * @var array{status:string,block?:bool,label?:string,text?:string,h1?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_map = [
	'pending'   => [ 'pending', 'hourglass' ],
	'confirmed' => [ 'confirmed', 'check-circle' ],
	'rejected'  => [ 'rejected', 'x-circle' ],
	'cancelled' => [ 'cancelled', 'slash-circle' ],
	'expired'   => [ 'expired', 'clock-x' ],
	'completed' => [ 'confirmed', 'user-check' ],
	'no_show'   => [ 'confirmed', 'user-x' ],
];
$momos_s   = (string) ( $args['status'] ?? 'pending' );
if ( ! isset( $momos_map[ $momos_s ] ) ) {
	return;
}
[ $momos_tone, $momos_icon ] = $momos_map[ $momos_s ];
$momos_past                  = in_array( $momos_s, [ 'completed', 'no_show' ], true );
$momos_label                 = (string) ( $args['label'] ?? momos_s( 'status.' . $momos_tone ) );
if ( ! empty( $args['block'] ) ) :
	$momos_text = (string) ( $args['text'] ?? ( $momos_past ? momos_s( 'status.past' ) : '' ) );
	?>
	<div class="momo-status momo-status--block momo-status--<?php echo esc_attr( $momos_tone ); ?>">
		<?php momos_the_icon( $momos_icon ); ?>
		<div>
			<?php if ( ! empty( $args['h1'] ) ) : ?>
				<h1 class="momo-h3"><?php echo esc_html( $momos_label ); ?></h1>
			<?php else : ?>
				<p class="momo-title"><?php echo esc_html( $momos_label ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $momos_text ) : ?>
				<p><?php echo esc_html( $momos_text ); ?></p>
			<?php endif; ?>
		</div>
	</div>
<?php else : ?>
	<span class="momo-status momo-status--<?php echo esc_attr( $momos_tone ); ?>"><?php momos_the_icon( $momos_icon, 'momo-icon--sm' ); ?><?php echo esc_html( $momos_label ); ?></span>
<?php endif; ?>
