<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;

/**
 * Per-language publication gate of SCR-C03 (FR-CORP-003, AC-CORP-03-03, BR-DIET-05, BR-I18N-02): the halal page
 * is published in a non-source language only while every statement template it needs (the one resolved for each
 * public branch row) has a reviewed/approved translation there. Otherwise `/{lang}/halal/` is a 404 instead of a
 * page with blank or mixed-language statements. The Japanese source page is always published.
 *
 * Layout note: the register stays as cards — FR-CORP-003(b) lists 8 columns (branch, claim type, certifier, scope,
 * certificate no., valid until, alcohol, last verified), more than the 5 that fit a table (screens.md §3.3 rule
 * "table if ≤ 5 columns fit, else cards").
 */
final class HalalPage {
	public static function published( ?string $lang = null ): bool {
		$lang = $lang ?? Locale::current();
		if ( Lang::SOURCE === $lang || ! class_exists( \Momos\Dietary\Service\ClaimResolver::class ) ) {
			return true;
		}
		try {
			$rows = \Momos\Dietary\Service\ClaimResolver::halal_page_rows( $lang );
		} catch ( \Throwable $e ) {
			return true; // Register failure: the template shows its "unavailable" box (never stale claims).
		}
		foreach ( $rows as $row ) {
			if ( '' === (string) ( $row['name'] ?? '' ) ) {
				continue; // Branch not translated: not listed on the page at all.
			}
			if ( empty( $row['statement_available'] ) ) {
				return false;
			}
		}
		return true;
	}
}
