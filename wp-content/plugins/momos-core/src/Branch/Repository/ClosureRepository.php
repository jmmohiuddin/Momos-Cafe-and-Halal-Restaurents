<?php
declare(strict_types=1);

namespace Momos\Branch\Repository;

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Domain\ClosureReason;
use Momos\Branch\Domain\ClosureType;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;

/**
 * Date closures and special hours (`momo_branch_closures`, FR-BR-007, spec §4.6).
 *
 * One logical record per (branch, date): `closed_all_day` = one row with period_no 0;
 * `special_hours` = 1..6 period rows sharing type and reason. Past dates (JST) are read-only.
 */
final class ClosureRepository {
	/**
	 * Closure rows in a date range (inclusive), ordered by date and period.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function rows_between( int $branch_id, string $from_ymd, string $to_ymd ): array {
		global $wpdb;
		$t    = Tables::closures();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE branch_id = %d AND closure_date BETWEEN %s AND %s ORDER BY closure_date, period_no", $branch_id, $from_ymd, $to_ymd ), ARRAY_A ); // phpcs:ignore
		return array_map( [ self::class, 'cast' ], (array) $rows );
	}

	/** @return list<array<string,mixed>> */
	public static function rows_on( int $branch_id, string $date_ymd ): array {
		return self::rows_between( $branch_id, $date_ymd, $date_ymd );
	}

	/**
	 * Group rows into one record per date: date => [type, public_reason, internal_note, periods].
	 *
	 * @param list<array<string,mixed>> $rows
	 * @return array<string, array<string,mixed>>
	 */
	public static function group_by_date( array $rows ): array {
		$out = [];
		foreach ( $rows as $r ) {
			$d = $r['closure_date'];
			if ( ! isset( $out[ $d ] ) ) {
				$out[ $d ] = [
					'closure_date'  => $d,
					'closure_type'  => $r['closure_type'],
					'public_reason' => $r['public_reason'],
					'internal_note' => $r['internal_note'],
					'periods'       => [],
				];
			}
			if ( ClosureType::SpecialHours->value === $r['closure_type'] ) {
				$out[ $d ]['periods'][] = [
					'period_no'       => $r['period_no'],
					'label'           => $r['label'],
					'opens_at'        => $r['opens_at'],
					'closes_at'       => $r['closes_at'],
					'closes_next_day' => $r['closes_next_day'],
					'last_order_at'   => $r['last_order_at'],
				];
			}
		}
		return $out;
	}

	/**
	 * Replace (or create) the closure record of every date in one transaction.
	 *
	 * @param list<string> $dates Y-m-d (JST business dates, today or later)
	 * @param list<array{label:string,opens_at:string,closes_at:string,last_order_at:?string}> $periods special-hours periods
	 */
	public static function replace_dates( int $branch_id, array $dates, string $type, array $periods, string $public_reason, string $internal_note, AccessContext $ctx ): void {
		$ctx->assert_can( $branch_id, 'hours' );
		if ( ! ClosureType::is_valid( $type ) || ! ClosureReason::is_valid( $public_reason ) || ! $dates ) {
			throw new \InvalidArgumentException( 'Invalid closure' );
		}
		self::assert_not_past( $dates );
		Transaction::run(
			static function () use ( $branch_id, $dates, $type, $periods, $public_reason, $internal_note, $ctx ): void {
				global $wpdb;
				$t   = Tables::closures();
				$now = Clock::mysql_utc();
				foreach ( $dates as $date ) {
					$before = self::rows_on( $branch_id, $date );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE branch_id = %d AND closure_date = %s", $branch_id, $date ) ); // phpcs:ignore
					$created_at = $before ? $before[0]['created_at'] : $now;
					$created_by = $before ? $before[0]['created_by'] : $ctx->actor_id();
					$rows       = ClosureType::ClosedAllDay->value === $type
						? [ [ 'period_no' => 0, 'period_label' => null, 'opens_at' => null, 'closes_at' => null, 'closes_next_day' => 0, 'last_order_at' => null ] ]
						: array_map(
							static fn( $p, $i ) => [
								'period_no'       => $i + 1,
								'period_label'    => (string) $p['label'],
								'opens_at'        => $p['opens_at'] . ':00',
								'closes_at'       => $p['closes_at'] . ':00',
								'closes_next_day' => $p['closes_at'] < $p['opens_at'] ? 1 : 0,
								'last_order_at'   => null !== $p['last_order_at'] && '' !== $p['last_order_at'] ? $p['last_order_at'] . ':00' : null,
							],
							array_values( $periods ),
							array_keys( array_values( $periods ) )
						);
					foreach ( $rows as $row ) {
						$ok = $wpdb->insert(
							$t,
							$row + [
								'branch_id'     => $branch_id,
								'closure_date'  => $date,
								'closure_type'  => $type,
								'public_reason' => $public_reason,
								'internal_note' => '' !== $internal_note ? mb_substr( $internal_note, 0, 255 ) : null,
								'created_at'    => $created_at,
								'created_by'    => $created_by,
								'updated_at'    => $now,
								'updated_by'    => $ctx->actor_id(),
							]
						);
						if ( false === $ok ) {
							throw new \RuntimeException( 'Could not save closure: ' . $wpdb->last_error );
						}
					}
					momos_audit(
						$before ? 'branch.closure_updated' : 'branch.closure_created',
						'branch',
						$branch_id,
						$branch_id,
						[
							'date'   => $date,
							'before' => self::audit_shape( $before ),
							'after'  => self::audit_shape( self::rows_on( $branch_id, $date ) ),
						],
						$ctx->system ? 'system' : 'staff'
					);
				}
				ScheduleService::flush();
				AfterCommit::dispatch( 'momos/branch/schedule_changed', $branch_id, array_values( $dates ) );
			}
		);
		ScheduleService::flush();
	}

	public static function delete_date( int $branch_id, string $date, AccessContext $ctx ): bool {
		$ctx->assert_can( $branch_id, 'hours' );
		self::assert_not_past( [ $date ] );
		return (bool) Transaction::run(
			static function () use ( $branch_id, $date, $ctx ): bool {
				global $wpdb;
				$before = self::rows_on( $branch_id, $date );
				if ( ! $before ) {
					return false;
				}
				$t = Tables::closures();
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE branch_id = %d AND closure_date = %s", $branch_id, $date ) ); // phpcs:ignore
				momos_audit( 'branch.closure_deleted', 'branch', $branch_id, $branch_id, [ 'date' => $date, 'before' => self::audit_shape( $before ) ], $ctx->system ? 'system' : 'staff' );
				ScheduleService::flush();
				AfterCommit::dispatch( 'momos/branch/schedule_changed', $branch_id, [ $date ] );
				return true;
			}
		);
	}

	/** Retention (ENT-04 [Rec]): delete closure rows older than the cutoff date. */
	public static function purge_before( string $date_ymd ): int {
		global $wpdb;
		$t = Tables::closures();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE closure_date < %s", $date_ymd ) ); // phpcs:ignore
	}

	/** @param list<string> $dates */
	private static function assert_not_past( array $dates ): void {
		$today = Clock::jst()->format( 'Y-m-d' );
		foreach ( $dates as $d ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) || $d < $today ) {
				throw new \InvalidArgumentException( __( 'You cannot change past dates.', 'momos-core' ) );
			}
		}
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array<string,mixed>|null
	 */
	private static function audit_shape( array $rows ): ?array {
		if ( ! $rows ) {
			return null;
		}
		return [
			'type'              => $rows[0]['closure_type'],
			'reason'            => $rows[0]['public_reason'],
			'periods'           => array_values( array_filter( array_map( static fn( $r ) => $r['opens_at'] ? $r['opens_at'] . '-' . $r['closes_at'] . ( $r['last_order_at'] ? ' LO ' . $r['last_order_at'] : '' ) : null, $rows ) ) ),
			'has_internal_note' => null !== $rows[0]['internal_note'] && '' !== $rows[0]['internal_note'],
		];
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	public static function cast( array $r ): array {
		return [
			'id'              => (int) $r['id'],
			'branch_id'       => (int) $r['branch_id'],
			'closure_date'    => (string) $r['closure_date'],
			'closure_type'    => (string) $r['closure_type'],
			'period_no'       => (int) $r['period_no'],
			'label'           => null !== $r['period_label'] ? (string) $r['period_label'] : null,
			'opens_at'        => null !== $r['opens_at'] ? substr( (string) $r['opens_at'], 0, 5 ) : null,
			'closes_at'       => null !== $r['closes_at'] ? substr( (string) $r['closes_at'], 0, 5 ) : null,
			'closes_next_day' => (bool) (int) $r['closes_next_day'],
			'last_order_at'   => null !== $r['last_order_at'] ? substr( (string) $r['last_order_at'], 0, 5 ) : null,
			'public_reason'   => (string) $r['public_reason'],
			'internal_note'   => null !== $r['internal_note'] ? (string) $r['internal_note'] : null,
			'created_at'      => (string) $r['created_at'],
			'created_by'      => null !== $r['created_by'] ? (int) $r['created_by'] : null,
		];
	}
}
