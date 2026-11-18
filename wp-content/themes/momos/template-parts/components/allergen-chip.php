<?php
/**
 * Allergen state chip: shape icon (● ◐ ○ ?, never mirrored, colour + shape + text) and allergen name.
 *
 * @package momos
 * @var array{state:string,name:string,state_label?:string} $args state: contains | may_contain | not_contained | not_verified
 */

defined( 'ABSPATH' ) || exit;

$momos_icons = [ 'contains' => 'allergen-contains', 'may_contain' => 'allergen-may', 'not_contained' => 'allergen-not', 'not_verified' => 'allergen-unverified' ];
$momos_state = (string) ( $args['state'] ?? 'not_verified' );
if ( ! isset( $momos_icons[ $momos_state ] ) || '' === (string) ( $args['name'] ?? '' ) ) {
	return;
}
$momos_sl = (string) ( $args['state_label'] ?? momos_s( 'allergen.state.' . $momos_state ) );
?>
<span class="momo-allergen-chip"><span class="momo-al--<?php echo esc_attr( $momos_state ); ?>"><?php momos_the_icon( $momos_icons[ $momos_state ] ); ?></span><?php echo esc_html( (string) $args['name'] ); ?><span class="momo-visually-hidden">: <?php echo esc_html( $momos_sl ); ?></span></span>
