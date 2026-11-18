<?php
declare(strict_types=1);

namespace Momos\Branch\Job;

use Momos\Branch\Query\OfferQuery;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Log\Logger;

/**
 * `momos_job_offer_expiry` (FR-CORP-011(e), BR-CORP-02, spec §4.11/§4.13): stamps
 * `momo_offer_expired_at` on published offers whose end has passed, and — as the 15-minute safety
 * sweep — purges pages of offers and notices whose start or end passed since the last run and
 * re-evaluates the header "Offers" item (0 ↔ ≥ 1, DEC-060). Idempotent, single-run via named lock.
 */
final class OfferExpiryJob {
	public const HOOK     = 'momos_job_offer_expiry';
	public const INTERVAL = 900;
	public const LAST_RUN = 'momos_offer_sweep_last_run';

	public static function register(): void {
		\Momos\Kernel\Jobs\Schedule::recurring( self::HOOK, self::INTERVAL );
		add_action( self::HOOK, [ self::class, 'run' ] );
	}

	/** @return array{expired:int,boundaries:int} */
	public static function run(): array {
		if ( ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return [ 'expired' => 0, 'boundaries' => 0 ];
		}
		try {
			$now     = Clock::utc();
			$expired = self::stamp_expired( $now );
			$since   = Clock::from_mysql_utc( (string) get_option( self::LAST_RUN, '' ) ?: null ) ?? $now->modify( '-' . self::INTERVAL . ' seconds' );
			$crossed = self::crossed( 'momo_offer', $since, $now );
			foreach ( self::crossed( 'momo_notice', $since, $now ) as $notice_id ) {
				\Momos\Branch\Service\NoticeService::purge( $notice_id );
			}
			foreach ( $crossed as $offer_id ) {
				do_action( 'momos/cache/purge', array_merge( [ 'momo-offers', 'momo-home' ], array_map( static fn( $b ) => 'momo-branch-' . $b, OfferQuery::affected_branch_ids( $offer_id ) ) ) );
			}
			OfferQuery::sync_visibility( $crossed[0] ?? 0 );
			update_option( self::LAST_RUN, Clock::mysql_utc( $now ), false );
			if ( $expired ) {
				Logger::info( 'offer.expired', [ 'count' => $expired ] );
			}
			return [ 'expired' => $expired, 'boundaries' => count( $crossed ) ];
		} finally {
			NamedLock::release( self::HOOK );
		}
	}

	/** Stamp `momo_offer_expired_at` where null and end ≤ now. */
	public static function stamp_expired( \DateTimeImmutable $now ): int {
		$ids = get_posts(
			[
				'post_type'        => 'momo_offer',
				'post_status'      => 'publish',
				'posts_per_page'   => 500,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => [ // phpcs:ignore
					'relation' => 'AND',
					[ 'key' => '_momo_offer_end_utc', 'value' => Clock::mysql_utc( $now ), 'compare' => '<=', 'type' => 'DATETIME' ],
					[
						'relation' => 'OR',
						[ 'key' => 'momo_offer_expired_at', 'compare' => 'NOT EXISTS' ],
						[ 'key' => 'momo_offer_expired_at', 'value' => '' ],
					],
				],
			]
		);
		foreach ( $ids as $id ) {
			update_post_meta( (int) $id, 'momo_offer_expired_at', Clock::mysql_utc( $now ) );
		}
		return count( $ids );
	}

	/**
	 * Published items whose start or end lies in (since, now].
	 *
	 * @return list<int>
	 */
	private static function crossed( string $type, \DateTimeImmutable $since, \DateTimeImmutable $now ): array {
		$prefix = 'momo_offer' === $type ? '_momo_offer_' : '_momo_notice_';
		$ids    = [];
		foreach ( [ 'start', 'end' ] as $which ) {
			$ids = array_merge(
				$ids,
				get_posts(
					[
						'post_type'        => $type,
						'post_status'      => 'publish',
						'posts_per_page'   => 500,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => true,
						'meta_query'       => [ // phpcs:ignore
							[ 'key' => $prefix . $which . '_utc', 'value' => [ Clock::mysql_utc( $since->modify( '+1 second' ) ), Clock::mysql_utc( $now ) ], 'compare' => 'BETWEEN', 'type' => 'DATETIME' ],
						],
					]
				)
			);
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}
}
