<?php
declare(strict_types=1);

namespace Momos\Privacy\Dsr;

use Momos\Kernel\Db\Tables;
use Momos\Kernel\Support\Crockford;
use Momos\Privacy\Retention\Anonymizer;

/**
 * WordPress core integration (Tools → Export / Erase Personal Data; Access spec §10.5 "Core
 * integration"): exporter and eraser `momos-reservations`. The notification-metadata exporter
 * `momos-notifications` is registered by the Notification module.
 *
 * - The eraser anonymises (never deletes) through the shared Anonymizer and reports active future
 *   reservations as retained (they must be cancelled first, AC-PRV-03-02).
 * - Core screens stay SA-only (`manage_options`); this class additionally requires `momo_manage_dsr`
 *   for `export_others_personal_data` / `erase_others_personal_data`, so vendor accounts (per-user
 *   deny, SEC-008/DEC-035) cannot use the core tools either. The callbacks re-check the capability
 *   outside WP-CLI.
 */
final class PersonalData {
	public const ID       = 'momos-reservations';
	public const PER_PAGE = 100;

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
		add_filter( 'map_meta_cap', [ self::class, 'map_meta_cap' ], 20, 2 );
	}

	/**
	 * @param array<string,array<string,mixed>> $exporters
	 * @return array<string,array<string,mixed>>
	 */
	public static function exporters( array $exporters ): array {
		$exporters[ self::ID ] = [
			'exporter_friendly_name' => __( "Momo's reservation requests", 'momos-core' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers
	 * @return array<string,array<string,mixed>>
	 */
	public static function erasers( array $erasers ): array {
		$erasers[ self::ID ] = [
			'eraser_friendly_name' => __( "Momo's reservation requests (anonymise)", 'momos-core' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * @param list<string> $caps
	 * @return list<string>
	 */
	public static function map_meta_cap( array $caps, string $cap ): array {
		if ( in_array( $cap, [ 'export_others_personal_data', 'erase_others_personal_data' ], true ) && ! in_array( 'momo_manage_dsr', $caps, true ) ) {
			$caps[] = 'momo_manage_dsr';
		}
		return $caps;
	}

	private static function allowed(): bool {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'momo_manage_dsr' );
	}

	/** @return list<int> */
	private static function ids_for( string $email, int $limit, int $offset = 0 ): array {
		global $wpdb;
		$norm = DsrService::normalize_email( $email );
		if ( null === $norm ) {
			return [];
		}
		$t = Tables::reservations();
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE email = %s AND pii_purged_at IS NULL ORDER BY id ASC LIMIT %d OFFSET %d", $norm, $limit, $offset ) ) ); // phpcs:ignore
	}

	/**
	 * Core exporter callback.
	 *
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		if ( ! self::allowed() ) {
			return [ 'data' => [], 'done' => true ];
		}
		$ids  = self::ids_for( $email, self::PER_PAGE, max( 0, $page - 1 ) * self::PER_PAGE );
		$data = [];
		foreach ( DsrService::rows( $ids ) as $row ) {
			$data[] = [
				'group_id'          => self::ID,
				'group_label'       => __( 'Reservation requests', 'momos-core' ),
				'group_description' => __( 'Reservation requests sent through the website. Times are Japan time unless marked UTC.', 'momos-core' ),
				'item_id'           => 'momos-reservation-' . $row['public_ref'],
				'data'              => self::pairs( DsrService::record( $row ) ),
			];
		}
		if ( 1 === $page ) {
			momos_audit( 'dsr.exported', 'dsr', null, null, [ 'by' => 'email', 'channel' => 'core_exporter', 'count' => count( $data ) ] );
		}
		return [ 'data' => $data, 'done' => count( $ids ) < self::PER_PAGE ];
	}

	/**
	 * Name/value pairs for the core export report.
	 *
	 * @param array<string,mixed> $r
	 * @return list<array{name:string,value:string}>
	 */
	public static function pairs( array $r ): array {
		$labels = [
			'reference'           => __( 'Reference', 'momos-core' ),
			'requested_date'      => __( 'Requested date', 'momos-core' ),
			'requested_time_jst'  => __( 'Requested time', 'momos-core' ),
			'party_size'          => __( 'Party size', 'momos-core' ),
			'status'              => __( 'Status', 'momos-core' ),
			'preferred_language'  => __( 'Email language', 'momos-core' ),
			'form_language'       => __( 'Form language', 'momos-core' ),
			'name'                => __( 'Name', 'momos-core' ),
			'email'               => __( 'Email', 'momos-core' ),
			'phone'               => __( 'Phone', 'momos-core' ),
			'special_request'     => __( 'Special request', 'momos-core' ),
			'message_to_customer' => __( 'Message from the branch', 'momos-core' ),
		];
		$out    = [ [ 'name' => __( 'Branch', 'momos-core' ), 'value' => trim( $r['branch']['name'] . ' (' . $r['branch']['code'] . ')' ) ] ];
		foreach ( $labels as $k => $label ) {
			if ( null !== $r[ $k ] && '' !== (string) $r[ $k ] ) {
				$out[] = [ 'name' => $label, 'value' => (string) $r[ $k ] ];
			}
		}
		$out[] = [ 'name' => __( 'Consent version', 'momos-core' ), 'value' => $r['consent']['version'] . ' (' . $r['consent']['at_utc'] . ' UTC)' ];
		foreach ( $r['timestamps'] as $k => $v ) {
			if ( null !== $v && '' !== (string) $v ) {
				$out[] = [ 'name' => $k, 'value' => (string) $v ];
			}
		}
		$events = array_map( static fn( array $e ): string => $e['at_utc'] . ' ' . $e['type'] . ( $e['to_status'] ? ' → ' . $e['to_status'] : '' ), $r['events'] );
		if ( $events ) {
			$out[] = [ 'name' => __( 'History (UTC)', 'momos-core' ), 'value' => implode( "\n", $events ) ];
		}
		$mails = array_map( static fn( array $n ): string => trim( ( $n['created_at'] ?? '' ) . ' ' . ( $n['template'] ?? '' ) . ' ' . ( $n['variant'] ?? '' ) ) . ' [' . ( $n['language'] ?? '' ) . ', ' . ( $n['status'] ?? '' ) . ']', $r['notifications'] );
		if ( $mails ) {
			$out[] = [ 'name' => __( 'Emails (UTC)', 'momos-core' ), 'value' => implode( "\n", $mails ) ];
		}
		return $out;
	}

	/**
	 * Core eraser callback: anonymise past/terminal reservations; retain active future ones.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		if ( ! self::allowed() ) {
			return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [ __( 'You are not allowed to erase reservation data.', 'momos-core' ) ], 'done' => true ];
		}
		$removed  = 0;
		$retained = 0;
		$messages = [];
		$ids      = self::ids_for( $email, 500 );
		foreach ( DsrService::rows( $ids ) as $row ) {
			if ( DsrService::is_active_future( $row ) ) {
				++$retained;
				/* translators: %s: reservation reference */
				$messages[] = sprintf( __( 'Reservation %s is an active future request and was kept. Cancel it first, then erase again.', 'momos-core' ), Crockford::display( (string) $row['public_ref'] ) );
				continue;
			}
			if ( Anonymizer::anonymize_reservation( (int) $row['id'], 'dsr' ) ) {
				++$removed;
				momos_audit( 'dsr.anonymized', 'reservation', (int) $row['id'], (int) $row['branch_id'], [ 'reason' => 'erasure_request', 'channel' => 'core_eraser' ] );
			}
		}
		return [ 'items_removed' => $removed > 0, 'items_retained' => $retained > 0, 'messages' => $messages, 'done' => count( $ids ) < 500 || 0 === $removed ];
	}
}
