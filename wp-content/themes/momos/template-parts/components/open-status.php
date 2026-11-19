<?php
/**
 * CMP-OpenStatus (§7.4): dot + text; times isolated LTR. `loading` → skeleton of final size; failure →
 * nothing (the hours table stays).
 *
 * @package momos
 * @var array{status?:array{state:string,html:string}|null,branch_id?:int,loading?:bool} $args
 */

defined( 'ABSPATH' ) || exit;

if ( ! empty( $args['loading'] ) ) {
	echo '<span class="momo-skel momo-skel--text" aria-hidden="true"></span>';
	return;
}
$momos_st = $args['status'] ?? null;
if ( null === $momos_st && ! empty( $args['branch_id'] ) && class_exists( \Momos\Presentation\View\Cards::class ) ) {
	$momos_st = \Momos\Presentation\View\Cards::open_status( (int) $args['branch_id'] );
}
if ( ! is_array( $momos_st ) || '' === (string) ( $momos_st['html'] ?? '' ) ) {
	return;
}
// Cache-safe: frame.js recomputes the state from the embedded JST schedule at view time (Doc 07 §5 C04, BR-BR-05).
$momos_sched = ! empty( $args['branch_id'] ) && class_exists( \Momos\Presentation\View\Cards::class ) ? \Momos\Presentation\View\Cards::open_status_client( (int) $args['branch_id'] ) : null;
?>
<span class="momo-open momo-open--<?php echo esc_attr( (string) $momos_st['state'] ); ?>"<?php echo is_array( $momos_sched ) ? ' data-momo-open="' . esc_attr( (string) wp_json_encode( $momos_sched, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '"' : ''; ?>><span><?php echo wp_kses( (string) $momos_st['html'], [ 'bdi' => [ 'dir' => true ] ] ); ?></span></span>
