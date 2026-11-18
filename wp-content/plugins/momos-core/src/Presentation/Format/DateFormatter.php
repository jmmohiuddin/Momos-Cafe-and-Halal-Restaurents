<?php
declare(strict_types=1);

namespace Momos\Presentation\Format;

use Momos\Kernel\I18n\Lang;

/**
 * Localised dates and times (FR-I18N-006, DEC-062, i18n-seo-analytics.md §4.4). Always Gregorian,
 * Asia/Tokyo, 24 h, Western digits (`-u-nu-latn`, incl. the bn override). ja/en use literal patterns;
 * ar/ur/hi/bn use CLDR skeletons via IntlDatePatternGenerator. Requires ext-intl; without it a
 * numeric ISO fallback is returned (never a mixed-language string).
 */
final class DateFormatter {
	public const TZ = 'Asia/Tokyo';

	private const SKELETON = [ 'long' => 'EEEEdMMMMy', 'short' => 'EEEdMMM' ];

	public static function locale( string $lang ): string {
		return match ( $lang ) {
			'ja' => 'ja-JP-u-ca-gregory-nu-latn',
			'en' => 'en-GB-u-ca-gregory-nu-latn',
			default => $lang . '-u-ca-gregory-nu-latn',
		};
	}

	/** Long date: ja 2026年12月3日（木） · en Thu 3 Dec 2026 · others CLDR EEEEdMMMMy. */
	public static function long( \DateTimeInterface|string $date, ?string $lang = null ): string {
		return self::format( $date, $lang ?? momos_lang(), 'long' );
	}

	/** Short date: ja 12/3（木） · en Thu 3 Dec · others CLDR EEEdMMM. */
	public static function short( \DateTimeInterface|string $date, ?string $lang = null ): string {
		return self::format( $date, $lang ?? momos_lang(), 'short' );
	}

	/** 24 h time "19:00" (Western digits in every language). */
	public static function time( \DateTimeInterface|string $time ): string {
		if ( is_string( $time ) ) {
			return preg_match( '/^(\d{1,2}):(\d{2})/', $time, $m ) ? sprintf( '%d:%s', (int) $m[1], $m[2] ) : $time;
		}
		return self::to_tokyo( $time )->format( 'G:i' );
	}

	/** Time + zone label for reservation surfaces (non-ja only, `common.tz_label`). */
	public static function time_with_zone( \DateTimeInterface|string $time, ?string $lang = null ): string {
		$lang  = $lang ?? momos_lang();
		$label = 'ja' === $lang ? '' : momos_t( 'common.tz_label', [], $lang );
		return trim( self::time( $time ) . ' ' . $label );
	}

	private static function format( \DateTimeInterface|string $date, string $lang, string $kind ): string {
		$dt = self::to_tokyo( is_string( $date ) ? new \DateTimeImmutable( $date, new \DateTimeZone( self::TZ ) ) : $date );
		if ( 'ja' === $lang ) {
			$wd = [ '日', '月', '火', '水', '木', '金', '土' ][ (int) $dt->format( 'w' ) ];
			return 'long' === $kind ? $dt->format( 'Y年n月j日' ) . '（' . $wd . '）' : $dt->format( 'n/j' ) . '（' . $wd . '）';
		}
		if ( ! class_exists( \IntlDateFormatter::class ) ) {
			return $dt->format( 'Y-m-d' );
		}
		if ( 'en' === $lang ) {
			$pattern = 'long' === $kind ? 'EEE d MMM y' : 'EEE d MMM';
		} elseif ( class_exists( \IntlDatePatternGenerator::class ) ) {
			$gen     = new \IntlDatePatternGenerator( self::locale( $lang ) );
			$pattern = (string) $gen->getBestPattern( self::SKELETON[ $kind ] );
		} else {
			$pattern = 'long' === $kind ? 'EEEE d MMMM y' : 'EEE d MMM';
		}
		$fmt = new \IntlDateFormatter( self::locale( $lang ), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, self::TZ, \IntlDateFormatter::GREGORIAN, $pattern );
		$out = $fmt->format( $dt );
		return is_string( $out ) ? self::latin_digits( $out ) : $dt->format( 'Y-m-d' );
	}

	private static function to_tokyo( \DateTimeInterface $dt ): \DateTimeImmutable {
		return \DateTimeImmutable::createFromInterface( $dt )->setTimezone( new \DateTimeZone( self::TZ ) );
	}

	/** Safety net: force Western digits even if an ICU build ignores `nu-latn`. */
	private static function latin_digits( string $s ): string {
		static $map = null;
		if ( null === $map ) {
			$map = [];
			foreach ( [ 0x0660, 0x06F0, 0x0966, 0x09E6 ] as $base ) {
				for ( $i = 0; $i < 10; $i++ ) {
					$map[ mb_chr( $base + $i, 'UTF-8' ) ] = (string) $i;
				}
			}
		}
		return strtr( $s, $map );
	}

	/** @internal for tests */
	public static function is_rtl_lang( string $lang ): bool {
		return Lang::is_rtl( $lang );
	}
}
