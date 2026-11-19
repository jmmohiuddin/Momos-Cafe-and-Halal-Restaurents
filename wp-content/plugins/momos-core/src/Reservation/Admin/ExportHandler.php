<?php
declare(strict_types=1);

namespace Momos\Reservation\Admin;

use Momos\Branch\Repository\BranchRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Reservation\Domain\ReservationStatus;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\Presenter;
use Momos\Reservation\Support\Options;

/**
 * Head-Office CSV export (F-RES-11, FR-RES-021, spec §5.3 "Export"): admin-post `momos_export`,
 * capability momo_export_reservations + nonce + required reason; ≤ momo_export_max_range_months and
 * ≤ momo_export_max_rows; UTF-8 with BOM streamed to php://output (never written to disk); formula
 * injection neutralised; TEST rows only when explicitly filtered; audited.
 */
final class ExportHandler {
	public const ACTION = 'momos_export';

	public const COLUMNS = [ 'reference', 'branch_code', 'requested_date', 'requested_time', 'party_size', 'status', 'preferred_lang', 'created_at', 'decision_at', 'decision_reason_code', 'guest_name', 'email', 'phone_e164' ];

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/** Neutralise spreadsheet formulas: cells starting with = + - @ TAB CR get a leading quote. */
	public static function cell( ?string $v ): string {
		$v = (string) $v;
		if ( '' !== $v && in_array( $v[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $v;
		}
		return $v;
	}

	/**
	 * Validate the request into filters, or return an error message.
	 *
	 * @param array<string,mixed> $in
	 * @return array{filters:array<string,mixed>,codes:list<string>,reason:string,notes:bool}|string
	 */
	public static function parse( array $in, Scope $scope ): array|string {
		$reason = trim( sanitize_text_field( (string) ( $in['reason'] ?? '' ) ) );
		if ( '' === $reason || mb_strlen( $reason ) > 200 ) {
			return __( 'Enter the reason for the export (up to 200 characters).', 'momos-core' );
		}
		$from = (string) ( $in['date_from'] ?? '' );
		$to   = (string) ( $in['date_to'] ?? '' );
		$df   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from );
		$dt   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to );
		if ( ! $df || ! $dt || $df->format( 'Y-m-d' ) !== $from || $dt->format( 'Y-m-d' ) !== $to || $dt < $df ) {
			return __( 'Enter a valid date range.', 'momos-core' );
		}
		$months = Options::int( 'momo_export_max_range_months' );
		if ( $dt > $df->modify( '+' . $months . ' months' ) ) {
			/* translators: %d: months */
			return sprintf( __( 'The date range can be at most %d months.', 'momos-core' ), $months );
		}
		$ids   = [];
		$codes = [];
		foreach ( (array) ( $in['branch'] ?? [] ) as $code ) {
			$b = BranchRepository::find_by_code( strtoupper( sanitize_text_field( (string) $code ) ), true );
			if ( ! $b || ! $scope->allows( (int) $b['id'] ) ) {
				return momos_t( 'staff.err.forbidden_branch' );
			}
			$ids[]   = (int) $b['id'];
			$codes[] = (string) $b['code'];
		}
		$statuses = array_values( array_intersect( ReservationStatus::values(), array_map( 'strval', (array) ( $in['status'] ?? [] ) ) ) );
		$filters  = [
			'branch_ids' => $ids ?: null,
			'statuses'   => $statuses ?: ReservationStatus::values(),
			'date_from'  => $from,
			'date_to'    => $to,
		];
		if ( ! $ids ) {
			// TEST rows are excluded unless explicitly filtered.
			$filters['exclude_branch_ids'] = array_values( array_filter( BranchRepository::published_ids( true ), [ BranchRepository::class, 'is_test' ] ) );
		}
		return [ 'filters' => $filters, 'codes' => $codes ?: [ 'all' ], 'reason' => $reason, 'notes' => ! empty( $in['include_special_requests'] ) ];
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( 'momo_export_reservations' ) ) {
			\Momos\Reservation\Service\ReservationAccess::deny( null, null, 'capability', 'export' );
			wp_die( esc_html__( 'You do not have permission to export reservations.', 'momos-core' ), 403 );
		}
		$scope = Scope::for_user( get_current_user_id() );
		// A branch outside the exporter's scope is refused like the inbox/report filter (TC-ACL-003,
		// access spec §4.3 E2): audited access.denied + 403, not a validation message.
		foreach ( (array) ( $_POST['branch'] ?? [] ) as $code ) { // phpcs:ignore WordPress.Security.NonceVerification -- nonce checked above.
			$b = BranchRepository::find_by_code( strtoupper( sanitize_text_field( wp_unslash( (string) $code ) ) ), true );
			if ( $b && ! $scope->allows( (int) $b['id'] ) ) {
				\Momos\Reservation\Service\ReservationAccess::deny( null, (int) $b['id'], 'forbidden_branch', 'export' );
				\Momos\Kernel\I18n\Locale::set( \Momos\Kernel\I18n\Locale::admin() );
				wp_die( esc_html( momos_t( 'staff.err.forbidden_branch' ) ), esc_html__( 'Export', 'momos-core' ), [ 'response' => 403, 'back_link' => true ] );
			}
		}
		$parsed = self::parse( wp_unslash( $_POST ), $scope ); // phpcs:ignore -- sanitised in parse().
		if ( is_string( $parsed ) ) {
			wp_die( esc_html( $parsed ), esc_html__( 'Export', 'momos-core' ), [ 'response' => 400, 'back_link' => true ] );
		}
		global $wpdb;
		$t     = Tables::reservations();
		$where = ReservationRepository::scoped_where( $scope, $parsed['filters'] );
		$count = null === $where ? 0 : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore -- prepared fragments.
		$max   = Options::int( 'momo_export_max_rows' );
		if ( $count > $max ) {
			/* translators: %d: row limit */
			wp_die( esc_html( sprintf( __( 'Narrow the filters — more than %d rows.', 'momos-core' ), $max ) ), esc_html__( 'Export', 'momos-core' ), [ 'response' => 400, 'back_link' => true ] );
		}
		$audit = [
			'filters' => [ 'branches' => $parsed['codes'], 'statuses' => $parsed['filters']['statuses'], 'date_from' => $parsed['filters']['date_from'], 'date_to' => $parsed['filters']['date_to'] ],
			'rows'    => $count,
			'reason'  => $parsed['reason'],
			'notes'   => $parsed['notes'],
		];
		momos_audit( 'reservation.exported', 'reservation', null, 1 === count( (array) $parsed['filters']['branch_ids'] ) ? (int) $parsed['filters']['branch_ids'][0] : null, $audit );
		if ( $parsed['notes'] ) {
			momos_audit( 'reservation.export_with_notes', 'reservation', null, null, $audit );
		}
		$name = sprintf( 'momo-reservations-%s-%s-%s.csv', implode( '-', $parsed['codes'] ), str_replace( '-', '', $parsed['filters']['date_from'] ), str_replace( '-', '', $parsed['filters']['date_to'] ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		header( 'X-Robots-Tag: noindex' );
		self::stream( null === $where ? '1=0' : $where, $parsed['notes'], fopen( 'php://output', 'w' ) );
		exit;
	}

	/**
	 * Write the CSV (BOM, header, rows in batches of 500).
	 *
	 * @param resource $out
	 */
	public static function stream( string $where, bool $notes, $out ): int {
		global $wpdb;
		$t    = Tables::reservations();
		$cols = $notes ? array_merge( self::COLUMNS, [ 'special_request' ] ) : self::COLUMNS;
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore
		fputcsv( $out, $cols, ',', '"', '' );
		$n      = 0;
		$offset = 0;
		do {
			$rows = (array) $wpdb->get_results( "SELECT * FROM {$t} WHERE {$where} ORDER BY requested_start_utc ASC, id ASC " . $wpdb->prepare( 'LIMIT 500 OFFSET %d', $offset ), ARRAY_A ); // phpcs:ignore -- prepared fragments.
			foreach ( $rows as $r ) {
				$b    = Presenter::branch( (int) $r['branch_id'] );
				$line = [
					(string) $r['public_ref'],
					$b ? (string) $b['code'] : '',
					(string) $r['requested_date'],
					substr( (string) $r['requested_time'], 0, 5 ),
					(string) $r['party_size'],
					(string) $r['status'],
					(string) $r['preferred_lang'],
					Clock::from_mysql_utc( (string) $r['created_at'] )->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ),
					(string) ( Presenter::iso_jst( $r['confirmed_at'] ?? $r['rejected_at'] ?? null ) ?? '' ),
					(string) $r['decision_reason_code'],
					(string) $r['guest_name'],
					(string) $r['email'],
					(string) $r['phone_e164'],
				];
				if ( $notes ) {
					$line[] = (string) $r['special_request'];
				}
				fputcsv( $out, array_map( [ self::class, 'cell' ], $line ), ',', '"', '' );
				++$n;
			}
			$offset += 500;
			$fetched = count( $rows );
		} while ( 500 === $fetched );
		return $n;
	}
}
