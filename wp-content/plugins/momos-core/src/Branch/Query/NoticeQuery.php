<?php
declare(strict_types=1);

namespace Momos\Branch\Query;

use Momos\Branch\Domain\NoticeScope;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Repository\BranchRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Active notices for the presentation layer (FR-BR-009, spec §4.10).
 *
 * Active ⇔ published ∧ start ≤ now ∧ (end null ∨ now < end) (UTC) ∧ (lang = ja ∨ approved).
 * Order: warning before info, then start desc. With $upcoming_hours > 0 notices starting within
 * that window are included with `upcoming = true` (render hidden with data-start/end, cache safety).
 */
final class NoticeQuery {
	/** @return list<array<string,mixed>> */
	public static function site_wide( ?string $lang = null, int $upcoming_hours = 0, ?\DateTimeImmutable $now = null ): array {
		return array_values( array_filter( self::active( $lang, $upcoming_hours, $now ), static fn( $n ) => NoticeScope::SiteWide->value === $n['scope'] ) );
	}

	/**
	 * Notices for a branch page / directory chip / reservation form. TEST branches only with
	 * $include_test (staff preview); an offer/notice scoped only to TEST is never public.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function for_branch( int $branch_id, ?string $lang = null, int $upcoming_hours = 0, ?\DateTimeImmutable $now = null, bool $include_test = false ): array {
		if ( ! $include_test && BranchRepository::is_test( $branch_id ) ) {
			return [];
		}
		return array_values( array_filter( self::active( $lang, $upcoming_hours, $now ), static fn( $n ) => NoticeScope::Branches->value === $n['scope'] && in_array( $branch_id, $n['branch_ids'], true ) ) );
	}

	/** @return list<array<string,mixed>> all active (and optionally upcoming) notices */
	public static function active( ?string $lang = null, int $upcoming_hours = 0, ?\DateTimeImmutable $now = null ): array {
		$lang = $lang ?? Lang::SOURCE;
		if ( ! Lang::is_valid( $lang ) || ! Lang::is_enabled( $lang ) ) {
			return [];
		}
		$now   = $now ?? Clock::utc();
		$limit = $now->modify( '+' . max( 0, $upcoming_hours ) . ' hours' );
		$meta  = [
			'relation' => 'AND',
			[ 'key' => '_momo_notice_start_utc', 'value' => Clock::mysql_utc( $limit ), 'compare' => '<=', 'type' => 'DATETIME' ],
			[
				'relation' => 'OR',
				[ 'key' => '_momo_notice_end_utc', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_momo_notice_end_utc', 'value' => Clock::mysql_utc( $now ), 'compare' => '>', 'type' => 'DATETIME' ],
			],
		];
		foreach ( Translations::meta_query( $lang ) as $clause ) {
			$meta[] = $clause;
		}
		$ids = get_posts(
			[
				'post_type'        => 'momo_notice',
				'post_status'      => 'publish',
				'posts_per_page'   => 100,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => $meta, // phpcs:ignore
			]
		);
		$out = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$n = self::item( $id, $lang, $now );
			if ( null !== $n ) {
				$out[] = $n;
			}
		}
		usort( $out, static fn( $a, $b ) => [ 'warning' === $a['severity'] ? 0 : 1, -$a['start_ms'] ] <=> [ 'warning' === $b['severity'] ? 0 : 1, -$b['start_ms'] ] );
		return $out;
	}

	/** @return array<string,mixed>|null */
	public static function item( int $id, string $lang, ?\DateTimeImmutable $now = null ): ?array {
		$now   = $now ?? Clock::utc();
		$start = Clock::from_mysql_utc( (string) get_post_meta( $id, '_momo_notice_start_utc', true ) ?: null );
		$end   = Clock::from_mysql_utc( (string) get_post_meta( $id, '_momo_notice_end_utc', true ) ?: null );
		if ( null === $start ) {
			return null;
		}
		$scope    = (string) get_post_meta( $id, 'momo_notice_scope', true ) === NoticeScope::SiteWide->value ? NoticeScope::SiteWide->value : NoticeScope::Branches->value;
		$branches = NoticeScope::Branches->value === $scope ? BranchScopeTaxonomy::branch_ids_of_post( $id ) : [];
		if ( NoticeScope::Branches->value === $scope && ! $branches ) {
			return null;
		}
		$body = Translations::post( $id, 'momo_notice_body', $lang );
		if ( '' === trim( $body ) ) {
			return null; // Never a mixed-language or empty banner (BR-I18N-02).
		}
		$url   = Translations::post( $id, 'momo_notice_link_url', $lang );
		$label = Translations::post( $id, 'momo_notice_link_label', $lang );
		if ( '' !== $url && str_starts_with( $url, '/' ) ) {
			$url = momos_url( $url, $lang );
		}
		return [
			'id'         => $id,
			'title'      => Translations::post( $id, 'title', $lang ),
			'body'       => $body,
			'severity'   => 'warning' === get_post_meta( $id, 'momo_notice_severity', true ) ? 'warning' : 'info',
			'scope'      => $scope,
			'branch_ids' => $branches,
			'link_url'   => '' !== $label ? $url : '',
			'link_label' => '' !== $url ? $label : '',
			'start_utc'  => $start->format( 'c' ),
			'end_utc'    => $end ? $end->format( 'c' ) : null,
			'start_ms'   => $start->getTimestamp() * 1000,
			'end_ms'     => $end ? $end->getTimestamp() * 1000 : null,
			'upcoming'   => $start > $now,
			'dismissible' => NoticeScope::SiteWide->value === $scope && 'warning' !== get_post_meta( $id, 'momo_notice_severity', true ),
		];
	}

	/** Computed admin state: draft · scheduled · active · expired. */
	public static function state( int $id, ?\DateTimeImmutable $now = null ): string {
		if ( 'publish' !== get_post_status( $id ) ) {
			return 'draft';
		}
		$now   = $now ?? Clock::utc();
		$start = Clock::from_mysql_utc( (string) get_post_meta( $id, '_momo_notice_start_utc', true ) ?: null );
		$end   = Clock::from_mysql_utc( (string) get_post_meta( $id, '_momo_notice_end_utc', true ) ?: null );
		if ( null !== $end && $now >= $end ) {
			return 'expired';
		}
		if ( null === $start || $now < $start ) {
			return 'scheduled';
		}
		return 'active';
	}
}
