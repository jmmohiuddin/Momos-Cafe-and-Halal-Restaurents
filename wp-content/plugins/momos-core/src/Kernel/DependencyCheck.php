<?php
declare(strict_types=1);

namespace Momos\Kernel;

/**
 * Runtime requirements (FR-FND-003): PHP extensions and production secrets. Failures produce an
 * SA-only admin notice and a critical log entry; the public site never fatals.
 */
final class DependencyCheck {
	/** @return list<string> problems */
	public static function problems(): array {
		$out = [];
		foreach ( [ 'intl', 'mbstring', 'sodium', 'openssl', 'json' ] as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$out[] = "PHP extension missing: {$ext}";
			}
		}
		if ( Env::is_production() ) {
			foreach ( [ 'MOMOS_TOKEN_KEY_V1', 'MOMOS_HMAC_KEY', 'MOMOS_TURNSTILE_SITE_KEY', 'MOMOS_TURNSTILE_SECRET' ] as $c ) {
				if ( ! defined( $c ) || '' === (string) constant( $c ) ) {
					$out[] = "Required secret not configured: {$c}";
				}
			}
			foreach ( [ 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS' ] as $c ) {
				if ( ! defined( $c ) || ! constant( $c ) ) {
					$out[] = "Hardening constant not set: {$c}";
				}
			}
		}
		return $out;
	}

	public static function register(): void {
		add_action( 'admin_notices', static function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$p = self::problems();
			if ( $p ) {
				echo '<div class="notice notice-error"><p><strong>momos-core:</strong> ' . esc_html( implode( ' · ', $p ) ) . '</p></div>';
			}
		} );
	}
}
