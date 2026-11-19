<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Repository\ReservationRepository;

/**
 * SCR-A17 operations report (F-RES-12, FR-RES-022): counts and rates per branch, language and ISO
 * week (JST), computed only from non-personal columns and events (survives anonymisation). TEST
 * branches are excluded. No names, emails, phones or notes.
 *
 * Definitions: accepted = rows with confirmed_at (accepted at some point); acceptance rate =
 * accepted ÷ (accepted + rejected + expired); expiry rate = expired ÷ same; no-show rate =
 * no_show ÷ (completed + no_show); response time = minutes from created_at to the first
 * status_changed to confirmed/rejected (median, p90); decisions within SLA = share of those decisions
 * made within the branch's response SLA (wall clock; branches with SLA 0 excluded).
 */
final class ReportService {
	/**
	 * @param array{date_from:string,date_to:string,basis:string,branch_ids:?array,lang:?string} $f
	 * @return array{total:array<string,mixed>,by_branch:array<string,array<string,mixed>>,by_lang:array<string,array<string,mixed>>,by_week:array<string,array<string,mixed>>}
	 */
	public static function build( Scope $scope, array $f ): array {
		global $wpdb;
		$test    = array_values( array_filter( BranchRepository::published_ids( true ), [ BranchRepository::class, 'is_test' ] ) );
		$filters = [ 'branch_ids' => $f['branch_ids'] ?? null, 'exclude_branch_ids' => $test ];
		if ( 'requested' === $f['basis'] ) {
			$filters['date_from'] = $f['date_from'];
			$filters['date_to']   = $f['date_to'];
		} else {
			$filters['created_from'] = Clock::mysql_utc( Clock::jst_at( $f['date_from'], '00:00' ) );
			$filters['created_to']   = Clock::mysql_utc( Clock::jst_at( $f['date_to'], '00:00' )->modify( '+1 day' ) );
		}
		$where = ReservationRepository::scoped_where( $scope, $filters );
		$empty = [ 'total' => self::metrics( [] ), 'by_branch' => [], 'by_lang' => [], 'by_week' => [] ];
		if ( null === $where ) {
			return $empty;
		}
		if ( ! empty( $f['lang'] ) ) {
			$where .= $wpdb->prepare( ' AND preferred_lang = %s', $f['lang'] );
		}
		$t    = Tables::reservations();
		$e    = Tables::events();
		$rows = (array) $wpdb->get_results( "SELECT id, branch_id, status, preferred_lang, created_at, requested_date, confirmed_at FROM {$t} WHERE {$where} LIMIT 50000", ARRAY_A ); // phpcs:ignore -- prepared fragments.
		if ( ! $rows ) {
			return $empty;
		}
		$ids       = implode( ',', array_map( static fn( $r ) => (int) $r['id'], $rows ) );
		$decisions = [];
		foreach ( (array) $wpdb->get_results( "SELECT reservation_id, MIN(created_at) AS at FROM {$e} WHERE reservation_id IN ({$ids}) AND event_type = 'status_changed' AND to_status IN ('confirmed','rejected') GROUP BY reservation_id", ARRAY_A ) as $d ) { // phpcs:ignore
			$decisions[ (int) $d['reservation_id'] ] = (string) $d['at'];
		}
		$sla = [];
		$all = [];
		$by  = [ 'by_branch' => [], 'by_lang' => [], 'by_week' => [] ];
		foreach ( $rows as $r ) {
			$bid           = (int) $r['branch_id'];
			$sla[ $bid ] ??= (int) BranchSettingsRepository::get( $bid )['response_sla_minutes'];
			$created       = Clock::from_mysql_utc( (string) $r['created_at'] );
			$r['response'] = isset( $decisions[ (int) $r['id'] ] ) ? intdiv( Clock::from_mysql_utc( $decisions[ (int) $r['id'] ] )->getTimestamp() - $created->getTimestamp(), 60 ) : null;
			$r['sla']      = $sla[ $bid ];
			$week_src      = 'requested' === $f['basis'] ? new \DateTimeImmutable( (string) $r['requested_date'], Clock::tz() ) : $created->setTimezone( Clock::tz() );
			$all[]         = $r;
			$b             = BranchRepository::get( $bid );
			$by['by_branch'][ $b ? (string) $b['code'] : (string) $bid ][] = $r;
			$by['by_lang'][ (string) $r['preferred_lang'] ][]              = $r;
			$by['by_week'][ $week_src->format( 'o-\WW' ) ][]               = $r;
		}
		$out = [ 'total' => self::metrics( $all ) ];
		foreach ( $by as $k => $groups ) {
			ksort( $groups );
			$out[ $k ] = array_map( [ self::class, 'metrics' ], $groups );
		}
		return $out;
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array<string,mixed>
	 */
	public static function metrics( array $rows ): array {
		$counts   = array_fill_keys( \Momos\Reservation\Domain\ReservationStatus::values(), 0 );
		$accepted = 0;
		$resp     = [];
		$in_sla   = 0;
		$with_sla = 0;
		foreach ( $rows as $r ) {
			++$counts[ (string) $r['status'] ];
			if ( ! empty( $r['confirmed_at'] ) ) {
				++$accepted;
			}
			if ( null !== ( $r['response'] ?? null ) ) {
				$resp[] = (int) $r['response'];
				if ( (int) $r['sla'] > 0 ) {
					++$with_sla;
					$in_sla += (int) $r['response'] <= (int) $r['sla'] ? 1 : 0;
				}
			}
		}
		$decided = $accepted + $counts['rejected'] + $counts['expired'];
		$attend  = $counts['completed'] + $counts['no_show'];
		sort( $resp );
		return [
			'created'         => count( $rows ),
			'counts'          => $counts,
			'accepted'        => $accepted,
			'acceptance_rate' => $decided > 0 ? $accepted / $decided : null,
			'expiry_rate'     => $decided > 0 ? $counts['expired'] / $decided : null,
			'no_show_rate'    => $attend > 0 ? $counts['no_show'] / $attend : null,
			'response_median' => self::percentile( $resp, 0.5 ),
			'response_p90'    => self::percentile( $resp, 0.9 ),
			'within_sla'      => $with_sla > 0 ? $in_sla / $with_sla : null,
		];
	}

	/** Nearest-rank percentile of a sorted list, null when empty. @param list<int> $sorted */
	public static function percentile( array $sorted, float $p ): ?int {
		$n = count( $sorted );
		if ( 0 === $n ) {
			return null;
		}
		return $sorted[ max( 0, (int) ceil( $p * $n ) - 1 ) ];
	}
}
