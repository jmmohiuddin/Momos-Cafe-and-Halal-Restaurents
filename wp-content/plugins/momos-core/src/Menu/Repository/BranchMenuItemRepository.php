<?php
declare(strict_types=1);

namespace Momos\Menu\Repository;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;

/**
 * SQL access to `{prefix}momo_branch_menu_items` (ENT-07, SDD §3.3.1). Prepared statements only.
 * Rows are never deleted; a missing row means "not offered". Lost updates are prevented by the
 * `version` column and conditional UPDATEs.
 */
final class BranchMenuItemRepository {
	/** @return array<string,mixed>|null */
	public static function find( int $branch_id, int $dish_id, bool $for_update = false ): ?array {
		global $wpdb;
		$t   = Tables::menu_items();
		$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE branch_id = %d AND dish_id = %d", $branch_id, $dish_id ); // phpcs:ignore
		$row = $wpdb->get_row( $sql . ( $for_update ? ' FOR UPDATE' : '' ), ARRAY_A ); // phpcs:ignore
		return $row ? self::cast( $row ) : null;
	}

	/** @return array<string,mixed>|null */
	public static function find_by_id( int $id ): ?array {
		global $wpdb;
		$t   = Tables::menu_items();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Rows of one branch keyed by dish ID.
	 *
	 * @param list<int>|null $dish_ids
	 * @return array<int, array<string,mixed>>
	 */
	public static function for_branch( int $branch_id, ?array $dish_ids = null ): array {
		global $wpdb;
		$t = Tables::menu_items();
		if ( null !== $dish_ids ) {
			$dish_ids = array_values( array_filter( array_map( 'intval', $dish_ids ) ) );
			if ( ! $dish_ids ) {
				return [];
			}
			$in   = implode( ',', array_fill( 0, count( $dish_ids ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE branch_id = %d AND dish_id IN ({$in})", $branch_id, ...$dish_ids ), ARRAY_A ); // phpcs:ignore
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE branch_id = %d", $branch_id ), ARRAY_A ); // phpcs:ignore
		}
		$out = [];
		foreach ( (array) $rows as $r ) {
			$r                           = self::cast( $r );
			$out[ (int) $r['dish_id'] ] = $r;
		}
		return $out;
	}

	/** @return list<array<string,mixed>> offered rows of a dish (all branches) */
	public static function offered_for_dish( int $dish_id ): array {
		global $wpdb;
		$t    = Tables::menu_items();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE dish_id = %d AND is_offered = 1 ORDER BY branch_id", $dish_id ), ARRAY_A ); // phpcs:ignore
		return array_map( [ self::class, 'cast' ], (array) $rows );
	}

	/** @return list<int> dish IDs offered at the branch */
	public static function offered_dish_ids( int $branch_id ): array {
		global $wpdb;
		$t = Tables::menu_items();
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT dish_id FROM {$t} WHERE branch_id = %d AND is_offered = 1", $branch_id ) ) ); // phpcs:ignore
	}

	/** @return list<int> dish IDs offered at ≥ 1 of the given branches */
	public static function dish_ids_offered_anywhere( array $branch_ids ): array {
		global $wpdb;
		$branch_ids = array_values( array_filter( array_map( 'intval', $branch_ids ) ) );
		if ( ! $branch_ids ) {
			return [];
		}
		$t  = Tables::menu_items();
		$in = implode( ',', array_fill( 0, count( $branch_ids ), '%d' ) );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT dish_id FROM {$t} WHERE is_offered = 1 AND branch_id IN ({$in})", ...$branch_ids ) ) ); // phpcs:ignore
	}

	public static function count_offered_rows_for_dish( int $dish_id ): int {
		global $wpdb;
		$t = Tables::menu_items();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE dish_id = %d AND is_offered = 1", $dish_id ) ); // phpcs:ignore
	}

	/**
	 * Insert a new row (version 1). Returns the new ID, or 0 when a row already exists (race).
	 *
	 * @param array<string,mixed> $values offered, price_override_jpy, availability, sold_out_until, availability_reason
	 */
	public static function insert( int $branch_id, int $dish_id, array $values, ?int $user_id ): int {
		global $wpdb;
		$now = Clock::mysql_utc();
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert(
			Tables::menu_items(),
			[
				'branch_id'           => $branch_id,
				'dish_id'             => $dish_id,
				'is_offered'          => ! empty( $values['offered'] ) ? 1 : 0, // Always explicit (spec §11 C6).
				'price_override_jpy'  => $values['price_override_jpy'] ?? null,
				'availability'        => $values['availability'] ?? 'available',
				'sold_out_until'      => $values['sold_out_until'] ?? null,
				'availability_reason' => $values['availability_reason'] ?? null,
				'version'             => 1,
				'created_at'          => $now,
				'updated_at'          => $now,
				'updated_by'          => $user_id ?: null,
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ]
		);
		$wpdb->suppress_errors( false );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Conditional update (optimistic locking). Returns true when exactly one row changed.
	 *
	 * @param array<string,mixed> $values DB column => value (is_offered, price_override_jpy, availability, sold_out_until, availability_reason)
	 */
	public static function update_if_version( int $id, int $version, array $values, ?int $user_id ): bool {
		global $wpdb;
		$t      = Tables::menu_items();
		$sets   = [];
		$params = [];
		foreach ( $values as $col => $val ) {
			if ( ! in_array( $col, [ 'is_offered', 'price_override_jpy', 'availability', 'sold_out_until', 'availability_reason' ], true ) ) {
				continue;
			}
			if ( null === $val ) {
				$sets[] = "{$col} = NULL";
			} else {
				$sets[]   = "{$col} = " . ( in_array( $col, [ 'is_offered', 'price_override_jpy' ], true ) ? '%d' : '%s' );
				$params[] = $val;
			}
		}
		$sets[]   = 'version = version + 1';
		$sets[]   = 'updated_at = %s';
		$params[] = Clock::mysql_utc();
		if ( $user_id ) {
			$sets[]   = 'updated_by = %d';
			$params[] = $user_id;
		} else {
			$sets[] = 'updated_by = NULL';
		}
		$params[] = $id;
		$params[] = $version;
		$sql      = "UPDATE {$t} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND version = %d';
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore
	}

	/** Ends every offer of a dish (retire). @return list<array<string,mixed>> the rows that were offered */
	public static function end_offers_for_dish( int $dish_id, ?int $user_id ): array {
		global $wpdb;
		$t    = Tables::menu_items();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE dish_id = %d AND is_offered = 1 FOR UPDATE", $dish_id ), ARRAY_A ); // phpcs:ignore
		if ( $rows ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET is_offered = 0, version = version + 1, updated_at = %s, updated_by = %d WHERE dish_id = %d AND is_offered = 1", Clock::mysql_utc(), (int) $user_id, $dish_id ) ); // phpcs:ignore
		}
		return array_map( [ self::class, 'cast' ], (array) $rows );
	}

	/** @param array<string,mixed> $r */
	public static function cast( array $r ): array {
		foreach ( [ 'id', 'branch_id', 'dish_id', 'is_offered', 'version' ] as $k ) {
			$r[ $k ] = (int) $r[ $k ];
		}
		$r['price_override_jpy'] = null === $r['price_override_jpy'] ? null : (int) $r['price_override_jpy'];
		$r['updated_by']         = null === ( $r['updated_by'] ?? null ) ? null : (int) $r['updated_by'];
		return $r;
	}
}
