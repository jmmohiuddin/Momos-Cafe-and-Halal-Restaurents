<?php
declare(strict_types=1);

namespace Momos\Presentation;

/**
 * Component renderer (ui-design-system.md §0, §7): `Components::render('notice-banner', $args)` loads the
 * theme partial `template-parts/components/{slug}.php` (child/parent theme), falling back to
 * `momos-core/templates/widgets/{slug}.php`. Partials receive `$args`, escape all output and never
 * query the database directly (data comes from module services / View models).
 */
final class Components {
	/** @param array<string,mixed> $args */
	public static function render( string $slug, array $args = [] ): string {
		$file = self::locate( $slug );
		if ( '' === $file ) {
			return '';
		}
		ob_start();
		try {
			load_template( $file, false, $args );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			\Momos\Kernel\Log\Logger::error( 'presentation.component_failed', [ 'component' => $slug, 'error' => $e->getMessage() ] );
			// Error state: a failing component renders nothing (+ comment for logged-in super admins only).
			return current_user_can( 'manage_options' ) ? '<!-- momo component "' . esc_html( $slug ) . '" failed -->' : '';
		}
		return (string) ob_get_clean();
	}

	/** @param array<string,mixed> $args */
	public static function output( string $slug, array $args = [] ): void {
		echo self::render( $slug, $args ); // phpcs:ignore WordPress.Security.EscapeOutput -- partials escape their output.
	}

	public static function locate( string $slug ): string {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return '';
		}
		$theme = locate_template( [ 'template-parts/components/' . $slug . '.php' ] );
		if ( '' !== $theme ) {
			return $theme;
		}
		$core = MOMOS_CORE_DIR . '/templates/widgets/' . $slug . '.php';
		return is_file( $core ) ? $core : '';
	}
}
