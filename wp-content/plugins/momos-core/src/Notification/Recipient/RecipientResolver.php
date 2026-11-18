<?php
declare(strict_types=1);

namespace Momos\Notification\Recipient;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Settings;
use Momos\Kernel\Support\Hash;

/**
 * Recipient resolution (DEC-049, GATE-03, spec §4.5). A recipient is
 * `array{type:'customer'|'branch'|'head_office'|'user', email:string, lang:string, user_id:?int}`.
 *
 * Branch recipients come only from the reservation's own branch (SEC-012, FR-RES-030 — a TEST
 * branch simply has its own QA list). HO lists come from the G3 settings. Staff language: active WP
 * user with that address → their admin locale; else the row's language; else ja (FR-I18N-010(d)).
 */
final class RecipientResolver {
	/** Keyed hash of a normalised address (DEC-059); kept until row deletion. */
	public static function hash( string $email ): string {
		return Hash::hmac( self::normalize( $email ), 'MOMOS_HASH_PEPPER' );
	}

	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	public static function mask( string $email ): string {
		return mb_substr( Hash::mask_email( self::normalize( $email ) ), 0, 100 );
	}

	/**
	 * The reservation's customer address (one To, PRV-009). Null when purged/absent.
	 *
	 * @param array<string,mixed> $reservation
	 * @return array{type:string,email:string,lang:string,user_id:?int}|null
	 */
	public static function customer( array $reservation ): ?array {
		$email = self::normalize( (string) ( $reservation['email'] ?? '' ) );
		if ( '' === $email || ! empty( $reservation['pii_purged_at'] ) ) {
			return null;
		}
		$lang = (string) ( $reservation['preferred_lang'] ?? 'ja' );
		return [ 'type' => 'customer', 'email' => $email, 'lang' => Lang::is_valid( $lang ) ? $lang : 'ja', 'user_id' => null ];
	}

	/**
	 * Active recipients of one branch (never another branch, never a global mailbox).
	 *
	 * @return list<array{type:string,email:string,lang:string,user_id:?int}>
	 */
	public static function branch( int $branch_id ): array {
		$rows = [];
		if ( class_exists( \Momos\Branch\Repository\BranchSettingsRepository::class ) ) {
			$rows = \Momos\Branch\Repository\BranchSettingsRepository::active_recipients( $branch_id );
		} else {
			$rows = self::active_rows( get_post_meta( $branch_id, 'momo_res_notification_recipients', true ) );
		}
		$out = [];
		foreach ( $rows as $r ) {
			$out[] = self::staff( 'branch', (string) $r['email'], (string) ( $r['language'] ?? 'ja' ) );
		}
		return self::unique( $out );
	}

	/**
	 * Head Office notification list (`momo_ho_escalation_recipients`, FR-FND-005(a), C4); with
	 * $digest also the extra digest list (`momo_digest_recipients`, E10 `head_office` only).
	 *
	 * @return list<array{type:string,email:string,lang:string,user_id:?int}>
	 */
	public static function head_office( bool $digest = false ): array {
		$out = [];
		foreach ( self::active_rows( self::setting( 'momo_ho_escalation_recipients' ) ) as $r ) {
			$out[] = self::staff( 'head_office', $r['email'], $r['language'] );
		}
		if ( $digest ) {
			foreach ( self::active_rows( self::setting( 'momo_digest_recipients' ) ) as $r ) {
				$out[] = self::staff( 'head_office', $r['email'], $r['language'] );
			}
		}
		return self::unique( $out );
	}

	/**
	 * E11 audience: HO list ∪ active CAP-DCA holders, one email per person.
	 *
	 * @return list<array{type:string,email:string,lang:string,user_id:?int}>
	 */
	public static function ho_and_dca(): array {
		$out = self::head_office();
		foreach ( get_users( [ 'capability' => 'momo_edit_dietary_claims', 'fields' => [ 'ID' ] ] ) as $u ) {
			$r = self::user( (int) $u->ID );
			if ( $r ) {
				$out[] = $r;
			}
		}
		return self::unique( $out );
	}

	/**
	 * A WP user (e.g. `handled_by`) as a recipient, when active.
	 *
	 * @return array{type:string,email:string,lang:string,user_id:?int}|null
	 */
	public static function user( int $user_id ): ?array {
		$u = get_userdata( $user_id );
		if ( ! $u || ! is_email( (string) $u->user_email ) || ! self::user_active( $user_id ) ) {
			return null;
		}
		return [ 'type' => 'user', 'email' => self::normalize( (string) $u->user_email ), 'lang' => Locale::admin( $user_id ), 'user_id' => $user_id ];
	}

	/**
	 * Staff recipient with the locale rule of FR-I18N-010(d).
	 *
	 * @return array{type:string,email:string,lang:string,user_id:?int}
	 */
	public static function staff( string $type, string $email, ?string $row_lang ): array {
		$email = self::normalize( $email );
		$user  = get_user_by( 'email', $email );
		if ( $user && self::user_active( (int) $user->ID ) ) {
			return [ 'type' => $type, 'email' => $email, 'lang' => Locale::admin( (int) $user->ID ), 'user_id' => (int) $user->ID ];
		}
		return [ 'type' => $type, 'email' => $email, 'lang' => in_array( $row_lang, Lang::ADMIN, true ) ? (string) $row_lang : 'ja', 'user_id' => null ];
	}

	public static function user_active( int $user_id ): bool {
		$expires = (string) get_user_meta( $user_id, 'momo_access_expires_at', true );
		if ( '' !== $expires && strtotime( $expires ) !== false && strtotime( $expires ) <= \Momos\Kernel\Clock\Clock::utc()->getTimestamp() ) {
			return false;
		}
		if ( '1' === (string) get_user_meta( $user_id, 'momo_account_disabled', true ) ) {
			return false;
		}
		return (bool) apply_filters( 'momos/access/user_active', true, $user_id );
	}

	/**
	 * Extension point `momos/notification/recipients` (spec §2/§4.5). The filter may only REMOVE
	 * recipients: anything it adds that was not resolved from the documented sources is dropped
	 * (never personal mailboxes, PRV-010).
	 *
	 * @param list<array{type:string,email:string,lang:string,user_id:?int}> $recipients
	 * @param array<string,mixed>|null                                       $reservation
	 * @return list<array{type:string,email:string,lang:string,user_id:?int}>
	 */
	public static function filter( array $recipients, string $template, ?array $reservation ): array {
		$allowed  = [];
		foreach ( $recipients as $r ) {
			$allowed[ $r['email'] ] = $r;
		}
		$filtered = apply_filters( 'momos/notification/recipients', $recipients, $template, $reservation );
		$out      = [];
		foreach ( is_array( $filtered ) ? $filtered : [] as $r ) {
			$email = is_array( $r ) ? self::normalize( (string) ( $r['email'] ?? '' ) ) : '';
			if ( isset( $allowed[ $email ] ) ) {
				$out[] = $allowed[ $email ];
			}
		}
		if ( ! $out && $recipients && 'customer' !== ( $recipients[0]['type'] ?? '' ) ) {
			Logger::info( 'notification.recipients_filtered_out', [ 'template' => $template ] );
		}
		return $out;
	}

	/**
	 * Active rows of a recipient list (option or meta JSON/array).
	 *
	 * @return list<array{email:string,language:string}>
	 */
	public static function active_rows( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$email = self::normalize( (string) ( $row['email'] ?? '' ) );
			if ( '' === $email || ! is_email( $email ) || empty( $row['active'] ) || '0' === (string) $row['active'] ) {
				continue;
			}
			$out[] = [ 'email' => $email, 'language' => 'en' === ( $row['language'] ?? 'ja' ) ? 'en' : 'ja' ];
		}
		return $out;
	}

	/** Setting value from the G3 options page, or a plain option of the same name. */
	public static function setting( string $name ): mixed {
		if ( null !== FieldRegistry::option_page_of( $name ) ) {
			return Settings::get( $name, [] );
		}
		return get_option( $name, [] );
	}

	/**
	 * @param list<array{type:string,email:string,lang:string,user_id:?int}> $list
	 * @return list<array{type:string,email:string,lang:string,user_id:?int}>
	 */
	private static function unique( array $list ): array {
		$out = [];
		foreach ( $list as $r ) {
			if ( ! isset( $out[ $r['email'] ] ) ) {
				$out[ $r['email'] ] = $r;
			}
		}
		return array_values( $out );
	}
}
