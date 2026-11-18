<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Captcha outcome stored with a submission (CHECK chk_momo_res_captcha; FR-RES-007).
 */
enum CaptchaStatus: string {
	case Verified = 'verified';
	case UnverifiedOutage = 'unverified_outage';
	case NotApplicable = 'not_applicable';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}
}
