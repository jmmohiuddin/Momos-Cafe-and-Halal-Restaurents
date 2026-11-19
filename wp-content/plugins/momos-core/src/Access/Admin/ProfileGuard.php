<?php
declare(strict_types=1);

namespace Momos\Access\Admin;

use Momos\Access\TwoFactor\TwoFactor;

/**
 * Self-elevation and role-governance guard (SEC-007, E12, BR-ADM-03, TC-ACL-013; spec §4.3/§5.6):
 *  - users cannot change their own role, branches, disabled flag, expiry, vendor flag or CAP-DCA:
 *    crafted fields on the own profile are ignored and audited `access.denied`;
 *  - users without `manage_options` cannot create or promote an administrator (403 + audit);
 *  - the only self-service access setting is the voluntary 2FA opt-in;
 *  - opening/saving user-edit.php for a user one may not edit (M9, e.g. HO → Super Administrator)
 *    answers 403 with a generic localised message and an `access.denied` row (FR-ADM-003,
 *    TC-ACL-013) instead of core's wp_die() without status (HTTP 500).
 */
final class ProfileGuard {
	/** POST fields that only a manager may set on someone else's account. */
	public const PROTECTED_FIELDS = [
		'momos_branches', 'momos_dca', 'momos_dca_present', 'momos_dca_ref', 'momos_disabled', 'momos_disabled_present', 'momos_vendor',
		'momos_vendor_present', 'momos_vendor_until', 'momos_vendor_end_now', 'momos_bg_until', 'momos_bg_ref', 'momos_bg_revoke', 'momos_2fa_reset', 'role',
		UserAccessFields::NONCE,
	];

	public static function register(): void {
		add_action( 'personal_options_update', [ self::class, 'own_profile' ], 1 );
		add_action( 'check_admin_referer', [ self::class, 'guard_role_assignment' ], 10, 2 );
		add_action( 'load-user-edit.php', [ self::class, 'guard_user_edit' ], 1 );
	}

	/** Target user of user-edit.php, or 0 (own profile / none). */
	public static function edit_target(): int {
		$raw = $_REQUEST['user_id'] ?? 0; // phpcs:ignore WordPress.Security.NonceVerification
		return is_scalar( $raw ) ? absint( $raw ) : 0;
	}

	public static function guard_user_edit(): void {
		$target = self::edit_target();
		if ( $target <= 0 || get_current_user_id() === $target || current_user_can( 'edit_user', $target ) ) {
			return;
		}
		momos_audit( 'access.denied', 'user', $target, null, [ 'cap' => 'edit_user', 'channel' => 'admin', 'route' => 'user-edit' ] );
		wp_die( esc_html( momos_t( 'admin.err.forbidden', [], \Momos\Kernel\I18n\Locale::admin() ) ), '', [ 'response' => 403 ] );
	}

	/** Own profile save: strip protected fields (audited), handle the 2FA opt-in. */
	public static function own_profile( $user_id ): void {
		$user_id = (int) $user_id;
		$found   = [];
		foreach ( self::PROTECTED_FIELDS as $f ) {
			if ( isset( $_POST[ $f ] ) ) { // phpcs:ignore -- core verified update-user_{id}.
				$found[] = $f;
				unset( $_POST[ $f ], $_REQUEST[ $f ] );
			}
		}
		if ( $found ) {
			momos_audit( 'access.denied', 'user', $user_id, null, [ 'channel' => 'admin', 'route' => 'profile', 'fields' => $found, 'reason' => 'self_edit' ] );
		}
		$nonce = isset( $_POST['_momos_optin_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_momos_optin_nonce'] ) ) : ''; // phpcs:ignore
		if ( '' !== $nonce && wp_verify_nonce( $nonce, 'momos_2fa_optin' ) && ! TwoFactor::is_enrolled( $user_id ) ) {
			empty( $_POST['momos_2fa_optin'] ) ? delete_user_meta( $user_id, TwoFactor::META_OPTIN ) : update_user_meta( $user_id, TwoFactor::META_OPTIN, '1' ); // phpcs:ignore
		}
	}

	/**
	 * Before core's edit_user(): a non-SA attempting to give the administrator role (crafted POST)
	 * is refused with 403 and audited (TC-ACL-013).
	 */
	public static function guard_role_assignment( $action, $result = null ): void {
		$action = (string) $action;
		if ( 'create-user' !== $action && ! str_starts_with( $action, 'update-user_' ) ) {
			return;
		}
		$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role'] ) ) : ''; // phpcs:ignore
		if ( 'administrator' === $role && ! current_user_can( 'manage_options' ) ) {
			$target = str_starts_with( $action, 'update-user_' ) ? (int) substr( $action, 12 ) : null;
			momos_audit( 'access.denied', 'user', $target ?: null, null, [ 'channel' => 'admin', 'route' => 'users', 'cap' => 'promote_user', 'reason' => 'administrator_role' ] );
			wp_die( esc_html__( 'You are not allowed to give users that role.', 'momos-core' ), 403 );
		}
	}
}
