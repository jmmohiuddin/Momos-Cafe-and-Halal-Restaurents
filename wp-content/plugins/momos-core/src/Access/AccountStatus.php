<?php
declare(strict_types=1);

namespace Momos\Access;

use Momos\Kernel\Clock\Clock;

/**
 * Account state helpers (SEC-002/006/008, FR-ADM-007): disabled flag, vendor flag, access window
 * expiry and break-glass. Meta keys are exact (Access spec §2.2) and written only by Access code.
 */
final class AccountStatus {
	public const META_DISABLED   = 'momo_account_disabled';
	public const META_VENDOR     = 'momo_vendor';
	public const META_EXPIRES    = 'momo_access_expires_at';
	public const META_BG_UNTIL   = 'momo_breakglass_until';
	public const META_BG_REF     = 'momo_breakglass_ref';
	public const META_LAST_LOGIN = 'momo_last_login_at';

	public static function is_disabled( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::META_DISABLED, true );
	}

	public static function is_vendor( int $user_id ): bool {
		return '1' === (string) get_user_meta( $user_id, self::META_VENDOR, true );
	}

	public static function expires_at( int $user_id ): ?\DateTimeImmutable {
		return Clock::from_mysql_utc( (string) get_user_meta( $user_id, self::META_EXPIRES, true ) );
	}

	public static function is_expired( int $user_id ): bool {
		$exp = self::expires_at( $user_id );
		return null !== $exp && $exp <= Clock::utc();
	}

	/** Disabled or past `momo_access_expires_at` → no access at all (assigned() = false). */
	public static function is_inactive( int $user_id ): bool {
		return $user_id > 0 && ( self::is_disabled( $user_id ) || self::is_expired( $user_id ) );
	}

	public static function breakglass_until( int $user_id ): ?\DateTimeImmutable {
		return Clock::from_mysql_utc( (string) get_user_meta( $user_id, self::META_BG_UNTIL, true ) );
	}

	public static function breakglass_active( int $user_id ): bool {
		$until = self::breakglass_until( $user_id );
		return null !== $until && $until > Clock::utc();
	}

	/** Branch-scoped role (ROLE-06/07)? */
	public static function is_scoped_role( string $role ): bool {
		return in_array( $role, Roles\RoleInstaller::config()['scoped_roles'], true );
	}

	public static function primary_role( int $user_id ): string {
		$u = get_userdata( $user_id );
		return $u && $u->roles ? (string) reset( $u->roles ) : '';
	}
}
