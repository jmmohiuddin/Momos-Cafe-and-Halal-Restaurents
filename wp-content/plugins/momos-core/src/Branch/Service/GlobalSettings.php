<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Settings;

/**
 * Read access to the global settings this module depends on (SCR-A15, owned by the
 * Settings/Reservation specs). Values come from the kernel options page when the owning module
 * registered the field, else from a plain option of the same name, else the documented default.
 */
final class GlobalSettings {
	/** PRD free-webmail block list (spec §4.8, PRV-010). */
	public const DEFAULT_BLOCKED_DOMAINS = [ 'gmail.com', 'yahoo.co.jp', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com', 'docomo.ne.jp', 'ezweb.ne.jp', 'au.com', 'softbank.ne.jp', 'i.softbank.jp' ];

	public static function get( string $name, mixed $default ): mixed {
		if ( null !== FieldRegistry::option_page_of( $name ) ) {
			$v = Settings::get( $name, $default );
			return ( null === $v || '' === $v ) ? $default : $v;
		}
		$v = get_option( $name, null );
		return ( null === $v || '' === $v || false === $v ) ? $default : $v;
	}

	/** BR-RES-15 minimum decision window ⟦30⟧. */
	public static function min_decision_window(): int {
		return max( 0, (int) self::get( 'momo_min_decision_window_minutes', 30 ) );
	}

	public static function reservations_globally_enabled(): bool {
		return '1' === (string) (int) self::get( 'momo_res_global_enabled', 1 );
	}

	/** @return list<string> */
	public static function blocked_domains(): array {
		return self::domain_list( self::get( 'momo_recipient_blocked_domains', self::DEFAULT_BLOCKED_DOMAINS ) );
	}

	/** @return list<string> empty = no allow-list configured */
	public static function allowed_domains(): array {
		return self::domain_list( self::get( 'momo_allowed_recipient_domains', [] ) );
	}

	/** @return list<string> */
	private static function domain_list( mixed $v ): array {
		if ( is_string( $v ) ) {
			$v = preg_split( '/[\s,]+/', $v ) ?: [];
		}
		$out = [];
		foreach ( (array) $v as $d ) {
			$d = strtolower( trim( (string) ( is_array( $d ) ? reset( $d ) : $d ) ) );
			if ( '' !== $d ) {
				$out[] = ltrim( $d, '@' );
			}
		}
		return array_values( array_unique( $out ) );
	}
}
