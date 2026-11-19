<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\I18n\Strings;
use Momos\Menu\Service\PriceFormatter;
use Momos\Presentation\Format\DateFormatter;
use Momos\Reservation\Validation\Digits;
use Momos\Tests\TestCase;

/**
 * QA (a11y-i18n team) — customer string catalogs and formatters across the six languages.
 *  - Prohibited wording (qa.md §7.7; TC-RES-021, TC-DIET-013/021, TC-REG-007): request-context strings never say
 *    "booking confirmed" (en/ja/ar lists), no claim terms, no ordering terms in any customer string.
 *  - Every customer key has a non-empty value in all six catalogs; no English sentence copied verbatim into
 *    ja/ar/ur/hi/bn (TC-I18N-011, DEC-027 — untranslated text is a gap, not a translation).
 *  - Digits are Western on output (DEC-062): no native digits in catalog values; DateFormatter keeps Latin digits
 *    for ar/ur/hi/bn (bn override) and uses the literal ja/en patterns; price + tax label per language.
 *  - Input normalisation of the five native digit blocks (TC-I18N-006, AC-I18N-01-03).
 */
final class QaA11yI18nCatalogTest extends TestCase {
	private const LANGS = [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ];

	public function set_up(): void {
		parent::set_up();
		Strings::flush();
	}

	public function tear_down(): void {
		Strings::flush();
		parent::tear_down();
	}

	/** Customer-facing keys only (staff/admin strings are ja/en admin copy, DEC-037). */
	private static function customer_keys(): array {
		return array_values( array_filter( array_keys( Strings::catalog( 'en' ) ), static fn( string $k ): bool => ! str_starts_with( $k, 'staff.' ) && ! str_starts_with( $k, 'admin.' ) ) );
	}

	/** Strings shown while a request is pending: C09, C10, C11 pending, E01, E04/E06 pending (qa.md §7.7). */
	private static function is_request_context( string $key ): bool {
		if ( str_starts_with( $key, 'res.' ) || in_array( $key, [ 'status.pending', 'email.subject.e01', 'email.e01.cancel_hint' ], true ) ) {
			return true;
		}
		return (bool) preg_match( '/^email\.(subject\.)?e0[46]\b.*pending/', $key );
	}

	public function test_request_context_strings_never_claim_a_confirmed_booking(): void {
		$lists = [
			'en' => '/\bbooked\b|you\'?re booked|reservation confirmed|booking confirmed|confirm table reservation|guarantee your seating|instant seating|your table is reserved|reservation complete|\byour reservation\b/i',
			'ja' => '/予約完了|予約確定|予約が確定|予約を確定|ご予約を承りました|このご予約/u',
			'ar' => '/تأكيد الحجز|تم تأكيد الحجز/u',
		];
		$allow = [ 'Request received — not yet confirmed', 'リクエスト受付・未確定', 'リクエストを受け付けました（まだ確定していません）' ];
		$hits  = [];
		foreach ( $lists as $lang => $re ) {
			foreach ( Strings::catalog( $lang ) as $key => $value ) {
				if ( ! self::is_request_context( (string) $key ) ) {
					continue;
				}
				$v = str_replace( $allow, '', (string) $value );
				if ( preg_match( $re, $v, $m ) ) {
					$hits[] = "{$lang} {$key}: \"{$m[0]}\" in \"{$value}\"";
				}
			}
		}
		$this->assertSame( [], $hits );
	}

	public function test_no_claim_or_ordering_terms_in_customer_strings(): void {
		$claim = [
			'en' => '/100\s?% halal|fully halal|allergen[- ]free|gluten[- ]free|safe for allerg|\bno risk\b|order now|add to cart|\bcheckout\b|place order|\btakeout\b|\bdelivery\b|coupon code|\bredeem\b/i',
			'ja' => '/100\s?%ハラール|完全ハラール|アレルゲンフリー|完全除去|アレルギーの方も安心|グルテンフリー|今すぐ注文|カートに追加|注文を確定/u',
		];
		$hits = [];
		foreach ( $claim as $lang => $re ) {
			foreach ( Strings::catalog( $lang ) as $key => $value ) {
				// Certified statements come from approved templates (halal.claim.certified*) and are allowed there.
				if ( str_starts_with( (string) $key, 'staff.' ) || str_starts_with( (string) $key, 'admin.' ) || preg_match( '/certified/', (string) $key ) ) {
					continue;
				}
				if ( preg_match( $re, (string) $value, $m ) ) {
					$hits[] = "{$lang} {$key}: \"{$m[0]}\"";
				}
			}
		}
		$this->assertSame( [], $hits );
	}

	public function test_every_customer_key_is_non_empty_in_all_six_languages(): void {
		$missing = [];
		foreach ( self::LANGS as $lang ) {
			$cat = Strings::catalog( $lang );
			foreach ( self::customer_keys() as $key ) {
				// The zone label is unused in Japanese by design (DEC-062: "JST" only in non-ja languages).
				if ( 'ja' === $lang && in_array( $key, [ 'email.jst', 'common.tz_label' ], true ) ) {
					continue;
				}
				if ( ! isset( $cat[ $key ] ) || '' === trim( (string) $cat[ $key ] ) ) {
					$missing[] = "{$lang}:{$key}";
				}
			}
		}
		$this->assertSame( [], $missing, 'customer keys missing/empty: ' . count( $missing ) );
	}

	public function test_no_english_sentence_copied_into_other_languages(): void {
		$en     = Strings::catalog( 'en' );
		$copied = [];
		foreach ( [ 'ja', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			$cat = Strings::catalog( $lang );
			foreach ( self::customer_keys() as $key ) {
				$v = (string) ( $en[ $key ] ?? '' );
				// Sentences only (≥ 3 words of Latin letters); brand names, codes and "JST" are allowed identical.
				if ( ! preg_match( '/[A-Za-z]{2,}\s+[A-Za-z]{2,}\s+[A-Za-z]{2,}/', $v ) ) {
					continue;
				}
				if ( isset( $cat[ $key ] ) && trim( (string) $cat[ $key ] ) === trim( $v ) ) {
					$copied[] = "{$lang}:{$key} = \"{$v}\"";
				}
			}
		}
		$this->assertSame( [], $copied );
	}

	public function test_catalog_values_use_western_digits(): void {
		$hits = [];
		foreach ( self::LANGS as $lang ) {
			foreach ( Strings::catalog( $lang ) as $key => $value ) {
				if ( str_starts_with( (string) $key, 'staff.' ) || str_starts_with( (string) $key, 'admin.' ) ) {
					continue;
				}
				if ( preg_match( '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0966}-\x{096F}\x{09E6}-\x{09EF}\x{FF10}-\x{FF19}]/u', (string) $value, $m ) ) {
					$hits[] = "{$lang} {$key}: \"{$m[0]}\"";
				}
			}
		}
		$this->assertSame( [], $hits );
	}

	public function test_placeholder_sets_match_english_in_every_language(): void {
		$en   = Strings::catalog( 'en' );
		$diff = [];
		foreach ( [ 'ja', 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			foreach ( Strings::catalog( $lang ) as $key => $value ) {
				if ( ! isset( $en[ $key ] ) ) {
					continue;
				}
				$a = Strings::placeholders( (string) $en[ $key ] );
				$b = Strings::placeholders( (string) $value );
				sort( $a );
				sort( $b );
				// Singular plural forms may spell the number out (ar "ساعة واحدة" for {n} = 1).
				if ( preg_match( '/(\.hour|_one)$/', (string) $key ) && array_values( array_diff( $a, [ 'n' ] ) ) === $b ) {
					continue;
				}
				if ( $a !== $b ) {
					$diff[] = "{$lang}:{$key} " . implode( ',', $b ) . ' ≠ ' . implode( ',', $a );
				}
			}
		}
		$this->assertSame( [], $diff );
	}

	public function test_dates_keep_western_digits_and_documented_patterns(): void {
		if ( ! class_exists( \IntlDateFormatter::class ) ) {
			$this->markTestSkipped( 'ext-intl missing' );
		}
		$d = new \DateTimeImmutable( '2026-12-03 19:00:00', new \DateTimeZone( 'Asia/Tokyo' ) );
		$this->assertSame( '2026年12月3日（木）', DateFormatter::long( $d, 'ja' ) );
		$this->assertSame( '12/3（木）', DateFormatter::short( $d, 'ja' ) );
		$this->assertSame( 'Thu 3 Dec 2026', DateFormatter::long( $d, 'en' ) );
		$this->assertSame( 'Thu 3 Dec', DateFormatter::short( $d, 'en' ) );
		$this->assertSame( '19:00', DateFormatter::time( $d ) );
		$this->assertSame( '19:00', DateFormatter::time_with_zone( $d, 'ja' ), 'no zone label in ja' );
		$this->assertSame( '19:00 JST', DateFormatter::time_with_zone( $d, 'en' ) );
		foreach ( [ 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			foreach ( [ DateFormatter::long( $d, $lang ), DateFormatter::short( $d, $lang ) ] as $s ) {
				$this->assertDoesNotMatchRegularExpression( '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0966}-\x{096F}\x{09E6}-\x{09EF}]/u', $s, "{$lang}: {$s}" );
				$this->assertStringContainsString( '3', $s, "{$lang}: day number in Western digits: {$s}" );
			}
			$this->assertStringContainsString( '2026', DateFormatter::long( $d, $lang ) );
		}
	}

	public function test_price_and_tax_label_per_language(): void {
		$this->assertSame( '¥1,500（税込）', PriceFormatter::text( 1500, 'ja' ) );
		$this->assertSame( '¥1,500 (tax incl.)', PriceFormatter::text( 1500, 'en' ) );
		foreach ( [ 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
			$html = PriceFormatter::html( 1500, $lang );
			$this->assertStringStartsWith( '<bdi dir="ltr">¥1,500</bdi>', $html, $lang );
			$this->assertDoesNotMatchRegularExpression( '/tax incl/i', $html, "{$lang}: tax label translated" );
		}
	}

	public function test_native_digit_input_is_normalised(): void {
		$this->assertSame( '4', Digits::normalize( '٤' ) );
		$this->assertSame( '4', Digits::normalize( '۴' ) );
		$this->assertSame( '4', Digits::normalize( '४' ) );
		$this->assertSame( '4', Digits::normalize( '৪' ) );
		$this->assertSame( '4', Digits::normalize( '４' ) );
		$this->assertSame( '+81 90-1234-5678', Digits::normalize( '+٨١ ٩٠-١٢٣٤-٥٦٧٨' ) );
	}

	public function test_bdi_helper_isolates_ltr(): void {
		$this->assertSame( '<bdi dir="ltr">+81 6-0000-0001</bdi>', momos_bdi( '+81 6-0000-0001' ) );
		$this->assertStringContainsString( '&lt;', momos_bdi( '<b>' ), 'escaped' );
	}
}
