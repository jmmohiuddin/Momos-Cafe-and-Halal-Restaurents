<?php
/**
 * CMP-Button (Doc 08 §3, ui-design-system.md §7.2). Real <a> when `url` is set, else <button>.
 * Verb-first labels; never "Order", "Book" or "Confirm" for requests. One primary per section.
 *
 * @package momos
 * @var array{label:string,url?:string,variant?:string,size?:string,icon?:string,icon_end?:string,type?:string,block?:bool,busy?:bool,busy_label?:string,disabled?:bool,external?:bool,aria_label?:string,attrs?:array<string,string>} $args
 *   variant: primary | secondary | on-dark | text | icon | danger ; size: lg | sm
 */

defined( 'ABSPATH' ) || exit;

$momos_v     = (string) ( $args['variant'] ?? 'secondary' );
$momos_class = 'momo-btn momo-btn--' . sanitize_html_class( $momos_v );
if ( ! empty( $args['size'] ) ) {
	$momos_class .= ' momo-btn--' . sanitize_html_class( (string) $args['size'] );
}
if ( ! empty( $args['block'] ) ) {
	$momos_class .= ' momo-btn--block';
}
$momos_busy  = ! empty( $args['busy'] );
$momos_label = $momos_busy && ! empty( $args['busy_label'] ) ? (string) $args['busy_label'] : (string) ( $args['label'] ?? '' );
$momos_ext   = ! empty( $args['external'] );
$momos_attrs = array_merge(
	[
		'class'         => $momos_class,
		'aria-label'    => $args['aria_label'] ?? null,
		'aria-busy'     => $momos_busy ? 'true' : null,
		'aria-disabled' => ( $momos_busy || ! empty( $args['disabled'] ) ) ? 'true' : null,
	],
	(array) ( $args['attrs'] ?? [] )
);
$momos_inner = ( $momos_busy ? momos_icon( 'spinner', 'momo-icon--spin' ) : ( ! empty( $args['icon'] ) ? momos_icon( (string) $args['icon'] ) : '' ) )
	. ( 'icon' === $momos_v ? '' : '<span>' . esc_html( $momos_label ) . '</span>' )
	. ( ! empty( $args['icon_end'] ) ? momos_icon( (string) $args['icon_end'] ) : '' )
	. ( $momos_ext ? momos_icon( 'external-link', 'momo-icon--sm' ) . momos_new_tab_hint() : '' );

if ( ! empty( $args['url'] ) ) {
	$momos_attrs['href']   = (string) $args['url'];
	$momos_attrs['target'] = $momos_ext ? '_blank' : null;
	$momos_attrs['rel']    = $momos_ext ? 'noopener' : null;
	echo '<a' . momos_attrs( $momos_attrs ) . '>' . $momos_inner . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
} else {
	$momos_attrs['type'] = (string) ( $args['type'] ?? 'button' );
	echo '<button' . momos_attrs( $momos_attrs ) . '>' . $momos_inner . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
}
