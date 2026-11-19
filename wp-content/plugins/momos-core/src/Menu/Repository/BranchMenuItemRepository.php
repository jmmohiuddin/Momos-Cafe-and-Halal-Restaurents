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
	/**
	 * Request-scoped read cache (NFR-PERF-02, TRD §5 "no queries inside template loops"): list pages prime
	 * the rows of all listed dishes with one query (prime_offered / prime_rows) and the per-card lookups
	 * (find, offered_for_dish) are then served from memory. Non-persistent group: never shared between
	 * requests. Every write through this repository bumps the generation, so a read after a write in the
	 * same request never sees stale rows. Writers outside this class must call flush_cache().
	 */
	public const CACHE_GROUP = 'momos_menu_items';

	private static int $generation = 0;

	public static function flush_cache(): void {
		++self::$generation;
	}

	private static function cache_key( string $kind, int ...$ids ): string {
		return $kind . ':' . self::$generation . ':' . implode( ':', $ids );
	}

	private static function cache_ready(): void {
		static $done = false;
		if ( ! $done ) {
			wp_cache_add_non_persistent_groups( [ self::CACHE_GROUP ] );
			$done = true;
		}
	}

	/** @return array<string,mixed>|null */
	public static function find( int $branch_id, int $dish_id, bool $for_update = false ): ?array {
		global $wpdb;
		self::cache_ready();
		$key = self::cache_key( 'row', $branch_id, $dish_id );
		if ( ! $for_update ) {
			$found  = false;
			$cached = wp_cache_get( $key, self::CACHE_GROUP, false, $found );
			if ( $found ) {
				return is_array( $cached ) ? $cached : null;
			}
		}
		$t   = Tables::menu_items();
		$sql = $wpdb->prepare( "SELECT * FROM {$t} WHERE branch_id = %d AND dish_id = %d", $branch_id, $dish_id ); // phpcs:ignore
		$row = $wpdb->get_row( $sql . ( $for_update ? ' FOR UPDATE' : '' ), ARRAY_A ); // phpcs:ignore
		$row = $row ? self::cast( $row ) : null;
		if ( ! $for_update ) {
			wp_cache_set( $key, $row ?? 0, self::CACHE_GROUP );
		}
		return $row;
	}

	/**
	 * Load the rows of one branch for many dishes with one query (missing rows are remembered as "none").
	 *
	 * @param list<int> $dish_ids
	 */
	public static function prime_rows( int $branch_id, array $dish_ids ): void {
		self::cache_ready();
		$missing = [];
		foreach ( array_unique( array_map( 'intval', $dish_ids ) ) as $id ) {
			$found = false;
			wp_cache_get( self::cache_key( 'row', $branch_id, $id ), self::CACHE_GROUP, false, $found );
			if ( ! $found && $id > 0 ) {
				$missing[] = $id;
			}
		}
		if ( ! $missing ) {
			return;
		}
		$rows = self::for_branch( $branch_id, $missing );
		foreach ( $missing as $id ) {
			wp_cache_set( self::cache_key( 'row', $branch_id, $id ), $rows[ $id ] ?? 0, self::CACHE_GROUP );
		}
	}

	/**
	 * Load the offered rows (all branches) of many dishes with one query; offered_for_dish() and find()
	 * for those rows are then answered from memory.
	 *
	 * @param list<int> $dish_ids
	 */
	public static function prime_offered( array $dish_ids ): void {
		global $wpdb;
		self::cache_ready();
		$missing = [];
		foreach ( array_unique( array_map( 'intval', $dish_ids ) ) as $id ) {
			$found = false;
			wp_cache_get( self::cache_key( 'offered', $id ), self::CACHE_GROUP, false, $found );
			if ( ! $found && $id > 0 ) {
				$missing[] = $id;
			}
		}
		if ( ! $missing ) {
			return;
		}
		$t       = Tables::menu_items();
		$in      = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE is_offered = 1 AND dish_id IN ({$in}) ORDER BY branch_id", ...$missing ), ARRAY_A ); // phpcs:ignore
		$by_dish = array_fill_keys( $missing, [] );
		foreach ( (array) $rows as $r ) {
			$r                                = self::cast( $r );
			$by_dish[ (int) $r['dish_id'] ][] = $r;
		}
		foreach ( $by_dish as $id => $list ) {
			self::remember_offered( (int) $id, $list );
		}
	}

	/** @param list<array<string,mixed>> $rows offered rows of one dish */
	private static function remember_offered( int $dish_id, array $rows ): void {
		wp_cache_set( self::cache_key( 'offered', $dish_id ), $rows, self::CACHE_GROUP );
		foreach ( $rows as $r ) {
			wp_cache_set( self::cache_key( 'row', (int) $r['branch_id'], $dish_id ), $r, self::CACHE_GROUP );
		}
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
			$r                          = self::cast( $r );
			$out[ (int) $r['dish_id'] ] = $r;
		}
		return $out;
	}

	/** @return list<array<string,mixed>> offered rows of a dish (all branches) */
	public static function offered_for_dish( int $dish_id ): array {
		global $wpdb;
		self::cache_ready();
		$found  = false;
		$cached = wp_cache_get( self::cache_key( 'offered', $dish_id ), self::CACHE_GROUP, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}
		$t    = Tables::menu_items();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE dish_id = %d AND is_offered = 1 ORDER BY branch_id", $dish_id ), ARRAY_A ); // phpcs:ignore
		$rows = array_map( [ self::class, 'cast' ], (array) $rows );
		self::remember_offered( $dish_id, $rows );
		return $rows;
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
		self::flush_cache();
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
		self::flush_cache();
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore
	}

	/** Ends every offer of a dish (retire). @return list<array<string,mixed>> the rows that were offered */
	public static function end_offers_for_dish( int $dish_id, ?int $user_id ): array {
		global $wpdb;
		$t    = Tables::menu_items();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE dish_id = %d AND is_offered = 1 FOR UPDATE", $dish_id ), ARRAY_A ); // phpcs:ignore
		if ( $rows ) {
			self::flush_cache();
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
