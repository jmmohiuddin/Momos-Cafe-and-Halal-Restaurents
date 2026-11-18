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
?>
<span class="momo-open momo-open--<?php echo esc_attr( (string) $momos_st['state'] ); ?>"><span><?php echo wp_kses( (string) $momos_st['html'], [ 'bdi' => [ 'dir' => true ] ] ); ?></span></span>
