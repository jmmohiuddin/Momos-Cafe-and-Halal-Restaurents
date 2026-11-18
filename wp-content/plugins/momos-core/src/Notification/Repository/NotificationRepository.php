<?php
declare(strict_types=1);

namespace Momos\Notification\Repository;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Log\Redactor;

/**
 * All SQL on `momo_notifications` (ENT-17, spec §3.1/§8.4). Prepared statements only, time from the
 * Clock (never NOW()). Bodies and subjects are never stored (PRV-011).
 */
final class NotificationRepository {
	public const STATUSES       = [ 'queued', 'sending', 'sent', 'failed', 'cancelled' ];
	public const TERMINAL       = [ 'sent', 'failed', 'cancelled' ];
	public const MAX_ATTEMPTS   = 5;
	public const RECIPIENT_TYPES = [ 'customer', 'branch', 'head_office', 'user' ];

	/**
	 * Insert one row; idempotent on `dedupe_key` (UNIQUE). Returns the new id, or null when a row
	 * with the same dedupe key already exists (spec §4.2 — duplicates are silently ignored).
	 *
	 * @param array<string,mixed> $row
	 */
	public static function insert( array $row ): ?int {
		global $wpdb;
		$t   = Tables::notifications();
		$now = Clock::mysql_utc();
		$row = array_merge(
			[
				'template_variant'  => null,
				'channel'           => 'email',
				'recipient_user_id' => null,
				'reservation_id'    => null,
				'branch_id'         => null,
				'object_type'       => null,
				'object_id'         => null,
				'status'            => 'queued',
				'attempts'          => 0,
				'next_attempt_at'   => null,
				'created_at'        => $now,
				'updated_at'        => $now,
			],
			$row
		);
		$cols   = [ 'uuid', 'dedupe_key', 'template_key', 'template_variant', 'channel', 'lang', 'recipient_type', 'recipient_email', 'recipient_hash', 'recipient_masked', 'recipient_user_id', 'reservation_id', 'branch_id', 'object_type', 'object_id', 'status', 'attempts', 'next_attempt_at', 'created_at', 'updated_at' ];
		$values = [];
		$marks  = [];
		foreach ( $cols as $c ) {
			$v = $row[ $c ] ?? null;
			if ( null === $v ) {
				$marks[] = 'NULL';
				continue;
			}
			$marks[]  = is_int( $v ) ? '%d' : '%s';
			$values[] = $v;
		}
		$sql = "INSERT INTO {$t} (" . implode( ',', $cols ) . ') VALUES (' . implode( ',', $marks ) . ') ON DUPLICATE KEY UPDATE id = id'; // phpcs:ignore
		$res = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore
		if ( false === $res ) {
			throw new \RuntimeException( 'notification insert failed: ' . Redactor::scrub( (string) $wpdb->last_error ) );
		}
		if ( 1 !== (int) $res ) {
			return null; // Duplicate dedupe key (0 rows affected).
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed>|null */
	public static function find( int $id ): ?array {
		global $wpdb;
		$t   = Tables::notifications();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/** @return array<string,mixed>|null */
	public static function find_by_dedupe( string $key ): ?array {
		global $wpdb;
		$t   = Tables::notifications();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE dedupe_key = %s", $key ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}

	/**
	 * Claim a due row for sending (spec §4.3 step 1). Returns false when another worker owns it or it
	 * is not due.
	 */
	public static function claim( int $id ): bool {
		global $wpdb;
		$t   = Tables::notifications();
		$now = Clock::mysql_utc();
		$n   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t} SET status = 'sending', locked_at = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'queued' AND attempts < %d AND (next_attempt_at IS NULL OR next_attempt_at <= %s)", // phpcs:ignore
				$now,
				$now,
				$id,
				self::MAX_ATTEMPTS,
				$now
			)
		);
		return 1 === (int) $n;
	}

	/** Accepted by the provider (spec §4.3 step 5). */
	public static function mark_sent( int $id, string $message_id, string $lang, ?string $info_code = null, ?string $info = null ): void {
		global $wpdb;
		$now = Clock::mysql_utc();
		$wpdb->update(
			Tables::notifications(),
			[
				'status'              => 'sent',
				'sent_at'             => $now,
				'updated_at'          => $now,
				'locked_at'           => null,
				'provider_message_id' => mb_substr( $message_id, 0, 190 ),
				'lang'                => $lang,
				'next_attempt_at'     => null,
				'last_error_code'     => $info_code,
				'last_error'          => null === $info ? null : self::clean_error( $info ),
			],
			[ 'id' => $id, 'status' => 'sending' ]
		);
	}

	public static function mark_retry( int $id, \DateTimeImmutable $next, string $code, string $error ): void {
		global $wpdb;
		$wpdb->update(
			Tables::notifications(),
			[
				'status'          => 'queued',
				'locked_at'       => null,
				'next_attempt_at' => Clock::mysql_utc( $next ),
				'last_error_code' => mb_substr( $code, 0, 60 ),
				'last_error'      => self::clean_error( $error ),
				'updated_at'      => Clock::mysql_utc(),
			],
			[ 'id' => $id ]
		);
	}

	public static function mark_failed( int $id, string $code, string $error ): void {
		global $wpdb;
		$now = Clock::mysql_utc();
		$wpdb->update(
			Tables::notifications(),
			[
				'status'          => 'failed',
				'failed_at'       => $now,
				'locked_at'       => null,
				'next_attempt_at' => null,
				'last_error_code' => mb_substr( $code, 0, 60 ),
				'last_error'      => self::clean_error( $error ),
				'updated_at'      => $now,
			],
			[ 'id' => $id ]
		);
	}

	/** Guard outcome (spec §4.3 step 2): `cancelled`, not counted as a failure. */
	public static function mark_cancelled( int $id, string $code ): void {
		global $wpdb;
		$wpdb->update(
			Tables::notifications(),
			[
				'status'          => 'cancelled',
				'locked_at'       => null,
				'next_attempt_at' => null,
				'last_error_code' => $code,
				'updated_at'      => Clock::mysql_utc(),
			],
			[ 'id' => $id ]
		);
	}

	/** Redacted (no address, no phone), truncated to 500 characters, request id suffix (spec §3.1). */
	public static function clean_error( string $error ): string {
		$error = trim( (string) preg_replace( '/\s+/', ' ', Redactor::scrub( wp_strip_all_tags( $error ) ) ) );
		$req   = \Momos\Kernel\Http\RequestContext::id();
		$sfx   = $req ? ' [req:' . $req . ']' : '';
		return mb_substr( $error, 0, 500 - mb_strlen( $sfx ) ) . $sfx;
	}

	/**
	 * Due queued rows, oldest first (FR-NOT-003(e)).
	 *
	 * @return list<int>
	 */
	public static function due_ids( int $limit = 25 ): array {
		global $wpdb;
		$t = Tables::notifications();
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'queued' AND attempts < %d AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY created_at ASC, id ASC LIMIT %d", self::MAX_ATTEMPTS, Clock::mysql_utc(), $limit ) ) // phpcs:ignore
		);
	}

	/** @return list<array<string,mixed>> rows stuck in `sending` since before $cutoff */
	public static function stale_sending( \DateTimeImmutable $cutoff, int $limit = 200 ): array {
		global $wpdb;
		$t = Tables::notifications();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'sending' AND locked_at < %s ORDER BY id ASC LIMIT %d", Clock::mysql_utc( $cutoff ), $limit ), ARRAY_A ); // phpcs:ignore
	}

	/** Oldest due queued row's created_at (queue-age alert, spec §4.10 step 5). */
	public static function oldest_due_created_at(): ?\DateTimeImmutable {
		global $wpdb;
		$t = Tables::notifications();
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$t} WHERE status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)", Clock::mysql_utc() ) ); // phpcs:ignore
		return Clock::from_mysql_utc( null === $v ? null : (string) $v );
	}

	/**
	 * Attempts and failed attempts on rows touched since $since (approximation of the per-attempt
	 * failure rate; spec §4.10 step 4).
	 *
	 * @return array{attempts:int,failed:int}
	 */
	public static function attempt_stats( \DateTimeImmutable $since ): array {
		global $wpdb;
		$t   = Tables::notifications();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(attempts),0) AS attempts, COALESCE(SUM(CASE WHEN status = 'sent' THEN GREATEST(attempts - 1, 0) WHEN status IN ('failed','queued') THEN attempts ELSE 0 END),0) AS failed FROM {$t} WHERE updated_at >= %s", // phpcs:ignore
				Clock::mysql_utc( $since )
			),
			ARRAY_A
		);
		return [ 'attempts' => (int) ( $row['attempts'] ?? 0 ), 'failed' => (int) ( $row['failed'] ?? 0 ) ];
	}

	/** @return array{queued:int,failed_24h:int} */
	public static function counts(): array {
		global $wpdb;
		$t = Tables::notifications();
		return [
			'queued'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('queued','sending')" ), // phpcs:ignore
			'failed_24h' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'failed' AND failed_at >= %s", Clock::mysql_utc( Clock::utc()->modify( '-24 hours' ) ) ) ), // phpcs:ignore
		];
	}

	/**
	 * Rows of one reservation, newest first (SCR-A09 panel, API-11 `notifications`).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function for_reservation( int $reservation_id ): array {
		global $wpdb;
		$t = Tables::notifications();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE reservation_id = %d ORDER BY created_at DESC, id DESC", $reservation_id ), ARRAY_A ); // phpcs:ignore
	}

	/** Number of resend rows created from an original row (`resend:{id}:{n}`). */
	public static function resend_count( int $original_id ): int {
		global $wpdb;
		$t = Tables::notifications();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE dedupe_key LIKE %s", $wpdb->esc_like( 'resend:' . $original_id . ':' ) . '%' ) ); // phpcs:ignore
	}

	/**
	 * Log view search (spec §7.3). Filters are validated by the caller; branch scope may be a list.
	 *
	 * @param array{status?:string,template?:string,branch_id?:int,lang?:string,from?:string,to?:string,branch_ids?:?array<int>} $f from/to = UTC datetimes
	 * @return array{rows:list<array<string,mixed>>,total:int}
	 */
	public static function search( array $f, int $page = 1, int $per_page = 50 ): array {
		global $wpdb;
		$t     = Tables::notifications();
		$where = [ '1=1' ];
		$args  = [];
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = $f['status'];
		}
		if ( ! empty( $f['template'] ) ) {
			$where[] = 'template_key = %s';
			$args[]  = $f['template'];
		}
		if ( ! empty( $f['branch_id'] ) ) {
			$where[] = 'branch_id = %d';
			$args[]  = (int) $f['branch_id'];
		}
		if ( isset( $f['branch_ids'] ) && is_array( $f['branch_ids'] ) ) {
			if ( ! $f['branch_ids'] ) {
				return [ 'rows' => [], 'total' => 0 ];
			}
			$where[] = 'branch_id IN (' . implode( ',', array_fill( 0, count( $f['branch_ids'] ), '%d' ) ) . ')';
			$args    = array_merge( $args, array_map( 'intval', $f['branch_ids'] ) );
		}
		if ( ! empty( $f['lang'] ) ) {
			$where[] = 'lang = %s';
			$args[]  = $f['lang'];
		}
		if ( ! empty( $f['from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $f['from'];
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = 'created_at < %s';
			$args[]  = $f['to'];
		}
		$w      = implode( ' AND ', $where );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		$count  = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$total  = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count, $args ) : $count ); // phpcs:ignore
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, [ $per_page, $offset ] ) ), ARRAY_A ); // phpcs:ignore
		return [ 'rows' => $rows, 'total' => $total ];
	}

	/**
	 * Failed rows of a branch in a window (E10 digest, spec §4.8).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function failed_between( ?int $branch_id, string $from_utc, string $to_utc ): array {
		global $wpdb;
		$t = Tables::notifications();
		if ( null === $branch_id ) {
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, template_key, template_variant, reservation_id, branch_id, last_error_code FROM {$t} WHERE status = 'failed' AND failed_at >= %s AND failed_at < %s ORDER BY failed_at ASC LIMIT 500", $from_utc, $to_utc ), ARRAY_A ); // phpcs:ignore
		}
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, template_key, template_variant, reservation_id, branch_id, last_error_code FROM {$t} WHERE branch_id = %d AND status = 'failed' AND failed_at >= %s AND failed_at < %s ORDER BY failed_at ASC LIMIT 500", $branch_id, $from_utc, $to_utc ), ARRAY_A ); // phpcs:ignore
	}

	// ------------------------------------------------------------- retention (spec §4.13)

	/** Terminal rows untouched for $days: recipient_email = NULL (masked + hash kept). Returns rows changed. */
	public static function purge_terminal_recipients( int $days = 30 ): int {
		global $wpdb;
		$t      = Tables::notifications();
		$now    = Clock::mysql_utc();
		$cutoff = Clock::mysql_utc( Clock::utc()->modify( '-' . max( 1, $days ) . ' days' ) );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET recipient_email = NULL, recipient_purged_at = %s WHERE status IN ('sent','failed','cancelled') AND recipient_email IS NOT NULL AND updated_at < %s", $now, $cutoff ) ); // phpcs:ignore
	}

	/** Reservation anonymised (retention or DSR): redact every row and cancel queued ones. */
	public static function purge_recipients_for_reservation( int $reservation_id ): int {
		global $wpdb;
		$t   = Tables::notifications();
		$now = Clock::mysql_utc();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'cancelled', last_error_code = 'recipient_purged', locked_at = NULL, next_attempt_at = NULL, updated_at = %s WHERE reservation_id = %d AND status = 'queued'", $now, $reservation_id ) ); // phpcs:ignore
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET recipient_email = NULL, recipient_masked = '[purged]', recipient_purged_at = %s WHERE reservation_id = %d AND recipient_type = 'customer'", $now, $reservation_id ) ); // phpcs:ignore
	}

	/** Delete rows created before $before in batches of $batch (DEC-034, 180 days). Returns rows deleted. */
	public static function delete_older_than( \DateTimeImmutable $before, int $batch = 500, int $max_batches = 100 ): int {
		global $wpdb;
		$t     = Tables::notifications();
		$total = 0;
		for ( $i = 0; $i < $max_batches; $i++ ) {
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s AND status <> 'sending' ORDER BY id LIMIT %d", Clock::mysql_utc( $before ), $batch ) ); // phpcs:ignore
			$total += $n;
			if ( $n < $batch ) {
				break;
			}
		}
		return $total;
	}

	/**
	 * DSR export (FR-PRV-004): metadata only, customer rows of the reservation.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function export_for_reservation( int $reservation_id ): array {
		global $wpdb;
		$t = Tables::notifications();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT template_key AS template, template_variant AS variant, lang AS language, status, created_at, sent_at, failed_at FROM {$t} WHERE reservation_id = %d AND recipient_type = 'customer' ORDER BY created_at ASC, id ASC", $reservation_id ), ARRAY_A ); // phpcs:ignore
	}
}
