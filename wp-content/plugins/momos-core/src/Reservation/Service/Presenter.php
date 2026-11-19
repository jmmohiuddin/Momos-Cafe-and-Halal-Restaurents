<?php
declare(strict_types=1);

namespace Momos\Reservation\Service;

use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Support\Crockford;
use Momos\Kernel\Support\Json;
use Momos\Reservation\Repository\EventRepository;
use Momos\Reservation\Support\Options;
use Momos\Reservation\Validation\Normalizer;

/**
 * Response shapes (spec §5): API-02 submission body, API-03/04 customer status view (no phone, no
 * special-request text, no staff identities or notes), API-10 list rows (no email/phone/special
 * request), API-11 staff detail (PII only with momo_view_customer_pii).
 */
final class Presenter {
	/** @var array<int, array<string,mixed>|null> */
	private static array $branches = [];

	/** @return array<string,mixed>|null */
	public static function branch( int $branch_id ): ?array {
		if ( ! array_key_exists( $branch_id, self::$branches ) ) {
			self::$branches[ $branch_id ] = BranchRepository::get( $branch_id );
		}
		return self::$branches[ $branch_id ];
	}

	public static function flush(): void {
		self::$branches = [];
	}

	public static function iso_jst( ?string $mysql_utc ): ?string {
		$t = Clock::from_mysql_utc( $mysql_utc );
		return $t ? $t->setTimezone( Clock::tz() )->format( 'c' ) : null;
	}

	/** Branch name in a language; Japanese name when no approved translation exists (proper noun). */
	public static function branch_name( int $branch_id, string $lang ): string {
		$name = BranchRepository::name( $branch_id, $lang );
		if ( '' === $name ) {
			$b    = self::branch( $branch_id );
			$name = $b ? (string) $b['name'] : '';
		}
		return $name;
	}

	/** Localised address line (ja = full Japanese address). */
	public static function branch_address( int $branch_id, string $lang ): string {
		$b = self::branch( $branch_id );
		if ( ! $b ) {
			return '';
		}
		if ( 'ja' === $lang || ! Translations::post_visible( $branch_id, $lang ) ) {
			return (string) $b['address_ja'];
		}
		$parts = [];
		foreach ( [ 'momo_address_line2', 'momo_address_line1', 'momo_city', 'momo_prefecture' ] as $f ) {
			$v = trim( Translations::post( $branch_id, $f, $lang ) );
			if ( '' !== $v ) {
				$parts[] = $v;
			}
		}
		$postal = (string) get_post_meta( $branch_id, 'momo_postal_code', true );
		return $parts ? implode( ', ', $parts ) . ( '' !== $postal ? ' ' . $postal : '' ) : (string) $b['address_ja'];
	}

	/** g***@example.com — the customer's own address, domain kept so they recognise it. */
	public static function mask_email( ?string $email ): string {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return '';
		}
		return mb_substr( $email, 0, 1 ) . '***@' . substr( $email, $at + 1 );
	}

	/** @param array<string,mixed> $row */
	public static function decision_deadline( array $row ): ?string {
		$cut   = (int) BranchSettingsRepository::get( (int) $row['branch_id'] )['decision_cutoff_minutes'];
		$start = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		return $start ? $start->modify( '-' . $cut . ' minutes' )->setTimezone( Clock::tz() )->format( 'c' ) : null;
	}

	/**
	 * API-02 body (201, 200 replay, 200 soft duplicate).
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function submission( array $row, string $lang, bool $duplicate ): array {
		$branch_id = (int) $row['branch_id'];
		$b         = self::branch( $branch_id ) ?? [];
		$settings  = BranchSettingsRepository::get( $branch_id );
		$ref       = (string) $row['public_ref'];
		$carrier   = Normalizer::is_carrier_email( (string) $row['email'] );
		$body      = [
			'reference'                => $ref,
			'reference_display'        => Crockford::display( $ref ),
			'status'                   => 'pending',
			'status_label'             => momos_t( 'status.pending', [], $lang ),
			'duplicate'                => $duplicate,
			'message'                  => $duplicate ? momos_t( 'res.info.duplicate', [ 'ref' => Crockford::display( $ref ) ], $lang ) : momos_t( 'res.submitted.next', [], $lang ),
			'summary'                  => [
				'branch_code'       => (string) ( $b['code'] ?? '' ),
				'branch_name'       => self::branch_name( $branch_id, $lang ),
				'branch_address'    => self::branch_address( $branch_id, $lang ),
				'branch_address_ja' => (string) ( $b['address_ja'] ?? '' ),
				'date'              => (string) $row['requested_date'],
				'time'              => substr( (string) $row['requested_time'], 0, 5 ),
				'timezone'          => 'Asia/Tokyo',
				'party_size'        => (int) $row['party_size'],
				'guest_name'        => (string) $row['guest_name'],
			],
			'email_masked'             => self::mask_email( (string) $row['email'] ),
			'decision_deadline'        => self::decision_deadline( $row ),
			'branch_contact'           => [ 'phone_e164' => (string) ( $b['phone_e164'] ?? '' ), 'phone_display' => (string) ( $b['phone_display'] ?? '' ) ],
			'response_promise_minutes' => $settings['show_response_promise'] && $settings['response_sla_minutes'] > 0 ? (int) $settings['response_sla_minutes'] : null,
			'carrier_email'            => $carrier,
			'email_delivery_tip'       => $carrier ? momos_t( 'res.carrier_tip', [ 'domain' => self::sending_domain() ], $lang ) : null,
		];
		if ( ! $duplicate && Options::bool( 'momo_res_status_page_enabled' ) ) {
			$body['status_url'] = TokenService::status_url( $row );
		}
		return $body;
	}

	public static function sending_domain(): string {
		$from = defined( 'MOMOS_MAIL_FROM' ) ? (string) MOMOS_MAIL_FROM : '';
		if ( str_contains( $from, '@' ) ) {
			return strtolower( substr( $from, strrpos( $from, '@' ) + 1 ) );
		}
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * API-03/04 customer view. completed/no_show are shown as confirmed + status_note (customers never
	 * see attendance); history without staff identities or notes.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function customer_status( array $row, string $lang ): array {
		$branch_id = (int) $row['branch_id'];
		$b         = self::branch( $branch_id ) ?? [];
		$status    = (string) $row['status'];
		$shown     = in_array( $status, [ 'completed', 'no_show' ], true ) ? 'confirmed' : $status;
		$start     = Clock::from_mysql_utc( (string) $row['requested_start_utc'] );
		$past      = in_array( $status, [ 'completed', 'no_show' ], true ) || ( 'confirmed' === $status && $start && Clock::utc() >= $start );
		$history   = [];
		foreach ( EventRepository::for_reservation( (int) $row['id'] ) as $e ) {
			if ( in_array( $e['event_type'], [ 'created', 'status_changed' ], true ) && ! empty( $e['to_status'] ) ) {
				$to = in_array( $e['to_status'], [ 'completed', 'no_show' ], true ) ? null : (string) $e['to_status'];
				if ( null !== $to ) {
					$history[] = [ 'status' => $to, 'at' => self::iso_jst( (string) $e['created_at'] ) ];
				}
			}
		}
		$suggested = 'rejected' === $status ? array_values( Json::decode_array( $row['suggested_times'] ?? null ) ) : [];
		$lat       = $b['lat'] ?? null;
		$lng       = $b['lng'] ?? null;
		$dir       = null;
		if ( null !== $lat && null !== $lng ) {
			$dir = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $lat . ',' . $lng ) . ( ! empty( $b['place_id'] ) ? '&destination_place_id=' . rawurlencode( (string) $b['place_id'] ) : '' );
		}
		$can_cancel = TransitionService::customer_can_cancel( $row );
		return [
			'reference_display'        => Crockford::display( (string) $row['public_ref'] ),
			'status'                   => $shown,
			'status_label'             => momos_t( 'status.' . $shown, [], $lang ),
			'status_note'              => $past ? momos_t( 'status.past', [], $lang ) : null,
			'branch'                   => [
				'code'           => (string) ( $b['code'] ?? '' ),
				'name'           => self::branch_name( $branch_id, $lang ),
				'address'        => self::branch_address( $branch_id, $lang ),
				'address_ja'     => (string) ( $b['address_ja'] ?? '' ),
				'phone_e164'     => (string) ( $b['phone_e164'] ?? '' ),
				'phone_display'  => (string) ( $b['phone_display'] ?? '' ),
				'directions_url' => $dir,
			],
			'date'                     => (string) $row['requested_date'],
			'time'                     => substr( (string) $row['requested_time'], 0, 5 ),
			'timezone'                 => 'Asia/Tokyo',
			'party_size'               => (int) $row['party_size'],
			'guest_given_name'         => Normalizer::given_name( (string) $row['guest_name'] ),
			'guest_name'               => (string) $row['guest_name'],
			'email_masked'             => self::mask_email( (string) $row['email'] ),
			'special_request_received' => '' !== trim( (string) $row['special_request'] ),
			'message_from_branch'      => in_array( $status, [ 'confirmed', 'rejected', 'cancelled', 'completed', 'no_show' ], true ) && '' !== (string) $row['message_to_customer'] ? (string) $row['message_to_customer'] : null,
			'decline_reason_label'     => 'rejected' === $status && ! empty( $row['decision_reason_code'] ) ? momos_t( 'decline.reason.' . $row['decision_reason_code'], [], $lang ) : null,
			'suggested_times'          => $suggested,
			'cancelled_by'             => 'cancelled' === $status ? ( 'staff' === $row['cancelled_by'] ? 'branch' : (string) $row['cancelled_by'] ) : null,
			'can_cancel'               => $can_cancel,
			'cancel_deadline'          => in_array( $status, [ 'pending', 'confirmed' ], true ) ? TransitionService::customer_cancel_deadline( $row )->setTimezone( Clock::tz() )->format( 'c' ) : null,
			'history'                  => $history,
		];
	}

	/**
	 * API-10 row: never email, phone or special-request text.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function list_row( array $row ): array {
		$b       = self::branch( (int) $row['branch_id'] ) ?? [];
		$handler = ! empty( $row['handled_by'] ) ? get_userdata( (int) $row['handled_by'] ) : null;
		$ref     = (string) $row['public_ref'];
		return [
			'id'                => (int) $row['id'],
			'reference_display' => Crockford::display( $ref ),
			'branch_code'       => (string) ( $b['code'] ?? '' ),
			'branch_name'       => (string) ( $b['name'] ?? '' ),
			'status'            => (string) $row['status'],
			'requested_date'    => (string) $row['requested_date'],
			'requested_time'    => substr( (string) $row['requested_time'], 0, 5 ),
			'party_size'        => (int) $row['party_size'],
			'guest_name'        => (string) $row['guest_name'],
			'guest_given_name'  => Normalizer::given_name( (string) $row['guest_name'] ),
			'preferred_lang'    => (string) $row['preferred_lang'],
			'handled_by'        => $handler ? [ 'id' => (int) $handler->ID, 'name' => (string) $handler->display_name ] : null,
			'created_at'        => self::iso_jst( (string) $row['created_at'] ),
			'sla_state'         => SlaClock::state( $row ),
			'flags'             => [
				'needs_action'          => '1' === (string) $row['needs_action_flag'],
				'customer_not_notified' => '0' === (string) $row['customer_notified_flag'],
				'carrier_email'         => Normalizer::is_carrier_email( (string) $row['email'] ),
				'has_special_request'   => '' !== trim( (string) $row['special_request'] ),
				'captcha_unverified'    => 'unverified_outage' === $row['captcha_status'],
				'test_branch'           => ! empty( $b['is_test'] ),
			],
			'version'           => (int) $row['version'],
		];
	}

	/**
	 * API-11 detail.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public static function detail( array $row, int $user_id ): array {
		$out = self::list_row( $row );
		if ( ReservationAccess::can_view_pii( $row, $user_id ) ) {
			$out['email']           = (string) $row['email'];
			$out['phone_e164']      = null !== $row['phone_e164'] ? (string) $row['phone_e164'] : null;
			$out['special_request'] = null !== $row['special_request'] ? (string) $row['special_request'] : null;
		}
		$s = BranchSettingsRepository::get( (int) $row['branch_id'] );
		foreach ( [ 'source_lang', 'consent_version', 'decision_reason_code', 'message_to_customer', 'cancelled_by', 'captcha_status', 'source' ] as $k ) {
			$out[ $k ] = null !== $row[ $k ] ? (string) $row[ $k ] : null;
		}
		$out['requested_start']   = self::iso_jst( (string) $row['requested_start_utc'] );
		$out['decision_deadline'] = self::decision_deadline( $row );
		$out['suggested_times']   = array_values( Json::decode_array( $row['suggested_times'] ?? null ) );
		foreach ( [ 'consent_at', 'updated_at', 'confirmed_at', 'rejected_at', 'cancelled_at', 'expired_at', 'attended_at', 'pii_purged_at' ] as $k ) {
			$out[ $k ] = self::iso_jst( $row[ $k ] ?? null );
		}
		$out['events'] = array_map(
			static fn( $e ) => [
				'id'            => (int) $e['id'],
				'type'          => (string) $e['event_type'],
				'from'          => $e['from_status'],
				'to'            => $e['to_status'],
				'actor_type'    => (string) $e['actor_type'],
				'actor_display' => 'system' === $e['actor_type'] ? null : $e['actor_display'],
				'reason'        => $e['reason_code'],
				'note'          => $e['note'],
				'payload'       => Json::decode_array( $e['payload'] ?? null ),
				'at'            => self::iso_jst( (string) $e['created_at'] ),
			],
			array_reverse( EventRepository::for_reservation( (int) $row['id'] ) )
		);
		$out['notifications']           = self::notifications( (int) $row['id'], $user_id );
		$out['allowed_actions']         = ActionPolicy::allowed( $row, $user_id );
		$out['branch_settings_summary'] = [
			'party_min'               => (int) $s['party_min'],
			'party_max'               => (int) $s['party_max'],
			'lead_time_minutes'       => (int) $s['lead_time_minutes'],
			'decision_cutoff_minutes' => (int) $s['decision_cutoff_minutes'],
			'booking_window_days'     => (int) $s['booking_window_days'],
			'response_sla_minutes'    => (int) $s['response_sla_minutes'],
			'enabled'                 => (bool) $s['enabled'],
		];
		return $out;
	}

	/**
	 * API-11 `notifications` (masked recipients only). Built on the Notification module's
	 * NotificationPanel::api_rows() (resend eligibility, `last_error` text only for SA/HO) plus the
	 * documented API-11 keys (`template`, `variant`, `language`, `last_error` = error code for others)
	 * and ISO JST instants. Newest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function notifications( int $reservation_id, ?int $user_id = null ): array {
		if ( ! class_exists( \Momos\Notification\Admin\NotificationPanel::class ) ) {
			return [];
		}
		global $wpdb;
		$t       = Tables::notifications();
		$created = [];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at FROM {$t} WHERE reservation_id = %d", $reservation_id ), ARRAY_A ) as $c ) { // phpcs:ignore
			$created[ (int) $c['id'] ] = (string) $c['created_at'];
		}
		$rows = \Momos\Notification\Admin\NotificationPanel::api_rows( $reservation_id, $user_id ?? get_current_user_id() );
		$out  = [];
		foreach ( $rows as $n ) {
			$id    = (int) $n['id'];
			$item  = $n;
			$item['template']        = (string) $n['template_key'];
			$item['variant']         = $n['template_variant'];
			$item['language']        = (string) $n['lang'];
			$item['last_error']      = array_key_exists( 'last_error', $n ) && null !== $n['last_error'] ? $n['last_error'] : $n['last_error_code'];
			$item['sent_at']         = self::iso_jst( $n['sent_at'] ?? null );
			$item['failed_at']       = self::iso_jst( $n['failed_at'] ?? null );
			$item['next_attempt_at'] = self::iso_jst( $n['next_attempt_at'] ?? null );
			$item['created_at']      = self::iso_jst( $created[ $id ] ?? null );
			$out[]                   = $item;
		}
		usort( $out, static fn( $a, $b ) => [ (string) $b['created_at'], $b['id'] ] <=> [ (string) $a['created_at'], $a['id'] ] );
		return array_slice( $out, 0, 50 );
	}
}
