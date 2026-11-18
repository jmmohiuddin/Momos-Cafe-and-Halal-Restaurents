<?php
/**
 * CMP-Accordion (screens.md §3.13): `<h3><button aria-expanded aria-controls>` per question; answers in
 * server HTML (print / no-JS: all open); multiple open; `#q-{id}` opens its item; chevron rotates.
 * Without JS the answers stay visible (the `hidden` state is only applied by screens.js).
 *
 * @package momos
 * @var array{items:list<array{id:string,question:string,answer:string}>,h?:int,group?:string} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_items = (array) ( $args['items'] ?? [] );
if ( ! $momos_items ) {
	return;
}
$momos_h = max( 2, min( 4, (int) ( $args['h'] ?? 3 ) ) );
?>
<div class="momo-accordion" data-momo-accordion<?php echo ! empty( $args['group'] ) ? ' data-group="' . esc_attr( (string) $args['group'] ) . '"' : ''; ?>>
	<?php foreach ( $momos_items as $momos_i ) : ?>
		<?php $momos_qid = 'q-' . sanitize_html_class( (string) $momos_i['id'] ); ?>
		<div class="momo-accordion__item" id="<?php echo esc_attr( $momos_qid ); ?>">
			<h<?php echo (int) $momos_h; ?> class="momo-accordion__q">
				<button type="button" class="momo-accordion__btn" aria-expanded="true" aria-controls="<?php echo esc_attr( $momos_qid ); ?>-a">
					<span><?php echo esc_html( (string) $momos_i['question'] ); ?></span>
					<?php momos_the_icon( 'chevron-down', 'momo-icon--chev' ); ?>
				</button>
			</h<?php echo (int) $momos_h; ?>>
			<div class="momo-accordion__a momo-prose" id="<?php echo esc_attr( $momos_qid ); ?>-a"><?php echo wp_kses_post( (string) $momos_i['answer'] ); ?></div>
		</div>
	<?php endforeach; ?>
</div>
