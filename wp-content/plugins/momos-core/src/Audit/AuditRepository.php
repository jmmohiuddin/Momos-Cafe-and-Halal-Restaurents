<?php
declare(strict_types=1);

namespace Momos\Audit;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;

/**
 * Read, purge and integrity check of `momo_audit_log` (ENT-20). There is no update path and the
 * only delete path is the retention purge (§6.5). Inserts go through AuditLogger.
 */
final class AuditRepository {
	public const PER_PAGE = 50;

	/**
	 * Filtered page for SCR-A16. Filters: from/to (JST Y-m-d, inclusive), actor (int user id or
	 * 'system'), action (exact name or "prefix.*"), object_type, object_id, branch_id, page.
	 *
	 * @param array<string,mixed> $f
	 * @return array{rows:list<array<string,mixed>>,total:int}
	 */
	public static function search( array $f ): array {
		global $wpdb;
		$t     = Tables::audit();
		$where = self::where( $f );
		$page  = max( 1, (int) ( $f['page'] ?? 1 ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore -- built from prepared fragments.
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC " . $wpdb->prepare( 'LIMIT %d OFFSET %d', self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ), ARRAY_A ); // phpcs:ignore
		return [ 'rows' => $rows, 'total' => $total ];
	}

	/** @param array<string,mixed> $f */
	public static function where( array $f ): string {
		global $wpdb;
		$w   = [ '1=1' ];
		$tz  = Clock::tz();
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( [ 'from' => '00:00:00', 'to' => '23:59:59' ] as $key => $time ) {
			$d = isset( $f[ $key ] ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $f[ $key ] . ' ' . $time, $tz ) : false;
			if ( $d ) {
				$at  = $d->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
				$w[] = 'from' === $key ? $wpdb->prepare( 'occurred_at >= %s', $at ) : $wpdb->prepare( 'occurred_at <= %s', $at );
			}
		}
		if ( isset( $f['actor'] ) && '' !== (string) $f['actor'] ) {
			$w[] = 'system' === $f['actor'] ? "actor_type = 'system'" : $wpdb->prepare( 'actor_id = %d', (int) $f['actor'] );
		}
		if ( ! empty( $f['action'] ) ) {
			$action = (string) $f['action'];
			$w[]    = str_ends_with( $action, '.*' )
				? $wpdb->prepare( 'action LIKE %s', $wpdb->esc_like( substr( $action, 0, -1 ) ) . '%' )
				: $wpdb->prepare( 'action = %s', $action );
		}
		if ( ! empty( $f['object_type'] ) ) {
			$w[] = $wpdb->prepare( 'object_type = %s', (string) $f['object_type'] );
		}
		if ( ! empty( $f['object_id'] ) ) {
			$w[] = $wpdb->prepare( 'object_id = %d', (int) $f['object_id'] );
		}
		if ( ! empty( $f['branch_id'] ) ) {
			$w[] = $wpdb->prepare( 'branch_id = %d', (int) $f['branch_id'] );
		}
		return implode( ' AND ', $w );
	}

	/** @return list<string> distinct object types (filter choices). */
	public static function object_types(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( 'SELECT DISTINCT object_type FROM ' . Tables::audit() . ' ORDER BY object_type LIMIT 100' ) ); // phpcs:ignore
	}

	/** @return list<int> distinct staff actor ids (filter choices, incl. disabled users). */
	public static function actor_ids(): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT actor_id FROM ' . Tables::audit() . ' WHERE actor_id IS NOT NULL LIMIT 500' ) ); // phpcs:ignore
	}

	/**
	 * Retention purge (§6.5, §10.4 steps 5–6): domain rows older than $domain_months, activity-class
	 * rows older than $activity_months. Batched; returns deleted counts.
	 *
	 * @return array{domain:int,activity:int}
	 */
	public static function purge( int $domain_months, int $activity_months, int $batch = 1000, int $max_batches = 200 ): array {
		global $wpdb;
		$t        = Tables::audit();
		$now      = Clock::utc();
		$activity = Catalogue::ACTIVITY;
		$in       = implode( ',', array_fill( 0, count( $activity ), '%s' ) );
		$out      = [ 'domain' => 0, 'activity' => 0 ];
		$plans    = [
			'domain'   => [ Clock::mysql_utc( $now->modify( '-' . $domain_months . ' months' ) ), "action NOT IN ({$in})" ],
			'activity' => [ Clock::mysql_utc( $now->modify( '-' . $activity_months . ' months' ) ), "action IN ({$in})" ],
		];
		foreach ( $plans as $kind => [ $cutoff, $cond ] ) {
			for ( $i = 0; $i < $max_batches; $i++ ) {
				$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE occurred_at < %s AND {$cond} ORDER BY id LIMIT %d", array_merge( [ $cutoff ], $activity, [ $batch ] ) ) ); // phpcs:ignore
				$out[ $kind ] += $n;
				if ( $n < $batch ) {
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Verify the HMAC chain. A row is valid when its row_hmac equals HMAC(prev, row) for one of the
	 * ⟦10⟧ preceding rows (tolerates concurrent writers that chained off the same predecessor). The
	 * first row of the checked range is the anchor (its predecessor may have been purged).
	 *
	 * @return array{checked:int,failed:list<int>}
	 */
	public static function verify( int $from_id = 0, int $limit = 100000 ): array {
		global $wpdb;
		$t       = Tables::audit();
		$recent  = [];
		$failed  = [];
		$checked = 0;
		$last    = $from_id > 0 ? $from_id - 1 : 0;
		if ( $from_id > 0 ) {
			$recent = array_map( 'strval', array_reverse( (array) $wpdb->get_col( $wpdb->prepare( "SELECT row_hmac FROM {$t} WHERE id < %d ORDER BY id DESC LIMIT 10", $from_id ) ) ) ); // phpcs:ignore
		}
		while ( $checked < $limit ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id > %d ORDER BY id ASC LIMIT 1000", $last ), ARRAY_A ); // phpcs:ignore
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				++$checked;
				$ok = false;
				if ( ! $recent ) {
					$ok = true; // Anchor row.
				} else {
					foreach ( array_reverse( $recent ) as $prev ) {
						if ( hash_equals( AuditLogger::hmac( $prev, $row ), (string) $row['row_hmac'] ) ) {
							$ok = true;
							break;
						}
					}
				}
				if ( ! $ok ) {
					$failed[] = $last;
				}
				$recent[] = (string) $row['row_hmac'];
				if ( count( $recent ) > 10 ) {
					array_shift( $recent );
				}
			}
		}
		return [ 'checked' => $checked, 'failed' => $failed ];
	}
}
