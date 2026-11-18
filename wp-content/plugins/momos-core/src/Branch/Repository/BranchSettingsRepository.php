<?php
declare(strict_types=1);

namespace Momos\Branch\Repository;

use Momos\Branch\Service\SettingsValidator;

/**
 * Per-branch reservation settings G6 (FR-RES-023/024, docs/CONTRACTS.md). Always read fresh from
 * post meta (no cross-request cache, FR-RES-024(b)). Invalid stored values make the branch refuse
 * requests (`enabled = false`) instead of accepting them with wrong rules.
 */
final class BranchSettingsRepository {
	/** Documented defaults ⟦proposals⟧ (spec §3.2 G6, §12.1). */
	public const DEFAULTS = [
		'momo_res_enabled'                        => '0',
		'momo_res_party_min'                      => 1,
		'momo_res_party_max'                      => 8,
		'momo_res_lead_time_minutes'              => 120,
		'momo_res_booking_window_days'            => 60,
		'momo_res_slot_interval_minutes'          => 30,
		'momo_res_decision_cutoff_minutes'        => 60,
		'momo_res_response_sla_minutes'           => 120,
		'momo_res_sla_clock'                      => 'wall_clock',
		'momo_res_show_response_promise'          => '0',
		'momo_res_max_pending_per_email'          => 3,
		'momo_res_customer_cancel_cutoff_minutes' => 0,
		'momo_res_notification_recipients'        => [],
		'momo_res_policy_page'                    => '',
	];

	/**
	 * Raw G6 values (meta key => value) with defaults applied.
	 *
	 * @return array<string,mixed>
	 */
	public static function raw( int $branch_id ): array {
		$out = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$out[ $key ] = metadata_exists( 'post', $branch_id, $key ) ? get_post_meta( $branch_id, $key, true ) : $default;
		}
		return $out;
	}

	/**
	 * @return array{enabled:bool,party_min:int,party_max:int,lead_time_minutes:int,booking_window_days:int,slot_interval_minutes:int,decision_cutoff_minutes:int,response_sla_minutes:int,sla_clock:string,show_response_promise:bool,max_pending_per_email:int,customer_cancel_cutoff_minutes:int,policy_page:?int,valid:bool}
	 */
	public static function get( int $branch_id ): array {
		$raw   = self::raw( $branch_id );
		$int   = static fn( string $k ): int => ( '' === $raw[ $k ] || null === $raw[ $k ] ) ? (int) self::DEFAULTS[ $k ] : (int) $raw[ $k ];
		$valid = 'momo_branch' === get_post_type( $branch_id ) && [] === SettingsValidator::validate( array_merge( $raw, [ 'momo_res_enabled' => '0' ] ), 0 );
		$page  = (int) $raw['momo_res_policy_page'];
		return [
			'enabled'                        => $valid && '1' === (string) $raw['momo_res_enabled'],
			'party_min'                      => $int( 'momo_res_party_min' ),
			'party_max'                      => $int( 'momo_res_party_max' ),
			'lead_time_minutes'              => $int( 'momo_res_lead_time_minutes' ),
			'booking_window_days'            => $int( 'momo_res_booking_window_days' ),
			'slot_interval_minutes'          => $int( 'momo_res_slot_interval_minutes' ),
			'decision_cutoff_minutes'        => $int( 'momo_res_decision_cutoff_minutes' ),
			'response_sla_minutes'           => $int( 'momo_res_response_sla_minutes' ),
			'sla_clock'                      => in_array( $raw['momo_res_sla_clock'], [ 'business_hours', 'wall_clock' ], true ) ? (string) $raw['momo_res_sla_clock'] : 'wall_clock',
			'show_response_promise'          => '1' === (string) $raw['momo_res_show_response_promise'],
			'max_pending_per_email'          => $int( 'momo_res_max_pending_per_email' ),
			'customer_cancel_cutoff_minutes' => $int( 'momo_res_customer_cancel_cutoff_minutes' ),
			'policy_page'                    => $page > 0 && 'publish' === get_post_status( $page ) ? $page : null,
			'valid'                          => $valid,
		];
	}

	/**
	 * Active notification recipients (DEC-049, PRV-010).
	 *
	 * @return list<array{email:string, language:'ja'|'en'}>
	 */
	public static function active_recipients( int $branch_id ): array {
		$out = [];
		foreach ( SettingsValidator::rows( get_post_meta( $branch_id, 'momo_res_notification_recipients', true ) ) as $row ) {
			$email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
			if ( '' === $email || ! is_email( $email ) || empty( $row['active'] ) || '0' === (string) $row['active'] ) {
				continue;
			}
			$out[] = [ 'email' => $email, 'language' => 'en' === ( $row['language'] ?? 'ja' ) ? 'en' : 'ja' ];
		}
		return $out;
	}
}
