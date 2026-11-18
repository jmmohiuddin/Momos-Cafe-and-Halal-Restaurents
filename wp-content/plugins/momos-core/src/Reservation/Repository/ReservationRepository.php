<?php
declare(strict_types=1);

namespace Momos\Reservation\Repository;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Support\Crockford;
use Momos\Reservation\Domain\Scope;

/**
 * All SQL on `momo_reservations` (docs/CONTRACTS.md, SDD §3.3). Prepared statements only; ORDER BY
 * from whitelists; time from the Clock (never NOW()). Staff list/count queries require a Scope.
 *
 * Rows are returned as associative arrays exactly as stored (incl. PII): callers enforce
 * `momo_view_customer_pii` before exposing email/phone/special_request.
 */
final class ReservationRepository {
	public const SORTS = [ 'requested_start' => 'requested_start_utc', 'created_at' => 'created_at' ];

	// ------------------------------------------------------------------ contract

	/** @return array<string,mixed>|null */
	public static function find( int $id ): ?array {
		global $wpdb;
		$t   = Tables::reservations();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/** Lookup by public reference; accepts display/lower-case/confusable input (DEC-051). @return array<string,mixed>|null */
	public static function find_by_ref( string $public_ref ): ?array {
		$ref = Crockford::normalize( $public_ref );
		if ( null === $ref ) {
			return null;
		}
		global $wpdb;
		$t   = Tables::reservations();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE public_ref = %s", $ref ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	// ------------------------------------------------------------------ submission support

	/** @return array<string,mixed>|null */
	public static function find_by_idempotency_key( string $key ): ?array {
		global $wpdb;
		$t   = Tables::reservations();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE idempotency_key = %s", strtolower( $key ) ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	public static function ref_exists( string $ref ): bool {
		global $wpdb;
		$t = Tables::reservations();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE public_ref = %s", $ref ) ); // phpcs:ignore
	}

	/**
	 * Soft duplicate (BR-RES-08 layer 2): same email + branch + date + time, pending or confirmed,
	 * created at or after $since_utc.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find_soft_duplicate( string $email, int $branch_id, string $date, string $time, string $since_utc, int $exclude_id = 0 ): ?array {
		global $wpdb;
		$t   = Tables::reservations();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE email = %s AND branch_id = %d AND requested_date = %s AND requested_time = %s AND status IN ('pending','confirmed') AND created_at >= %s AND id <> %d ORDER BY id DESC LIMIT 1", // phpcs:ignore
				$email,
				$branch_id,
				$date,
				self::time_sql( $time ),
				$since_utc,
				$exclude_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/** Active pending requests of an email at a branch (BR-RES-08 layer 3). */
	public static function count_pending( string $email, int $branch_id, int $exclude_id = 0 ): int {
		global $wpdb;
		$t = Tables::reservations();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE email = %s AND branch_id = %d AND status = 'pending' AND id <> %d", $email, $branch_id, $exclude_id ) ); // phpcs:ignore
	}

	/**
	 * INSERT a reservation. Returns the new id, or throws with the MySQL error (callers detect
	 * duplicate keys and deadlocks from the message).
	 *
	 * @param array<string,mixed> $row
	 */
	public static function insert( array $row ): int {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( Tables::reservations(), $row );
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			throw new \RuntimeException( (string) $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Optimistic conditional UPDATE (FR-RES-025, spec §3.8). Always bumps version and updated_at.
	 * $expected_version null = no version condition; $expected_status null = no status condition.
	 * A null value in $set writes SQL NULL. Values given as ['raw' => 'COALESCE(handled_by,%d)', 'args' => [..]]
	 * are written as prepared expressions.
	 *
	 * @param array<string,mixed> $set
	 * @return int affected rows (0 or 1)
	 */
	public static function update_conditional( int $id, ?int $expected_version, ?string $expected_status, array $set ): int {
		global $wpdb;
		$t     = Tables::reservations();
		$parts = [ 'version = version + 1', $wpdb->prepare( 'updated_at = %s', Clock::mysql_utc() ) ];
		foreach ( $set as $col => $value ) {
			if ( ! preg_match( '/^[a-z_0-9]+$/', $col ) ) {
				throw new \InvalidArgumentException( 'Bad column' );
			}
			if ( is_array( $value ) && isset( $value['raw'] ) ) {
				$parts[] = "`{$col}` = " . ( empty( $value['args'] ) ? $value['raw'] : $wpdb->prepare( $value['raw'], ...$value['args'] ) ); // phpcs:ignore
			} elseif ( null === $value ) {
				$parts[] = "`{$col}` = NULL";
			} elseif ( is_int( $value ) ) {
				$parts[] = $wpdb->prepare( "`{$col}` = %d", $value ); // phpcs:ignore
			} else {
				$parts[] = $wpdb->prepare( "`{$col}` = %s", (string) $value ); // phpcs:ignore
			}
		}
		$where = $wpdb->prepare( 'id = %d', $id );
		if ( null !== $expected_version ) {
			$where .= $wpdb->prepare( ' AND version = %d', $expected_version );
		}
		if ( null !== $expected_status ) {
			$where .= $wpdb->prepare( ' AND status = %s', $expected_status );
		}
		$result = $wpdb->query( "UPDATE {$t} SET " . implode( ', ', $parts ) . " WHERE {$where}" ); // phpcs:ignore -- parts prepared above.
		if ( false === $result ) {
			throw new \RuntimeException( (string) $wpdb->last_error );
		}
		return (int) $result;
	}

	// ------------------------------------------------------------------ staff queries (scoped)

	/**
	 * Inbox search (API-10, FR-RES-008). Filters: branch_ids (list<int>|null), statuses (list),
	 * date_from, date_to, q, flags (list), has_special_request (bool|null), handled_by ('me'|'none'),
	 * since (UTC mysql), ids (list<int>|null restriction, e.g. overdue), sort, order, page, per_page.
	 *
	 * @param array<string,mixed> $f
	 * @return array{rows:list<array<string,mixed>>,total:int,new_count:?int}
	 */
	public static function search( Scope $scope, array $f ): array {
		global $wpdb;
		$t     = Tables::reservations();
		$where = self::scoped_where( $scope, $f );
		if ( null === $where ) {
			return [ 'rows' => [], 'total' => 0, 'new_count' => null !== ( $f['since'] ?? null ) ? 0 : null ];
		}
		$sort     = self::SORTS[ $f['sort'] ?? 'requested_start' ] ?? 'requested_start_utc';
		$order    = 'desc' === strtolower( (string) ( $f['order'] ?? 'asc' ) ) ? 'DESC' : 'ASC';
		$per_page = max( 1, min( 50, (int) ( $f['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $f['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore -- built from prepared fragments.
		$rows     = (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE {$where} ORDER BY {$sort} {$order}, id {$order} " . $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset ), ARRAY_A ); // phpcs:ignore
		$new      = null;
		if ( ! empty( $f['since'] ) ) {
			$new = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where} AND " . $wpdb->prepare( 'created_at >= %s', $f['since'] ) ); // phpcs:ignore
		}
		return [ 'rows' => $rows, 'total' => $total, 'new_count' => $new ];
	}

	/**
	 * Count per status within scope (badges / X-Momo-Status-Counts).
	 *
	 * @param list<int>|null $branch_filter
	 * @return array<string,int>
	 */
	public static function counts_by_status( Scope $scope, ?array $branch_filter = null ): array {
		global $wpdb;
		$t     = Tables::reservations();
		$where = self::scoped_where( $scope, [ 'branch_ids' => $branch_filter ] );
		$out   = array_fill_keys( \Momos\Reservation\Domain\ReservationStatus::values(), 0 );
		if ( null === $where ) {
			return $out;
		}
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} WHERE {$where} GROUP BY status", ARRAY_A ) as $r ) { // phpcs:ignore
			$out[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Pending rows in scope (id, branch_id, created_at) for SLA evaluation (overdue filter, badge).
	 *
	 * @return list<array{id:int,branch_id:int,created_at:string}>
	 */
	public static function pending_light( Scope $scope, int $limit = 2000 ): array {
		global $wpdb;
		$t     = Tables::reservations();
		$where = self::scoped_where( $scope, [ 'statuses' => [ 'pending' ] ] );
		if ( null === $where ) {
			return [];
		}
		$rows = (array) $wpdb->get_results( "SELECT id, branch_id, created_at FROM {$t} WHERE {$where} ORDER BY created_at ASC " . $wpdb->prepare( 'LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore
		return array_map( static fn( $r ) => [ 'id' => (int) $r['id'], 'branch_id' => (int) $r['branch_id'], 'created_at' => (string) $r['created_at'] ], $rows );
	}

	/**
	 * WHERE clause for a scope + filters, or null when the scope is empty (no rows visible).
	 *
	 * @param array<string,mixed> $f
	 */
	public static function scoped_where( Scope $scope, array $f ): ?string {
		global $wpdb;
		$branch_ids = $scope->restrict( isset( $f['branch_ids'] ) && null !== $f['branch_ids'] ? array_map( 'intval', (array) $f['branch_ids'] ) : null );
		if ( null !== $branch_ids && ! $branch_ids ) {
			return null;
		}
		$w = [ '1=1' ];
		if ( null !== $branch_ids ) {
			$w[] = 'branch_id IN (' . implode( ',', array_map( 'intval', $branch_ids ) ) . ')';
		}
		if ( ! empty( $f['exclude_branch_ids'] ) ) {
			$w[] = 'branch_id NOT IN (' . implode( ',', array_map( 'intval', (array) $f['exclude_branch_ids'] ) ) . ')';
		}
		if ( ! empty( $f['statuses'] ) ) {
			$w[] = 'status IN (' . implode( ',', array_map( static fn( $s ) => $wpdb->prepare( '%s', $s ), (array) $f['statuses'] ) ) . ')';
		}
		if ( ! empty( $f['date_from'] ) ) {
			$w[] = $wpdb->prepare( 'requested_date >= %s', $f['date_from'] );
		}
		if ( ! empty( $f['date_to'] ) ) {
			$w[] = $wpdb->prepare( 'requested_date <= %s', $f['date_to'] );
		}
		if ( ! empty( $f['created_from'] ) ) {
			$w[] = $wpdb->prepare( 'created_at >= %s', $f['created_from'] );
		}
		if ( ! empty( $f['created_to'] ) ) {
			$w[] = $wpdb->prepare( 'created_at < %s', $f['created_to'] );
		}
		if ( isset( $f['q'] ) && '' !== (string) $f['q'] ) {
			$q   = (string) $f['q'];
			$ref = Crockford::normalize( $q );
			$sub = [ $wpdb->prepare( 'guest_name LIKE %s', '%' . $wpdb->esc_like( $q ) . '%' ) ];
			if ( null !== $ref ) {
				$sub[] = $wpdb->prepare( 'public_ref = %s', $ref );
			}
			$w[] = '(' . implode( ' OR ', $sub ) . ')';
		}
		foreach ( (array) ( $f['flags'] ?? [] ) as $flag ) {
			$w[] = match ( $flag ) {
				'needs_action' => 'needs_action_flag = 1',
				'not_notified' => 'customer_notified_flag = 0',
				'unclaimed'    => 'handled_by IS NULL',
				default        => '1=1',
			};
		}
		if ( isset( $f['has_special_request'] ) && null !== $f['has_special_request'] ) {
			$w[] = $f['has_special_request'] ? "(special_request IS NOT NULL AND special_request <> '')" : "(special_request IS NULL OR special_request = '')";
		}
		if ( 'none' === ( $f['handled_by'] ?? null ) ) {
			$w[] = 'handled_by IS NULL';
		} elseif ( 'me' === ( $f['handled_by'] ?? null ) ) {
			$w[] = $wpdb->prepare( 'handled_by = %d', $scope->user_id );
		}
		if ( ! empty( $f['since'] ) ) {
			$w[] = $wpdb->prepare( '(created_at >= %s OR updated_at >= %s)', $f['since'], $f['since'] );
		}
		if ( isset( $f['ids'] ) && null !== $f['ids'] ) {
			$ids = array_map( 'intval', (array) $f['ids'] );
			$w[] = $ids ? 'id IN (' . implode( ',', $ids ) . ')' : '1=0';
		}
		return implode( ' AND ', $w );
	}

	// ------------------------------------------------------------------ jobs (system scope)

	/** @return list<int> branch ids that have pending rows */
	public static function branches_with_pending(): array {
		global $wpdb;
		$t = Tables::reservations();
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT branch_id FROM {$t} WHERE status = 'pending'" ) ); // phpcs:ignore
	}

	/** @return list<int> pending ids of a branch with start ≤ $until_utc (expiry candidates) */
	public static function pending_due( int $branch_id, string $until_utc, int $limit = 200 ): array {
		global $wpdb;
		$t = Tables::reservations();
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE branch_id = %d AND status = 'pending' AND requested_start_utc <= %s ORDER BY requested_start_utc ASC LIMIT %d", $branch_id, $until_utc, $limit ) ) ); // phpcs:ignore
	}

	/** @return list<array<string,mixed>> pending rows of a branch (SLA job) */
	public static function pending_of_branch( int $branch_id, int $limit = 500 ): array {
		global $wpdb;
		$t = Tables::reservations();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, branch_id, public_ref, created_at, handled_by, version FROM {$t} WHERE branch_id = %d AND status = 'pending' ORDER BY created_at ASC LIMIT %d", $branch_id, $limit ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Future pending/confirmed rows of a branch, optionally limited to requested dates (closure check).
	 *
	 * @param list<string>|null $dates
	 * @return list<array<string,mixed>>
	 */
	public static function active_future( int $branch_id, ?array $dates = null, int $limit = 1000 ): array {
		global $wpdb;
		$t   = Tables::reservations();
		$sql = $wpdb->prepare( "SELECT id, branch_id, public_ref, requested_date, requested_time, party_size, status, version, needs_action_flag FROM {$t} WHERE branch_id = %d AND status IN ('pending','confirmed') AND requested_start_utc >= %s", $branch_id, Clock::mysql_utc() ); // phpcs:ignore
		if ( null !== $dates ) {
			if ( ! $dates ) {
				return [];
			}
			$sql .= ' AND requested_date IN (' . implode( ',', array_map( static fn( $d ) => $wpdb->prepare( '%s', $d ), $dates ) ) . ')';
		}
		$sql .= $wpdb->prepare( ' ORDER BY requested_start_utc ASC LIMIT %d', $limit );
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore -- prepared above.
	}

	public static function time_sql( string $hm ): string {
		return strlen( $hm ) === 5 ? $hm . ':00' : $hm;
	}
}
