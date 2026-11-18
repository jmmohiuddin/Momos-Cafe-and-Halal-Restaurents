<?php
/**
 * Icons (Doc 08 §2.9, ui-design-system.md §6): one SVG sprite, `<svg><use href="sprite.svg#i-{name}">`.
 * Directional icons (chevron/arrow start/end, directions) get `momo-icon--dir` and mirror in RTL.
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

/** Icons that mirror in RTL (FR-I18N-001(c)). Never: phone, clock, map-pin, check, globe, allergen shapes. */
const MOMOS_DIR_ICONS = [ 'chevron-start', 'chevron-end', 'arrow-start', 'arrow-end', 'directions' ];

/**
 * Decorative icon markup (always `aria-hidden`; meaning is carried by visible text or the control name).
 *
 * @param string $name  Icon id without the `i-` prefix.
 * @param string $class Extra classes (e.g. `momo-icon--sm`).
 */
function momos_icon( string $name, string $class = '' ): string {
	$classes = 'momo-icon' . ( in_array( $name, MOMOS_DIR_ICONS, true ) ? ' momo-icon--dir' : '' ) . ( '' !== $class ? ' ' . $class : '' );
	$href    = class_exists( \Momos\Presentation\Assets::class ) ? \Momos\Presentation\Assets::sprite_url() : get_theme_file_uri( 'assets/icons/sprite.svg' );
	return '<svg class="' . esc_attr( $classes ) . '" aria-hidden="true" focusable="false"><use href="' . esc_url( $href ) . '#i-' . esc_attr( $name ) . '"/></svg>';
}

/** Echo an icon. */
function momos_the_icon( string $name, string $class = '' ): void {
	echo momos_icon( $name, $class ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in momos_icon().
}
