<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Domain\OperatingStatus;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Branch\Repository\HoursRepository;

/**
 * G6 reservation-settings rules (FR-RES-023, spec §4.8, BR-RES-15, PRV-010, FR-RES-026).
 */
final class SettingsValidator {
	/** meta key => [min, max] (spec §3.2 G6). party_max min is party_min (checked separately). */
	public const RANGES = [
		'momo_res_party_min'                      => [ 1, 20 ],
		'momo_res_party_max'                      => [ 1, 50 ],
		'momo_res_lead_time_minutes'              => [ 0, 10080 ],
		'momo_res_booking_window_days'            => [ 1, 365 ],
		'momo_res_decision_cutoff_minutes'        => [ 0, 1440 ],
		'momo_res_response_sla_minutes'           => [ 0, 1440 ],
		'momo_res_max_pending_per_email'          => [ 1, 10 ],
		'momo_res_customer_cancel_cutoff_minutes' => [ 0, 1440 ],
	];

	public const SLOT_INTERVALS = [ 15, 30, 60 ];
	public const MAX_RECIPIENTS = 10;

	/**
	 * Validate the full G6 value set (after the change). $branch_id is used for the enable and
	 * TEST rules; post status/operating status are read from the saved post (G4 saves first).
	 *
	 * @param array<string,mixed> $v meta key => value
	 * @return list<string>
	 */
	public static function validate( array $v, int $branch_id ): array {
		$errors = [];
		$d      = BranchSettingsRepository::DEFAULTS;
		$num    = static function ( string $key ) use ( $v, $d ): ?int {
			$raw = $v[ $key ] ?? null;
			if ( null === $raw || '' === $raw ) {
				return (int) $d[ $key ];
			}
			return is_numeric( $raw ) && (string) (int) $raw === (string) $raw ? (int) $raw : ( is_int( $raw ) ? $raw : null );
		};
		foreach ( self::RANGES as $key => [ $min, $max ] ) {
			$n = $num( $key );
			if ( null === $n || $n < $min || $n > $max ) {
				$errors[] = self::range_msg( $key, $min, $max );
			}
		}
		$sla = $num( 'momo_res_response_sla_minutes' );
		if ( null !== $sla && $sla > 0 && $sla < 15 ) {
			$errors[] = self::range_msg( 'momo_res_response_sla_minutes', 15, 1440 ) . ' ' . __( '(0 turns the reminder off.)', 'momos-core' );
		}
		$slot = $num( 'momo_res_slot_interval_minutes' );
		if ( ! in_array( $slot, self::SLOT_INTERVALS, true ) ) {
			$errors[] = __( 'Choose a slot interval of 15, 30 or 60 minutes.', 'momos-core' );
		}
		$clock = (string) ( $v['momo_res_sla_clock'] ?? $d['momo_res_sla_clock'] );
		if ( ! in_array( $clock, [ 'business_hours', 'wall_clock' ], true ) ) {
			$errors[] = __( 'Choose a valid response-time clock.', 'momos-core' );
		}
		$pmin = $num( 'momo_res_party_min' );
		$pmax = $num( 'momo_res_party_max' );
		if ( null !== $pmin && null !== $pmax && $pmin > $pmax ) {
			$errors[] = __( 'Minimum party size cannot be larger than the maximum.', 'momos-core' );
		}
		$lead   = $num( 'momo_res_lead_time_minutes' );
		$cutoff = $num( 'momo_res_decision_cutoff_minutes' );
		$window = GlobalSettings::min_decision_window();
		if ( null !== $lead && null !== $cutoff && $cutoff > $lead - $window ) {
			/* translators: %d: minimum decision window in minutes */
			$errors[] = sprintf( __( 'The decision cutoff must be at least %d minutes shorter than the minimum lead time.', 'momos-core' ), $window );
		}

		$recipients = self::rows( $v['momo_res_notification_recipients'] ?? [] );
		$errors     = array_merge( $errors, self::validate_recipients( $recipients, $branch_id ) );

		if ( '1' === (string) ( $v['momo_res_enabled'] ?? '0' ) ) {
			$errors = array_merge( $errors, self::enable_errors( $branch_id, $recipients ) );
		}
		return array_values( array_unique( $errors ) );
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return list<string>
	 */
	public static function validate_recipients( array $rows, int $branch_id ): array {
		$errors = [];
		if ( count( $rows ) > self::MAX_RECIPIENTS ) {
			/* translators: %d: maximum number of recipients */
			$errors[] = sprintf( __( 'Add at most %d notification recipients.', 'momos-core' ), self::MAX_RECIPIENTS );
		}
		$seen = [];
		foreach ( $rows as $row ) {
			$email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
			if ( '' === $email || ! is_email( $email ) ) {
				$errors[] = __( 'Enter a valid email address for every recipient.', 'momos-core' );
				continue;
			}
			if ( isset( $seen[ $email ] ) ) {
				$errors[] = __( 'Each recipient email can be listed only once.', 'momos-core' );
			}
			$seen[ $email ] = true;
			if ( ! in_array( (string) ( $row['language'] ?? 'ja' ), [ 'ja', 'en' ], true ) ) {
				$errors[] = __( 'Recipient language must be Japanese or English.', 'momos-core' );
			}
			$domain_error = self::domain_error( $email );
			if ( null !== $domain_error ) {
				$errors[] = $domain_error;
			}
		}
		if ( $branch_id && '1' === (string) get_post_meta( $branch_id, 'momo_is_test_branch', true ) ) {
			$others = self::active_recipients_of_real_branches( $branch_id );
			foreach ( $rows as $row ) {
				if ( ! empty( $row['active'] ) && '0' !== (string) $row['active'] && in_array( strtolower( (string) ( $row['email'] ?? '' ) ), $others, true ) ) {
					$errors[] = __( 'The TEST branch must use test inboxes only, not the recipients of a real branch.', 'momos-core' );
					break;
				}
			}
		}
		return array_values( array_unique( $errors ) );
	}

	/** Recipient domain rule (PRV-010): block list always, allow-list when configured. */
	public static function domain_error( string $email ): ?string {
		$domain  = strtolower( (string) substr( strrchr( $email, '@' ) ?: '', 1 ) );
		$allowed = GlobalSettings::allowed_domains();
		$base    = __( 'Use a business-controlled address (personal webmail is not allowed).', 'momos-core' );
		if ( in_array( $domain, GlobalSettings::blocked_domains(), true ) ) {
			return $allowed ? $base . ' ' . sprintf( /* translators: %s: domain list */ __( 'Allowed domains: %s', 'momos-core' ), implode( ', ', $allowed ) ) : $base;
		}
		if ( $allowed && ! in_array( $domain, $allowed, true ) ) {
			return $base . ' ' . sprintf( /* translators: %s: domain list */ __( 'Allowed domains: %s', 'momos-core' ), implode( ', ', $allowed ) );
		}
		return null;
	}

	/**
	 * `momo_res_enabled = 1` preconditions (spec §4.8).
	 *
	 * @param list<array<string,mixed>> $recipients
	 * @return list<string>
	 */
	public static function enable_errors( int $branch_id, array $recipients ): array {
		$post   = get_post( $branch_id );
		$status = (string) get_post_meta( $branch_id, 'momo_operating_status', true );
		$active = array_filter( $recipients, static fn( $r ) => ! empty( $r['active'] ) && '0' !== (string) $r['active'] );
		$rows   = HoursRepository::all_rows( $branch_id );
		$no_lo  = array_filter( $rows, static fn( $r ) => null === $r['last_order_at'] );
		$ok     = $post && 'publish' === $post->post_status && ( '' === $status || OperatingStatus::Open->value === $status ) && $active && $rows && ! $no_lo;
		return $ok ? [] : [ __( 'Online requests can be turned on only when the branch is published and open, has an active recipient and every opening period has a last-order time.', 'momos-core' ) ];
	}

	/** @return list<array<string,mixed>> */
	public static function rows( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/** @return list<string> lower-cased active recipient emails of all non-test branches */
	private static function active_recipients_of_real_branches( int $exclude ): array {
		$ids = get_posts(
			[
				'post_type'      => 'momo_branch',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'post__not_in'   => [ $exclude ],
				'no_found_rows'  => true,
			]
		);
		$out = [];
		foreach ( $ids as $id ) {
			if ( '1' === (string) get_post_meta( (int) $id, 'momo_is_test_branch', true ) ) {
				continue;
			}
			foreach ( BranchSettingsRepository::active_recipients( (int) $id ) as $r ) {
				$out[] = strtolower( $r['email'] );
			}
		}
		return $out;
	}

	private static function range_msg( string $key, int $min, int $max ): string {
		$labels = [
			'momo_res_party_min'                      => __( 'Minimum party size', 'momos-core' ),
			'momo_res_party_max'                      => __( 'Maximum party size', 'momos-core' ),
			'momo_res_lead_time_minutes'              => __( 'Minimum lead time', 'momos-core' ),
			'momo_res_booking_window_days'            => __( 'Booking window', 'momos-core' ),
			'momo_res_decision_cutoff_minutes'        => __( 'Decision cutoff', 'momos-core' ),
			'momo_res_response_sla_minutes'           => __( 'Response time target', 'momos-core' ),
			'momo_res_max_pending_per_email'          => __( 'Pending requests per email', 'momos-core' ),
			'momo_res_customer_cancel_cutoff_minutes' => __( 'Customer cancellation cutoff', 'momos-core' ),
		];
		/* translators: 1: field label 2: minimum 3: maximum */
		return sprintf( __( '%1$s: Enter a value between %2$d and %3$d.', 'momos-core' ), $labels[ $key ] ?? $key, $min, $max );
	}
}
