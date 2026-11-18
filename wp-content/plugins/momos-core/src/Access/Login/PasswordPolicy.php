<?php
declare(strict_types=1);

namespace Momos\Access\Login;

/**
 * Password policy (SEC-003, TC-SEC-023; spec §5.4): 12–128 characters, no composition rules, no
 * forced rotation; common/breached passwords rejected from a bundled offline list (no network);
 * reset links valid 1 hour. Enforced on profile update, user creation and password reset.
 */
final class PasswordPolicy {
	public const MIN = 12;
	public const MAX = 128;

	/** Offline baseline of the most common passwords (lower-case; ≥ 12 characters only matter). */
	private const COMMON = [
		'123456789012', '1234567890123', '12345678901234', '123456123456', '1q2w3e4r5t6y', '1qaz2wsx3edc', 'qwertyuiopas',
		'qwertyuiop123', 'qwerty123456', 'password1234', 'password12345', 'password123!', 'passwordpassword', 'p@ssw0rd1234',
		'iloveyou1234', 'abc123456789', 'abcdefghijkl', 'aaaaaaaaaaaa', '111111111111', '000000000000', 'letmein12345',
		'welcome12345', 'welcome123456', 'administrator', 'administrator1', 'admin1234567', 'adminadmin123', 'changeme1234',
		'trustno112345', 'football1234', 'baseball1234', 'superman1234', 'sunshine1234', 'princess1234', 'qwerty1234567',
		'monkey123456', 'dragon123456', 'master123456', 'shadow123456', 'michael12345', 'jennifer1234', 'whatever1234',
		'1234567890qwerty', 'qwertyuiopasdf', 'zaq12wsxcde3', '!qaz2wsx#edc', 'asdfghjkl123', 'zxcvbnm12345', 'momos1234567',
		'momoscafe123', 'momoscafe2026', 'halal1234567', 'restaurant123', 'password2026', 'password2025', 'summer2026!!',
	];

	public static function register(): void {
		add_action( 'user_profile_update_errors', [ self::class, 'on_profile_errors' ], 10, 3 );
		add_action( 'validate_password_reset', [ self::class, 'on_reset' ], 10, 2 );
		add_filter( 'password_reset_expiration', static fn() => HOUR_IN_SECONDS );
	}

	/** Error key or null. */
	public static function check( string $password ): ?string {
		$len = mb_strlen( $password );
		if ( $len < self::MIN || $len > self::MAX ) {
			return 'admin.pw.err.length';
		}
		$lower = mb_strtolower( $password );
		if ( in_array( $lower, self::COMMON, true ) || 1 === count( array_unique( mb_str_split( $lower ) ) ) ) {
			return 'admin.pw.err.breached';
		}
		return null;
	}

	/** @param \WP_Error $errors */
	public static function on_profile_errors( $errors, $update, $user ): void {
		$pass = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore -- core verified the nonce.
		if ( '' === $pass ) {
			return;
		}
		$err = self::check( $pass );
		if ( null !== $err ) {
			$errors->add( 'momos_password', esc_html( momos_t( $err, [], LoginThrottle::lang() ) ), [ 'form-field' => 'pass1' ] );
		}
	}

	/** @param \WP_Error $errors */
	public static function on_reset( $errors, $user ): void {
		$pass = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore
		if ( '' === $pass ) {
			return;
		}
		$err = self::check( $pass );
		if ( null !== $err ) {
			$errors->add( 'momos_password', esc_html( momos_t( $err, [], LoginThrottle::lang() ) ) );
		}
	}
}
