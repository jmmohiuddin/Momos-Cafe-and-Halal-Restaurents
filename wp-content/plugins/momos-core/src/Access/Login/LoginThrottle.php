<?php
declare(strict_types=1);

namespace Momos\Access\Login;

use Momos\Access\AuditTrail;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Http\RequestContext;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Settings;
use Momos\Kernel\Support\Hash;

/**
 * Login throttling and lockout (SEC-004 canonical values, FR-SEC-002, AC-SEC-01-02; spec §5.3):
 *  - `login_ip`:   ⟦10⟧ failures (password or 2FA) per IP within 15 min → IP locked ⟦60 min⟧;
 *  - `login_user`: ⟦10⟧ failures per existing account within 15 min → account locked ⟦60 min⟧ from any IP;
 *  - invalid username → IP locked immediately when `momo_login_lock_invalid_username` (default on);
 *  - `lostpw_ip`:  ⟦5⟧ lost-password requests per IP per hour → generic confirmation, no email;
 *  - lost-password form: the same confirmation for unknown and existing accounts (no enumeration
 *    oracle, TC-SEC-003, spec §5.3) — `uniform_lostpassword()`.
 * Buckets are keyed by HMAC("rl", subject) — raw IPs are never stored. One generic error for unknown
 * user, wrong password, disabled and expired accounts; the lockout message states when to retry.
 */
final class LoginThrottle {
	public const MAX_FAILURES        = 10;
	public const WINDOW              = 900;
	public const LOCK_SECONDS        = 3600;
	public const LOSTPW_PER_HOUR     = 5;
	public const SITE_ALERT_PER_HOUR = 20;

	private static ?int $locked_until   = null;
	private static bool $lostpw_blocked = false;

	public static function register(): void {
		add_filter( 'authenticate', [ self::class, 'authenticate' ], 99, 3 );
		add_action( 'wp_login_failed', [ self::class, 'on_failed' ], 10, 2 );
		add_filter( 'login_errors', [ self::class, 'login_errors' ], 99 );
		add_action( 'lostpassword_post', [ self::class, 'on_lostpassword' ], 10, 2 );
		add_filter( 'send_retrieve_password_email', [ self::class, 'allow_reset_email' ], 99 );
		add_action( 'login_form_lostpassword', [ self::class, 'uniform_lostpassword' ] );
		add_action( 'login_form_retrievepassword', [ self::class, 'uniform_lostpassword' ] );
	}

	// ----------------------------------------------------------------- buckets

	private static function key( string $bucket, string $subject ): string {
		return 'momos_lt_' . $bucket . '_' . substr( Hash::hmac( 'rl|' . $subject ), 0, 32 );
	}

	/** @return array{fails:list<int>,locked_until:int} */
	private static function load( string $bucket, string $subject ): array {
		$v = get_transient( self::key( $bucket, $subject ) );
		return is_array( $v ) ? [ 'fails' => array_map( 'intval', (array) ( $v['fails'] ?? [] ) ), 'locked_until' => (int) ( $v['locked_until'] ?? 0 ) ] : [ 'fails' => [], 'locked_until' => 0 ];
	}

	/** @param array{fails:list<int>,locked_until:int} $v */
	private static function store( string $bucket, string $subject, array $v ): void {
		set_transient( self::key( $bucket, $subject ), $v, self::LOCK_SECONDS + self::WINDOW );
	}

	private static function now(): int {
		return Clock::utc()->getTimestamp();
	}

	/** Lock expiry (unix) for the user and/or IP, or null when not locked. */
	public static function locked_until( ?int $user_id, ?string $ip ): ?int {
		$until = 0;
		if ( null !== $ip && '' !== $ip ) {
			$until = max( $until, self::load( 'ip', $ip )['locked_until'] );
		}
		if ( $user_id ) {
			$until = max( $until, self::load( 'user', (string) $user_id )['locked_until'] );
		}
		return $until > self::now() ? $until : null;
	}

	/**
	 * Record a failure (password, 2FA code, disabled/expired) and lock when a limit is reached.
	 * Returns the lock expiry when this failure caused or met a lock.
	 */
	public static function fail( ?int $user_id, ?string $ip, bool $invalid_username = false ): ?int {
		$now     = self::now();
		$locked  = null;
		$targets = [];
		if ( null !== $ip && '' !== $ip ) {
			$targets[] = [ 'ip', $ip ];
		}
		if ( $user_id ) {
			$targets[] = [ 'user', (string) $user_id ];
		}
		foreach ( $targets as [ $bucket, $subject ] ) {
			$v            = self::load( $bucket, $subject );
			$v['fails']   = array_values( array_filter( $v['fails'], static fn( int $t ) => $t > $now - self::WINDOW ) );
			$v['fails'][] = $now;
			$v['fails']   = array_slice( $v['fails'], -self::MAX_FAILURES );
			$immediate    = 'ip' === $bucket && $invalid_username && self::lock_invalid_usernames();
			if ( $v['locked_until'] <= $now && ( $immediate || count( $v['fails'] ) >= self::MAX_FAILURES ) ) {
				$v['locked_until'] = $now + self::LOCK_SECONDS;
				$locked            = $v['locked_until'];
				self::on_lock( $bucket, 'user' === $bucket ? (int) $subject : null, $v['locked_until'] );
			}
			self::store( $bucket, $subject, $v );
		}
		self::count_site_failure();
		return $locked;
	}

	/** Failures from an IP within the 15-min window (captcha step, `LoginCaptcha`). */
	public static function recent_ip_failures( string $ip ): int {
		$now = self::now();
		return count( array_filter( self::load( 'ip', $ip )['fails'], static fn( int $t ) => $t > $now - self::WINDOW ) );
	}

	/** Successful login clears the account bucket (the IP bucket expires on its own). */
	public static function success( int $user_id ): void {
		delete_transient( self::key( 'user', (string) $user_id ) );
	}

	public static function lock_invalid_usernames(): bool {
		return '1' === (string) Settings::get( 'momo_login_lock_invalid_username', '1' );
	}

	private static function on_lock( string $bucket, ?int $user_id, int $until ): void {
		AuditTrail::for_user( $user_id, 'auth.lockout', 'auth', $user_id, null, [ 'bucket' => 'login_' . $bucket, 'until' => gmdate( 'Y-m-d H:i:s', $until ) ] );
		$privileged = $user_id && array_intersect( [ 'administrator', 'momo_head_office' ], (array) ( get_userdata( $user_id )->roles ?? [] ) );
		if ( $privileged ) {
			Logger::warning( 'security.privileged_lockout', [ 'user_id' => $user_id ] );
			do_action( 'momos/monitoring/alert', 'privileged_lockout', [ 'user_id' => $user_id ] );
		}
	}

	private static function count_site_failure(): void {
		$key = 'momos_lt_site_' . Clock::utc()->format( 'YmdH' );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, 2 * HOUR_IN_SECONDS );
		if ( self::SITE_ALERT_PER_HOUR + 1 === $n ) {
			Logger::warning( 'security.failed_logins_threshold', [ 'count' => $n ] );
			do_action( 'momos/monitoring/alert', 'failed_logins', [ 'count' => $n ] );
		}
	}

	// ----------------------------------------------------------------- hooks

	/**
	 * Priority 99, after the password check: locked IP/account → refused even with a correct
	 * password; disabled or expired account → the same generic error (spec §5.1 step 2).
	 *
	 * @param \WP_User|\WP_Error|null $user
	 * @return \WP_User|\WP_Error|null
	 */
	public static function authenticate( $user, $username, $password ) {
		$candidate = $user instanceof \WP_User ? $user : self::lookup( (string) $username );
		$until     = self::locked_until( $candidate ? (int) $candidate->ID : null, RequestContext::ip() );
		if ( null !== $until ) {
			self::$locked_until = $until;
			return new \WP_Error( 'momos_locked', self::locked_message( $until ) );
		}
		if ( $user instanceof \WP_User && \Momos\Access\AccountStatus::is_inactive( (int) $user->ID ) ) {
			AuditTrail::for_user( (int) $user->ID, 'auth.disabled_login', 'user', (int) $user->ID, null, [ 'state' => \Momos\Access\AccountStatus::is_disabled( (int) $user->ID ) ? 'disabled' : 'expired' ] );
			return new \WP_Error( 'momos_inactive', self::generic_message() );
		}
		return $user;
	}

	/** @param \WP_Error|null $error */
	public static function on_failed( $username, $error = null ): void {
		if ( $error instanceof \WP_Error && 'momos_locked' === $error->get_error_code() ) {
			return; // Already locked; not counted again.
		}
		$user    = self::lookup( (string) $username );
		$unknown = null === $user && '' !== trim( (string) $username );
		$until   = self::fail( $user ? (int) $user->ID : null, RequestContext::ip(), $unknown );
		if ( null !== $until && ! $unknown ) {
			// An unknown-username lock is not announced on that same response (no username oracle).
			self::$locked_until = $until;
		}
		// Never the typed username (SEC-081): only the account id when it exists.
		AuditTrail::for_user( $user ? (int) $user->ID : null, 'auth.login_failed', 'auth', $user ? (int) $user->ID : null, null, [ 'reason' => $user ? 'credentials' : 'unknown_account' ] );
	}

	public static function login_errors( $html ): string {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore
		if ( ! in_array( $action, [ 'login', '' ], true ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore
			return (string) $html;
		}
		if ( '' === trim( wp_strip_all_tags( (string) $html ) ) ) {
			return (string) $html;
		}
		return esc_html( null !== self::$locked_until ? self::locked_message( self::$locked_until ) : self::generic_message() );
	}

	public static function generic_message(): string {
		return momos_t( 'admin.login.err.generic', [], self::lang() );
	}

	public static function locked_message( int $until ): string {
		$time = ( new \DateTimeImmutable( '@' . $until ) )->setTimezone( Clock::tz() )->format( 'H:i' );
		return momos_t( 'admin.login.err.locked', [ 'time' => $time . ' JST' ], self::lang() );
	}

	/** Admin/login language: ja or en (DEC-037). */
	public static function lang(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return str_starts_with( (string) $locale, 'ja' ) ? 'ja' : 'en';
	}

	private static function lookup( string $username ): ?\WP_User {
		$username = trim( $username );
		if ( '' === $username ) {
			return null;
		}
		$u = is_email( $username ) ? get_user_by( 'email', $username ) : get_user_by( 'login', $username );
		return $u instanceof \WP_User ? $u : null;
	}

	/** @param \WP_Error $errors */
	public static function on_lostpassword( $errors, $user_data = null ): void {
		$ip = RequestContext::ip();
		if ( null === $ip ) {
			return;
		}
		$key = 'momos_lt_lostpw_' . substr( Hash::hmac( 'rl|' . $ip ), 0, 32 ) . '_' . Clock::utc()->format( 'YmdH' );
		$n   = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, 2 * HOUR_IN_SECONDS );
		if ( $n > self::LOSTPW_PER_HOUR ) {
			self::$lostpw_blocked = true;
			Logger::warning( 'security.rate_limited', [ 'bucket' => 'lostpw_ip' ] );
		}
	}

	/**
	 * wp-login.php?action=lostpassword POST: process the request ourselves and always answer with core's
	 * confirmation redirect, whether the account exists or not and whether the e-mail could be sent
	 * (TC-SEC-003, FR-SEC-002, spec §5.3). An empty field keeps core's "enter a username" message.
	 */
	public static function uniform_lostpassword(): void {
		$target = self::lostpassword_outcome();
		if ( null !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/** Redirect target after a lost-password POST, or null when core should handle the request. */
	public static function lostpassword_outcome(): ?string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['user_login'] ) || ! is_string( $_POST['user_login'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return null;
		}
		$login = trim( wp_unslash( $_POST['user_login'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- core sanitises in retrieve_password().
		if ( '' === $login ) {
			return null;
		}
		$result = retrieve_password( $login );
		if ( is_wp_error( $result ) ) {
			// Error code only (never the typed login, SEC-081).
			Logger::info( 'auth.lostpassword_not_sent', [ 'code' => $result->get_error_code() ] );
		}
		$redirect = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? trim( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- validated by wp_safe_redirect().
		return '' !== $redirect ? $redirect : 'wp-login.php?checkemail=confirm';
	}

	public static function allow_reset_email( $send ): bool {
		return (bool) $send && ! self::$lostpw_blocked;
	}

	/** Reset request-static state (tests). */
	public static function reset_state(): void {
		self::$locked_until   = null;
		self::$lostpw_blocked = false;
	}
}
