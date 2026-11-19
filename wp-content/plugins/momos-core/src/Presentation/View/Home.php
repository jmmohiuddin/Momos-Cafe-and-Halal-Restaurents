<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Strings;

/**
 * Homepage / brand-level data that must never leave a hole (FR-CORP-001(e)(f), AC-CORP-01-04/05, Doc 07 §5 C01
 * "Empty state"). Pure reads through the Dietary and Branch services; no claim text is typed here.
 */
final class Home {
	/** Catalog key of the documented neutral fallback (Doc 07 §6.4 `brand.neutral` = Doc 11 §8.1). */
	public const NEUTRAL_KEY = 'halal.brand_neutral';

	/**
	 * Halal summary (FR-CORP-001(e), FR-CORP-003(a)): the brand statement selected in Brand settings
	 * (`momo_brand_halal_statement_key`, resolved by Dietary\ClaimResolver::brand_claim()), else the approved
	 * `brand.neutral` template; when neither is approved in $lang, the documented neutral sentence from the
	 * catalog, so the section never disappears and never mixes languages. The fallback carries no claim type,
	 * certifier or mark (AC-CORP-01-04).
	 *
	 * @return array<string,mixed> ClaimResolver::brand_claim() shape (+ `fallback` = true for the catalog text)
	 */
	public static function halal_claim( ?string $lang = null ): array {
		$lang  = $lang ?? Locale::current();
		$claim = null;
		if ( class_exists( \Momos\Dietary\Service\ClaimResolver::class ) ) {
			try {
				$claim = \Momos\Dietary\Service\ClaimResolver::brand_claim( $lang );
			} catch ( \Throwable $e ) {
				$claim = null;
			}
		}
		if ( is_array( $claim ) && '' !== trim( (string) ( $claim['statement'] ?? '' ) ) ) {
			return $claim + [ 'fallback' => false ];
		}
		return [
			'type'                => 'neutral',
			'template_key'        => 'brand.neutral',
			'statement'           => momos_t( self::NEUTRAL_KEY, [], $lang ),
			'statement_available' => false,
			'fallback'            => true,
		];
	}

	/**
	 * "How reservations work" response promise (FR-CORP-001(f), AC-CORP-01-05, DEC-013): shown only when the copy
	 * (`res.submitted.promise`) is approved in $lang and every branch that accepts online requests has the promise
	 * switched on with a response time; the longest configured time is used so the sentence holds for every branch.
	 * '' = omitted.
	 */
	public static function response_promise( ?string $lang = null ): string {
		$lang = $lang ?? Locale::current();
		if ( ! Strings::is_approved( 'res.submitted.promise', $lang ) || ! class_exists( \Momos\Branch\Repository\BranchSettingsRepository::class ) || ! class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			return '';
		}
		$max = 0;
		foreach ( \Momos\Branch\Repository\BranchRepository::public_ids( \Momos\Kernel\I18n\Lang::SOURCE ) as $id ) { // Every bookable branch, translated or not.
			$b = \Momos\Branch\Repository\BranchRepository::get( (int) $id );
			if ( null === $b || ! ActionBar::accepting( (int) $id, $b ) ) {
				continue;
			}
			$s = \Momos\Branch\Repository\BranchSettingsRepository::get( (int) $id );
			if ( empty( $s['show_response_promise'] ) || (int) ( $s['response_sla_minutes'] ?? 0 ) <= 0 ) {
				return ''; // One accepting branch without an approved promise: no brand-level promise.
			}
			$max = max( $max, (int) $s['response_sla_minutes'] );
		}
		return $max > 0 ? momos_t( 'res.submitted.promise', [ 'duration' => self::duration( $max, $lang ) ], $lang ) : '';
	}

	/** Minutes → "{n} minutes" / "{n} hours" (mirror of Notification Renderer::duration / reservation-common.js). */
	public static function duration( int $minutes, string $lang ): string {
		if ( $minutes >= 60 && 0 === $minutes % 60 ) {
			$h = intdiv( $minutes, 60 );
			return momos_t( 1 === $h ? 'email.duration.hour' : 'email.duration.hours', [ 'n' => $h ], $lang );
		}
		return momos_t( 'email.duration.minutes', [ 'n' => $minutes ], $lang );
	}
}
