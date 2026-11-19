<?php
declare(strict_types=1);

namespace Momos\Privacy\Dsr;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\Support\Crockford;
use Momos\Privacy\Retention\Anonymizer;

/**
 * Data-subject requests (F-PRV-03 / FR-PRV-004, PRV-016/017, US-PRV-003; Access spec §10.5, SCR-A19).
 *
 * Search by one value: a reservation reference (8 Crockford characters, hyphen optional) or an email
 * address (trimmed, case-insensitive exact match). No partial match, no browsing; all branches;
 * purged rows are never returned. Contact-form messages are not stored (DEC-038), so the contact
 * channel has nothing to find, export or erase.
 *
 * Every step is audited (`dsr.searched|exported|corrected|anonymized`) with counts, field names,
 * reason codes and an optional DSR-register reference — never the searched value or personal data.
 * Callers enforce `momo_manage_dsr` (vendor accounts carry a per-user deny, SEC-008).
 */
final class DsrService {
	public const MAX_RESULTS = 200;

	/** Reason codes (free text is not accepted so personal data cannot reach the audit log). */
	public const CORRECT_REASONS = [ 'subject_request', 'staff_error', 'other' ];
	public const ERASE_REASONS   = [ 'erasure_request', 'objection', 'other' ];

	/** Optional DSR register reference, e.g. "DSR-2026-014" (no spaces, no "@"). */
	public const REQUEST_REF_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\/#-]{0,39}$/';

	/** Customer-visible reservation event types included in the export. */
	public const EXPORT_EVENTS = [ 'created', 'status_changed', 'modified', 'customer_contacted' ];

	/**
	 * @return array{by:?string,ids:list<int>,error:?string} error: empty|invalid
	 */
	public static function search( string $input, bool $audit = true ): array {
		global $wpdb;
		$value = trim( $input );
		if ( '' === $value ) {
			return [ 'by' => null, 'ids' => [], 'error' => 'empty' ];
		}
		$t = Tables::reservations();
		if ( str_contains( $value, '@' ) ) {
			$email = self::normalize_email( $value );
			if ( null === $email ) {
				return [ 'by' => null, 'ids' => [], 'error' => 'invalid' ];
			}
			$by  = 'email';
			$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE email = %s AND pii_purged_at IS NULL ORDER BY requested_start_utc DESC, id DESC LIMIT %d", $email, self::MAX_RESULTS ) ); // phpcs:ignore
		} else {
			$ref = Crockford::normalize( $value );
			if ( null === $ref ) {
				return [ 'by' => null, 'ids' => [], 'error' => 'invalid' ];
			}
			$by  = 'reference';
			$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE public_ref = %s AND pii_purged_at IS NULL", $ref ) ); // phpcs:ignore
		}
		$ids = array_map( 'intval', $ids );
		if ( $audit ) {
			momos_audit( 'dsr.searched', 'dsr', null, null, [ 'by' => $by, 'results' => count( $ids ) ] );
		}
		return [ 'by' => $by, 'ids' => $ids, 'error' => null ];
	}

	/** Trimmed email with lower-cased domain (same normalisation as API-02), or null when invalid. */
	public static function normalize_email( string $value ): ?string {
		$value = trim( $value );
		if ( preg_match( '/[\r\n\s]/', $value ) || strlen( $value ) > 254 || ! str_contains( $value, '@' ) ) {
			return null;
		}
		$at    = (int) strrpos( $value, '@' );
		$email = substr( $value, 0, $at ) . '@' . strtolower( substr( $value, $at + 1 ) );
		return is_email( $email ) ? $email : null;
	}

	/**
	 * Non-purged reservation rows for ids (order kept).
	 *
	 * @param list<int> $ids
	 * @return list<array<string,mixed>>
	 */
	public static function rows( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $i ): bool => $i > 0 ) ) );
		if ( ! $ids ) {
			return [];
		}
		$t    = Tables::reservations();
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE id IN ({$in}) AND pii_purged_at IS NULL", $ids ), ARRAY_A ); // phpcs:ignore
		$by   = [];
		foreach ( $rows as $r ) {
			$by[ (int) $r['id'] ] = $r;
		}
		$out = [];
		foreach ( $ids as $id ) {
			if ( isset( $by[ $id ] ) ) {
				$out[] = $by[ $id ];
			}
		}
		return $out;
	}

	/** @return array{code:string,name:string} */
	public static function branch( int $branch_id ): array {
		$b = class_exists( \Momos\Branch\Repository\BranchRepository::class ) ? \Momos\Branch\Repository\BranchRepository::get( $branch_id ) : null;
		return [ 'code' => (string) ( $b['code'] ?? '' ), 'name' => (string) ( $b['name'] ?? '' ) ];
	}

	/**
	 * Export record of one reservation: everything the customer gave or was told, consent, lifecycle
	 * timestamps (UTC), customer-visible events and notification metadata. Staff internal notes are
	 * excluded ⟦TBD — Counsel⟧; email bodies are never stored.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function record( array $row ): array {
		$id     = (int) $row['id'];
		$branch = self::branch( (int) $row['branch_id'] );
		$events = [];
		if ( class_exists( \Momos\Reservation\Repository\EventRepository::class ) ) {
			foreach ( \Momos\Reservation\Repository\EventRepository::for_reservation( $id ) as $e ) {
				if ( in_array( (string) $e['event_type'], self::EXPORT_EVENTS, true ) ) {
					$events[] = [ 'type' => (string) $e['event_type'], 'from_status' => $e['from_status'], 'to_status' => $e['to_status'], 'at_utc' => (string) $e['created_at'] ];
				}
			}
		}
		$notifications = class_exists( \Momos\Notification\Repository\NotificationRepository::class ) ? \Momos\Notification\Repository\NotificationRepository::export_for_reservation( $id ) : [];
		$timestamps    = [];
		foreach ( [ 'created_at', 'updated_at', 'confirmed_at', 'rejected_at', 'cancelled_at', 'expired_at', 'attended_at' ] as $k ) {
			$timestamps[ $k . '_utc' ] = $row[ $k ] ?? null;
		}
		return [
			'reference'           => Crockford::display( (string) $row['public_ref'] ),
			'branch'              => $branch,
			'requested_date'      => (string) $row['requested_date'],
			'requested_time_jst'  => substr( (string) $row['requested_time'], 0, 5 ),
			'party_size'          => (int) $row['party_size'],
			'status'              => (string) $row['status'],
			'preferred_language'  => (string) $row['preferred_lang'],
			'form_language'       => (string) $row['source_lang'],
			'name'                => $row['guest_name'],
			'email'               => $row['email'],
			'phone'               => $row['phone_e164'],
			'special_request'     => $row['special_request'],
			'message_to_customer' => $row['message_to_customer'],
			'consent'             => [ 'version' => (string) $row['consent_version'], 'at_utc' => (string) $row['consent_at'] ],
			'timestamps'          => $timestamps,
			'events'              => $events,
			'notifications'       => array_map( static fn( array $n ): array => array_intersect_key( $n, array_flip( [ 'template', 'variant', 'language', 'status', 'created_at', 'sent_at', 'failed_at' ] ) ), $notifications ),
		];
	}

	/**
	 * Export bundle (streamed JSON by the handler; never written to disk).
	 *
	 * @param list<int> $ids
	 * @return array<string,mixed>
	 */
	public static function bundle( array $ids, string $by = 'reference' ): array {
		$records = array_map( [ self::class, 'record' ], self::rows( $ids ) );
		momos_audit( 'dsr.exported', 'dsr', null, null, [ 'by' => in_array( $by, [ 'email', 'reference' ], true ) ? $by : 'reference', 'count' => count( $records ) ] );
		return [
			'format'           => 'momos-dsr-export',
			'format_version'   => 1,
			'generated_at_utc' => Clock::mysql_utc(),
			'times'            => 'requested_date/requested_time_jst are Japan time; *_utc values are UTC',
			'reservations'     => $records,
			'contact_form'     => 'Contact form messages are not stored (sent by email only).',
		];
	}

	public static function export_filename( ?\DateTimeImmutable $now = null ): string {
		return 'momo-dsr-export-' . ( $now ?? Clock::jst() )->setTimezone( Clock::tz() )->format( 'Ymd-His' ) . '.json';
	}

	public static function valid_request_ref( string $ref ): bool {
		return '' === $ref || 1 === preg_match( self::REQUEST_REF_PATTERN, $ref );
	}

	/** @param array<string,mixed> $row */
	public static function is_active_future( array $row, ?\DateTimeImmutable $now = null ): bool {
		$start = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		return in_array( (string) $row['status'], [ 'pending', 'confirmed' ], true ) && null !== $start && $start > ( $now ?? Clock::utc() );
	}

	/**
	 * Correct guest name, email, phone and/or preferred language of the given reservations (any
	 * status). Only non-empty submitted fields change; validation follows API-02. Audit per row with
	 * the changed field names (values masked by the audit writer).
	 *
	 * @param list<int>             $ids
	 * @param array<string,string>  $input keys name, email, phone, preferred_lang
	 * @return array{updated:int,errors:array<string,string>}
	 */
	public static function correct( array $ids, array $input, string $reason, string $request_ref = '' ): array {
		$errors = [];
		if ( ! in_array( $reason, self::CORRECT_REASONS, true ) ) {
			$errors['reason'] = 'required';
		}
		if ( ! self::valid_request_ref( $request_ref ) ) {
			$errors['request_ref'] = 'invalid_format';
		}
		$set = self::validate_correction( $input, $errors );
		if ( ! $set && ! $errors ) {
			$errors['fields'] = 'required';
		}
		$rows = self::rows( $ids );
		if ( ! $rows ) {
			$errors['ids'] = 'required';
		}
		if ( $errors ) {
			return [ 'updated' => 0, 'errors' => $errors ];
		}
		$updated = 0;
		foreach ( $rows as $row ) {
			$changes = [];
			foreach ( $set as $col => $value ) {
				if ( (string) ( $row[ $col ] ?? '' ) !== (string) ( $value ?? '' ) ) {
					$changes[ $col ] = [ 'from' => $row[ $col ], 'to' => $value ];
				}
			}
			if ( ! $changes ) {
				continue;
			}
			$id = (int) $row['id'];
			try {
				Transaction::run(
					static function () use ( $id, $changes, $row, $reason, $request_ref ): void {
						$n = \Momos\Reservation\Repository\ReservationRepository::update_conditional( $id, (int) $row['version'], null, array_map( static fn( array $c ) => $c['to'], $changes ) );
						if ( 1 !== $n ) {
							throw new \RuntimeException( 'version_conflict' );
						}
						momos_audit( 'dsr.corrected', 'reservation', $id, (int) $row['branch_id'], $changes + [ 'reason' => $reason, 'request_ref' => $request_ref ] );
					}
				);
				++$updated;
			} catch ( \RuntimeException $e ) {
				$errors['conflict'] = 'version_conflict';
			}
		}
		return [ 'updated' => $updated, 'errors' => $errors ];
	}

	/**
	 * @param array<string,string> $input
	 * @param array<string,string> $errors
	 * @return array<string,?string> column => new value
	 */
	private static function validate_correction( array $input, array &$errors ): array {
		$set  = [];
		$name = trim( (string) ( $input['name'] ?? '' ) );
		if ( '' !== $name ) {
			$name = class_exists( \Normalizer::class ) ? (string) \Normalizer::normalize( $name, \Normalizer::FORM_C ) : $name;
			if ( mb_strlen( $name ) > 100 ) {
				$errors['name'] = 'too_long';
			} elseif ( preg_match( '/[\x00-\x1F\x7F<>]/u', $name ) ) {
				$errors['name'] = 'invalid_format';
			} else {
				$set['guest_name'] = $name;
			}
		}
		$email = trim( (string) ( $input['email'] ?? '' ) );
		if ( '' !== $email ) {
			$norm = self::normalize_email( $email );
			if ( null === $norm ) {
				$errors['email'] = 'invalid_format';
			} else {
				$set['email'] = $norm;
			}
		}
		$phone = trim( (string) ( $input['phone'] ?? '' ) );
		if ( '' !== $phone ) {
			$norm = class_exists( \Momos\Reservation\Validation\Normalizer::class ) ? \Momos\Reservation\Validation\Normalizer::phone( $phone ) : false;
			if ( ! is_string( $norm ) ) {
				$errors['phone'] = 'invalid_format';
			} else {
				$set['phone_e164'] = $norm;
			}
		}
		$lang = trim( (string) ( $input['preferred_lang'] ?? '' ) );
		if ( '' !== $lang ) {
			if ( ! Lang::is_valid( $lang ) ) {
				$errors['preferred_lang'] = 'invalid_format';
			} else {
				$set['preferred_lang'] = $lang;
			}
		}
		return $set;
	}

	/**
	 * Anonymise one reservation after the operator typed its reference (AC-PRV-03-02: blocked while
	 * the reservation is pending/confirmed and in the future).
	 *
	 * @return string anonymized|confirm_mismatch|blocked_active|not_found|invalid_reason|invalid_ref
	 */
	public static function anonymize( int $id, string $typed_reference, string $reason, string $request_ref = '' ): string {
		if ( ! in_array( $reason, self::ERASE_REASONS, true ) ) {
			return 'invalid_reason';
		}
		if ( ! self::valid_request_ref( $request_ref ) ) {
			return 'invalid_ref';
		}
		$rows = self::rows( [ $id ] );
		if ( ! $rows ) {
			return 'not_found';
		}
		$row = $rows[0];
		if ( Crockford::normalize( $typed_reference ) !== (string) $row['public_ref'] ) {
			return 'confirm_mismatch';
		}
		if ( self::is_active_future( $row ) ) {
			return 'blocked_active';
		}
		if ( ! Anonymizer::anonymize_reservation( $id, 'dsr' ) ) {
			return 'not_found';
		}
		momos_audit( 'dsr.anonymized', 'reservation', $id, (int) $row['branch_id'], [ 'reason' => $reason, 'request_ref' => $request_ref, 'channel' => 'dsr_tool' ] );
		return 'anonymized';
	}
}
