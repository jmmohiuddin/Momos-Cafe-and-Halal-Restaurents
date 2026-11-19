<?php
declare(strict_types=1);

namespace Momos\Kernel\Clock;

/**
 * Single source of "now" (SDD §3.6). All business logic asks the Clock; tests freeze it.
 */
abstract class Clock {
	private static ?Clock $instance = null;

	abstract public function now(): \DateTimeImmutable;

	public static function get(): Clock {
		if ( null === self::$instance ) {
			$frozen         = defined( 'MOMOS_FROZEN_NOW' ) ? (string) MOMOS_FROZEN_NOW : (string) get_option( 'momos_frozen_now', '' );
			self::$instance = ( '' !== $frozen && ! \Momos\Kernel\Env::is_production() ) ? new FrozenClock( new \DateTimeImmutable( $frozen ) ) : new SystemClock();
		}
		return self::$instance;
	}

	public static function set( ?Clock $clock ): void {
		self::$instance = $clock;
	}

	/** Current time in UTC. */
	public static function utc(): \DateTimeImmutable {
		return self::get()->now()->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/** Current time in Japan (DEC-050). */
	public static function jst(): \DateTimeImmutable {
		return self::get()->now()->setTimezone( self::tz() );
	}

	public static function tz(): \DateTimeZone {
		return new \DateTimeZone( 'Asia/Tokyo' );
	}

	/** MySQL DATETIME string in UTC. */
	public static function mysql_utc( ?\DateTimeImmutable $t = null ): string {
		return ( $t ?? self::utc() )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** Parse a UTC MySQL DATETIME. */
	public static function from_mysql_utc( ?string $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/** Combine a JST business-local date (Y-m-d) and time (H:i[:s]). */
	public static function jst_at( string $date, string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $date . ' ' . $time, self::tz() );
	}
}
