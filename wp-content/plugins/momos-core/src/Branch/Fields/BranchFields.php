<?php
declare(strict_types=1);

namespace Momos\Branch\Fields;

use Momos\Branch\Domain\OperatingStatus;
use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Branch\Service\BranchPublishValidator;
use Momos\Branch\Service\SettingsValidator;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Translations;

/**
 * Field groups of `momo_branch`: G4 identity, G5 access & facilities, G6 reservation settings
 * (spec §3.2, TRD G4–G6). Meta keys are the documented `momo_*` names.
 */
final class BranchFields {
	public const G4 = 'momo_branch_identity';
	public const G5 = 'momo_branch_access';
	public const G6 = 'momo_branch_reservation';

	/** Max access-direction rows (spec §3.2 G5). */
	public const MAX_DIRECTIONS = 5;

	/** Identity fields whose change emits `identity_changed` (spec §4.1). */
	public const IDENTITY_KEYS = [ 'momo_branch_code', 'momo_operating_status', 'momo_reopen_date', 'momo_postal_code', 'momo_prefecture', 'momo_city', 'momo_address_line1', 'momo_address_line2', 'momo_address_ja', 'momo_lat', 'momo_lng', 'momo_place_id', 'momo_phone_e164', 'momo_phone_display', 'momo_concept_label', 'momo_area_label' ];

	/** Fields that require a fresh GATE-01 confirmation when changed on a published branch. */
	public const RECONFIRM_KEYS = [ 'momo_postal_code', 'momo_prefecture', 'momo_city', 'momo_address_line1', 'momo_address_line2', 'momo_address_ja', 'momo_phone_e164' ];

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_groups' ], 8 );
	}

	public static function register_groups(): void {
		$id   = 'momo_edit_branch_identity';
		$st   = 'momo_edit_branch_settings';
		$unk  = [ 'unknown' => __( 'Unknown (not shown)', 'momos-core' ) ];
		FieldRegistry::group(
			[
				'key'       => self::G4,
				'title'     => __( 'Identity', 'momos-core' ),
				'post_type' => 'momo_branch',
				'order'     => 10,
				'fields'    => [
					[ 'name' => 'momo_branch_code', 'label' => __( 'Branch code', 'momos-core' ), 'type' => 'text', 'maxlength' => 3, 'required' => 'publish', 'edit_cap' => $id, 'column' => true, 'pattern' => '^[A-Z]{3}$', 'pattern_message' => __( 'Branch code must be 3 capital letters and unique.', 'momos-core' ), 'help' => __( '3 capital letters, e.g. SHN. Cannot be changed after publication or once reservations exist.', 'momos-core' ), 'validate' => [ self::class, 'validate_code' ] ],
					[ 'name' => 'momo_concept_label', 'label' => __( 'Concept label', 'momos-core' ), 'type' => 'text', 'maxlength' => 60, 'edit_cap' => $id, 'translatable' => true, 'help' => __( 'Optional brand/concept tag (DEC-001).', 'momos-core' ) ],
					[ 'name' => 'momo_operating_status', 'label' => __( 'Operating status', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'required' => true, 'default' => OperatingStatus::Open->value, 'edit_cap' => $id, 'column' => true, 'choices' => self::status_choices(), 'validate' => [ self::class, 'validate_status' ] ],
					[ 'name' => 'momo_reopen_date', 'label' => __( 'Reopening date', 'momos-core' ), 'type' => 'date', 'edit_cap' => $id, 'show_if' => [ 'momo_operating_status' => OperatingStatus::TemporarilyClosed->value ], 'validate' => [ self::class, 'validate_reopen_date' ] ],
					[ 'name' => 'momo_area_label', 'label' => __( 'Area / station label', 'momos-core' ), 'type' => 'text', 'maxlength' => 40, 'edit_cap' => $id, 'translatable' => true ],
					[ 'name' => 'momo_postal_code', 'label' => __( 'Postal code', 'momos-core' ), 'type' => 'text', 'maxlength' => 8, 'required' => 'publish', 'edit_cap' => $id, 'pattern' => '^\d{3}-\d{4}$', 'pattern_message' => __( 'Enter the postal code as 123-4567.', 'momos-core' ) ],
					[ 'name' => 'momo_prefecture', 'label' => __( 'Prefecture', 'momos-core' ), 'type' => 'text', 'maxlength' => 20, 'required' => 'publish', 'edit_cap' => $id, 'translatable' => true ],
					[ 'name' => 'momo_city', 'label' => __( 'City / ward', 'momos-core' ), 'type' => 'text', 'maxlength' => 60, 'required' => 'publish', 'edit_cap' => $id, 'translatable' => true ],
					[ 'name' => 'momo_address_line1', 'label' => __( 'Address line 1 (street)', 'momos-core' ), 'type' => 'text', 'maxlength' => 120, 'required' => 'publish', 'edit_cap' => $id, 'translatable' => true ],
					[ 'name' => 'momo_address_line2', 'label' => __( 'Address line 2 (building, floor)', 'momos-core' ), 'type' => 'text', 'maxlength' => 120, 'edit_cap' => $id, 'translatable' => true, 'help' => __( 'Floor must be confirmed by the owner.', 'momos-core' ) ],
					[ 'name' => 'momo_address_ja', 'label' => __( 'Full Japanese address', 'momos-core' ), 'type' => 'textarea', 'rows' => 2, 'maxlength' => 300, 'required' => 'publish', 'edit_cap' => $id, 'help' => __( 'Copied verbatim by the "Copy Japanese address" button: postal code, building and floor included.', 'momos-core' ) ],
					[ 'name' => 'momo_lat', 'label' => __( 'Latitude', 'momos-core' ), 'type' => 'text', 'maxlength' => 12, 'required' => 'publish', 'edit_cap' => $id, 'validate' => [ self::class, 'validate_lat' ] ],
					[ 'name' => 'momo_lng', 'label' => __( 'Longitude', 'momos-core' ), 'type' => 'text', 'maxlength' => 12, 'required' => 'publish', 'edit_cap' => $id, 'validate' => [ self::class, 'validate_lng' ] ],
					[ 'name' => 'momo_place_id', 'label' => __( 'Google Place ID', 'momos-core' ), 'type' => 'text', 'maxlength' => 255, 'edit_cap' => $id, 'pattern' => '^[A-Za-z0-9_-]+$' ],
					[ 'name' => 'momo_phone_e164', 'label' => __( 'Phone (E.164)', 'momos-core' ), 'type' => 'tel', 'maxlength' => 20, 'required' => 'publish', 'edit_cap' => $id, 'pattern' => '^\+81\d{9,10}$', 'pattern_message' => __( 'Enter a valid phone number.', 'momos-core' ), 'help' => __( 'e.g. 06-0000-0000 is stored as +81600000000.', 'momos-core' ) ],
					[ 'name' => 'momo_phone_display', 'label' => __( 'Phone (display)', 'momos-core' ), 'type' => 'text', 'maxlength' => 20, 'required' => 'publish', 'edit_cap' => $id, 'pattern' => '^[0-9-]{10,14}$', 'pattern_message' => __( 'Enter a valid phone number.', 'momos-core' ), 'help' => __( 'National format; derived from the E.164 number when left empty.', 'momos-core' ) ],
					[ 'name' => 'momo_branch_email', 'label' => __( 'Branch email (reply-to, not public)', 'momos-core' ), 'type' => 'email', 'maxlength' => 254, 'required' => 'publish', 'edit_cap' => $id, 'validate' => [ self::class, 'validate_branch_email' ] ],
					[ 'name' => 'momo_is_test_branch', 'label' => __( 'Hidden TEST branch', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => 'manage_options', 'message' => __( 'Hidden test branch for production smoke tests (never public)', 'momos-core' ) ],
					[ 'name' => 'momo_facts_confirmed_by', 'label' => __( 'Facts confirmed in writing by', 'momos-core' ), 'type' => 'text', 'maxlength' => 100, 'required' => 'publish', 'edit_cap' => $id ],
					[ 'name' => 'momo_facts_confirmed_at', 'label' => __( 'Facts confirmed on', 'momos-core' ), 'type' => 'date', 'required' => 'publish', 'edit_cap' => $id, 'validate' => [ self::class, 'validate_not_future' ] ],
					[ 'name' => 'momo_facts_evidence_note', 'label' => __( 'Evidence / fact-sheet reference', 'momos-core' ), 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500, 'edit_cap' => $id ],
					[ 'name' => 'momo_gallery', 'label' => __( 'Gallery (real photos only)', 'momos-core' ), 'type' => 'gallery', 'edit_cap' => 'edit_post', 'validate' => [ self::class, 'validate_gallery' ] ],
					[ 'name' => 'momo_external_ref', 'label' => __( 'External reference (Phase 2, never displayed)', 'momos-core' ), 'type' => 'text', 'maxlength' => 64, 'edit_cap' => 'manage_options' ],
					[ 'name' => 'momo_first_published_at', 'label' => __( 'First published (UTC)', 'momos-core' ), 'type' => 'text', 'stamp' => 'datetime' ],
				],
			]
		);

		$yes_no = [ 'yes' => __( 'Yes', 'momos-core' ), 'no' => __( 'No', 'momos-core' ) ];
		FieldRegistry::group(
			[
				'key'       => self::G5,
				'title'     => __( 'Access & facilities', 'momos-core' ),
				'post_type' => 'momo_branch',
				'order'     => 20,
				'description' => __( '"Unknown" values are never shown to customers. Only enter owner-confirmed facts.', 'momos-core' ),
				'fields'    => [
					[
						'name'         => 'momo_access_directions',
						'label'        => __( 'Access directions', 'momos-core' ),
						'type'         => 'repeater',
						'max_rows'     => self::MAX_DIRECTIONS,
						'edit_cap'     => $id,
						'button_label' => __( 'Add station', 'momos-core' ),
						'sub_fields'   => [
							[ 'name' => 'station', 'label' => __( 'Station', 'momos-core' ), 'type' => 'text', 'maxlength' => 60, 'required' => true ],
							[ 'name' => 'exit', 'label' => __( 'Exit', 'momos-core' ), 'type' => 'text', 'maxlength' => 20 ],
							[ 'name' => 'walking_minutes', 'label' => __( 'Walking minutes', 'momos-core' ), 'type' => 'number', 'min' => 1, 'max' => 60 ],
						],
					],
					[ 'name' => 'momo_access_notes', 'label' => __( 'Access notes / landmarks', 'momos-core' ), 'type' => 'textarea', 'rows' => 3, 'maxlength' => 400, 'edit_cap' => $id, 'translatable' => true ],
					[ 'name' => 'momo_prayer_space', 'label' => __( 'Prayer space', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => 'unknown', 'edit_cap' => $id, 'choices' => $yes_no + [ 'nearby' => __( 'Nearby', 'momos-core' ) ] + $unk ],
					[ 'name' => 'momo_prayer_note', 'label' => __( 'Prayer space note', 'momos-core' ), 'type' => 'text', 'maxlength' => 120, 'edit_cap' => $id, 'translatable' => true ],
					[ 'name' => 'momo_smoking_policy', 'label' => __( 'Smoking', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => 'unknown', 'edit_cap' => $id, 'choices' => [ 'non_smoking' => __( 'Non-smoking', 'momos-core' ), 'smoking_area' => __( 'Smoking area', 'momos-core' ) ] + $unk ],
					[ 'name' => 'momo_step_free_access', 'label' => __( 'Step-free access', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => 'unknown', 'edit_cap' => $id, 'choices' => [ 'yes' => __( 'Yes', 'momos-core' ), 'partial' => __( 'Partly', 'momos-core' ), 'no' => __( 'No', 'momos-core' ) ] + $unk ],
					[ 'name' => 'momo_seating_types', 'label' => __( 'Seating', 'momos-core' ), 'type' => 'checkbox', 'edit_cap' => $id, 'choices' => [ 'table' => __( 'Table', 'momos-core' ), 'counter' => __( 'Counter', 'momos-core' ), 'private_room' => __( 'Private room', 'momos-core' ), 'tatami' => __( 'Tatami', 'momos-core' ), 'terrace' => __( 'Terrace', 'momos-core' ) ] ],
					[ 'name' => 'momo_payment_methods', 'label' => __( 'Payment methods', 'momos-core' ), 'type' => 'checkbox', 'edit_cap' => $id, 'choices' => [ 'cash' => __( 'Cash', 'momos-core' ), 'credit_card' => __( 'Credit cards', 'momos-core' ), 'ic_card' => __( 'IC cards', 'momos-core' ), 'qr_payment' => __( 'QR payments', 'momos-core' ), 'other' => __( 'Other', 'momos-core' ) ] ],
					[ 'name' => 'momo_wifi', 'label' => __( 'Wi-Fi', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => 'unknown', 'edit_cap' => $id, 'choices' => $yes_no + $unk ],
					[ 'name' => 'momo_map_preview_image', 'label' => __( 'Map preview image (own/licensed, no Google imagery)', 'momos-core' ), 'type' => 'image', 'edit_cap' => $id ],
				],
				'on_save'   => [ self::class, 'mirror_directions' ],
			]
		);

		$d = BranchSettingsRepository::DEFAULTS;
		FieldRegistry::group(
			[
				'key'       => self::G6,
				'title'     => __( 'Reservation settings', 'momos-core' ),
				'post_type' => 'momo_branch',
				'order'     => 30,
				'view_cap'  => 'momo_view_reservations',
				'description' => __( 'Changes apply to new requests and modifications only. Head Office only.', 'momos-core' ),
				'fields'    => [
					[ 'name' => 'momo_res_enabled', 'label' => __( 'Accept online requests', 'momos-core' ), 'type' => 'true_false', 'default' => $d['momo_res_enabled'], 'edit_cap' => $st, 'column' => true ],
					[ 'name' => 'momo_res_party_min', 'label' => __( 'Minimum party size', 'momos-core' ), 'type' => 'number', 'min' => 1, 'max' => 20, 'default' => (string) $d['momo_res_party_min'], 'edit_cap' => $st ],
					[ 'name' => 'momo_res_party_max', 'label' => __( 'Maximum party size', 'momos-core' ), 'type' => 'number', 'min' => 1, 'max' => 50, 'default' => (string) $d['momo_res_party_max'], 'edit_cap' => $st ],
					[ 'name' => 'momo_res_lead_time_minutes', 'label' => __( 'Minimum lead time (minutes)', 'momos-core' ), 'type' => 'number', 'min' => 0, 'max' => 10080, 'default' => (string) $d['momo_res_lead_time_minutes'], 'edit_cap' => $st ],
					[ 'name' => 'momo_res_booking_window_days', 'label' => __( 'Booking window (days)', 'momos-core' ), 'type' => 'number', 'min' => 1, 'max' => 365, 'default' => (string) $d['momo_res_booking_window_days'], 'edit_cap' => $st ],
					[ 'name' => 'momo_res_slot_interval_minutes', 'label' => __( 'Slot interval (minutes)', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => (string) $d['momo_res_slot_interval_minutes'], 'edit_cap' => $st, 'choices' => [ '15' => '15', '30' => '30', '60' => '60' ] ],
					[ 'name' => 'momo_res_decision_cutoff_minutes', 'label' => __( 'Decision cutoff before start (minutes)', 'momos-core' ), 'type' => 'number', 'min' => 0, 'max' => 1440, 'default' => (string) $d['momo_res_decision_cutoff_minutes'], 'edit_cap' => $st ],
					[ 'name' => 'momo_res_response_sla_minutes', 'label' => __( 'Response time target (minutes, 0 = off)', 'momos-core' ), 'type' => 'number', 'min' => 0, 'max' => 1440, 'default' => (string) $d['momo_res_response_sla_minutes'], 'edit_cap' => $st ],
					[ 'name' => 'momo_res_sla_clock', 'label' => __( 'Response time counted in', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => $d['momo_res_sla_clock'], 'edit_cap' => $st, 'choices' => [ 'wall_clock' => __( 'Wall-clock time', 'momos-core' ), 'business_hours' => __( 'Business hours', 'momos-core' ) ] ],
					[ 'name' => 'momo_res_show_response_promise', 'label' => __( 'Show response promise to customers', 'momos-core' ), 'type' => 'true_false', 'default' => $d['momo_res_show_response_promise'], 'edit_cap' => $st, 'help' => __( 'Requires management approval (DEC-013).', 'momos-core' ) ],
					[ 'name' => 'momo_res_max_pending_per_email', 'label' => __( 'Max pending requests per email', 'momos-core' ), 'type' => 'number', 'min' => 1, 'max' => 10, 'default' => (string) $d['momo_res_max_pending_per_email'], 'edit_cap' => $st ],
					[ 'name' => 'momo_res_customer_cancel_cutoff_minutes', 'label' => __( 'Customer cancellation cutoff (minutes, 0 = until start)', 'momos-core' ), 'type' => 'number', 'min' => 0, 'max' => 1440, 'default' => (string) $d['momo_res_customer_cancel_cutoff_minutes'], 'edit_cap' => $st ],
					[
						'name'         => 'momo_res_notification_recipients',
						'label'        => __( 'Notification recipients', 'momos-core' ),
						'type'         => 'repeater',
						'max_rows'     => SettingsValidator::MAX_RECIPIENTS,
						'edit_cap'     => $st,
						'button_label' => __( 'Add recipient', 'momos-core' ),
						'help'         => __( 'Business-controlled addresses only (no personal webmail).', 'momos-core' ),
						'sub_fields'   => [
							[ 'name' => 'email', 'label' => __( 'Email', 'momos-core' ), 'type' => 'email', 'required' => true ],
							[ 'name' => 'language', 'label' => __( 'Language', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => 'ja', 'choices' => [ 'ja' => '日本語', 'en' => 'English' ] ],
							[ 'name' => 'active', 'label' => __( 'Active', 'momos-core' ), 'type' => 'true_false', 'default' => '1' ],
						],
					],
					[ 'name' => 'momo_res_policy_page', 'label' => __( 'Branch reservation policy page', 'momos-core' ), 'type' => 'post_object', 'post_type' => 'page', 'edit_cap' => $st ],
				],
			]
		);

		// Core fields + access-direction rows (station/exit per row) join the translation registry.
		$tr = [
			'title'   => [ 'label' => __( 'Branch name', 'momos-core' ), 'type' => 'text', 'source' => 'post_title', 'max' => 80 ],
			'content' => [ 'label' => __( 'Description', 'momos-core' ), 'type' => 'html', 'source' => 'post_content' ],
		];
		for ( $i = 1; $i <= self::MAX_DIRECTIONS; $i++ ) {
			/* translators: %d: row number */
			$tr[ 'access_station_' . $i ] = [ 'label' => sprintf( __( 'Access row %d: station', 'momos-core' ), $i ), 'type' => 'text', 'source' => 'meta:_momo_access_station_' . $i, 'max' => 60 ];
			/* translators: %d: row number */
			$tr[ 'access_exit_' . $i ] = [ 'label' => sprintf( __( 'Access row %d: exit', 'momos-core' ), $i ), 'type' => 'text', 'source' => 'meta:_momo_access_exit_' . $i, 'max' => 20 ];
		}
		Translations::register( 'post:momo_branch', $tr );
	}

	/** @return array<string,string> */
	public static function status_choices(): array {
		return [
			OperatingStatus::Open->value              => __( 'Open', 'momos-core' ),
			OperatingStatus::TemporarilyClosed->value => __( 'Temporarily closed', 'momos-core' ),
			OperatingStatus::ClosedPermanently->value => __( 'Closed permanently', 'momos-core' ),
		];
	}

	/**
	 * Translatable keys of the branch (spec §2 BranchFieldRegistry::translatableFields()).
	 *
	 * @return list<string>
	 */
	public static function translatable_fields(): array {
		return array_keys( Translations::fields( 'post:momo_branch' ) );
	}

	/** Mirror station/exit of each access row into source meta for the translation editor (IMPL-002). */
	public static function mirror_directions( int|string $post_id ): void {
		$post_id = (int) $post_id;
		$rows    = get_post_meta( $post_id, 'momo_access_directions', true );
		$rows    = is_array( $rows ) ? array_values( $rows ) : [];
		for ( $i = 1; $i <= self::MAX_DIRECTIONS; $i++ ) {
			$row = $rows[ $i - 1 ] ?? null;
			if ( $row ) {
				update_post_meta( $post_id, '_momo_access_station_' . $i, (string) ( $row['station'] ?? '' ) );
				update_post_meta( $post_id, '_momo_access_exit_' . $i, (string) ( $row['exit'] ?? '' ) );
			} else {
				delete_post_meta( $post_id, '_momo_access_station_' . $i );
				delete_post_meta( $post_id, '_momo_access_exit_' . $i );
			}
		}
	}

	// ------------------------------------------------------------------ field validators

	/** Unique `^[A-Z]{3}$`; immutable after first publish or once reservations exist (§4.1, Q-422). */
	public static function validate_code( mixed $new, int|string $post_id ): ?string {
		$post_id = (int) $post_id;
		$new     = (string) $new;
		$old     = (string) get_post_meta( $post_id, 'momo_branch_code', true );
		if ( '' !== $old && $old !== $new ) {
			if ( BranchRepository::has_reservations( $post_id ) ) {
				return __( 'The branch code cannot be changed after reservations exist.', 'momos-core' );
			}
			if ( '' !== (string) get_post_meta( $post_id, 'momo_first_published_at', true ) ) {
				return __( 'The branch code cannot be changed after publication.', 'momos-core' );
			}
		}
		return BranchPublishValidator::code_error( $new, $post_id );
	}

	/** closed_permanently refused while future pending/confirmed reservations exist (§4.3). */
	public static function validate_status( mixed $new, int|string $post_id ): ?string {
		if ( OperatingStatus::ClosedPermanently->value !== (string) $new ) {
			return null;
		}
		$future = \Momos\Branch\Service\ClosureImpactService::future_active_reservations( (int) $post_id );
		if ( ! $future ) {
			return null;
		}
		$list = implode(
			'; ',
			array_map(
				static fn( $r ) => sprintf( '%s %s %s (%s)', \Momos\Kernel\Support\Crockford::display( (string) $r['public_ref'] ), $r['requested_date'], substr( (string) $r['requested_time'], 0, 5 ), $r['status'] ),
				array_slice( $future, 0, 20 )
			)
		);
		/* translators: 1: number of requests 2: list of references */
		return sprintf( __( 'Handle the %1$d pending or confirmed future requests before closing this branch: %2$s', 'momos-core' ), count( $future ), $list );
	}

	public static function validate_reopen_date( mixed $new ): ?string {
		return '' !== (string) $new && (string) $new < Clock::jst()->format( 'Y-m-d' ) ? __( 'The reopening date cannot be in the past.', 'momos-core' ) : null;
	}

	public static function validate_not_future( mixed $new ): ?string {
		return '' !== (string) $new && (string) $new > Clock::jst()->format( 'Y-m-d' ) ? __( 'The confirmation date cannot be in the future.', 'momos-core' ) : null;
	}

	public static function validate_lat( mixed $new ): ?string {
		return self::coord_error( (string) $new, 20.0, 46.0 );
	}

	public static function validate_lng( mixed $new ): ?string {
		return self::coord_error( (string) $new, 122.0, 154.0 );
	}

	public static function coord_error( string $v, float $min, float $max ): ?string {
		if ( '' === $v ) {
			return null;
		}
		if ( ! preg_match( '/^-?\d{1,3}\.\d{5,}$/', $v ) || (float) $v < $min || (float) $v > $max ) {
			return __( 'Enter latitude and longitude with at least 5 decimal places.', 'momos-core' );
		}
		return null;
	}

	public static function validate_branch_email( mixed $new ): ?string {
		return '' === (string) $new ? null : SettingsValidator::domain_error( (string) $new );
	}

	/** 0–12 images, none flagged illustrative (DEC-047). */
	public static function validate_gallery( mixed $new ): ?string {
		$ids = array_map( 'intval', (array) $new );
		if ( count( $ids ) > 12 ) {
			return __( 'Add at most 12 gallery photos.', 'momos-core' );
		}
		return BranchPublishValidator::illustrative_error( $ids );
	}
}
