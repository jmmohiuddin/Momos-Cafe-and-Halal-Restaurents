<?php
/**
 * Global helper functions for templates (theme) and modules. Thin wrappers over kernel services.
 */

declare(strict_types=1);

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Strings;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'momos_t' ) ) {
	/**
	 * Customer-facing / email string by key in the current (or given) language.
	 *
	 * @param array<string, string|int|float> $args
	 */
	function momos_t( string $key, array $args = [], ?string $lang = null ): string {
		return Strings::t( $key, $args, $lang );
	}

	/** Echo an escaped catalog string. @param array<string, string|int|float> $args */
	function momos_e( string $key, array $args = [], ?string $lang = null ): void {
		echo esc_html( Strings::t( $key, $args, $lang ) );
	}

	function momos_lang(): string {
		return Locale::current();
	}

	function momos_is_rtl(): bool {
		return Lang::is_rtl( Locale::current() );
	}

	/** Language-aware URL for a site path. */
	function momos_url( string $path = '/', ?string $lang = null ): string {
		return Url::to( $path, $lang );
	}

	/** Translated field of a post (empty string when not approved in that language). */
	function momos_tr( int|\WP_Post $post, string $field, ?string $lang = null ): string {
		return Translations::post( $post, $field, $lang );
	}

	/** Translated field of a term. */
	function momos_tr_term( int|\WP_Term $term, string $field, ?string $lang = null ): string {
		return Translations::term( $term, $field, $lang );
	}

	/** Wrap Latin/number fragments for bidi isolation inside RTL text (FR-I18N-005). */
	function momos_bdi( string $text ): string {
		return '<bdi>' . esc_html( $text ) . '</bdi>';
	}
}
