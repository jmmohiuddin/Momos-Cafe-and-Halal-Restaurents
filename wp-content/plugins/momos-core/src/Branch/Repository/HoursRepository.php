<?php
declare(strict_types=1);

namespace Momos\Branch\Repository;

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;

/**
 * Weekly opening periods (`momo_branch_hours`, FR-BR-006, SDD §3.3 Q1).
 *
 * A schedule set = rows sharing (valid_from, valid_until); the default set has both NULL.
 * Writes replace a whole set in one transaction (no partial week) and require an AccessContext
 * (SEC-011).
 */
final class HoursRepository {
	/**
	 * Every row of the branch, all sets, ordered weekday/period.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function all_rows( int $branch_id ): array {
		global $wpdb;
		$t    = Tables::hours();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE branch_id = %d ORDER BY valid_from IS NULL DESC, valid_from, weekday, period_no", $branch_id ), ARRAY_A ); // phpcs:ignore
		return array_map( [ self::class, 'cast' ], (array) $rows );
	}

	/**
	 * Distinct schedule sets of the branch: default first (if any rows), then dated sets by start.
	 *
	 * @return list<array{valid_from:?string,valid_until:?string}>
	 */
	public static function sets( int $branch_id ): array {
		$out = [];
		foreach ( self::all_rows( $branch_id ) as $r ) {
			$key         = ( $r['valid_from'] ?? '' ) . '|' . ( $r['valid_until'] ?? '' );
			$out[ $key ] = [ 'valid_from' => $r['valid_from'], 'valid_until' => $r['valid_until'] ];
		}
		return array_values( $out );
	}

	/**
	 * Rows of one set.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function rows_for_set( int $branch_id, ?string $valid_from, ?string $valid_until ): array {
		return array_values(
			array_filter(
				self::all_rows( $branch_id ),
				static fn( $r ) => $r['valid_from'] === $valid_from && $r['valid_until'] === $valid_until
			)
		);
	}

	public static function max_updated_at( int $branch_id ): string {
		global $wpdb;
		$t = Tables::hours();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(updated_at) FROM {$t} WHERE branch_id = %d", $branch_id ) ); // phpcs:ignore
	}

	/**
	 * Replace one schedule set with validated periods (HoursValidator ran before).
	 *
	 * @param list<array{weekday:int,period_no:int,label:string,opens_at:string,closes_at:string,last_order_at:?string}> $periods
	 */
	public static function replace_week( int $branch_id, ?string $valid_from, ?string $valid_until, array $periods, AccessContext $ctx ): void {
		$ctx->assert_can( $branch_id, 'hours' );
		Transaction::run(
			static function () use ( $branch_id, $valid_from, $valid_until, $periods, $ctx ): void {
				global $wpdb;
				$t      = Tables::hours();
				$before = self::compact( self::rows_for_set( $branch_id, $valid_from, $valid_until ) );
				self::delete_set_rows( $branch_id, $valid_from, $valid_until );
				$now = Clock::mysql_utc();
				foreach ( $periods as $p ) {
					$ok = $wpdb->insert(
						$t,
						[
							'branch_id'       => $branch_id,
							'weekday'         => (int) $p['weekday'],
							'period_no'       => (int) $p['period_no'],
							'period_label'    => (string) $p['label'],
							'opens_at'        => $p['opens_at'] . ':00',
							'closes_at'       => $p['closes_at'] . ':00',
							'closes_next_day' => $p['closes_at'] < $p['opens_at'] ? 1 : 0,
							'last_order_at'   => null !== $p['last_order_at'] && '' !== $p['last_order_at'] ? $p['last_order_at'] . ':00' : null,
							'valid_from'      => $valid_from,
							'valid_until'     => $valid_until,
							'created_at'      => $now,
							'updated_at'      => $now,
							'updated_by'      => $ctx->actor_id(),
						]
					);
					if ( false === $ok ) {
						throw new \RuntimeException( 'Could not save opening hours: ' . $wpdb->last_error );
					}
				}
				ScheduleService::flush();
				$after = self::compact( self::rows_for_set( $branch_id, $valid_from, $valid_until ) );
				momos_audit(
					'branch.hours_updated',
					'branch',
					$branch_id,
					$branch_id,
					[
						'set'    => [ 'valid_from' => $valid_from, 'valid_until' => $valid_until ],
						'before' => $before,
						'after'  => $after,
					],
					$ctx->system ? 'system' : 'staff'
				);
				AfterCommit::dispatch( 'momos/branch/schedule_changed', $branch_id, [] );
			}
		);
		ScheduleService::flush();
	}

	/** Remove a dated schedule set entirely (the default set is emptied with replace_week). */
	public static function delete_set( int $branch_id, string $valid_from, ?string $valid_until, AccessContext $ctx ): void {
		self::replace_week( $branch_id, $valid_from, $valid_until, [], $ctx );
	}

	private static function delete_set_rows( int $branch_id, ?string $valid_from, ?string $valid_until ): void {
		global $wpdb;
		$t     = Tables::hours();
		$where = $wpdb->prepare( 'branch_id = %d', $branch_id );
		$where .= null === $valid_from ? ' AND valid_from IS NULL' : $wpdb->prepare( ' AND valid_from = %s', $valid_from );
		$where .= null === $valid_until ? ' AND valid_until IS NULL' : $wpdb->prepare( ' AND valid_until = %s', $valid_until );
		$wpdb->query( "DELETE FROM {$t} WHERE {$where}" ); // phpcs:ignore -- built from prepared fragments.
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	public static function cast( array $r ): array {
		return [
			'id'              => (int) $r['id'],
			'branch_id'       => (int) $r['branch_id'],
			'weekday'         => (int) $r['weekday'],
			'period_no'       => (int) $r['period_no'],
			'label'           => (string) $r['period_label'],
			'opens_at'        => substr( (string) $r['opens_at'], 0, 5 ),
			'closes_at'       => substr( (string) $r['closes_at'], 0, 5 ),
			'closes_next_day' => (bool) (int) $r['closes_next_day'],
			'last_order_at'   => null !== $r['last_order_at'] ? substr( (string) $r['last_order_at'], 0, 5 ) : null,
			'valid_from'      => $r['valid_from'] ?: null,
			'valid_until'     => $r['valid_until'] ?: null,
			'updated_at'      => (string) $r['updated_at'],
		];
	}

	/**
	 * Audit-friendly representation: weekday => ["11:00-15:00 LO 14:30 (lunch)", …].
	 *
	 * @param list<array<string,mixed>> $rows
	 * @return array<int, list<string>>
	 */
	public static function compact( array $rows ): array {
		$out = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r['weekday'] ][] = $r['opens_at'] . '-' . $r['closes_at'] . ( $r['last_order_at'] ? ' LO ' . $r['last_order_at'] : '' ) . ' (' . $r['label'] . ')';
		}
		ksort( $out );
		return $out;
	}
}
