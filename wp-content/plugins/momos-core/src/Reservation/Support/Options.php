<?php
declare(strict_types=1);

namespace Momos\Reservation\Support;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Settings;

/**
 * Global reservation settings G3 (SCR-A15, spec §2.4). The options page is owned by the settings
 * screen; values come from the kernel options page when the field is registered there, else from a
 * plain WordPress option of the same name, else the documented default ([TBD → x] values).
 */
final class Options {
	public const DEFAULTS = [
		'momo_res_global_enabled'          => 1,
		'momo_soft_duplicate_minutes'      => 10,
		'momo_min_decision_window_minutes' => 30,
		'momo_rate_ip_per_hour'            => 5,
		'momo_rate_email_per_hour'         => 3,
		'momo_rate_ip_degraded_per_hour'   => 2,
		'momo_rate_status_ip_per_hour'     => 30,
		'momo_rate_cancel_ip_per_hour'     => 10,
		'momo_rate_staff_per_minute'       => 120,
		'momo_carrier_domains'             => [ 'docomo.ne.jp', 'ezweb.ne.jp', 'au.com', 'softbank.ne.jp', 'i.softbank.jp', 'ymobile.ne.jp' ],
		'momo_export_max_range_months'     => 12,
		'momo_export_max_rows'             => 10000,
		'momo_consent_version_current'     => '',
		'momo_ho_escalation_recipients'    => [],
		'momo_special_request_separate_consent' => 0,
		'momo_res_status_page_enabled'     => 1,
		'momo_degraded_alert_per_hour'     => 10,
	];

	public static function get( string $name ): mixed {
		$default = self::DEFAULTS[ $name ] ?? null;
		if ( null !== FieldRegistry::option_page_of( $name ) ) {
			$v = Settings::get( $name, $default );
		} else {
			$v = get_option( $name, null );
		}
		return ( null === $v || '' === $v || false === $v ) ? $default : $v;
	}

	public static function int( string $name ): int {
		return (int) self::get( $name );
	}

	public static function bool( string $name ): bool {
		return '1' === (string) (int) self::get( $name );
	}

	/** @return list<string> lower-case domains */
	public static function domains( string $name ): array {
		$v = self::get( $name );
		if ( is_string( $v ) ) {
			$v = preg_split( '/[\s,]+/', $v ) ?: [];
		}
		$out = [];
		foreach ( (array) $v as $d ) {
			$d = strtolower( trim( ltrim( (string) ( is_array( $d ) ? reset( $d ) : $d ), '@' ) ) );
			if ( '' !== $d ) {
				$out[] = $d;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Head-office escalation recipients (E08 sla_escalation): list of emails.
	 *
	 * @return list<string>
	 */
	public static function escalation_recipients(): array {
		$out = [];
		foreach ( (array) self::get( 'momo_ho_escalation_recipients' ) as $row ) {
			$email = is_array( $row ) ? (string) ( $row['email'] ?? '' ) : (string) $row;
			if ( is_array( $row ) && isset( $row['active'] ) && ! $row['active'] ) {
				continue;
			}
			$email = strtolower( trim( $email ) );
			if ( '' !== $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Current consent registry version (≤ 20 characters, column consent_version). */
	public static function consent_version(): string {
		$v = trim( (string) self::get( 'momo_consent_version_current' ) );
		if ( '' === $v ) {
			return 'unversioned';
		}
		return mb_substr( $v, 0, 20 );
	}
}
