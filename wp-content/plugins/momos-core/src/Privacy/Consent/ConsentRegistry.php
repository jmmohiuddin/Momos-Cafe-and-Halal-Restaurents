<?php
declare(strict_types=1);

namespace Momos\Privacy\Consent;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Lang;

/**
 * Consent registry (ENT-24, PRV-003, FR-PRV-002, TK-12-16; Access spec §10.2, conflict 9).
 *
 * Storage: plain option `momo_consent_versions` (list of rows, edited on SCR-A15 by SA/HO through the
 * Admin module, or with `wp momos privacy consent add`) and the optional pin
 * `momo_consent_version_current`.
 *
 * Key format (Doc 11): `PP{privacy version}-RP{reservation policy version}`, e.g. `PP1.0-RP1.0`,
 * at most 20 characters because `momo_reservations.consent_version` is varchar(20). The TRD example
 * `PP-2026-11-v1|RP-2026-11-v1` (27 characters) cannot be stored and is not accepted; keys are
 * validated, never truncated.
 *
 * Current key (`current()`): the pin when it is set, valid, present in the registry and already
 * effective; otherwise the registry row with the latest `effective_from` ≤ now (JST); otherwise
 * `unversioned`. The Reservation module currently stores the pin as set by Head Office
 * (`Reservation\Support\Options::consent_version()`); switching it to `current()` is a kernel request
 * (it changes the documented "unversioned until Head Office selects a key" behaviour of M0005).
 * Earlier reservations keep their key (AC-PRV-01-02).
 */
final class ConsentRegistry {
	public const OPTION      = 'momo_consent_versions';
	public const PIN_OPTION  = 'momo_consent_version_current';
	public const MAX_LENGTH  = 20;
	public const PATTERN     = '/^PP\d+\.\d+-RP\d+\.\d+$/';
	public const UNVERSIONED = 'unversioned';

	public static function register(): void {
		add_filter( 'pre_update_option_' . self::OPTION, [ self::class, 'complete_rows' ], 10, 2 );
		add_filter( 'momos/fields/publish_errors/page', [ self::class, 'legal_page_errors' ], 20, 4 );
	}

	public static function is_valid_key( string $key ): bool {
		return strlen( $key ) <= self::MAX_LENGTH && 1 === preg_match( self::PATTERN, $key );
	}

	public static function key_for( string $privacy_version, string $reservation_policy_version ): string {
		return 'PP' . $privacy_version . '-RP' . $reservation_policy_version;
	}

	/**
	 * Registry rows, normalised, ordered by effective_from ascending. Invalid rows are skipped.
	 *
	 * @return list<array{consent_key:string,privacy_policy_version:string,reservation_policy_version:string,privacy_page_revision_id:int,reservation_policy_revision_id:int,effective_from:string,text_sha256:array<string,string>,created_by:int}>
	 */
	public static function rows(): array {
		$raw = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$key = trim( (string) ( $r['consent_key'] ?? '' ) );
			$eff = self::effective_utc( (string) ( $r['effective_from'] ?? '' ) );
			if ( ! self::is_valid_key( $key ) || null === $eff ) {
				continue;
			}
			$hashes = [];
			foreach ( (array) ( $r['text_sha256'] ?? [] ) as $lang => $hex ) {
				if ( Lang::is_valid( (string) $lang ) && is_string( $hex ) && preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
					$hashes[ (string) $lang ] = $hex;
				}
			}
			$out[] = [
				'consent_key'                    => $key,
				'privacy_policy_version'         => (string) ( $r['privacy_policy_version'] ?? '' ),
				'reservation_policy_version'     => (string) ( $r['reservation_policy_version'] ?? '' ),
				'privacy_page_revision_id'       => (int) ( $r['privacy_page_revision_id'] ?? 0 ),
				'reservation_policy_revision_id' => (int) ( $r['reservation_policy_revision_id'] ?? 0 ),
				'effective_from'                 => (string) $r['effective_from'],
				'text_sha256'                    => $hashes,
				'created_by'                     => (int) ( $r['created_by'] ?? 0 ),
			];
		}
		usort( $out, static fn( $a, $b ) => [ self::effective_utc( $a['effective_from'] ), $a['consent_key'] ] <=> [ self::effective_utc( $b['effective_from'] ), $b['consent_key'] ] );
		return $out;
	}

	/** `effective_from` (JST "Y-m-d" or "Y-m-d H:i[:s]") as a UTC instant, or null when invalid. */
	public static function effective_utc( string $value ): ?\DateTimeImmutable {
		$value = trim( $value );
		foreach ( [ '!Y-m-d', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i' ] as $fmt ) {
			$d = \DateTimeImmutable::createFromFormat( $fmt, $value, Clock::tz() );
			if ( $d && $d->format( substr( $fmt, 1 ) ) === $value ) {
				return $d->setTimezone( new \DateTimeZone( 'UTC' ) );
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null the registry row with $key */
	public static function find( string $key ): ?array {
		foreach ( self::rows() as $r ) {
			if ( $r['consent_key'] === $key ) {
				return $r;
			}
		}
		return null;
	}

	/** Row in force at $now: latest effective_from ≤ now. @return array<string,mixed>|null */
	public static function active_row( ?\DateTimeImmutable $now = null ): ?array {
		$now    = $now ?? Clock::utc();
		$active = null;
		foreach ( self::rows() as $r ) {
			if ( self::effective_utc( $r['effective_from'] ) <= $now ) {
				$active = $r;
			}
		}
		return $active;
	}

	/** Key stored on new reservations (≤ 20 characters). */
	public static function current( ?\DateTimeImmutable $now = null ): string {
		$now = $now ?? Clock::utc();
		$pin = trim( self::raw_pin() );
		if ( '' !== $pin && self::is_valid_key( $pin ) ) {
			$row = self::find( $pin );
			if ( null !== $row && self::effective_utc( $row['effective_from'] ) <= $now ) {
				return $pin;
			}
		}
		$active = self::active_row( $now );
		return null !== $active ? $active['consent_key'] : self::UNVERSIONED;
	}

	private static function raw_pin(): string {
		$v = get_option( self::PIN_OPTION, '' );
		return is_string( $v ) ? $v : '';
	}

	/**
	 * History for SCR-A19: registry rows with the number of reservations that store each key and
	 * whether the row is in force. Keys found on reservations but missing from the registry (e.g.
	 * `unversioned`) are listed too.
	 *
	 * @return list<array{consent_key:string,effective_from:string,privacy_policy_version:string,reservation_policy_version:string,reservations:int,active:bool,in_registry:bool}>
	 */
	public static function history(): array {
		global $wpdb;
		$t      = Tables::reservations();
		$counts = [];
		foreach ( (array) $wpdb->get_results( "SELECT consent_version AS k, COUNT(*) AS n FROM {$t} GROUP BY consent_version", ARRAY_A ) as $r ) { // phpcs:ignore
			$counts[ (string) $r['k'] ] = (int) $r['n'];
		}
		$current = self::current();
		$out     = [];
		foreach ( self::rows() as $r ) {
			$out[] = [
				'consent_key'                => $r['consent_key'],
				'effective_from'             => $r['effective_from'],
				'privacy_policy_version'     => $r['privacy_policy_version'],
				'reservation_policy_version' => $r['reservation_policy_version'],
				'reservations'               => $counts[ $r['consent_key'] ] ?? 0,
				'active'                     => $r['consent_key'] === $current,
				'in_registry'                => true,
			];
			unset( $counts[ $r['consent_key'] ] );
		}
		foreach ( $counts as $k => $n ) {
			$out[] = [ 'consent_key' => (string) $k, 'effective_from' => '', 'privacy_policy_version' => '', 'reservation_policy_version' => '', 'reservations' => $n, 'active' => false, 'in_registry' => false ];
		}
		return $out;
	}

	/**
	 * SHA-256 of the rendered consent label per enabled language (Access spec §10.2 `text_sha256`).
	 *
	 * @return array<string,string>
	 */
	public static function text_hashes(): array {
		$out = [];
		foreach ( Lang::enabled() as $lang ) {
			$out[ $lang ] = hash( 'sha256', momos_t( 'res.label.consent', [], $lang ) );
		}
		return $out;
	}

	/**
	 * Add a registry row (CLI / programmatic). Validates the documented rules and audits
	 * `settings.updated` without personal data.
	 *
	 * @return array<string,mixed> the stored row
	 * @throws \InvalidArgumentException with a translated message
	 */
	public static function add_version( string $privacy_version, string $reservation_policy_version, string $effective_from, int $privacy_revision = 0, int $policy_revision = 0 ): array {
		foreach ( [ $privacy_version, $reservation_policy_version ] as $v ) {
			if ( ! preg_match( '/^\d{1,3}\.\d{1,3}$/', $v ) ) {
				throw new \InvalidArgumentException( __( 'Policy versions must look like 1.0.', 'momos-core' ) );
			}
		}
		$key = self::key_for( $privacy_version, $reservation_policy_version );
		if ( ! self::is_valid_key( $key ) ) {
			throw new \InvalidArgumentException( __( 'The consent key must look like PP1.0-RP1.0 and have at most 20 characters.', 'momos-core' ) );
		}
		if ( null === self::effective_utc( $effective_from ) ) {
			throw new \InvalidArgumentException( __( 'Effective from must be a Japan-time date (YYYY-MM-DD) or date and time (YYYY-MM-DD HH:MM).', 'momos-core' ) );
		}
		$raw = get_option( self::OPTION, [] );
		$raw = is_array( $raw ) ? array_values( $raw ) : [];
		foreach ( $raw as $r ) {
			if ( is_array( $r ) && ( $r['consent_key'] ?? '' ) === $key ) {
				throw new \InvalidArgumentException( __( 'Each consent key can appear only once.', 'momos-core' ) );
			}
		}
		$row   = [
			'consent_key'                    => $key,
			'privacy_policy_version'         => $privacy_version,
			'reservation_policy_version'     => $reservation_policy_version,
			'privacy_page_revision_id'       => $privacy_revision > 0 ? $privacy_revision : '',
			'reservation_policy_revision_id' => $policy_revision > 0 ? $policy_revision : '',
			'effective_from'                 => $effective_from,
			'text_sha256'                    => self::text_hashes(),
			'created_by'                     => get_current_user_id(),
		];
		$raw[] = $row;
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $raw, '', false );
		} else {
			update_option( self::OPTION, $raw, false );
		}
		momos_audit( 'settings.updated', 'settings', null, null, [ 'page' => 'momo-settings', 'tab' => 'reservations', 'changes' => [ self::OPTION => [ 'from' => null, 'to' => 'added ' . $key ] ] ] );
		return $row;
	}

	/** Key the Reservation module stores on new rows right now (the pin, ≤ 20 characters). */
	public static function stored_key(): string {
		return class_exists( \Momos\Reservation\Support\Options::class ) ? \Momos\Reservation\Support\Options::consent_version() : self::current();
	}

	/**
	 * Pin a registry key as the current consent version (`momo_consent_version_current`), audited.
	 *
	 * @throws \InvalidArgumentException with a translated message
	 */
	public static function activate( string $key ): void {
		$row = self::is_valid_key( $key ) ? self::find( $key ) : null;
		if ( null === $row ) {
			throw new \InvalidArgumentException( __( 'Add the consent key to the registry first.', 'momos-core' ) );
		}
		if ( self::effective_utc( $row['effective_from'] ) > Clock::utc() ) {
			throw new \InvalidArgumentException( __( 'This consent version is not effective yet.', 'momos-core' ) );
		}
		$before = self::raw_pin();
		if ( $before === $key ) {
			return;
		}
		update_option( self::PIN_OPTION, $key, false );
		momos_audit( 'settings.updated', 'settings', null, null, [ 'page' => 'momo-settings', 'tab' => 'reservations', 'changes' => [ self::PIN_OPTION => [ 'from' => $before, 'to' => $key ] ] ] );
	}

	/**
	 * Saves through the settings screen drop fields it does not edit: keep `text_sha256` and
	 * `created_by` of existing keys, and fill them for new keys.
	 *
	 * @param mixed $new
	 * @param mixed $old
	 * @return mixed
	 */
	public static function complete_rows( $new, $old ) {
		if ( ! is_array( $new ) ) {
			return $new;
		}
		$prev = [];
		foreach ( is_array( $old ) ? $old : [] as $r ) {
			if ( is_array( $r ) && isset( $r['consent_key'] ) ) {
				$prev[ (string) $r['consent_key'] ] = $r;
			}
		}
		$hashes = null;
		foreach ( $new as $i => $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$k = (string) ( $r['consent_key'] ?? '' );
			if ( empty( $r['text_sha256'] ) ) {
				$r['text_sha256'] = $prev[ $k ]['text_sha256'] ?? ( $hashes ??= self::text_hashes() );
			}
			if ( empty( $r['created_by'] ) ) {
				$r['created_by'] = (int) ( $prev[ $k ]['created_by'] ?? get_current_user_id() );
			}
			$new[ $i ] = $r;
		}
		return $new;
	}

	/**
	 * TK-12-16: publishing the Privacy Policy or Reservation Policy page with a version label that
	 * has no registry row is blocked.
	 *
	 * @param list<string>        $errors
	 * @param array<string,mixed> $merged
	 * @param array<string,mixed> $data
	 * @return list<string>
	 */
	public static function legal_page_errors( array $errors, array $merged, array $data, int $post_id ): array {
		if ( $post_id <= 0 || ! class_exists( \Momos\Presentation\SystemPages::class ) ) {
			return $errors;
		}
		$field = null;
		if ( \Momos\Presentation\SystemPages::legal_id( 'privacy' ) === $post_id ) {
			$field = 'privacy_policy_version';
		} elseif ( \Momos\Presentation\SystemPages::legal_id( 'res_policy' ) === $post_id ) {
			$field = 'reservation_policy_version';
		}
		if ( null === $field ) {
			return $errors;
		}
		$version = trim( (string) ( $merged['momo_legal_version'] ?? get_post_meta( $post_id, 'momo_legal_version', true ) ) );
		if ( '' === $version ) {
			return $errors;
		}
		foreach ( self::rows() as $r ) {
			if ( $r[ $field ] === $version ) {
				return $errors;
			}
		}
		/* translators: %s: policy version label, e.g. 1.1 */
		$errors[] = sprintf( __( 'Version %s has no row in the consent registry (Settings → Reservations). Add the consent version first, then publish.', 'momos-core' ), $version );
		return $errors;
	}
}
