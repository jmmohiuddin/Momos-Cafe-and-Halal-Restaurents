<?php
declare(strict_types=1);

namespace Momos\Notification\Support;

use Momos\Kernel\Db\Tables;

/**
 * Read-only access to reservation data (spec §2 "read DTOs"). Uses the Reservation module's
 * repository when present, else a prepared query on Tables::reservations(). Rows include PII: only
 * the renderer/recipient resolver use them, nothing is logged.
 */
final class ReservationReader {
	/** @return array<string,mixed>|null */
	public static function find( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		if ( class_exists( \Momos\Reservation\Repository\ReservationRepository::class ) ) {
			return \Momos\Reservation\Repository\ReservationRepository::find( $id );
		}
		global $wpdb;
		$t   = Tables::reservations();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/**
	 * Latest event of a type for a reservation (payload decoded), e.g. `modified` before/after for E06.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest_event( int $reservation_id, string $type ): ?array {
		global $wpdb;
		$t   = Tables::events();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE reservation_id = %d AND event_type = %s ORDER BY id DESC LIMIT 1", $reservation_id, $type ), ARRAY_A ); // phpcs:ignore
		if ( ! $row ) {
			return null;
		}
		$row['payload'] = \Momos\Kernel\Support\Json::decode_array( isset( $row['payload'] ) ? (string) $row['payload'] : null );
		return $row;
	}

	/**
	 * Light reservation rows for digest sections (no PII columns).
	 *
	 * @param list<int|string> $args
	 * @return list<array<string,mixed>>
	 */
	public static function light( string $where, array $args, int $limit = 200 ): array {
		global $wpdb;
		$t    = Tables::reservations();
		$args[] = $limit;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, public_ref, branch_id, status, requested_date, requested_time, party_size FROM {$t} WHERE {$where} ORDER BY requested_start_utc ASC, id ASC LIMIT %d", $args ), ARRAY_A ); // phpcs:ignore
	}

	public static function count( string $where, array $args ): int {
		global $wpdb;
		$t = Tables::reservations();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $args ) ); // phpcs:ignore
	}
}
