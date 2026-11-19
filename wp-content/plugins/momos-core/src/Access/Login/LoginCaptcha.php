<?php
declare(strict_types=1);

namespace Momos\Access\Login;

use Momos\Api\Captcha\TurnstileVerifier;
use Momos\Kernel\Http\RequestContext;
use Momos\Kernel\Log\Logger;

/**
 * Captcha step of the staff login (access spec §5.3 `Access\LoginCaptcha`, SDD §6 "Turnstile on the login
 * form after 3 failures from an IP", FR-SEC-002, SEC-004): from the 3rd failed login (password or 2FA)
 * per IP within 15 min the password form shows a Cloudflare Turnstile widget (managed mode, WCAG 3.3.8)
 * and a login POST is refused unless its token verifies (action `staff_login`, `TurnstileVerifier`).
 *
 *  - The captcha decision does not depend on the password: a missing/invalid token gives the same
 *    captcha error for right and wrong passwords (no password oracle). A lockout message still wins.
 *  - Below the threshold the login screen loads no third-party script (PRV-024).
 *  - Outage (siteverify unreachable) → the login is not blocked (lockout limits still apply), logged.
 *  - Test mode (non-production `MOMOS_TURNSTILE_MODE=test`): no network; the form carries a test token.
 */
final class LoginCaptcha {
	public const THRESHOLD = 3;
	public const ACTION    = 'staff_login';
	public const FIELD     = 'cf-turnstile-response';
	public const SCRIPT    = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

	private static bool $failed = false;

	public static function register(): void {
		add_filter( 'authenticate', [ self::class, 'authenticate' ], 100, 3 );
		add_action( 'login_form', [ self::class, 'render' ] );
		add_action( 'login_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_filter( 'login_errors', [ self::class, 'login_errors' ], 100 );
	}

	/** Is the captcha required for this client (≥ THRESHOLD failures per IP within the window)? */
	public static function required( ?string $ip = null ): bool {
		$ip = $ip ?? RequestContext::ip();
		return null !== $ip && '' !== $ip && LoginThrottle::recent_ip_failures( $ip ) >= self::THRESHOLD;
	}

	/** Only real login-form posts are checked (not the empty sign-on of a GET, not cookie re-auth). */
	private static function is_login_post(): bool {
		return 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['log'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Priority 100 (after LoginThrottle at 99).
	 *
	 * @param \WP_User|\WP_Error|null $user
	 * @return \WP_User|\WP_Error|null
	 */
	public static function authenticate( $user, $username = '', $password = '' ) {
		if ( ! self::is_login_post() || ( $user instanceof \WP_Error && 'momos_locked' === $user->get_error_code() ) ) {
			return $user;
		}
		if ( '' === trim( (string) $username ) && '' === (string) $password ) {
			return $user;
		}
		if ( ! self::required() ) {
			return $user;
		}
		$token  = isset( $_POST[ self::FIELD ] ) && is_string( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$result = '' === $token ? TurnstileVerifier::FAILED : TurnstileVerifier::verify( substr( $token, 0, 2048 ), self::ACTION );
		if ( TurnstileVerifier::OUTAGE === $result ) {
			Logger::warning( 'security.login_captcha_outage' );
			return $user;
		}
		if ( TurnstileVerifier::VERIFIED === $result ) {
			return $user;
		}
		self::$failed = true;
		return new \WP_Error( 'momos_captcha', self::message() );
	}

	public static function message(): string {
		return momos_t( 'admin.login.err.captcha', [], LoginThrottle::lang() );
	}

	/** The captcha error replaces the generic error on that response. */
	public static function login_errors( $html ): string {
		return self::$failed ? esc_html( self::message() ) : (string) $html;
	}

	public static function render(): void {
		if ( ! self::required() ) {
			return;
		}
		$lang = LoginThrottle::lang();
		echo '<div class="cf-turnstile" data-sitekey="' . esc_attr( TurnstileVerifier::site_key() ) . '" data-action="' . esc_attr( self::ACTION ) . '" data-language="' . esc_attr( $lang ) . '" data-appearance="interaction-only" style="margin-block-end:16px;"></div>';
		if ( 'test' === TurnstileVerifier::mode() ) {
			echo '<input type="hidden" name="' . esc_attr( self::FIELD ) . '" value="test-pass" />';
		}
	}

	public static function enqueue(): void {
		if ( 'live' === TurnstileVerifier::mode() && '' !== TurnstileVerifier::site_key() && self::required() ) {
			wp_enqueue_script( 'momos-login-turnstile', self::SCRIPT, [], null, [ 'strategy' => 'async' ] ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party URL must not carry ?ver.
		}
	}

	/** Reset request-static state (tests). */
	public static function reset_state(): void {
		self::$failed = false;
	}
}
