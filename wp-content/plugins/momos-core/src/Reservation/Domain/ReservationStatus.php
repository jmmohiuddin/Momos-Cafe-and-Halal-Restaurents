<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Reservation statuses (DEC-011, spec §2.3; mirrors CHECK chk_momo_res_status of M0002).
 * pending/confirmed are active; every other status is terminal (BR-RES-05).
 */
enum ReservationStatus: string {
	case Pending   = 'pending';
	case Confirmed = 'confirmed';
	case Rejected  = 'rejected';
	case Cancelled = 'cancelled';
	case Expired   = 'expired';
	case Completed = 'completed';
	case NoShow    = 'no_show';

	public function isActive(): bool {
		return self::Pending === $this || self::Confirmed === $this;
	}

	public function isTerminal(): bool {
		return ! $this->isActive();
	}

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ): string => $c->value, self::cases() );
	}

	public static function is_valid( mixed $value ): bool {
		return is_string( $value ) && null !== self::tryFrom( $value );
	}

	/** Staff label (admin ja/en, spec §8.3). */
	public function staffLabel(): string {
		return match ( $this ) {
			self::Pending   => __( 'Pending', 'momos-core' ),
			self::Confirmed => __( 'Accepted', 'momos-core' ),
			self::Rejected  => __( 'Declined', 'momos-core' ),
			self::Cancelled => __( 'Cancelled', 'momos-core' ),
			self::Expired   => __( 'Expired', 'momos-core' ),
			self::Completed => __( 'Attended', 'momos-core' ),
			self::NoShow    => __( 'No-show', 'momos-core' ),
		};
	}
}
