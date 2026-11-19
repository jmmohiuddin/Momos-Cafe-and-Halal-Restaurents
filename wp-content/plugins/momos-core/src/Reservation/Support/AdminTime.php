<?php
declare(strict_types=1);

namespace Momos\Reservation\Support;

use Momos\Kernel\Clock\Clock;
use Momos\Presentation\Format\DateFormatter;

/**
 * Staff-screen date/time labels (FR-I18N-010(c), AC-I18N-04-01; Doc 07 §6.10 / Doc 08 §6.6 "Admin (ja/en)":
 * ISO date in tables, 24 h time, weekday on reservation surfaces). Always Asia/Tokyo with a visible
 * "JST" label, the weekday in the viewing user's admin language (ja or en, FR-I18N-010(a)):
 * en `2026-12-03 (Thu) 19:00 JST` · ja `2026-12-03（木）19:00 JST`. Without ext-intl the weekday is
 * omitted (never a mixed-language string).
 */
final class AdminTime {
	/** Admin language of the current user: `ja` or `en` (FR-I18N-010(a)). */
	public static function lang(): string {
		return str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en';
	}

	/** Business-local requested date (`Y-m-d`) and time (`H:i`) → label. */
	public static function requested( string $date, string $time, ?string $lang = null ): string {
		try {
			$dt = new \DateTimeImmutable( $date . ' ' . substr( $time, 0, 5 ), Clock::tz() );
		} catch ( \Exception $e ) {
			return trim( $date . ' ' . $time ) . ' JST';
		}
		return self::format( $dt, $lang );
	}

	/** Instant (ISO 8601 with offset, e.g. Presenter::iso_jst(), or MySQL UTC) → label; '' when empty. */
	public static function instant( ?string $value, ?string $lang = null ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$dt = preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ? Clock::from_mysql_utc( $value ) : null;
		if ( null === $dt ) {
			try {
				$dt = new \DateTimeImmutable( $value );
			} catch ( \Exception $e ) {
				return $value;
			}
		}
		return self::format( $dt, $lang );
	}

	public static function format( \DateTimeInterface $dt, ?string $lang = null ): string {
		$lang = 'ja' === ( $lang ?? self::lang() ) ? 'ja' : 'en';
		$jst  = \DateTimeImmutable::createFromInterface( $dt )->setTimezone( Clock::tz() );
		$wd   = self::weekday( $jst, $lang );
		$date = $jst->format( 'Y-m-d' );
		$time = $jst->format( 'H:i' );
		if ( '' === $wd ) {
			return $date . ' ' . $time . ' JST';
		}
		return 'ja' === $lang ? $date . '（' . $wd . '）' . $time . ' JST' : $date . ' (' . $wd . ') ' . $time . ' JST';
	}

	private static function weekday( \DateTimeImmutable $jst, string $lang ): string {
		if ( ! class_exists( \IntlDateFormatter::class ) ) {
			return '';
		}
		$fmt = new \IntlDateFormatter( DateFormatter::locale( $lang ), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, DateFormatter::TZ, \IntlDateFormatter::GREGORIAN, 'EEE' );
		$out = $fmt->format( $jst );
		return is_string( $out ) ? $out : '';
	}
}
