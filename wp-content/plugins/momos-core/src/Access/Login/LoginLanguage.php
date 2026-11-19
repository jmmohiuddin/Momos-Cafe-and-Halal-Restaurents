<?php
declare(strict_types=1);

namespace Momos\Access\Login;

use Momos\Kernel\I18n\Locale;

/**
 * Language of the staff login screens (SCR-A01, WF-ADM-01; DEC-003/DEC-037 "Japanese first, admin ja/en",
 * WCAG 3.1.1): wp-login.php — password form, lost/reset password, 2FA screens and the generic errors —
 * is Japanese unless the visitor chose English with the ja/en selector (core `wp_lang` parameter and
 * cookie). The request language (`<html lang>`, `momos_t()`) follows the same choice.
 *
 * Core's own login strings come from the WordPress ja language pack when it is installed; when it is
 * not (offline build hosts) the few strings of the login screens fall back to the access catalog
 * (`admin.login.*`) so the screen never mixes an English form with a `lang="ja"` attribute.
 */
final class LoginLanguage {
	/** Core msgid (default text domain) → catalog key, used only while that msgid is untranslated. */
	public const CORE_STRINGS = [
		'Log In'                                    => 'admin.login.log_in',
		'Log in'                                    => 'admin.login.log_in',
		'Username or Email Address'                 => 'admin.login.username',
		'Password'                                  => 'admin.login.password',
		'Show password'                             => 'admin.login.show_password',
		'Hide password'                             => 'admin.login.hide_password',
		'Lost your password?'                       => 'admin.login.lost_link',
		'Lost Password'                             => 'admin.login.lost_title',
		'Get New Password'                          => 'admin.login.lost_submit',
		'Please enter your username or email address. You will receive an email message with instructions on how to reset your password.' => 'admin.login.lost_intro',
		'Please enter a username or email address.' => 'admin.login.err.empty_username',
		'Check your email'                          => 'admin.login.checkemail_title',
		'Check your email for the confirmation link, then visit the <a href="%s">login page</a>.' => 'admin.login.checkemail_text',
		'You are now logged out.'                   => 'admin.login.logged_out',
		'&larr; Go to %s'                           => 'admin.login.back_to_site',
		'Error:'                                    => 'admin.login.err.prefix',
		'Reset Password'                            => 'admin.login.rp_title',
		'Enter your new password below or generate one.' => 'admin.login.rp_intro',
		'New password'                              => 'admin.login.rp_new',
		'Generate Password'                         => 'admin.login.rp_generate',
		'Save Password'                             => 'admin.login.rp_save',
		'Your password has been reset.'             => 'admin.login.rp_done',
		'Your password reset link appears to be invalid. Please request a new link below.' => 'admin.login.rp_invalid',
		'Your password reset link has expired. Please request a new link below.' => 'admin.login.rp_expired',
		'Your session has expired. Please log in to continue where you left off.' => 'admin.login.reauth',
	];

	public static function register(): void {
		add_filter( 'determine_locale', [ self::class, 'determine_locale' ], 20 );
		add_action( 'login_init', [ self::class, 'set_request_language' ], 0 );
		add_filter( 'gettext', [ self::class, 'core_fallback' ], 20, 3 );
		add_filter( 'gettext_with_context', [ self::class, 'core_fallback_with_context' ], 20, 4 );
		add_filter( 'login_display_language_dropdown', '__return_false' );
		add_action( 'login_footer', [ self::class, 'selector' ], 5 );
	}

	public static function is_login_screen(): bool {
		return 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' );
	}

	/** Visitor's choice on the login screens: 'en' only when asked for, else Japanese (DEC-037). */
	public static function choice(): string {
		$raw = '';
		if ( isset( $_GET['wp_lang'] ) && is_string( $_GET['wp_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$raw = sanitize_text_field( wp_unslash( $_GET['wp_lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} elseif ( isset( $_COOKIE['wp_lang'] ) && is_string( $_COOKIE['wp_lang'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_COOKIE['wp_lang'] ) );
		}
		return str_starts_with( strtolower( $raw ), 'en' ) ? 'en' : 'ja';
	}

	/** @param string $locale */
	public static function determine_locale( $locale ): string {
		if ( ! self::is_login_screen() ) {
			return (string) $locale;
		}
		return 'en' === self::choice() ? 'en_US' : 'ja';
	}

	/** `<html lang>` (Kernel Router) and momos_t() follow the login language. */
	public static function set_request_language(): void {
		Locale::set( self::choice() );
	}

	/**
	 * @param string $translation
	 * @param string $text
	 * @param string $domain
	 */
	public static function core_fallback( $translation, $text, $domain ): string {
		$translation = (string) $translation;
		if ( 'default' !== $domain || $translation !== (string) $text || ! self::is_login_screen() || 'ja' !== self::choice() ) {
			return $translation;
		}
		$key = self::CORE_STRINGS[ (string) $text ] ?? null;
		if ( null === $key ) {
			return $translation;
		}
		$value = momos_t( $key, [ 'site' => '%s', 'link_start' => '<a href="%s">', 'link_end' => '</a>' ], 'ja' );
		return $value === $key ? $translation : $value;
	}

	/**
	 * Same for `_x()` strings ("&larr; Go to %s" has the context "site").
	 *
	 * @param string $translation
	 * @param string $text
	 * @param string $context
	 * @param string $domain
	 */
	public static function core_fallback_with_context( $translation, $text, $context, $domain ): string {
		return self::core_fallback( $translation, $text, $domain );
	}

	/** ja/en selector (SCR-A01) below the login box; keeps the current action and redirect target. */
	public static function selector(): void {
		$current = self::choice();
		$links   = [];
		foreach ( [ 'ja' => [ 'ja', '日本語' ], 'en' => [ 'en_US', 'English' ] ] as $code => [ $wp_lang, $label ] ) {
			$url = add_query_arg( 'wp_lang', $wp_lang );
			$links[] = $code === $current
				? '<a href="' . esc_url( $url ) . '" lang="' . esc_attr( $code ) . '" aria-current="true">' . esc_html( $label ) . '</a>'
				: '<a href="' . esc_url( $url ) . '" lang="' . esc_attr( $code ) . '" hreflang="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<nav class="momos-login-lang" aria-label="' . esc_attr( momos_t( 'admin.login.lang_label', [], $current ) ) . '" style="text-align:center;margin-block:16px 0;">'
			. implode( ' <span aria-hidden="true">|</span> ', $links ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			. '</nav>';
	}
}
