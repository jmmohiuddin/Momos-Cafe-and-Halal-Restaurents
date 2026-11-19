<?php
declare(strict_types=1);

namespace Momos\Audit;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Env;
use Momos\Kernel\Http\RequestContext;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Support\Hash;
use Momos\Kernel\Support\Json;

/**
 * Append-only audit writer (FR-ADM-004, SEC-080…082, Access spec §6.1/§6.2).
 *
 * Entry points: `momos_audit()` → action `momos/audit/log`, the field layer (`momos/fields/saved`,
 * `momos/fields/denied`), `momos/access/denied` and `momos/i18n/translation_saved`.
 *
 * - `changes` is masked at write time: PII keys become "[masked]" in both before and after values
 *   (SEC-081); remaining strings are scrubbed for email/phone patterns; JSON ≤ 64 KB.
 * - `ip_truncated` = IPv4 /24, IPv6 /48 (DEC-059); `request_id` from the request context.
 * - `row_hmac` = keyed HMAC-SHA256 chain: HMAC(pepper, "audit|" + previous row_hmac + "|" + canonical
 *   row). Editing or deleting a row breaks the chain (`wp momos audit verify`).
 * - Inside a business transaction a failed insert throws (no unaudited decision); outside it is
 *   logged and the request continues (§6.1.5).
 */
final class AuditLogger {
	public const MASK = '[masked]';

	public const MAX_CHANGES_BYTES = 65536;

	/** SEC-081 masking deny-list (exact keys, case-insensitive). */
	public const PII_KEYS = [
		'guest_name', 'name', 'email', 'phone', 'phone_e164', 'special_request', 'note', 'message_to_customer',
		'recipient_email', 'user_email', 'momo_phone', 'user_login', 'user_pass', 'password', 'pass1', 'pass2',
		'token', 'token_hash', 'secret', 'recovery_code', 'totp_code', 'ip', 'ip_address', 'user_agent', 'first_name',
		'last_name', 'nickname', 'display_name',
	];

	/** Alert threshold for access denials per user and hour (SEC-013 ⟦10⟧). */
	public const DENIAL_ALERT_PER_HOUR = 10;

	/** @var array<string,bool> object keys with an access.denied row in this request */
	private static array $denied_seen = [];

	public static function register(): void {
		add_action( 'momos/audit/log', [ self::class, 'on_log' ], 10, 6 );
		add_action( 'momos/fields/saved', [ self::class, 'on_fields_saved' ], 50, 5 );
		add_action( 'momos/fields/denied', [ self::class, 'on_fields_denied' ], 99, 5 );
		add_action( 'momos/access/denied', [ self::class, 'on_access_denied' ], 99, 1 );
		add_action( 'momos/i18n/translation_saved', [ self::class, 'on_translation_saved' ], 10, 7 );
	}

	/**
	 * @param array<string,mixed> $changes
	 */
	public static function on_log( string $action, string $object_type, ?int $object_id = null, ?int $branch_id = null, array $changes = [], string $actor_type = 'staff' ): void {
		self::record( $action, $object_type, $object_id, $branch_id, $changes, $actor_type );
	}

	/**
	 * Record an entry for the current actor. Returns the row id, or null when dropped/de-duplicated.
	 *
	 * @param array<string,mixed> $changes
	 */
	public static function record( string $action, string $object_type, ?int $object_id = null, ?int $branch_id = null, array $changes = [], string $actor_type = 'staff' ): ?int {
		$user_id = get_current_user_id();
		if ( 'staff' === $actor_type && ! $user_id ) {
			$actor_type = 'system';
		}
		$actor_id = 'staff' === $actor_type ? $user_id : null;
		return self::write( $action, $object_type, $object_id, $branch_id, $changes, $actor_type, $actor_id );
	}

	/**
	 * Record an entry for an explicit actor (login events before a session exists).
	 *
	 * @param array<string,mixed> $changes
	 */
	public static function record_for( ?int $actor_id, string $action, string $object_type, ?int $object_id = null, ?int $branch_id = null, array $changes = [] ): ?int {
		return self::write( $action, $object_type, $object_id, $branch_id, $changes, $actor_id ? 'staff' : 'system', $actor_id ?: null );
	}

	/**
	 * @param array<string,mixed> $changes
	 */
	private static function write( string $action, string $object_type, ?int $object_id, ?int $branch_id, array $changes, string $actor_type, ?int $actor_id ): ?int {
		global $wpdb;
		$action = Catalogue::normalize( $action );
		if ( ! Catalogue::is_known( $action ) ) {
			Logger::error( 'audit.unknown_action', [ 'action' => $action ] );
			if ( defined( 'MOMOS_AUDIT_STRICT' ) && MOMOS_AUDIT_STRICT ) {
				throw new \InvalidArgumentException( "Unknown audit action {$action}" );
			}
			if ( Env::is_production() ) {
				return null; // Dropped (§6.1.1); developers see the ERROR line in other environments.
			}
		}
		if ( ! in_array( $actor_type, [ 'staff', 'system', 'customer' ], true ) ) {
			$actor_type = 'system';
		}
		$dedupe_key = 'access.denied' === $action ? self::object_key( $object_type, $object_id ) : '';
		if ( 'reservation.detail_viewed' === $action && $actor_id && $object_id ) {
			// De-duplicated per user per reservation per 30 min (SDD §2.4.7).
			$tkey = 'momos_aud_dv_' . $actor_id . '_' . $object_id;
			if ( get_transient( $tkey ) ) {
				return null;
			}
			set_transient( $tkey, 1, 30 * MINUTE_IN_SECONDS );
		}

		$user            = $actor_id ? get_userdata( $actor_id ) : null;
		$role            = $user && $user->roles ? (string) reset( $user->roles ) : null;
		$row             = [
			'occurred_at'  => Clock::mysql_utc(),
			'actor_type'   => $actor_type,
			'actor_id'     => $actor_id,
			'actor_role'   => null !== $role ? substr( $role, 0, 40 ) : null,
			'action'       => substr( $action, 0, 60 ),
			'object_type'  => substr( sanitize_key( $object_type ) ?: 'unknown', 0, 30 ),
			'object_id'    => $object_id ?: null,
			'branch_id'    => $branch_id ?: null,
			'changes'      => self::encode_changes( $changes ),
			'ip_truncated' => self::client_ip_truncated(),
			'request_id'   => substr( RequestContext::id(), 0, 36 ),
		];
		$prev            = (string) $wpdb->get_var( 'SELECT row_hmac FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1' ); // phpcs:ignore
		$row['row_hmac'] = self::hmac( $prev, $row );

		$ok = $wpdb->insert( self::table(), $row );
		if ( false === $ok ) {
			Logger::error( 'audit.write_failed', [ 'action' => $action, 'object_type' => $object_type ] );
			if ( Transaction::active() ) {
				throw new \RuntimeException( 'Audit write failed' );
			}
			return null;
		}
		$id = (int) $wpdb->insert_id;
		if ( '' !== $dedupe_key ) {
			self::$denied_seen[ $dedupe_key ] = true;
			self::count_denial( $actor_id );
		}
		/**
		 * Fired after an audit row was written (monitoring/alert listeners).
		 *
		 * @param int                 $id
		 * @param array<string,mixed> $row
		 */
		do_action( 'momos/audit/recorded', $id, $row );
		return $id;
	}

	// ----------------------------------------------------------------- listeners

	/**
	 * Option pages → settings.updated / brand.updated with masked before/after (SCR-A15 "every save
	 * audited"). Post/term saves are audited by the owning module; a module may opt a post type in
	 * with the filter `momos/audit/field_save_action` (return the action name).
	 *
	 * @param array<string, array{0:mixed,1:mixed}> $changes
	 */
	public static function on_fields_saved( string $type, int|string $object_id, string $group_key, array $changes, int $user_id ): void {
		if ( ! $changes ) {
			return;
		}
		$diff = [];
		foreach ( $changes as $name => $pair ) {
			$diff[ (string) $name ] = [ 'from' => $pair[0] ?? null, 'to' => $pair[1] ?? null ];
		}
		if ( 'option' === $type ) {
			$action = 'momo-brand' === (string) $object_id ? 'brand.updated' : 'settings.updated';
			self::record( $action, 'settings', null, null, [ 'page' => (string) $object_id, 'group' => $group_key, 'changes' => $diff ] );
			return;
		}
		$post_type = 'post' === $type ? (string) get_post_type( (int) $object_id ) : '';
		$action    = apply_filters( 'momos/audit/field_save_action', null, $type, $post_type, $group_key );
		if ( is_string( $action ) && '' !== $action ) {
			self::record( $action, '' !== $post_type ? $post_type : $type, (int) $object_id, null, [ 'group' => $group_key, 'changes' => $diff ] );
		}
	}

	/**
	 * Rejected field changes (E4). Modules that audit their own denials run first; this listener only
	 * writes a row when no `access.denied` row exists yet for the object in this request.
	 *
	 * @param list<string> $denied
	 */
	public static function on_fields_denied( string $type, int|string $object_id, string $group_key, array $denied, int $user_id ): void {
		$object_type = 'post' === $type ? (string) get_post_type( (int) $object_id ) : ( 'option' === $type ? 'settings' : $type );
		$oid         = is_numeric( $object_id ) ? (int) $object_id : null;
		if ( self::already_denied( $object_type, $oid ) ) {
			return;
		}
		self::record( 'access.denied', $object_type ?: $type, $oid, null, [ 'channel' => 'field', 'group' => $group_key, 'fields' => array_values( array_map( 'strval', $denied ) ) ] );
	}

	/**
	 * Denials announced by modules. Modules normally call momos_audit('access.denied') themselves;
	 * the row is written here only when that did not happen in this request.
	 *
	 * @param array<string,mixed> $denial
	 */
	public static function on_access_denied( $denial ): void {
		$denial      = is_array( $denial ) ? $denial : [];
		$object_type = (string) ( $denial['object_type'] ?? 'unknown' );
		$object_id   = isset( $denial['object_id'] ) ? (int) $denial['object_id'] : null;
		if ( self::already_denied( $object_type, $object_id ) ) {
			return;
		}
		$changes = array_intersect_key( $denial, array_flip( [ 'capability', 'cap', 'channel', 'route', 'screen', 'reason', 'action', 'field', 'aspect' ] ) );
		self::record( 'access.denied', $object_type, $object_id, isset( $denial['branch_id'] ) ? (int) $denial['branch_id'] : null, $changes );
	}

	/** @param list<string> $fields */
	public static function on_translation_saved( string $type, int $id, string $lang, string $old_status, string $status, array $fields = [], int $user_id = 0 ): void {
		if ( $old_status === $status && 'approved' !== $status && ! $fields ) {
			return;
		}
		$action      = 'approved' === $status && 'approved' !== $old_status ? 'translation.approved'
			: ( 'draft' === $status && in_array( $old_status, [ 'needs_review', 'approved' ], true ) ? 'translation.returned' : 'translation.updated' );
		$object_type = 'term' === $type ? 'term' : (string) get_post_type( $id );
		self::record( $action, $object_type ?: $type, $id, null, [ 'lang' => $lang, 'status' => [ 'from' => $old_status, 'to' => $status ], 'fields' => array_values( $fields ) ] );
	}

	// ----------------------------------------------------------------- helpers

	public static function table(): string {
		return \Momos\Kernel\Db\Tables::audit();
	}

	/** @param array<string,mixed> $changes */
	public static function encode_changes( array $changes ): ?string {
		if ( ! $changes ) {
			return null;
		}
		$json = Json::encode( self::mask( $changes ) );
		if ( strlen( $json ) > self::MAX_CHANGES_BYTES ) {
			$json = Json::encode( [ '_truncated' => true, 'keys' => array_slice( array_map( 'strval', array_keys( $changes ) ), 0, 50 ) ] );
		}
		return $json;
	}

	/**
	 * Mask PII keys (both from/to values) and scrub email/phone patterns from remaining strings.
	 *
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	public static function mask( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::PII_KEYS, true ) ) {
				$data[ $key ] = self::mask_value( $value );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::mask( $value );
			} elseif ( is_string( $value ) ) {
				$data[ $key ] = self::scrub( $value );
			}
		}
		return $data;
	}

	/** Keep the change structure ({from,to}) but replace values. */
	private static function mask_value( mixed $value ): mixed {
		if ( is_array( $value ) && ( array_key_exists( 'from', $value ) || array_key_exists( 'to', $value ) ) ) {
			return [ 'from' => self::MASK, 'to' => self::MASK ];
		}
		return self::MASK;
	}

	private static function scrub( string $value ): string {
		$value = (string) preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $value );
		// Phone numbers (E.164 / Japanese formats); dates and times are left intact.
		$value = (string) preg_replace( [ '/\+\d{9,15}\b/', '/\b0\d{1,4}-\d{1,4}-\d{4}\b/', '/\b0\d{9,10}\b/' ], '[phone]', $value );
		// Opaque tokens (43-char base64url, status tokens) never reach the audit log.
		return (string) preg_replace( '/\b[A-Za-z0-9_-]{43}\b/', self::MASK, $value );
	}

	public static function client_ip_truncated(): ?string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return null;
		}
		return Hash::truncate_ip( RequestContext::ip() );
	}

	/** Canonical representation of a row for the HMAC (column order fixed; ids as int|null). @param array<string,mixed> $row */
	public static function canonical( array $row ): string {
		$int = static fn( $v ) => ( null === $v || '' === $v ) ? null : (int) $v;
		$str = static fn( $v ) => null === $v ? null : (string) $v;
		return (string) wp_json_encode(
			[
				$str( $row['occurred_at'] ?? null ),
				$str( $row['actor_type'] ?? null ),
				$int( $row['actor_id'] ?? null ),
				$str( $row['actor_role'] ?? null ),
				$str( $row['action'] ?? null ),
				$str( $row['object_type'] ?? null ),
				$int( $row['object_id'] ?? null ),
				$int( $row['branch_id'] ?? null ),
				$str( $row['changes'] ?? null ),
				$str( $row['ip_truncated'] ?? null ),
				$str( $row['request_id'] ?? null ),
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}

	/** @param array<string,mixed> $row */
	public static function hmac( string $prev, array $row ): string {
		return hash_hmac( 'sha256', 'audit|' . $prev . '|' . self::canonical( $row ), self::key() );
	}

	/**
	 * Pepper: MOMOS_HASH_PEPPER (docs) or the kernel's MOMOS_HMAC_KEY (alias, spec §17.16), resolved by the kernel.
	 * Fails closed (QA-075, DEC-059): a production install without a pepper throws instead of writing an unkeyed,
	 * forgeable chain.
	 *
	 * @throws \RuntimeException when the pepper is not configured.
	 */
	public static function key(): string {
		return Hash::key();
	}

	private static function object_key( string $object_type, ?int $object_id ): string {
		return null !== $object_id && $object_id > 0 ? 'id:' . $object_id : 'type:' . sanitize_key( $object_type );
	}

	private static function already_denied( string $object_type, ?int $object_id ): bool {
		return isset( self::$denied_seen[ self::object_key( $object_type, $object_id ) ] );
	}

	/** Reset per-request state (tests). */
	public static function reset_request_state(): void {
		self::$denied_seen = [];
	}

	/** SEC-013: alert once per hour when one user produces more than ⟦10⟧ denials in an hour. */
	private static function count_denial( ?int $actor_id ): void {
		if ( ! $actor_id ) {
			return;
		}
		$hour = Clock::utc()->format( 'YmdH' );
		$key  = 'momos_denials_' . $actor_id . '_' . $hour;
		$n    = (int) get_transient( $key ) + 1;
		set_transient( $key, $n, 2 * HOUR_IN_SECONDS );
		if ( self::DENIAL_ALERT_PER_HOUR + 1 === $n ) {
			Logger::warning( 'security.denials_threshold', [ 'user_id' => $actor_id, 'count' => $n ] );
			do_action( 'momos/monitoring/alert', 'access_denials', [ 'user_id' => $actor_id, 'count' => $n ] );
		}
	}
}
