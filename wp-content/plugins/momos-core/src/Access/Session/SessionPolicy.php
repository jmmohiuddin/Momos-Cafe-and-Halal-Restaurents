<?php
declare(strict_types=1);

namespace Momos\Access\Session;

use Momos\Kernel\Clock\Clock;

/**
 * Session policy (SEC-005, FR-ADM-006/008, TC-SEC-024/019; spec §5.5):
 *  - absolute lifetime ⟦14 h⟧ for every login (auth_cookie_expiration), "Remember me" hidden and ignored;
 *  - idle timeout ⟦8 h⟧ via session meta `momo_last_activity` (refreshed at most every 5 min);
 *  - all sessions destroyed on role change, branch-assignment change, disable, password reset and
 *    2FA reset; the next request of a revoked session shows the login page;
 *  - auth cookies re-emitted with SameSite=Lax (HttpOnly; Secure on HTTPS).
 */
final class SessionPolicy {
	public const ABSOLUTE = 14 * HOUR_IN_SECONDS;
	public const IDLE     = 8 * HOUR_IN_SECONDS;
	public const TOUCH    = 300;

	public static function register(): void {
		add_filter( 'auth_cookie_expiration', [ self::class, 'expiration' ], 99 );
		add_filter( 'attach_session_information', [ self::class, 'attach' ], 10, 2 );
		add_action( 'login_head', [ self::class, 'hide_remember_me' ] );
		add_action( 'login_init', [ self::class, 'ignore_remember_me' ], 1 );
		add_filter( 'login_form_defaults', static fn( $d ) => array_merge( (array) $d, [ 'remember' => false ] ) );
		add_action( 'admin_init', [ self::class, 'enforce_admin' ], 1 );
		add_filter( 'rest_authentication_errors', [ self::class, 'enforce_rest' ], 90 );

		// Revocation triggers (FR-ADM-008).
		add_action( 'set_user_role', [ self::class, 'on_role_change' ], 10, 3 );
		add_action( 'add_user_role', [ self::class, 'on_role_change' ], 10, 1 );
		add_action( 'remove_user_role', [ self::class, 'on_role_change' ], 10, 1 );
		add_action( 'momos/user/assignment_changed', [ self::class, 'destroy_all' ], 10, 1 );
		add_action( 'after_password_reset', [ self::class, 'on_password_reset' ], 10, 1 );
		add_action( 'profile_update', [ self::class, 'on_profile_update' ], 10, 2 );

		// SameSite=Lax cookies (core cannot set the attribute).
		add_action( 'set_auth_cookie', [ self::class, 'emit_auth_cookie' ], 10, 6 );
		add_action( 'set_logged_in_cookie', [ self::class, 'emit_logged_in_cookie' ], 10, 6 );
		add_filter( 'send_auth_cookies', [ self::class, 'suppress_core_cookies' ], 10, 4 );
	}

	public static function expiration(): int {
		return self::ABSOLUTE;
	}

	/** @param array<string,mixed> $session */
	public static function attach( $session, $user_id ): array {
		$session                       = (array) $session;
		$session['momo_last_activity'] = Clock::utc()->getTimestamp();
		if ( \Momos\Access\TwoFactor\LoginFlow::passing() ) {
			$session['momo_2fa'] = 1;
		}
		return $session;
	}

	public static function ignore_remember_me(): void {
		unset( $_POST['rememberme'], $_REQUEST['rememberme'] ); // phpcs:ignore
	}

	public static function hide_remember_me(): void {
		echo '<style>.forgetmenot{display:none!important}</style>';
	}

	/** Destroy every session of a user (WP_Session_Tokens::destroy_all). */
	public static function destroy_all( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
	}

	/** @param list<string> $old_roles */
	public static function on_role_change( $user_id, $role = '', $old_roles = [] ): void {
		if ( 'set_user_role' === current_action() && [ $role ] === array_values( (array) $old_roles ) ) {
			return; // No actual change.
		}
		self::destroy_all( (int) $user_id );
	}

	public static function on_password_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			self::destroy_all( (int) $user->ID );
			\Momos\Access\AuditTrail::for_user( (int) $user->ID, 'auth.password_reset', 'user', (int) $user->ID );
		}
	}

	/** Password changed in the profile: other sessions end (own current session kept). */
	public static function on_profile_update( $user_id, $old_user ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! $old_user instanceof \WP_User || $user->user_pass === $old_user->user_pass ) {
			return;
		}
		$manager = \WP_Session_Tokens::get_instance( (int) $user_id );
		if ( get_current_user_id() === (int) $user_id && '' !== wp_get_session_token() ) {
			$manager->destroy_others( wp_get_session_token() );
		} else {
			$manager->destroy_all();
		}
	}

	// ----------------------------------------------------------------- idle timeout + 2FA gate

	/**
	 * Check the current cookie session: account still active (not disabled, access window
	 * `momo_access_expires_at` not passed — TC-ADM-008, spec §5.1 step 2 / §5.6), idle timeout and the
	 * second-factor flag. Returns an error code ('inactive'|'idle'|'2fa') when the session must end,
	 * else null (and refreshes last activity).
	 */
	public static function check_current(): ?string {
		$user_id = get_current_user_id();
		$token   = wp_get_session_token();
		if ( ! $user_id || '' === $token ) {
			return null;
		}
		if ( \Momos\Access\AccountStatus::is_inactive( $user_id ) ) {
			return 'inactive'; // Does not wait for momos_job_access_expiry.
		}
		$manager = \WP_Session_Tokens::get_instance( $user_id );
		$session = $manager->get( $token );
		if ( ! is_array( $session ) ) {
			return null;
		}
		$now  = Clock::utc()->getTimestamp();
		$last = (int) ( $session['momo_last_activity'] ?? $session['login'] ?? $now );
		if ( $now - $last > self::IDLE ) {
			return 'idle';
		}
		if ( \Momos\Access\TwoFactor\TwoFactor::required( $user_id ) && empty( $session['momo_2fa'] ) ) {
			return '2fa';
		}
		if ( $now - $last >= self::TOUCH ) {
			$session['momo_last_activity'] = $now;
			$manager->update( $token, $session );
		}
		return null;
	}

	private static function end_current_session( string $reason = '' ): void {
		if ( 'inactive' === $reason ) {
			self::destroy_all( get_current_user_id() ); // Disabled/expired: every session ends (§5.5).
		}
		wp_destroy_current_session();
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
	}

	public static function enforce_admin(): void {
		$reason = self::check_current();
		if ( null === $reason ) {
			return;
		}
		self::end_current_session( $reason );
		if ( wp_doing_ajax() ) {
			wp_die( '-1', '', [ 'response' => 401 ] );
		}
		$url = add_query_arg( 'reauth', '1', wp_login_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : admin_url() ) ); // phpcs:ignore
		wp_safe_redirect( $url );
		exit;
	}

	/** @param \WP_Error|null|true $result */
	public static function enforce_rest( $result ) {
		if ( null !== $result || ! is_user_logged_in() ) {
			return $result;
		}
		$reason = self::check_current();
		if ( null !== $reason ) {
			self::end_current_session( $reason );
			return new \WP_Error( 'rest_forbidden', __( 'Your session has ended. Please log in again.', 'momos-core' ), [ 'status' => 401 ] );
		}
		return $result;
	}

	// ----------------------------------------------------------------- SameSite cookies

	/** Suppress core's Set-Cookie for logins only (logout/clear keeps core behaviour). */
	public static function suppress_core_cookies( $send, $expire = 0, $expiration = 0, $user_id = 0 ): bool {
		if ( (int) $user_id > 0 && ! headers_sent() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		return (bool) $send;
	}

	private static function set( string $name, string $value, int $expire, string $path, bool $secure ): void {
		if ( headers_sent() || ( defined( 'WP_CLI' ) && WP_CLI ) || '' === $path ) {
			return;
		}
		setcookie( $name, $value, [ 'expires' => $expire, 'path' => $path, 'domain' => (string) COOKIE_DOMAIN, 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax' ] );
	}

	public static function emit_auth_cookie( $cookie, $expire, $expiration, $user_id, $scheme, $token = '' ): void {
		$secure = 'secure_auth' === $scheme;
		$name   = $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
		self::set( $name, (string) $cookie, (int) $expire, PLUGINS_COOKIE_PATH, $secure );
		self::set( $name, (string) $cookie, (int) $expire, ADMIN_COOKIE_PATH, $secure );
	}

	public static function emit_logged_in_cookie( $cookie, $expire, $expiration, $user_id, $scheme = 'logged_in', $token = '' ): void {
		$secure = is_ssl() && 'https' === wp_parse_url( get_option( 'home' ), PHP_URL_SCHEME );
		$secure = (bool) apply_filters( 'secure_logged_in_cookie', $secure, (int) $user_id, is_ssl() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook (mirrors wp_set_auth_cookie()).
		self::set( LOGGED_IN_COOKIE, (string) $cookie, (int) $expire, COOKIEPATH, $secure );
		if ( COOKIEPATH !== SITECOOKIEPATH ) {
			self::set( LOGGED_IN_COOKIE, (string) $cookie, (int) $expire, SITECOOKIEPATH, $secure );
		}
	}
}
