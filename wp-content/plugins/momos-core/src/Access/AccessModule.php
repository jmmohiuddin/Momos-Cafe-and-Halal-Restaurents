<?php
declare(strict_types=1);

namespace Momos\Access;

use Momos\Kernel\ModuleInterface;

/**
 * Access module (F-ADM-02, F-SEC-01, SEC-001…015, TRD §2.7; docs/specs/access-security-privacy.md):
 * roles/capabilities, central meta-capability mapping M1–M12, branch scope, user lifecycle and
 * branch assignments, TOTP 2FA, login throttling, password and session policy, hardening,
 * security headers and WordPress-level activity logging.
 */
final class AccessModule implements ModuleInterface {
	public function register(): void {
		add_filter(
			'momos/migrations',
			static function ( array $list ): array {
				$list[] = new Migration\M0004_Roles();
				return $list;
			}
		);
		Roles\RoleInstaller::register();
		MetaCapMapper::register();
		BranchScope::register();
		Admin\ProfileGuard::register();
		Admin\UserAccessFields::register();
		Admin\UsersList::register();
		TwoFactor\LoginFlow::register();
		Login\LoginThrottle::register();
		Login\LoginCaptcha::register();
		Login\LoginLanguage::register();
		Login\PasswordPolicy::register();
		Session\SessionPolicy::register();
		Job\AccessExpiryJob::register();
		Hardening::register();
		Http\SecurityHeaders::register();
		Activity\ActivityLogger::register();

		add_filter(
			'momos/privacy/retention_steps',
			static function ( array $steps ): array {
				$steps['csp_reports'] = static fn( bool $dry_run ): int => Http\CspReport::prune( $dry_run );
				return $steps;
			}
		);
		add_action( 'set_user_role', [ Users\AssignmentService::class, 'on_role_change' ], 20, 3 );
		add_action( 'admin_notices', [ self::class, 'key_notice' ] );
	}

	/** SA notice when MOMOS_2FA_KEY is missing (secrets derived from WordPress salts, spec §5.2). */
	public static function key_notice(): void {
		if ( current_user_can( 'manage_options' ) && \Momos\Kernel\Env::is_production() && TwoFactor\TwoFactor::key_is_fallback() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'MOMOS_2FA_KEY is not defined: two-factor secrets are encrypted with a key derived from the WordPress salts. Define a dedicated 32-byte key in the secrets file.', 'momos-core' ) . '</p></div>';
		}
	}
}
