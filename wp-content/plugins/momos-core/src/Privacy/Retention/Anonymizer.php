<?php
declare(strict_types=1);

namespace Momos\Privacy\Retention;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Support\Json;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\EventType;
use Momos\Reservation\Repository\EventRepository;

/**
 * Single anonymisation routine for retention, the DSR tool and the WordPress eraser
 * (FR-PRV-003/004, SDD §5.10 "Anonymisation", Access spec §10.4, reservation.md §2.4 retention).
 *
 * In one transaction per reservation:
 * - `momo_reservations`: guest_name, email, phone_e164, special_request, message_to_customer,
 *   token_hash, ip_hash, user_agent_hash → NULL; pii_purged_at = now (CHECK chk_momo_res_pii allows
 *   the NULLs only together with pii_purged_at). Kept: branch, date, time, party size, languages,
 *   status, lifecycle timestamps, reason codes, consent_version/consent_at, token_expires_utc —
 *   enough for the KPI reports (counts unchanged, AC-PRV-02-01).
 * - `momo_reservation_events`: note → NULL; payload reduced to structural keys (free text removed).
 * - `momo_notifications`: via Notification `Retention::anonymise_reservation()` (recipient NULL,
 *   masked `[purged]`, queued rows cancelled).
 * - event `pii_purged` (actor system, reason_code = reason).
 * After commit: `momos/reservation/anonymized (int $id)` (SDD §2.5).
 *
 * Idempotent: an already purged or missing reservation returns false and changes nothing.
 */
final class Anonymizer {
	public const REASONS = [ 'retention', 'dsr' ];

	/** PII columns cleared on `momo_reservations`. */
	public const PII_COLUMNS = [ 'guest_name', 'email', 'phone_e164', 'special_request', 'message_to_customer', 'token_hash', 'ip_hash', 'user_agent_hash' ];

	/**
	 * Payload keys that are structural (codes, ids, numbers, dates) and survive scrubbing. Every other
	 * key — free text such as `override_reason` or a staff display name — is removed.
	 */
	public const PAYLOAD_KEEP = [
		'before', 'after', 'date', 'time', 'party_size', 'party', 'requested_date', 'requested_time',
		'captcha', 'source_lang', 'notification_id', 'template', 'error_code', 'minutes_waiting', 'trigger',
		'via', 'previous_handler_id', 'override', 'reason', 'reason_code',
	];

	/**
	 * Anonymise one reservation. Returns true when this call purged it.
	 *
	 * @throws \RuntimeException on a database error (the transaction is rolled back).
	 */
	public static function anonymize_reservation( int $id, string $reason ): bool {
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown anonymisation reason' );
		}
		return (bool) Transaction::run(
			static function () use ( $id, $reason ): bool {
				global $wpdb;
				$t   = Tables::reservations();
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, pii_purged_at FROM {$t} WHERE id = %d FOR UPDATE", $id ), ARRAY_A ); // phpcs:ignore
				if ( ! $row || null !== $row['pii_purged_at'] ) {
					return false;
				}
				$now  = Clock::mysql_utc();
				$sets = implode( ', ', array_map( static fn( string $c ): string => "`{$c}` = NULL", self::PII_COLUMNS ) );
				$ok   = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET {$sets}, pii_purged_at = %s, updated_at = %s, version = version + 1 WHERE id = %d AND pii_purged_at IS NULL", $now, $now, $id ) ); // phpcs:ignore -- column list is a class constant.
				if ( false === $ok ) {
					throw new \RuntimeException( 'reservation_update_failed' );
				}
				if ( 1 !== (int) $ok ) {
					return false;
				}
				self::scrub_events( $id );
				if ( class_exists( \Momos\Notification\Service\Retention::class ) ) {
					\Momos\Notification\Service\Retention::anonymise_reservation( $id );
				}
				EventRepository::add( $id, EventType::PiiPurged, Actor::system(), null, null, $reason );
				AfterCommit::dispatch( 'momos/reservation/anonymized', $id );
				return true;
			}
		);
	}

	/** Null event notes and strip free text from payloads of one reservation. */
	private static function scrub_events( int $id ): void {
		global $wpdb;
		$t = Tables::events();
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET note = NULL WHERE reservation_id = %d AND note IS NOT NULL", $id ) ) ) { // phpcs:ignore
			throw new \RuntimeException( 'event_update_failed' );
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM {$t} WHERE reservation_id = %d AND payload IS NOT NULL", $id ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $r ) {
			$clean = self::scrub_payload( Json::decode_array( (string) $r['payload'] ) );
			$json  = $clean ? Json::encode( $clean ) : null;
			if ( $json === $r['payload'] ) {
				continue;
			}
			if ( false === $wpdb->update( $t, [ 'payload' => $json ], [ 'id' => (int) $r['id'] ] ) ) {
				throw new \RuntimeException( 'event_update_failed' );
			}
		}
	}

	/**
	 * Keep only structural keys; scalar values must be numbers, booleans, null or short code-like
	 * strings (no spaces, no "@"), so a name or a sentence never survives.
	 *
	 * @param array<mixed> $payload
	 * @return array<mixed>
	 */
	public static function scrub_payload( array $payload ): array {
		$out = [];
		foreach ( $payload as $k => $v ) {
			if ( is_string( $k ) && ! in_array( $k, self::PAYLOAD_KEEP, true ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$v = self::scrub_payload( $v );
				if ( [] === $v ) {
					continue;
				}
			} elseif ( is_string( $v ) && ! preg_match( '/^[A-Za-z0-9_.:\-+]{0,40}$/', $v ) ) {
				continue;
			} elseif ( ! is_string( $v ) && ! is_int( $v ) && ! is_float( $v ) && ! is_bool( $v ) && null !== $v ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}
}
