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
		foreach ( [ 'intl', 'mbstring', 'sodium', 'openssl', 'json', 'fileinfo' ] as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$out[] = "PHP extension missing: {$ext}";
			}
		}
		if ( Env::is_production() ) {
			foreach ( [ 'MOMOS_TOKEN_KEY_V1', 'MOMOS_TURNSTILE_SITE_KEY', 'MOMOS_TURNSTILE_SECRET' ] as $c ) {
				if ( ! Env::has( $c ) ) {
					$out[] = "Required secret not configured: {$c}";
				}
			}
			// Pepper for IP/UA/email/rate-limit/audit HMACs; Support\Hash fails closed without it (QA-075).
			if ( ! Env::has( 'MOMOS_HASH_PEPPER' ) && ! Env::has( 'MOMOS_HMAC_KEY' ) ) {
				$out[] = 'Required secret not configured: MOMOS_HASH_PEPPER (alias MOMOS_HMAC_KEY)';
			}
			if ( defined( 'MOMOS_TRUSTED_IP_HEADER' ) && MOMOS_TRUSTED_IP_HEADER && ! Http\RequestContext::trusted_proxies() ) {
				$out[] = 'MOMOS_TRUSTED_IP_HEADER is set but MOMOS_TRUSTED_PROXIES is empty: the header is ignored';
			}
			foreach ( [ 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'DISALLOW_UNFILTERED_HTML' ] as $c ) {
				if ( ! defined( $c ) || ! constant( $c ) ) {
					$out[] = "Hardening constant not set: {$c}";
				}
			}
		}
		return $out;
	}

	public static function register(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				$p = self::problems();
				if ( $p ) {
					echo '<div class="notice notice-error"><p><strong>momos-core:</strong> ' . esc_html( implode( ' · ', $p ) ) . '</p></div>';
				}
			}
		);
	}
}
