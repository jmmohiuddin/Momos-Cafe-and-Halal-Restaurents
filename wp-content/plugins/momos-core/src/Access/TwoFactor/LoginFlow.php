<?php
declare(strict_types=1);

namespace Momos\Access\TwoFactor;

use Momos\Access\Login\LoginThrottle;
use Momos\Kernel\Http\RequestContext;

/**
 * Second login step in wp-login.php (SCR-A01, WF-ADM-01; Access spec §5.1/§5.2):
 *
 * 1. After the password check (`authenticate` priority 100) a user who needs 2FA gets no cookie:
 *    a challenge is stored and the code form (or the enrolment wizard) is rendered instead.
 * 2. `wp-login.php?action=momos_2fa` (POST, token in a hidden field) verifies the TOTP code or a
 *    single-use recovery code; ≤ 5 wrong codes per challenge, each counted as a login failure.
 * 3. Enrolment: key + local QR → current code → 10 recovery codes shown once → "I have saved
 *    these codes" → login completes. Grace period 0.
 * 4. Success: auth cookie (not persistent), session flag `momo_2fa = 1`, `wp_login` fired,
 *    role-aware redirect (`redirect_to` preserved).
 */
final class LoginFlow {
	private static bool $passing = false;

	public static function register(): void {
		add_filter( 'authenticate', [ self::class, 'intercept' ], 100, 3 );
		add_action( 'login_form_momos_2fa', [ self::class, 'handle' ] );
		add_filter( 'login_redirect', [ self::class, 'login_redirect' ], 10, 3 );
		add_filter( 'wp_login_errors', [ self::class, 'login_page_errors' ] );
	}

	/** Messages after an aborted second step (?momos_2fa=expired|locked|too_many). @param \WP_Error $errors */
	public static function login_page_errors( $errors ) {
		$reason = isset( $_GET['momos_2fa'] ) ? sanitize_key( wp_unslash( (string) $_GET['momos_2fa'] ) ) : ''; // phpcs:ignore
		$keys   = [ 'expired' => 'admin.2fa.err.expired', 'too_many' => 'admin.2fa.err.too_many', 'locked' => 'admin.login.err.locked_generic' ];
		if ( isset( $keys[ $reason ] ) && $errors instanceof \WP_Error ) {
			$errors->add( 'momos_2fa', esc_html( momos_t( $keys[ $reason ], [], LoginThrottle::lang() ) ) );
		}
		return $errors;
	}

	/** True while the second factor has just been verified (session flag). */
	public static function passing(): bool {
		return self::$passing;
	}

	private static function in_login_form(): bool {
		return 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['log'] ); // phpcs:ignore
	}

	/**
	 * @param \WP_User|\WP_Error|null $user
	 * @return \WP_User|\WP_Error|null
	 */
	public static function intercept( $user, $username = '', $password = '' ) {
		if ( ! $user instanceof \WP_User || ! self::in_login_form() || ! TwoFactor::required( (int) $user->ID ) ) {
			return $user;
		}
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore
		$enrol    = ! TwoFactor::is_enrolled( (int) $user->ID );
		$token    = Challenge::create( (int) $user->ID, $redirect, $enrol );
		self::render( (int) $user->ID, $token, $enrol ? 'enrol' : 'code' );
		exit;
	}

	/** POST wp-login.php?action=momos_2fa */
	public static function handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore
			wp_safe_redirect( wp_login_url() );
			exit;
		}
		$uid   = isset( $_POST['momos_uid'] ) ? (int) $_POST['momos_uid'] : 0; // phpcs:ignore -- challenge token is the CSRF/state guard.
		$token = isset( $_POST['momos_2fa_token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['momos_2fa_token'] ) ) : ''; // phpcs:ignore
		$step  = isset( $_POST['momos_step'] ) ? sanitize_key( wp_unslash( (string) $_POST['momos_step'] ) ) : 'verify'; // phpcs:ignore
		$chal  = Challenge::get( $uid, $token );
		if ( null === $chal ) {
			self::back_to_login( 'expired' );
		}
		$locked = LoginThrottle::locked_until( $uid, RequestContext::ip() );
		if ( null !== $locked ) {
			Challenge::delete( $uid );
			self::back_to_login( 'locked' );
		}
		$result = self::process( $uid, $chal, $step, $_POST ); // phpcs:ignore
		if ( 'done' === $result['state'] ) {
			self::finish( $uid, (string) ( $chal['redirect_to'] ?? '' ) );
		}
		if ( 'restart' === $result['state'] ) {
			self::back_to_login( 'too_many' );
		}
		self::render( $uid, $token, (string) $result['state'], (string) ( $result['error'] ?? '' ), (array) ( $result['codes'] ?? [] ) );
		exit;
	}

	/**
	 * Pure step logic (no output) — unit-testable.
	 *
	 * @param array<string,mixed> $chal
	 * @param array<string,mixed> $post
	 * @return array{state:string,error?:string,codes?:list<string>}
	 */
	public static function process( int $uid, array $chal, string $step, array $post ): array {
		$code     = isset( $post['momos_code'] ) ? sanitize_text_field( wp_unslash( (string) $post['momos_code'] ) ) : '';
		$recovery = isset( $post['momos_recovery'] ) ? sanitize_text_field( wp_unslash( (string) $post['momos_recovery'] ) ) : '';

		if ( 'enrol_done' === $step && 'codes' === ( $chal['stage'] ?? '' ) ) {
			if ( empty( $post['momos_saved'] ) ) {
				// Codes are shown only once: issue a fresh set rather than re-displaying old ones.
				Challenge::save( $uid, $chal );
				return [ 'state' => 'codes', 'error' => 'admin.2fa.err.confirm_saved', 'codes' => TwoFactor::new_recovery_codes( $uid ) ];
			}
			return [ 'state' => 'done' ];
		}

		if ( 'enrol' === ( $chal['stage'] ?? '' ) ) {
			$secret = TwoFactor::decrypt( (string) ( $chal['pending'] ?? '' ) );
			$codes  = null !== $secret ? TwoFactor::enrol( $uid, $secret, $code ) : null;
			if ( null === $codes ) {
				return self::wrong( $uid, $chal, 'enrol' );
			}
			$chal['stage']   = 'codes';
			$chal['pending'] = '';
			Challenge::save( $uid, $chal );
			return [ 'state' => 'codes', 'codes' => $codes ];
		}

		if ( 'code' === ( $chal['stage'] ?? '' ) ) {
			$ok = '' !== $recovery ? TwoFactor::use_recovery_code( $uid, $recovery ) : TwoFactor::verify_code( $uid, $code );
			return $ok ? [ 'state' => 'done' ] : self::wrong( $uid, $chal, 'code' );
		}
		return [ 'state' => 'restart' ];
	}

	/**
	 * @param array<string,mixed> $chal
	 * @return array{state:string,error?:string}
	 */
	private static function wrong( int $uid, array $chal, string $stage ): array {
		$chal['attempts'] = (int) ( $chal['attempts'] ?? 0 ) + 1;
		LoginThrottle::fail( $uid, RequestContext::ip() );
		\Momos\Access\AuditTrail::for_user( $uid, 'auth.login_failed', 'auth', $uid, null, [ 'reason' => 'second_factor' ] );
		if ( $chal['attempts'] >= Challenge::MAX_ATTEMPTS ) {
			Challenge::delete( $uid );
			return [ 'state' => 'restart' ];
		}
		update_user_meta( $uid, Challenge::META, (string) wp_json_encode( $chal ) );
		return [ 'state' => $stage, 'error' => 'admin.2fa.err.invalid' ];
	}

	/** Set the auth cookie (not persistent), flag the session and redirect. */
	private static function finish( int $uid, string $redirect_to ): void {
		Challenge::delete( $uid );
		$user = get_userdata( $uid );
		if ( ! $user ) {
			self::back_to_login( 'expired' );
		}
		self::complete_login( $user );
		$requested = '' !== $redirect_to ? $redirect_to : '';
		$target    = (string) apply_filters( 'login_redirect', '' !== $requested ? $requested : admin_url(), $requested, $user );
		wp_safe_redirect( wp_validate_redirect( $target, admin_url() ) );
		exit;
	}

	/** Shared by finish() and tests: cookie + session flag + wp_login. */
	public static function complete_login( \WP_User $user ): void {
		LoginThrottle::success( (int) $user->ID );
		self::$passing = true;
		try {
			wp_set_auth_cookie( (int) $user->ID, false, is_ssl() );
		} finally {
			self::$passing = false;
		}
		wp_set_current_user( (int) $user->ID );
		do_action( 'wp_login', $user->user_login, $user );
	}

	private static function back_to_login( string $reason ): never {
		wp_safe_redirect( add_query_arg( 'momos_2fa', $reason, wp_login_url() ) );
		exit;
	}

	/**
	 * Default landing page by role (spec §5.1 step 5): RS/BM → pending requests of their branches;
	 * others → dashboard; an explicit redirect_to wins.
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof \WP_User || '' !== (string) $requested ) {
			return $redirect_to;
		}
		if ( array_intersect( [ 'momo_branch_manager', 'momo_reservation_staff' ], $user->roles ) ) {
			return admin_url( 'admin.php?page=momos-reservations&status=pending' );
		}
		return admin_url();
	}

	// ----------------------------------------------------------------- rendering

	/** @param list<string> $codes */
	public static function render( int $uid, string $token, string $stage, string $error = '', array $codes = [] ): void {
		$lang   = LoginThrottle::lang();
		$user   = get_userdata( $uid );
		$qr     = '';
		$secret = '';
		if ( 'enrol' === $stage && $user ) {
			$data = json_decode( (string) get_user_meta( $uid, Challenge::META, true ), true );
			$bin  = TwoFactor::decrypt( (string) ( $data['pending'] ?? '' ) );
			if ( null !== $bin ) {
				$secret = Totp::display( $bin );
				try {
					$qr = QrCode::svg( Totp::uri( $bin, $user->user_login, self::issuer() ), 220, momos_t( 'admin.2fa.enrol.qr_alt', [], $lang ) );
				} catch ( \InvalidArgumentException $e ) {
					$qr = ''; // Manual key entry stays available.
				}
			}
		}
		$title = momos_t( 'enrol' === $stage || 'codes' === $stage ? 'admin.2fa.enrol.title' : 'admin.2fa.title', [], $lang );
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		if ( function_exists( 'login_header' ) ) {
			login_header( $title );
		} else {
			echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head><body class="login">';
		}
		include MOMOS_CORE_DIR . '/templates/admin/access-2fa.php';
		if ( function_exists( 'login_footer' ) ) {
			login_footer();
		} else {
			echo '</body></html>';
		}
	}

	/** Issuer label: brand trading name setting (placeholder until supplied). */
	public static function issuer(): string {
		$name = class_exists( \Momos\Kernel\Settings::class ) ? (string) \Momos\Kernel\Settings::get( 'momo_trading_name', '' ) : '';
		return '' !== trim( $name ) ? trim( $name ) : (string) get_bloginfo( 'name' );
	}
}
