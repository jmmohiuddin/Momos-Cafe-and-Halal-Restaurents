<?php
declare(strict_types=1);

namespace Momos\Notification\Template;

use Momos\Kernel\Clock\Clock;

/**
 * Email value formatting (Doc 07 §6.10, spec §4.6): ICU dates, Gregorian, Western digits,
 * Asia/Tokyo; 24-hour times; JST label only in non-ja customer emails (DEC-062); staff date-times;
 * given-name rule (FR-NOT-002(a), Doc 11 §5.4); phone display per language.
 */
final class EmailFormatter {
	/** Long date of a JST business date: ja 2026年12月3日（木）, en Thu 3 Dec 2026, others CLDR EEEEdMMMMy. */
	public static function date_long( string $ymd, string $lang ): string {
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', substr( $ymd, 0, 10 ), Clock::tz() );
		if ( ! $dt ) {
			return $ymd;
		}
		if ( ! class_exists( \IntlDateFormatter::class ) ) {
			return $dt->format( 'Y-m-d' );
		}
		$locale = $lang . '-u-nu-latn';
		$fmt    = new \IntlDateFormatter( $locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Asia/Tokyo', \IntlDateFormatter::GREGORIAN );
		if ( 'ja' === $lang ) {
			$fmt->setPattern( "y年M月d日'（'E'）'" );
		} elseif ( 'en' === $lang ) {
			$fmt->setPattern( 'EEE d MMM y' );
		} else {
			$pattern = class_exists( \IntlDatePatternGenerator::class ) ? ( \IntlDatePatternGenerator::create( $locale )?->getBestPattern( 'EEEEdMMMMy' ) ) : null;
			$fmt->setPattern( $pattern ?: 'EEEE d MMMM y' );
		}
		$out = $fmt->format( $dt );
		return false === $out ? $dt->format( 'Y-m-d' ) : (string) $out;
	}

	/** 24-hour HH:mm from H:i[:s]. */
	public static function time( string $his ): string {
		return substr( $his, 0, 5 );
	}

	/** Customer time with the JST label for non-ja languages (DEC-062). */
	public static function customer_time( string $his, string $lang, string $jst_label ): string {
		$t = self::time( $his );
		return 'ja' === $lang || '' === $jst_label ? $t : $t . ' ' . $jst_label;
	}

	/** Staff date-time from a UTC instant: en "2026-12-24 19:00 JST", ja "2026-12-24 19:00". */
	public static function staff_datetime( \DateTimeImmutable $utc, string $lang ): string {
		$s = $utc->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' );
		return 'en' === $lang ? $s . ' JST' : $s;
	}

	/** Staff date + time from the JST business date/time columns. */
	public static function staff_slot( string $ymd, string $his, string $lang ): string {
		$s = substr( $ymd, 0, 10 ) . ' ' . self::time( $his );
		return 'en' === $lang ? $s . ' JST' : $s;
	}

	/**
	 * Given name only: first token after splitting on the first whitespace (incl. U+3000), else the
	 * whole string; max 20 characters. "Aisha Rahman" → "Aisha".
	 */
	public static function given_name( ?string $guest_name ): string {
		$name = (string) preg_replace( '/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', (string) $guest_name ); // Multibyte-safe trim.
		if ( '' === $name ) {
			return '';
		}
		$parts = preg_split( '/[\s\x{3000}]+/u', $name, 2 );
		$first = is_array( $parts ) && '' !== $parts[0] ? $parts[0] : $name;
		return mb_substr( $first, 0, 20 );
	}

	/** Phone for display: ja national format, other languages international (+81 …). */
	public static function phone( string $e164, string $national, string $lang ): string {
		if ( 'ja' === $lang ) {
			return '' !== $national ? $national : $e164;
		}
		if ( '' !== $e164 && class_exists( \libphonenumber\PhoneNumberUtil::class ) ) {
			try {
				$util = \libphonenumber\PhoneNumberUtil::getInstance();
				return $util->format( $util->parse( $e164, 'JP' ), \libphonenumber\PhoneNumberFormat::INTERNATIONAL );
			} catch ( \Throwable $e ) {
				return $e164;
			}
		}
		return '' !== $e164 ? $e164 : $national;
	}

	/** Minutes as a short duration label used inside sentences ("120 min" / "120分"). */
	public static function minutes( int $minutes, string $lang ): string {
		return 'ja' === $lang ? $minutes . '分' : $minutes . ' min';
	}
}
