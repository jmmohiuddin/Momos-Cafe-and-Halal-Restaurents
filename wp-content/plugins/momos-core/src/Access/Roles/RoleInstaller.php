<?php
declare(strict_types=1);

namespace Momos\Access\Roles;

use Momos\Access\AccountStatus;
use Momos\Kernel\Log\Logger;

/**
 * Roles and capabilities from `config/capabilities.php` (TRD §2.7.2 authoritative, DEC-024).
 *
 * - Momo's roles get exactly the configured capability set (extra caps are removed); the core
 *   `administrator` role gets every configured capability added (core caps kept) and never the
 *   per-user CAP-DCA.
 * - Core roles subscriber/contributor/author/editor are removed (no account may hold them, §2.1).
 * - Vendor accounts carry per-user `false` for the PII caps and the reservation-processing /
 *   notification-log caps (`vendor_denies`, DEC-035, SEC-008, TC-ACL-022), re-applied on every
 *   sync, profile update and role change; break-glass lifts two of them (PII + view) temporarily.
 * - Applied by migration M0004 and re-synced whenever momos-core's version or the table changes.
 * - Role display names: English stored; Japanese shown to ja admin users (DEC-037).
 */
final class RoleInstaller {
	public const SIGNATURE_OPTION = 'momos_roles_signature';

	/** Core roles removed by the installer. */
	public const REMOVED_CORE_ROLES = [ 'subscriber', 'contributor', 'author', 'editor' ];

	/** Caps lifted by break-glass (export and DSR stay denied, TC-ACL-022). */
	public const BREAKGLASS_LIFTS = [ 'momo_view_customer_pii', 'momo_view_reservations' ];

	/** @var array<string,mixed>|null */
	private static ?array $config = null;

	public static function register(): void {
		add_action( 'init', [ self::class, 'maybe_sync' ], 2 );
		add_filter( 'gettext_with_context', [ self::class, 'translate_role_name' ], 10, 4 );
		add_action( 'profile_update', [ self::class, 'apply_user_denies' ], 20, 1 );
		add_action( 'set_user_role', [ self::class, 'apply_user_denies' ], 20, 1 );
		add_action( 'user_register', [ self::class, 'apply_user_denies' ], 20, 1 );
	}

	/** @return array{roles:array<string,array{0:string,1:string}>,scoped_roles:list<string>,caps:array<string,list<string>>,per_user:list<string>,vendor_denies:list<string>} */
	public static function config(): array {
		if ( null === self::$config ) {
			self::$config = include MOMOS_CORE_DIR . '/config/capabilities.php';
		}
		return self::$config;
	}

	public static function signature(): string {
		return MOMOS_CORE_VERSION . ':' . md5( (string) wp_json_encode( self::config() ) );
	}

	public static function maybe_sync(): void {
		if ( wp_installing() || ! is_blog_installed() ) {
			return;
		}
		if ( get_option( self::SIGNATURE_OPTION ) !== self::signature() ) {
			self::sync();
		}
	}

	/** Role slug → list of granted capabilities. @return array<string, list<string>> */
	public static function caps_by_role(): array {
		$out = [];
		foreach ( self::config()['caps'] as $cap => $roles ) {
			foreach ( $roles as $role ) {
				$out[ $role ][] = (string) $cap;
			}
		}
		return $out;
	}

	public static function sync(): void {
		$wp_roles = wp_roles();
		$cfg      = self::config();
		$by_role  = self::caps_by_role();
		$per_user = $cfg['per_user'];

		foreach ( $cfg['roles'] as $slug => [ $en ] ) {
			$caps = array_fill_keys( $by_role[ $slug ] ?? [], true );
			remove_role( $slug );
			add_role( $slug, $en, $caps );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $by_role['administrator'] ?? [] as $cap ) {
				if ( empty( $admin->capabilities[ $cap ] ) ) {
					$admin->add_cap( $cap );
				}
			}
			foreach ( $per_user as $cap ) {
				if ( isset( $admin->capabilities[ $cap ] ) ) {
					$admin->remove_cap( $cap );
				}
			}
		}
		foreach ( self::REMOVED_CORE_ROLES as $role ) {
			if ( get_role( $role ) ) {
				$holders = get_users( [ 'role' => $role, 'fields' => 'ID', 'number' => 1 ] );
				if ( $holders ) {
					Logger::warning( 'access.core_role_in_use', [ 'role' => $role ] );
				}
				remove_role( $role );
			}
		}
		update_option( 'users_can_register', 0 );
		update_option( 'default_role', '' );
		$wp_roles->for_site();

		foreach ( get_users( [ 'meta_key' => AccountStatus::META_VENDOR, 'meta_value' => '1', 'fields' => 'ID' ] ) as $uid ) { // phpcs:ignore
			self::apply_user_denies( (int) $uid );
		}
		update_option( self::SIGNATURE_OPTION, self::signature(), true );
		Logger::info( 'access.roles_synced', [ 'version' => MOMOS_CORE_VERSION ] );
	}

	/**
	 * Per-user denies for vendor accounts (DEC-035): the `vendor_denies` caps are stored as `false`
	 * (user caps override role caps); an active break-glass lifts BREAKGLASS_LIFTS only.
	 * Also strips CAP-DCA from ineligible users (vendor accounts, roles other than SA/HO).
	 */
	public static function apply_user_denies( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$denies = self::config()['vendor_denies'];
		if ( AccountStatus::is_vendor( $user_id ) ) {
			$lift = AccountStatus::breakglass_active( $user_id ) ? self::BREAKGLASS_LIFTS : [];
			foreach ( $denies as $cap ) {
				$want_deny = ! in_array( $cap, $lift, true );
				$has_deny  = array_key_exists( $cap, $user->caps ) && false === (bool) $user->caps[ $cap ];
				if ( $want_deny && ! $has_deny ) {
					$user->add_cap( $cap, false );
				} elseif ( ! $want_deny && $has_deny ) {
					$user->remove_cap( $cap );
				}
			}
		} else {
			foreach ( $denies as $cap ) {
				if ( array_key_exists( $cap, $user->caps ) && false === (bool) $user->caps[ $cap ] ) {
					$user->remove_cap( $cap );
				}
			}
		}
		if ( ! empty( $user->caps['momo_edit_dietary_claims'] ) && ! self::dca_eligible( $user_id ) ) {
			$user->remove_cap( 'momo_edit_dietary_claims' );
			momos_audit( 'user.cap_dca_revoked', 'user', $user_id, null, [ 'reason' => 'ineligible' ] );
		}
	}

	/** CAP-DCA eligibility (§5.7): HO users, or SA non-vendor users; never vendors. */
	public static function dca_eligible( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || AccountStatus::is_vendor( $user_id ) ) {
			return false;
		}
		return (bool) array_intersect( [ 'momo_head_office', 'administrator' ], $user->roles );
	}

	/**
	 * Japanese role names for ja admin users (translate_user_role uses the "User role" context).
	 *
	 * @param string $translation
	 */
	public static function translate_role_name( $translation, $text, $context, $domain ) {
		if ( 'User role' !== $context || ! str_starts_with( (string) get_user_locale(), 'ja' ) ) {
			return $translation;
		}
		foreach ( self::config()['roles'] as [ $en, $ja ] ) {
			if ( $en === $text ) {
				return $ja;
			}
		}
		if ( 'Administrator' === $text ) {
			return 'スーパー管理者';
		}
		return $translation;
	}

	/** Display name of a role in the current admin language. */
	public static function role_label( string $slug ): string {
		if ( 'administrator' === $slug ) {
			return str_starts_with( (string) get_user_locale(), 'ja' ) ? 'スーパー管理者' : __( 'Super Administrator', 'momos-core' );
		}
		$names = self::config()['roles'][ $slug ] ?? null;
		if ( ! $names ) {
			return $slug;
		}
		return str_starts_with( (string) get_user_locale(), 'ja' ) ? $names[1] : $names[0];
	}
}
