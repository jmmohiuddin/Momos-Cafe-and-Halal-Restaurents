<?php
/**
 * Text link (always underlined, accent-text colour). `more` adds a mirrored arrow; `external` opens a new
 * tab with the "(opens in a new tab)" hint and external-link icon.
 *
 * @package momos
 * @var array{label:string,url:string,more?:bool,external?:bool,attrs?:array<string,string>} $args
 */

defined( 'ABSPATH' ) || exit;

$momos_ext   = ! empty( $args['external'] );
$momos_attrs = array_merge(
	[
		'class'  => 'momo-link' . ( ! empty( $args['more'] ) ? ' momo-link--more' : '' ),
		'href'   => (string) $args['url'],
		'target' => $momos_ext ? '_blank' : null,
		'rel'    => $momos_ext ? 'noopener' : null,
	],
	(array) ( $args['attrs'] ?? [] )
);
echo '<a' . momos_attrs( $momos_attrs ) . '>' . esc_html( (string) $args['label'] ) // phpcs:ignore WordPress.Security.EscapeOutput
	. ( ! empty( $args['more'] ) ? momos_icon( 'arrow-end', 'momo-icon--sm' ) : '' ) // phpcs:ignore WordPress.Security.EscapeOutput
	. ( $momos_ext ? momos_icon( 'external-link', 'momo-icon--sm' ) . momos_new_tab_hint() : '' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput
