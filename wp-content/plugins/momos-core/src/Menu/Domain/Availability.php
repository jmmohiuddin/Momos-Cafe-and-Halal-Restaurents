<?php
declare(strict_types=1);

namespace Momos\Menu\Domain;

/** Branch menu row availability (spec §2.2, DB CHECK chk_momo_bmi_availability). */
enum Availability: string {
	case Available   = 'available';
	case SoldOut     = 'sold_out';
	case Unavailable = 'unavailable';

	/** @return list<string> */
	public static function values(): array {
		return array_map( static fn( self $c ) => $c->value, self::cases() );
	}

	public function label(): string {
		return match ( $this ) {
			self::Available   => __( 'Available', 'momos-core' ),
			self::SoldOut     => __( 'Sold out', 'momos-core' ),
			self::Unavailable => __( 'Unavailable', 'momos-core' ),
		};
	}
}
