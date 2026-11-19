<?php
/**
 * Accommodation channel (CMP-032, DEC-036, TC-A11Y-012): a Head Office phone/e-mail alternative for
 * anyone unable to use the reservation form, rendered before the form fields so it is reached first by
 * keyboard and screen reader, with or without JavaScript. The catalog sentence keeps its own word order;
 * only the {phone}/{email} placeholders are replaced by links (never concatenated fragments).
 *
 * @package momos
 * @var array{contact:array{phone:string,tel:string,email:string}} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_c = (array) ( $args['contact'] ?? [] );
$momos_p = '' !== (string) ( $momos_c['tel'] ?? '' );
$momos_m = '' !== (string) ( $momos_c['email'] ?? '' );
if ( ! $momos_p && ! $momos_m ) {
	return;
}
$momos_key  = $momos_p && $momos_m ? 'res.alt_contact' : ( $momos_p ? 'res.alt_contact.phone' : 'res.alt_contact.email' );
$momos_repl = [];
if ( $momos_p ) {
	$momos_repl['{phone}'] = '<a href="' . esc_attr( (string) $momos_c['tel'] ) . '">' . momos_ltr( (string) $momos_c['phone'] ) . '</a>';
}
if ( $momos_m ) {
	$momos_repl['{email}'] = '<a href="' . esc_url( 'mailto:' . (string) $momos_c['email'] ) . '"><bdi dir="ltr">' . esc_html( (string) $momos_c['email'] ) . '</bdi></a>';
}
?>
<p class="momo-res-alt" data-res-alt-contact><?php momos_the_icon( 'phone' ); ?><span><?php echo strtr( esc_html( momos_s( $momos_key ) ), $momos_repl ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped sentence + escaped links. ?></span></p>
