<?php
declare(strict_types=1);

namespace Momos\Access\Users;

use Momos\Access\AccountStatus;
use Momos\Access\Roles\RoleInstaller;
use Momos\Access\Session\SessionPolicy;
use Momos\Access\TwoFactor\TwoFactor;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Jobs\Schedule;

/**
 * Account lifecycle actions (SEC-002/006/007/008, DEC-035, FR-ADM-002/007/008; spec §5.6/§5.7).
 * Every method validates the actor's rights itself (handlers are thin) and throws
 * \InvalidArgumentException with an admin-language message on refusal.
 */
final class AccountService {
	public const VENDOR_WINDOW_MAX = 72 * HOUR_IN_SECONDS;
	public const BREAKGLASS_MAX    = 8 * HOUR_IN_SECONDS;

	/** Actor may manage this target at all (M9 + self rules). */
	private static function assert_manage( int $actor, int $target, bool $allow_vendor_admin = false ): void {
		if ( ! user_can( $actor, 'momo_manage_staff' ) || ! get_userdata( $target ) ) {
			throw new \InvalidArgumentException( __( 'You are not allowed to change this user.', 'momos-core' ) );
		}
		if ( $actor === $target ) {
			throw new \InvalidArgumentException( __( 'You cannot change your own access settings.', 'momos-core' ) );
		}
		$is_vendor_admin = $allow_vendor_admin && AccountStatus::is_vendor( $target );
		if ( ! $is_vendor_admin && ! user_can( $actor, 'edit_user', $target ) ) {
			throw new \InvalidArgumentException( __( 'You are not allowed to change this user.', 'momos-core' ) );
		}
	}

	/**
	 * Disable: flag, all sessions destroyed, branch rows kept (SEC-006). Returns warnings
	 * (e.g. the user's email is a notification recipient).
	 *
	 * @return list<string>
	 */
	public static function disable( int $target, int $actor, string $reason = 'manual' ): array {
		if ( $actor > 0 ) {
			self::assert_manage( $actor, $target, true );
		}
		if ( AccountStatus::is_disabled( $target ) ) {
			return [];
		}
		update_user_meta( $target, AccountStatus::META_DISABLED, '1' );
		SessionPolicy::destroy_all( $target );
		momos_audit( 'user.disabled', 'user', $target, null, [ 'reason' => $reason ], $actor > 0 ? 'staff' : 'system' );
		do_action( 'momos/user/disabled', $target );
		return self::recipient_warnings( $target );
	}

	public static function enable( int $target, int $actor ): void {
		self::assert_manage( $actor, $target );
		if ( AccountStatus::is_vendor( $target ) ) {
			throw new \InvalidArgumentException( __( 'Vendor accounts are enabled only through a time-limited vendor access window.', 'momos-core' ) );
		}
		if ( ! AccountStatus::is_disabled( $target ) && ! AccountStatus::is_expired( $target ) ) {
			return;
		}
		delete_user_meta( $target, AccountStatus::META_DISABLED );
		delete_user_meta( $target, AccountStatus::META_EXPIRES );
		momos_audit( 'user.enabled', 'user', $target, null, [ 'branches_kept' => count( \Momos\Access\BranchScope::rows( $target ) ) ] );
	}

	/** Vendor flag (ROLE-01 non-vendor only). Flagged accounts start disabled; denies applied. */
	public static function set_vendor( int $target, bool $vendor, int $actor ): void {
		if ( ! user_can( $actor, 'manage_options' ) || AccountStatus::is_vendor( $actor ) || $actor === $target ) {
			throw new \InvalidArgumentException( __( 'Only a Super Administrator of Momo\'s can change the vendor flag.', 'momos-core' ) );
		}
		if ( AccountStatus::is_vendor( $target ) === $vendor ) {
			return;
		}
		if ( $vendor ) {
			update_user_meta( $target, AccountStatus::META_VENDOR, '1' );
			if ( user_can( $target, 'momo_edit_dietary_claims' ) ) {
				( new \WP_User( $target ) )->remove_cap( 'momo_edit_dietary_claims' );
				momos_audit( 'user.cap_dca_revoked', 'user', $target, null, [ 'reason' => 'vendor' ] );
			}
			momos_audit( 'user.updated', 'user', $target, null, [ 'vendor' => [ 'from' => false, 'to' => true ] ] );
			self::disable( $target, 0, 'vendor_flagged' );
		} else {
			delete_user_meta( $target, AccountStatus::META_VENDOR );
			delete_user_meta( $target, AccountStatus::META_BG_UNTIL );
			delete_user_meta( $target, AccountStatus::META_BG_REF );
			momos_audit( 'user.updated', 'user', $target, null, [ 'vendor' => [ 'from' => true, 'to' => false ] ] );
		}
		RoleInstaller::apply_user_denies( $target );
	}

	/**
	 * Vendor access window (DEC-035, FR-ADM-007): ≤ ⟦72 h⟧ from now; enables the account until then.
	 * Allowed for `momo_manage_staff` on vendor-flagged users even when they are administrators.
	 */
	public static function set_vendor_window( int $target, \DateTimeImmutable $until, int $actor ): void {
		self::assert_manage( $actor, $target, true );
		if ( ! AccountStatus::is_vendor( $target ) ) {
			throw new \InvalidArgumentException( __( 'Access windows apply to vendor accounts only.', 'momos-core' ) );
		}
		$now = Clock::utc();
		if ( $until <= $now || $until->getTimestamp() - $now->getTimestamp() > self::VENDOR_WINDOW_MAX ) {
			throw new \InvalidArgumentException( momos_t( 'admin.vendor.err.window', [ 'hours' => (int) ( self::VENDOR_WINDOW_MAX / HOUR_IN_SECONDS ) ], self::admin_lang() ) );
		}
		update_user_meta( $target, AccountStatus::META_EXPIRES, Clock::mysql_utc( $until ) );
		delete_user_meta( $target, AccountStatus::META_DISABLED );
		RoleInstaller::apply_user_denies( $target );
		Schedule::at( $until->modify( '+1 minute' ), \Momos\Access\Job\AccessExpiryJob::HOOK );
		momos_audit( 'user.access_window_set', 'user', $target, null, [ 'expires_at' => Clock::mysql_utc( $until ) ] );
	}

	/**
	 * Break-glass (SEC-008, TK-11-14, TC-ACL-022): ROLE-01 non-vendor only, Owner approval reference,
	 * ≤ ⟦8 h⟧; lifts the denies for customer PII and reservation viewing only.
	 */
	public static function grant_breakglass( int $target, \DateTimeImmutable $until, string $reference, int $actor ): void {
		if ( ! user_can( $actor, 'manage_options' ) || AccountStatus::is_vendor( $actor ) ) {
			throw new \InvalidArgumentException( __( 'Only a Super Administrator of Momo\'s can grant break-glass access.', 'momos-core' ) );
		}
		if ( ! AccountStatus::is_vendor( $target ) ) {
			throw new \InvalidArgumentException( __( 'Break-glass applies to vendor accounts only.', 'momos-core' ) );
		}
		$reference = trim( sanitize_text_field( $reference ) );
		if ( '' === $reference || mb_strlen( $reference ) > 100 || preg_match( '/@|\+?\d[\d\s-]{8,}/', $reference ) ) {
			throw new \InvalidArgumentException( __( 'Enter the Owner approval reference (up to 100 characters, no personal data).', 'momos-core' ) );
		}
		$now = Clock::utc();
		if ( $until <= $now || $until->getTimestamp() - $now->getTimestamp() > self::BREAKGLASS_MAX ) {
			throw new \InvalidArgumentException( __( 'Break-glass access can last at most 8 hours.', 'momos-core' ) );
		}
		update_user_meta( $target, AccountStatus::META_BG_UNTIL, Clock::mysql_utc( $until ) );
		update_user_meta( $target, AccountStatus::META_BG_REF, $reference );
		RoleInstaller::apply_user_denies( $target );
		Schedule::at( $until->modify( '+1 minute' ), \Momos\Access\Job\AccessExpiryJob::HOOK );
		momos_audit( 'vendor.breakglass_granted', 'user', $target, null, [ 'until' => Clock::mysql_utc( $until ), 'ref' => $reference ] );
	}

	public static function revoke_breakglass( int $target, string $reason = 'expired' ): void {
		if ( '' === (string) get_user_meta( $target, AccountStatus::META_BG_UNTIL, true ) ) {
			return;
		}
		delete_user_meta( $target, AccountStatus::META_BG_UNTIL );
		delete_user_meta( $target, AccountStatus::META_BG_REF );
		RoleInstaller::apply_user_denies( $target );
		SessionPolicy::destroy_all( $target );
		momos_audit( 'vendor.breakglass_revoked', 'user', $target, null, [ 'reason' => $reason ], 'expired' === $reason ? 'system' : 'staff' );
	}

	/**
	 * CAP-DCA grant/revoke (§5.7): by SA/HO, never to oneself; to HO users, or to SA non-vendor users
	 * with an Owner request reference; never to vendors or other roles.
	 */
	public static function set_dca( int $target, bool $grant, int $actor, string $reference = '' ): void {
		self::assert_manage( $actor, $target );
		$has = ! empty( get_userdata( $target )->caps['momo_edit_dietary_claims'] );
		if ( $has === $grant ) {
			return;
		}
		$user = new \WP_User( $target );
		if ( $grant ) {
			if ( ! RoleInstaller::dca_eligible( $target ) ) {
				throw new \InvalidArgumentException( __( 'The Dietary Claims Approver permission can be given only to Head Office users (or a Super Administrator on Owner request), never to vendor accounts.', 'momos-core' ) );
			}
			$reference = trim( sanitize_text_field( $reference ) );
			if ( in_array( 'administrator', $user->roles, true ) && ( '' === $reference || mb_strlen( $reference ) > 100 ) ) {
				throw new \InvalidArgumentException( __( 'Enter the Owner request reference to grant this permission to a Super Administrator.', 'momos-core' ) );
			}
			$user->add_cap( 'momo_edit_dietary_claims', true );
			momos_audit( 'user.cap_dca_granted', 'user', $target, null, '' !== $reference ? [ 'ref' => $reference ] : [] );
		} else {
			$user->remove_cap( 'momo_edit_dietary_claims' );
			momos_audit( 'user.cap_dca_revoked', 'user', $target, null, [] );
		}
	}

	/** Lost device (§5.2): SA/HO (HO not for administrators) after out-of-band identity check. */
	public static function reset_2fa( int $target, int $actor ): void {
		self::assert_manage( $actor, $target );
		TwoFactor::reset( $target );
		SessionPolicy::destroy_all( $target );
		momos_audit( 'user.2fa_reset', 'user', $target, null, [] );
	}

	/** @return list<string> warnings when the user's email is a configured notification recipient */
	public static function recipient_warnings( int $target ): array {
		$u = get_userdata( $target );
		if ( ! $u || '' === $u->user_email ) {
			return [];
		}
		$email = strtolower( $u->user_email );
		$found = false;
		foreach ( [ 'momo_ho_escalation_recipients', 'momo_digest_recipients' ] as $opt ) {
			$rows = class_exists( \Momos\Kernel\Settings::class ) ? (array) \Momos\Kernel\Settings::get( $opt, [] ) : [];
			foreach ( $rows as $row ) {
				if ( strtolower( (string) ( is_array( $row ) ? ( $row['email'] ?? '' ) : $row ) ) === $email ) {
					$found = true;
				}
			}
		}
		if ( ! $found ) {
			global $wpdb;
			$found = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = 'momo_branch' AND m.meta_key LIKE %s AND m.meta_value LIKE %s LIMIT 1", 'momo_res_%recipient%', '%' . $wpdb->esc_like( $email ) . '%' ) ); // phpcs:ignore
		}
		return $found ? [ __( 'This user\'s email address is still listed as a notification recipient (Head Office list or a branch). Update the recipient lists.', 'momos-core' ) ] : [];
	}

	public static function admin_lang(): string {
		return str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en';
	}
}
