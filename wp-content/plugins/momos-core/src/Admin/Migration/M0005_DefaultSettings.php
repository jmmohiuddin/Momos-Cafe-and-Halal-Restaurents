<?php
declare(strict_types=1);

namespace Momos\Admin\Migration;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Migration\MigrationInterface;

/**
 * M0005 (TRD §6.5): G3 defaults made explicit and the consent-registry placeholder row
 * (Access spec §10.2). The current key (`momo_consent_version_current`) is left for Head Office to
 * select once counsel approved the legal texts (until then reservations store "unversioned").
 * Idempotent: existing values are never overwritten.
 */
final class M0005_DefaultSettings implements MigrationInterface {
	public const PLACEHOLDER_KEY = 'PP1.0-RP1.0';

	public function id(): string {
		return 'M0005_DefaultSettings';
	}

	public function up(): void {
		// Plain options read by the Reservation/Branch modules (autoload off).
		$plain = [
			'momo_consent_versions' => [
				[
					'consent_key'                    => self::PLACEHOLDER_KEY,
					'privacy_policy_version'         => '1.0',
					'reservation_policy_version'     => '1.0',
					'privacy_page_revision_id'       => '',
					'reservation_policy_revision_id' => '',
					'effective_from'                 => Clock::jst()->format( 'Y-m-d' ),
				],
			],
		];
		foreach ( $plain as $name => $value ) {
			if ( false === get_option( $name, false ) ) {
				add_option( $name, $value, '', false );
			}
		}
		// G3 options-page values (option momos_opt_momo-settings): documented defaults.
		$defaults = [
			'momo_retention_pii_months'        => '12',
			'momo_retention_ip_hash_days'      => '30',
			'momo_retention_notification_days' => '180',
			'momo_retention_audit_months'      => '24',
			'momo_retention_activity_months'   => '12',
			'momo_csp_mode'                    => 'report_only',
			'momo_hsts_max_age'                => '300',
			'momo_login_lock_invalid_username' => '1',
			'momo_heartbeat_enabled'           => '1',
		];
		$page     = get_option( 'momos_opt_momo-settings', [] );
		$page     = is_array( $page ) ? $page : [];
		$changed  = false;
		foreach ( $defaults as $k => $v ) {
			if ( ! array_key_exists( $k, $page ) ) {
				$page[ $k ] = $v;
				$changed    = true;
			}
		}
		if ( $changed ) {
			update_option( 'momos_opt_momo-settings', $page, true );
			\Momos\Kernel\Settings::flush();
		}
		update_option( 'users_can_register', 0 );
	}
}
