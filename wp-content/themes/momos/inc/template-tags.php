<?php
/**
 * Thin template helpers over momos-core Presentation services (no queries, no business rules here).
 *
 * @package momos
 */

defined( 'ABSPATH' ) || exit;

/** Is the momos-core Presentation layer available? (theme degrades to a bare frame without it). */
function momos_core_ready(): bool {
	return function_exists( 'momos_t' ) && class_exists( \Momos\Presentation\Components::class );
}

/**
 * Catalog string (escaped by the caller). Safe without momos-core: returns the key.
 *
 * @param array<string, string|int|float> $args
 */
function momos_s( string $key, array $args = [], ?string $lang = null ): string {
	return function_exists( 'momos_t' ) ? momos_t( $key, $args, $lang ) : $key;
}

/** Current language code. */
function momos_theme_lang(): string {
	return function_exists( 'momos_lang' ) ? momos_lang() : 'ja';
}

/**
 * Render a component partial (template-parts/components/{slug}.php) and echo it.
 *
 * @param array<string,mixed> $args
 */
function momos_component( string $slug, array $args = [] ): void {
	if ( momos_core_ready() ) {
		\Momos\Presentation\Components::output( $slug, $args );
		return;
	}
	get_template_part( 'template-parts/components/' . $slug, null, $args );
}

/** Same, returned as a string. @param array<string,mixed> $args */
function momos_component_html( string $slug, array $args = [] ): string {
	if ( momos_core_ready() ) {
		return \Momos\Presentation\Components::render( $slug, $args );
	}
	ob_start();
	get_template_part( 'template-parts/components/' . $slug, null, $args );
	return (string) ob_get_clean();
}

/** Language URL for a site path. */
function momos_theme_url( string $path = '/' ): string {
	return function_exists( 'momos_url' ) ? momos_url( $path ) : home_url( $path );
}

/** System page URL by role (falls back to the documented path). */
function momos_page_url( string $role ): string {
	if ( class_exists( \Momos\Presentation\SystemPages::class ) ) {
		return (string) \Momos\Presentation\SystemPages::url( $role );
	}
	return home_url( '/' . ( 'home' === $role ? '' : $role . '/' ) );
}

/** `<bdi dir="ltr">` isolation (FR-I18N-005). */
function momos_ltr( string $text, string $class = '' ): string {
	return class_exists( \Momos\Presentation\Helpers\Bdi::class ) ? \Momos\Presentation\Helpers\Bdi::ltr( $text, $class ) : '<bdi dir="ltr">' . esc_html( $text ) . '</bdi>';
}

/**
 * HTML attributes from an array (values escaped; boolean true → bare attribute; null/false skipped).
 *
 * @param array<string, string|int|bool|null> $attrs
 */
function momos_attrs( array $attrs ): string {
	$out = '';
	foreach ( $attrs as $k => $v ) {
		if ( null === $v || false === $v || '' === $v ) {
			continue;
		}
		$out .= ' ' . esc_attr( (string) $k ) . ( true === $v ? '' : '="' . esc_attr( (string) $v ) . '"' );
	}
	return $out;
}

/** "(opens in a new tab)" visually hidden suffix for target=_blank links. */
function momos_new_tab_hint(): string {
	return '<span class="momo-visually-hidden"> ' . esc_html( momos_s( 'a11y.new_tab' ) ) . '</span>';
}
