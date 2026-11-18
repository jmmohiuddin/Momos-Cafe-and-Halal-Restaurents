<?php
declare(strict_types=1);

namespace Momos\Reservation\Repository;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Support\Json;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\EventType;

/**
 * Append-only event log `momo_reservation_events` (spec §2.3). One row for every change of a
 * reservation; payloads never contain PII.
 */
final class EventRepository {
	/** @param array<string,mixed>|null $payload */
	public static function add( int $reservation_id, EventType $type, Actor $actor, ?string $from = null, ?string $to = null, ?string $reason = null, ?string $note = null, ?array $payload = null ): int {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert(
			Tables::events(),
			[
				'reservation_id' => $reservation_id,
				'event_type'     => $type->value,
				'from_status'    => $from,
				'to_status'      => $to,
				'actor_type'     => $actor->type->value,
				'actor_id'       => $actor->id,
				'actor_display'  => $actor->display,
				'reason_code'    => $reason,
				'note'           => $note,
				'payload'        => null === $payload ? null : Json::encode( $payload ),
				'created_at'     => Clock::mysql_utc(),
			]
		);
		$wpdb->suppress_errors( $suppress );
		if ( false === $ok ) {
			throw new \RuntimeException( 'Event insert failed: ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	/** @return list<array<string,mixed>> oldest first */
	public static function for_reservation( int $reservation_id ): array {
		global $wpdb;
		$t = Tables::events();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE reservation_id = %d ORDER BY created_at ASC, id ASC", $reservation_id ), ARRAY_A ); // phpcs:ignore
	}

	public static function has( int $reservation_id, EventType $type ): bool {
		global $wpdb;
		$t = Tables::events();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$t} WHERE reservation_id = %d AND event_type = %s LIMIT 1", $reservation_id, $type->value ) ); // phpcs:ignore
	}

	public static function count( int $reservation_id, EventType $type ): int {
		global $wpdb;
		$t = Tables::events();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE reservation_id = %d AND event_type = %s", $reservation_id, $type->value ) ); // phpcs:ignore
	}

	/** @return array<string,mixed>|null */
	public static function find( int $event_id ): ?array {
		global $wpdb;
		$t   = Tables::events();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $event_id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}
}
