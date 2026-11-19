<?php
declare(strict_types=1);

namespace Momos\Dietary\Service;

use Momos\Dietary\Domain\ClaimType;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Log\Logger;
use Momos\Menu\Service\MenuQueryService;
use Momos\Menu\Support\Branches;

/**
 * Halal and alcohol display data (DEC-020, FR-DIET-002/003/004, CMP-013, spec §3.10/§3.11).
 * Statements come only from approved G2 templates (human-translated, approved per language) with
 * variables filled from the register. A record is displayable only when active, within validity
 * (JST, valid_until inclusive) and verified — checked at render time, independent of the expiry job.
 * Non-displayable or missing records fall back to `no_claim` (never auto-downgraded). Any exception →
 * nothing rendered and an ERROR log. Contract: docs/CONTRACTS.md.
 */
final class ClaimResolver {
	/**
	 * @return array<string,mixed> ['type','template_key','statement','statement_available','label','certifier','scope',
	 *   'scope_label','number','valid_until','valid_until_display','mark_url','mark_id','mark_alt','register_url','record_id',
	 *   'verified_at','last_verified','alcohol_served','alcohol_in_cooking','alcohol_lines']
	 */
	public static function branch_claim( int $branch_id, ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		try {
			return self::resolve_branch( $branch_id, $lang );
		} catch ( \Throwable $e ) {
			Logger::error( 'dietary.claim_resolve_failed', [ 'branch_id' => $branch_id, 'error' => $e->getMessage() ] );
			return self::empty_view( 'none' );
		}
	}

	/**
	 * Dish halal line by `momo_dish_halal_basis` (never omitted; FR-DIET-003(e)).
	 *
	 * @return array<string,mixed> branch_claim() shape + 'basis', 'statement_html', 'suitable_for_halal_diet', 'branch'
	 */
	public static function dish_claim( int $dish_id, ?int $branch_id = null, ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		try {
			$view           = self::resolve_dish( $dish_id, $lang );
			$view['branch'] = null !== $branch_id ? self::branch_claim( $branch_id, $lang ) : null;
			return $view;
		} catch ( \Throwable $e ) {
			Logger::error( 'dietary.claim_resolve_failed', [ 'dish_id' => $dish_id, 'error' => $e->getMessage() ] );
			return self::empty_view( 'none' ) + [ 'basis' => '', 'statement_html' => '', 'suitable_for_halal_diet' => false, 'branch' => null ];
		}
	}

	/** Brand-level statement (home/about): an approved `brand.*` statement, else `brand.neutral`. */
	public static function brand_claim( ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		foreach ( StatementRepository::all() as $key => $row ) {
			if ( str_starts_with( $key, 'brand.' ) && 'brand.neutral' !== $key && null !== ( $text = StatementRepository::text( $key, $lang ) ) ) {
				return [ 'template_key' => $key, 'statement' => $text, 'statement_available' => true ];
			}
		}
		$text = StatementRepository::text( 'brand.neutral', $lang );
		return [ 'template_key' => 'brand.neutral', 'statement' => (string) $text, 'statement_available' => null !== $text ];
	}

	/** Halal page (SCR-C03) rows: one per public branch. @return list<array<string,mixed>> */
	public static function halal_page_rows( ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		$rows = [];
		foreach ( Branches::published_ids() as $id ) {
			if ( Branches::is_public( $id ) ) {
				$rows[] = [ 'branch_id' => $id, 'name' => Branches::name( $id, $lang ), 'url' => Branches::url( $id, $lang ) ] + self::branch_claim( $id, $lang );
			}
		}
		return $rows;
	}

	/** "All branches certified" wording is allowed only when every public branch has a displayable certified record. */
	public static function all_branches_certified(): bool {
		$ids = array_values( array_filter( Branches::published_ids(), static fn( $id ) => Branches::is_public( $id ) ) );
		if ( ! $ids ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( 'certified' !== self::branch_claim( $id, Lang::SOURCE )['type'] ) {
				return false;
			}
		}
		return true;
	}

	/** Localised date for claim text (ja: 2026年12月31日; others ISO, Western digits). */
	public static function format_date( string $ymd, string $lang ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) {
			return $ymd;
		}
		if ( Lang::SOURCE === $lang ) {
			return (int) $m[1] . '年' . (int) $m[2] . '月' . (int) $m[3] . '日';
		}
		if ( 'en' === $lang ) {
			return ( new \DateTimeImmutable( $ymd ) )->format( 'j M Y' );
		}
		return $ymd;
	}

	// ------------------------------------------------------------------ internals

	/** @return array<string,mixed> */
	private static function resolve_branch( int $branch_id, string $lang ): array {
		if ( ! Branches::is_published( $branch_id ) ) {
			return self::empty_view( 'none' );
		}
		$stored = (string) get_post_meta( $branch_id, 'momo_halal_claim_type', true );
		$record = (int) get_post_meta( $branch_id, 'momo_halal_claim_record', true );
		$type   = ClaimType::NoClaim->value;
		if ( in_array( $stored, [ 'certified', 'halal_ingredients' ], true )
			&& CertificateService::record_links_branch( $record, $branch_id, $stored )
			&& CertificateService::is_displayable( $record ) ) {
			$type = $stored;
		}
		$view = self::empty_view( $type );
		if ( ClaimType::NoClaim->value === $type ) {
			$view['template_key'] = 'no_claim';
			$text                 = StatementRepository::text( 'no_claim', $lang );
		} else {
			$scope                = (string) get_post_meta( $record, 'momo_cert_scope', true );
			$key                  = self::template_for( $type, $scope, (string) get_post_meta( $branch_id, 'momo_halal_statement_key', true ), (string) get_post_meta( $record, 'momo_cert_statement_key', true ) );
			$view                 = array_merge( $view, self::record_view( $record, $lang ) );
			$view['template_key'] = $key;
			$view['label']        = momos_t( 'certified' === $type ? 'halal.label.certified' : 'halal.label.ingredients', [], $lang );
			$text                 = self::fill( $key, $record, $lang );
		}
		$view['statement']           = (string) $text;
		$view['statement_available'] = null !== $text;

		// Last verified = later of the record's verified_at and the branch stamp.
		$dates = array_filter( [ (string) get_post_meta( $branch_id, 'momo_claims_verified_at', true ), ClaimType::NoClaim->value !== $type ? (string) get_post_meta( $record, 'momo_cert_verified_at', true ) : '' ] );
		if ( $dates ) {
			$latest                = Clock::from_mysql_utc( max( $dates ) );
			$view['verified_at']   = $latest ? $latest->setTimezone( Clock::tz() )->format( 'Y-m-d' ) : null;
			$view['last_verified'] = $view['verified_at'] ? momos_t( 'last_verified', [ 'date' => self::format_date( $view['verified_at'], $lang ) ], $lang ) : null;
		}

		// Alcohol policy (unknown = not displayed).
		$served                     = self::alcohol_value( (string) get_post_meta( $branch_id, 'momo_alcohol_served', true ) );
		$cooking                    = self::alcohol_value( (string) get_post_meta( $branch_id, 'momo_alcohol_in_cooking', true ) );
		$view['alcohol_served']     = $served;
		$view['alcohol_in_cooking'] = $cooking;
		$lines                      = [];
		if ( 'unknown' !== $served && null !== ( $t = StatementRepository::text( 'alcohol.served.' . $served, $lang ) ) ) {
			$lines[] = $t;
		}
		if ( 'unknown' !== $cooking && null !== ( $t = StatementRepository::text( 'alcohol.cooking.' . $cooking, $lang ) ) ) {
			$lines[] = $t;
		}
		$note = (string) get_post_meta( $branch_id, 'momo_alcohol_note_key', true );
		if ( '' !== $note && null !== ( $t = StatementRepository::text( $note, $lang ) ) ) {
			$lines[] = $t;
		}
		$view['alcohol_lines'] = $lines;
		return $view;
	}

	/** @return array<string,mixed> */
	private static function resolve_dish( int $dish_id, string $lang ): array {
		$basis  = (string) get_post_meta( $dish_id, 'momo_dish_halal_basis', true );
		$basis  = '' === $basis ? 'branch_default' : $basis;
		$record = (int) get_post_meta( $dish_id, 'momo_dish_claim_record', true );
		$view   = self::empty_view( 'no_claim' ) + [ 'basis' => $basis, 'statement_html' => '', 'suitable_for_halal_diet' => false ];

		if ( 'certified_item' === $basis && CertificateService::record_links_dish( $record, $dish_id, 'certified', 'specific_menu' ) && CertificateService::is_displayable( $record ) ) {
			$view                            = array_merge( $view, self::record_view( $record, $lang ) );
			$view['type']                    = 'certified';
			$view['template_key']            = 'certified.specific_menu';
			$view['label']                   = momos_t( 'halal.label.certified', [], $lang );
			$view['suitable_for_halal_diet'] = true; // JSON-LD HalalDiet only here (FR-SEO-006(d)).
			$text                            = self::fill( 'certified.specific_menu', $record, $lang );
		} elseif ( 'halal_ingredients' === $basis && CertificateService::record_links_dish( $record, $dish_id, 'halal_ingredients', null ) && CertificateService::is_displayable( $record ) ) {
			$view                 = array_merge( $view, self::record_view( $record, $lang ) );
			$view['type']         = 'halal_ingredients';
			$view['template_key'] = 'halal_ingredients';
			$view['label']        = momos_t( 'halal.label.ingredients', [], $lang );
			$text                 = self::fill( 'halal_ingredients', $record, $lang );
		} elseif ( 'branch_default' === $basis ) {
			$branches = array_values( array_filter( MenuQueryService::branches_offering( $dish_id ), static fn( $b ) => '' !== Branches::name( $b, $lang ) ) );
			if ( $branches ) {
				$view['template_key']   = 'branch_default';
				$raw                    = StatementRepository::text( 'branch_default', $lang );
				$names                  = array_map( static fn( $b ) => Branches::name( $b, $lang ), $branches );
				$links                  = array_map( static fn( $b ) => '<a href="' . esc_url( Branches::url( $b, $lang ) ) . '">' . esc_html( Branches::name( $b, $lang ) ) . '</a>', $branches );
				$sep                    = momos_t( 'diet.list.separator', [], $lang );
				$text                   = null === $raw ? null : strtr( $raw, [ '{branch_links}' => implode( $sep, $names ) ] );
				$view['statement_html'] = null === $raw ? '' : strtr( esc_html( $raw ), [ '{branch_links}' => implode( esc_html( $sep ), $links ) ] );
			} else {
				$view['template_key'] = 'brand.neutral';
				$text                 = StatementRepository::text( 'brand.neutral', $lang );
			}
		} else {
			$view['template_key'] = 'no_claim';
			$text                 = StatementRepository::text( 'no_claim', $lang );
		}
		$view['statement']           = (string) $text;
		$view['statement_available'] = null !== $text;
		if ( '' === $view['statement_html'] && null !== $text ) {
			$view['statement_html'] = esc_html( (string) $text );
		}
		$verified = (string) get_post_meta( $dish_id, 'momo_claims_verified_at', true );
		if ( 'no_claim' !== $view['type'] ) {
			$verified = max( $verified, (string) get_post_meta( $record, 'momo_cert_verified_at', true ) );
		}
		if ( '' !== $verified && ( $dt = Clock::from_mysql_utc( $verified ) ) ) {
			$view['verified_at']   = $dt->setTimezone( Clock::tz() )->format( 'Y-m-d' );
			$view['last_verified'] = momos_t( 'last_verified', [ 'date' => self::format_date( $view['verified_at'], $lang ) ], $lang );
		}
		return $view;
	}

	private static function template_for( string $type, string $scope, string $branch_key, string $record_key ): string {
		$wanted = 'certified' === $type ? 'certified.' . $scope : 'halal_ingredients';
		foreach ( [ $branch_key, $record_key ] as $k ) {
			$row = '' !== $k ? StatementRepository::get( $k ) : null;
			if ( $row && $row['claim_type'] === $type && ( 'certified' !== $type || $k === $wanted ) ) {
				return $k;
			}
		}
		return $wanted;
	}

	/** Template text with register variables, or null when not approved / not fully translatable in $lang. */
	private static function fill( string $key, int $record, string $lang ): ?string {
		$raw = StatementRepository::text( $key, $lang );
		if ( null === $raw ) {
			return null;
		}
		$vars = [
			'{certifier}'   => (string) get_post_meta( $record, 'momo_cert_certifier_name', true ),
			'{cert_no}'     => (string) get_post_meta( $record, 'momo_cert_number', true ),
			'{valid_until}' => self::format_date( (string) get_post_meta( $record, 'momo_cert_valid_until', true ), $lang ),
		];
		if ( str_contains( $raw, '{covered_ingredients}' ) ) {
			$names = [];
			foreach ( array_filter( array_map( 'intval', (array) get_post_meta( $record, 'momo_cert_covered_ingredients', true ) ) ) as $tid ) {
				$n = Translations::term( $tid, 'name', $lang );
				if ( '' === $n ) {
					return null; // No mixed-language claim text (BR-DIET-05).
				}
				$names[] = $n;
			}
			if ( ! $names ) {
				return null;
			}
			$vars['{covered_ingredients}'] = implode( momos_t( 'diet.list.separator', [], $lang ), $names );
		}
		return strtr( $raw, $vars );
	}

	/** @return array<string,mixed> */
	private static function record_view( int $record, string $lang ): array {
		$scope = (string) get_post_meta( $record, 'momo_cert_scope', true );
		$until = (string) get_post_meta( $record, 'momo_cert_valid_until', true );
		$mark  = CertificateService::mark_allowed( $record ) ? (int) get_post_meta( $record, 'momo_cert_mark_image', true ) : 0;
		$cert  = (string) get_post_meta( $record, 'momo_cert_certifier_name', true );
		$url   = (string) get_post_meta( $record, 'momo_cert_public_register_url', true );
		return [
			'record_id'           => $record,
			'certifier'           => '' !== $cert ? $cert : null,
			'scope'               => '' !== $scope ? $scope : null,
			'scope_label'         => '' !== $scope ? momos_t( 'halal.scope.' . $scope, [], $lang ) : null,
			'number'              => (string) get_post_meta( $record, 'momo_cert_number', true ) ?: null,
			'valid_until'         => '' !== $until ? $until : null,
			'valid_until_display' => '' !== $until ? self::format_date( $until, $lang ) : null,
			'mark_id'             => $mark ?: null,
			'mark_url'            => $mark ? (string) wp_get_attachment_image_url( $mark, 'medium' ) : null,
			'mark_alt'            => $mark ? momos_t( 'halal.mark_alt', [ 'certifier' => $cert ], $lang ) : null,
			'register_url'        => str_starts_with( $url, 'https://' ) ? $url : null,
		];
	}

	private static function alcohol_value( string $v ): string {
		return in_array( $v, [ 'yes', 'no' ], true ) ? $v : 'unknown';
	}

	/** @return array<string,mixed> */
	private static function empty_view( string $type ): array {
		return [
			'type'                => $type,
			'template_key'        => null,
			'statement'           => '',
			'statement_available' => false,
			'label'               => null,
			'certifier'           => null,
			'scope'               => null,
			'scope_label'         => null,
			'number'              => null,
			'valid_until'         => null,
			'valid_until_display' => null,
			'mark_url'            => null,
			'mark_id'             => null,
			'mark_alt'            => null,
			'register_url'        => null,
			'record_id'           => null,
			'verified_at'         => null,
			'last_verified'       => null,
			'alcohol_served'      => 'unknown',
			'alcohol_in_cooking'  => 'unknown',
			'alcohol_lines'       => [],
		];
	}
}
