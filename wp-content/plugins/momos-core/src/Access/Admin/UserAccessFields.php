<?php
declare(strict_types=1);

namespace Momos\Access\Admin;

use Momos\Access\AccountStatus;
use Momos\Access\BranchScope;
use Momos\Access\Roles\RoleInstaller;
use Momos\Access\TwoFactor\TwoFactor;
use Momos\Access\Users\AccountService;
use Momos\Access\Users\AssignmentService;
use Momos\Kernel\Clock\Clock;

/**
 * SCR-A10 "Momo's access" section on the native user screens (spec §13.1, FR-ADM-002, US-ADM-001):
 * branches (required for ROLE-06/07), phone, CAP-DCA, account disabled, vendor flag (SA), vendor
 * access window, break-glass (SA), two-factor status/reset. On the own profile every access field
 * is read-only (ProfileGuard). Validation runs in `user_profile_update_errors`; values are written
 * after core saved the user (`profile_update` / `user_register`) under the section's own nonce.
 */
final class UserAccessFields {
	public const NONCE = '_momos_access_nonce';
	public const ACTION = 'momos_user_access';

	/** Username deny-list (SEC-002). */
	public const DENIED_LOGINS = [ 'admin', 'administrator', 'momo', 'momos', 'test', 'staff', 'user', 'root', 'superadmin' ];

	private static bool $saved = false;

	/** @var list<string> */
	private static array $notices = [];

	public static function register(): void {
		add_action( 'show_user_profile', [ self::class, 'render_own' ] );
		add_action( 'edit_user_profile', [ self::class, 'render_other' ] );
		add_action( 'user_new_form', [ self::class, 'render_new' ] );
		add_action( 'user_profile_update_errors', [ self::class, 'validate' ], 20, 3 );
		add_action( 'profile_update', [ self::class, 'save' ], 30, 1 );
		add_action( 'user_register', [ self::class, 'save' ], 30, 1 );
		add_filter( 'illegal_user_logins', [ self::class, 'illegal_logins' ] );
		add_action( 'admin_notices', [ self::class, 'notices' ] );
	}

	/** @param list<string> $logins */
	public static function illegal_logins( $logins ): array {
		return array_values( array_unique( array_merge( (array) $logins, self::DENIED_LOGINS ) ) );
	}

	public static function login_denied( string $login ): bool {
		$l = strtolower( trim( $login ) );
		return in_array( $l, self::DENIED_LOGINS, true ) || (bool) preg_match( '/^branch\d+$/', $l );
	}

	// ----------------------------------------------------------------- rendering

	public static function render_own( \WP_User $user ): void {
		self::render( $user, false );
	}

	public static function render_other( \WP_User $user ): void {
		self::render( $user, self::can_manage( (int) $user->ID ) );
	}

	public static function render_new( $context = '' ): void {
		if ( 'add-new-user' !== $context || ! current_user_can( 'momo_manage_staff' ) ) {
			return;
		}
		self::render( null, true );
	}

	public static function can_manage( int $target ): bool {
		$actor = get_current_user_id();
		if ( ! current_user_can( 'momo_manage_staff' ) || $actor === $target ) {
			return false;
		}
		return current_user_can( 'edit_user', $target ) || AccountStatus::is_vendor( $target );
	}

	private static function render( ?\WP_User $user, bool $editable ): void {
		$uid        = $user ? (int) $user->ID : 0;
		$branches   = self::branch_choices();
		$assigned   = $uid ? BranchScope::rows( $uid ) : [];
		$role       = $user && $user->roles ? (string) reset( $user->roles ) : '';
		$is_sa      = current_user_can( 'manage_options' ) && ! AccountStatus::is_vendor( get_current_user_id() );
		$vendor     = $uid && AccountStatus::is_vendor( $uid );
		$disabled   = $uid && AccountStatus::is_disabled( $uid );
		$expires    = $uid ? AccountStatus::expires_at( $uid ) : null;
		$bg_until   = $uid ? AccountStatus::breakglass_until( $uid ) : null;
		$has_dca    = $user && ! empty( $user->caps['momo_edit_dietary_claims'] );
		$dca_ok     = $uid && RoleInstaller::dca_eligible( $uid );
		$enrolled   = $uid && TwoFactor::is_enrolled( $uid );
		$recovery   = $uid ? TwoFactor::recovery_remaining( $uid ) : 0;
		$phone      = $uid ? (string) get_user_meta( $uid, 'momo_phone', true ) : '';
		$last_login = $uid ? (string) get_user_meta( $uid, AccountStatus::META_LAST_LOGIN, true ) : '';
		$own        = $uid && get_current_user_id() === $uid;
		$optin      = $uid && '1' === (string) get_user_meta( $uid, TwoFactor::META_OPTIN, true );
		$scoped     = $role && AccountStatus::is_scoped_role( $role );
		include MOMOS_CORE_DIR . '/templates/admin/access-user-fields.php';
	}

	/** @return array<int,string> canonical branches (any status), "CODE — name" */
	public static function branch_choices(): array {
		$out = [];
		foreach ( get_posts( [ 'post_type' => 'momo_branch', 'post_status' => [ 'publish', 'draft', 'pending', 'private' ], 'posts_per_page' => 200, 'orderby' => [ 'menu_order' => 'ASC', 'title' => 'ASC' ], 'no_found_rows' => true ] ) as $p ) {
			$code             = (string) get_post_meta( $p->ID, 'momo_branch_code', true );
			$out[ (int) $p->ID ] = trim( ( '' !== $code ? $code . ' — ' : '' ) . $p->post_title );
		}
		return $out;
	}

	// ----------------------------------------------------------------- validation

	private static function nonce_ok(): bool {
		$n = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) ) : ''; // phpcs:ignore
		return '' !== $n && false !== wp_verify_nonce( $n, self::ACTION );
	}

	/** @return list<int> */
	private static function posted_branches(): array {
		return isset( $_POST['momos_branches'] ) ? array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['momos_branches'] ) ) ) ) : []; // phpcs:ignore
	}

	/**
	 * @param \WP_Error $errors
	 * @param bool      $update
	 * @param object    $user   stdClass prepared by edit_user()
	 */
	public static function validate( $errors, $update, $user ): void {
		$lang  = AccountService::admin_lang();
		$login = (string) ( $user->user_login ?? '' );
		if ( ! $update && self::login_denied( $login ) ) {
			$errors->add( 'momos_login', esc_html( momos_t( 'admin.user.err.username', [], $lang ) ), [ 'form-field' => 'user_login' ] );
		}
		if ( ! $update && '' === trim( (string) ( $user->first_name ?? '' ) . (string) ( $user->last_name ?? '' ) ) ) {
			$errors->add( 'momos_name', esc_html__( 'Enter a first or last name: the display name must differ from the username.', 'momos-core' ), [ 'form-field' => 'first_name' ] );
		}
		if ( $update && isset( $user->display_name ) && '' !== $login && strtolower( (string) $user->display_name ) === strtolower( $login ) && isset( $_POST['display_name'] ) ) { // phpcs:ignore
			$errors->add( 'momos_display', esc_html__( 'Choose a display name that is different from the username.', 'momos-core' ), [ 'form-field' => 'display_name' ] );
		}
		$email = (string) ( $user->user_email ?? '' );
		if ( '' !== $email && self::personal_mail_domain( $email ) ) {
			$errors->add( 'momos_email', esc_html__( 'Use a business email address (personal webmail and carrier addresses are not allowed for staff accounts).', 'momos-core' ), [ 'form-field' => 'email' ] );
		}
		if ( isset( $user->locale ) && ! in_array( (string) $user->locale, [ '', 'ja', 'en_US' ], true ) ) {
			$errors->add( 'momos_locale', esc_html__( 'The admin language must be Japanese or English.', 'momos-core' ), [ 'form-field' => 'locale' ] );
		}
		if ( ! self::nonce_ok() ) {
			return;
		}
		$target = (int) ( $user->ID ?? 0 );
		if ( $target && ! self::can_manage( $target ) ) {
			return; // Own profile / not permitted: ProfileGuard ignores the fields.
		}
		$role = isset( $user->role ) && '' !== (string) $user->role ? (string) $user->role : ( $target ? AccountStatus::primary_role( $target ) : '' );
		if ( '' !== $role && AccountStatus::is_scoped_role( $role ) && ! self::posted_branches() ) {
			$errors->add( 'momos_branches', esc_html( momos_t( 'admin.user.err.branch_required', [], $lang ) ), [ 'form-field' => 'momos_branches' ] );
		}
		$phone = isset( $_POST['momos_phone'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['momos_phone'] ) ) ) : ''; // phpcs:ignore
		if ( '' !== $phone && null === self::normalize_phone( $phone ) ) {
			$errors->add( 'momos_phone', esc_html__( 'Enter the phone number in international format, e.g. +81312345678.', 'momos-core' ), [ 'form-field' => 'momos_phone' ] );
		}
	}

	public static function personal_mail_domain( string $email ): bool {
		$domain  = strtolower( (string) substr( (string) strrchr( $email, '@' ), 1 ) );
		$blocked = class_exists( \Momos\Branch\Service\GlobalSettings::class ) ? \Momos\Branch\Service\GlobalSettings::blocked_domains() : [ 'gmail.com', 'yahoo.co.jp', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'docomo.ne.jp', 'ezweb.ne.jp', 'au.com', 'softbank.ne.jp', 'i.softbank.jp' ];
		return in_array( $domain, $blocked, true );
	}

	public static function normalize_phone( string $raw ): ?string {
		if ( class_exists( \libphonenumber\PhoneNumberUtil::class ) ) {
			try {
				$util = \libphonenumber\PhoneNumberUtil::getInstance();
				$num  = $util->parse( $raw, 'JP' );
				return $util->isValidNumber( $num ) ? $util->format( $num, \libphonenumber\PhoneNumberFormat::E164 ) : null;
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		$d = (string) preg_replace( '/[\s()-]/', '', $raw );
		return preg_match( '/^\+\d{8,15}$/', $d ) ? $d : null;
	}

	// ----------------------------------------------------------------- persistence

	public static function save( $user_id ): void {
		$user_id = (int) $user_id;
		if ( self::$saved || ! is_admin() || ! self::nonce_ok() ) {
			return;
		}
		$posted_uid = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0; // phpcs:ignore
		if ( $posted_uid && $posted_uid !== $user_id ) {
			return;
		}
		if ( ! self::can_manage( $user_id ) ) {
			return;
		}
		self::$saved = true;
		$actor       = get_current_user_id();
		$post        = wp_unslash( $_POST ); // phpcs:ignore -- nonce verified; each value validated below.
		self::apply( $user_id, $actor, is_array( $post ) ? $post : [] );
	}

	/**
	 * Apply posted access values (also used by tests). Refusals become admin notices.
	 *
	 * @param array<string,mixed> $post
	 */
	public static function apply( int $user_id, int $actor, array $post ): void {
		$role = AccountStatus::primary_role( $user_id );
		$run  = static function ( callable $fn ): void {
			try {
				$fn();
			} catch ( \InvalidArgumentException $e ) {
				self::$notices[] = $e->getMessage();
			}
		};

		if ( AccountStatus::is_scoped_role( $role ) ) {
			$branches = isset( $post['momos_branches'] ) ? array_map( 'intval', (array) $post['momos_branches'] ) : [];
			if ( $branches ) {
				AssignmentService::set( $user_id, $branches, $actor );
			}
		} elseif ( BranchScope::rows( $user_id ) ) {
			AssignmentService::set( $user_id, [], $actor );
		}

		$phone = isset( $post['momos_phone'] ) ? self::normalize_phone( sanitize_text_field( (string) $post['momos_phone'] ) ) : null;
		if ( isset( $post['momos_phone'] ) ) {
			'' === trim( (string) $post['momos_phone'] ) ? delete_user_meta( $user_id, 'momo_phone' ) : ( null !== $phone ? update_user_meta( $user_id, 'momo_phone', $phone ) : null );
		}

		if ( current_user_can( 'manage_options' ) && isset( $post['momos_vendor_present'] ) ) {
			$run( static fn() => AccountService::set_vendor( $user_id, ! empty( $post['momos_vendor'] ), $actor ) );
		}

		if ( isset( $post['momos_dca_present'] ) ) {
			$run( static fn() => AccountService::set_dca( $user_id, ! empty( $post['momos_dca'] ), $actor, (string) ( $post['momos_dca_ref'] ?? '' ) ) );
		}

		if ( isset( $post['momos_disabled_present'] ) && ! AccountStatus::is_vendor( $user_id ) ) {
			$want = ! empty( $post['momos_disabled'] );
			if ( $want && ! AccountStatus::is_disabled( $user_id ) ) {
				$run( static function () use ( $user_id, $actor ): void {
					foreach ( AccountService::disable( $user_id, $actor ) as $w ) {
						self::$notices[] = $w;
					}
				} );
			} elseif ( ! $want && AccountStatus::is_disabled( $user_id ) ) {
				$run( static fn() => AccountService::enable( $user_id, $actor ) );
			}
		}

		if ( ! empty( $post['momos_vendor_until'] ) ) {
			$until = self::parse_jst( (string) $post['momos_vendor_until'] );
			$run( static function () use ( $user_id, $until, $actor ): void {
				if ( null === $until ) {
					throw new \InvalidArgumentException( __( 'Enter a valid date and time.', 'momos-core' ) );
				}
				AccountService::set_vendor_window( $user_id, $until, $actor );
			} );
		}
		if ( ! empty( $post['momos_vendor_end_now'] ) && AccountStatus::is_vendor( $user_id ) ) {
			$run( static fn() => AccountService::disable( $user_id, $actor, 'vendor_window_closed' ) );
		}

		if ( ! empty( $post['momos_bg_until'] ) ) {
			$until = self::parse_jst( (string) $post['momos_bg_until'] );
			$run( static function () use ( $user_id, $until, $actor, $post ): void {
				if ( null === $until ) {
					throw new \InvalidArgumentException( __( 'Enter a valid date and time.', 'momos-core' ) );
				}
				AccountService::grant_breakglass( $user_id, $until, (string) ( $post['momos_bg_ref'] ?? '' ), $actor );
			} );
		}
		if ( ! empty( $post['momos_bg_revoke'] ) && current_user_can( 'manage_options' ) ) {
			AccountService::revoke_breakglass( $user_id, 'manual' );
		}

		if ( ! empty( $post['momos_2fa_reset'] ) ) {
			$run( static fn() => AccountService::reset_2fa( $user_id, $actor ) );
		}
		if ( self::$notices ) {
			set_transient( 'momos_access_notices_' . $actor, self::$notices, 120 );
		}
	}

	/** JST `Y-m-d\TH:i` (datetime-local) → UTC instant. */
	public static function parse_jst( string $value ): ?\DateTimeImmutable {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', trim( $value ), Clock::tz() );
		return $d ? $d->setTimezone( new \DateTimeZone( 'UTC' ) ) : null;
	}

	public static function notices(): void {
		$key  = 'momos_access_notices_' . get_current_user_id();
		$list = get_transient( $key );
		if ( ! $list ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-warning" role="alert"><ul>';
		foreach ( (array) $list as $m ) {
			echo '<li>' . esc_html( (string) $m ) . '</li>';
		}
		echo '</ul></div>';
	}

	/** Reset request state (tests). */
	public static function reset_state(): void {
		self::$saved   = false;
		self::$notices = [];
	}

	/** @return list<string> */
	public static function last_notices(): array {
		return self::$notices;
	}
}
