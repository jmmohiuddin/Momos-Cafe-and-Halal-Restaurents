<?php
declare(strict_types=1);

namespace Momos\Access\Activity;

use Momos\Access\AccountStatus;
use Momos\Access\AuditTrail;
use Momos\Kernel\Clock\Clock;

/**
 * WordPress-level activity events into `momo_audit_log` (replaces the activity-log/security plugin,
 * IMPL-004; Access spec §6.3/§6.4; SEC-080): login success (failures are logged by LoginThrottle,
 * never with the typed username or password), logout, user created/role changed/updated, plugin and
 * theme activation, core updates and changes of security-relevant core options.
 */
final class ActivityLogger {
	/** Core options whose changes are logged (spec §6.4). */
	public const OPTIONS = [ 'siteurl', 'home', 'users_can_register', 'default_role', 'admin_email', 'blog_public', 'momos_languages_enabled' ];

	public static function register(): void {
		add_action( 'wp_login', [ self::class, 'login' ], 10, 2 );
		add_action( 'wp_logout', [ self::class, 'logout' ], 10, 1 );
		add_action( 'user_register', [ self::class, 'user_created' ], 10, 1 );
		add_action( 'set_user_role', [ self::class, 'role_changed' ], 10, 3 );
		add_action( 'profile_update', [ self::class, 'user_updated' ], 10, 3 );
		add_action( 'activated_plugin', static fn( $plugin ) => momos_audit( 'wp.plugin_activated', 'wp', null, null, [ 'plugin' => (string) $plugin ] ) );
		add_action( 'deactivated_plugin', static fn( $plugin ) => momos_audit( 'wp.plugin_deactivated', 'wp', null, null, [ 'plugin' => (string) $plugin ] ) );
		add_action( 'switch_theme', static fn( $name, $new = null, $old = null ) => momos_audit( 'wp.theme_switched', 'wp', null, null, [ 'theme' => [ 'from' => $old instanceof \WP_Theme ? $old->get_stylesheet() : null, 'to' => $new instanceof \WP_Theme ? $new->get_stylesheet() : (string) $name ] ] ), 10, 3 );
		add_action( '_core_updated_successfully', static fn( $version ) => momos_audit( 'wp.core_updated', 'wp', null, null, [ 'version' => (string) $version ] ) );
		add_action( 'updated_option', [ self::class, 'option_updated' ], 10, 3 );
	}

	public static function login( $user_login, $user = null ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		update_user_meta( (int) $user->ID, AccountStatus::META_LAST_LOGIN, Clock::mysql_utc() );
		AuditTrail::for_user( (int) $user->ID, 'auth.login_succeeded', 'auth', (int) $user->ID, null, [ '2fa' => \Momos\Access\TwoFactor\LoginFlow::passing() || \Momos\Access\TwoFactor\TwoFactor::is_enrolled( (int) $user->ID ) ] );
	}

	public static function logout( $user_id = 0 ): void {
		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			AuditTrail::for_user( $user_id, 'auth.logout', 'auth', $user_id );
		}
	}

	public static function user_created( $user_id ): void {
		$user_id = (int) $user_id;
		momos_audit( 'user.created', 'user', $user_id, null, [ 'role' => AccountStatus::primary_role( $user_id ) ] );
	}

	/** @param list<string> $old_roles */
	public static function role_changed( $user_id, $role, $old_roles = [] ): void {
		$old = array_values( (array) $old_roles );
		if ( [ (string) $role ] === $old ) {
			return;
		}
		if ( ! $old && self::just_registered( (int) $user_id ) ) {
			return; // Initial role at creation is recorded by user.created.
		}
		momos_audit( 'user.role_changed', 'user', (int) $user_id, null, [ 'role' => [ 'from' => $old ? implode( ',', $old ) : null, 'to' => (string) $role ] ] );
	}

	private static function just_registered( int $user_id ): bool {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return true;
		}
		$registered = strtotime( (string) $u->user_registered . ' UTC' );
		return false === $registered || abs( current_time( 'timestamp', true ) - $registered ) < 60;
	}

	/**
	 * email / display name / locale changes (values masked: only that they changed).
	 *
	 * @param \WP_User|null $old
	 * @param array<string,mixed> $data
	 */
	public static function user_updated( $user_id, $old = null, $data = [] ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! $old instanceof \WP_User ) {
			return;
		}
		$changed = [];
		foreach ( [ 'user_email', 'display_name' ] as $f ) {
			if ( (string) $old->{$f} !== (string) $user->{$f} ) {
				$changed[ $f ] = [ 'from' => '[masked]', 'to' => '[masked]' ];
			}
		}
		if ( isset( $data['locale'] ) && (string) $old->locale !== (string) $data['locale'] ) {
			$changed['locale'] = [ 'from' => (string) $old->locale, 'to' => (string) $data['locale'] ];
		}
		if ( $changed ) {
			momos_audit( 'user.updated', 'user', (int) $user_id, null, $changed );
		}
	}

	public static function option_updated( $option, $old, $new ): void {
		if ( ! in_array( (string) $option, self::OPTIONS, true ) || $old === $new ) {
			return;
		}
		$to = 'admin_email' === $option ? '[masked]' : $new;
		momos_audit( 'wp.option_updated', 'wp', null, null, [ 'option' => (string) $option, 'from' => 'admin_email' === $option ? '[masked]' : $old, 'to' => $to ] );
	}
}
