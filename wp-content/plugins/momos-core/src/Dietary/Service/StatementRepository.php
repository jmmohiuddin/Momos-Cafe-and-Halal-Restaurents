<?php
declare(strict_types=1);

namespace Momos\Dietary\Service;

use Momos\Dietary\Guard\WordingGuard;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\I18n\Lang;
use Momos\Menu\Support\CachePurge;

/**
 * Approved halal/alcohol statement templates (G2, option `momos_dietary_statements`; FR-DIET-003,
 * BR-DIET-05, Doc 11 §8.1/§8.7). Rows: {key, claim_type, text_ja, approved, approved_by, approved_at,
 * i18n: {lang: {text, status, updated_by, updated_at}}}. A statement renders in a language only when the
 * row is approved and (for lang ≠ ja) its human translation is approved. Only CAP-DCA holders change
 * rows; translators edit translations of rows; every change is audited `dietary.statement_updated`.
 */
final class StatementRepository {
	public const OPTION        = 'momos_dietary_statements';
	public const CROSS_CONTACT = 'momo_allergen_cross_contact_confirmed';
	public const TYPES         = [ 'certified', 'halal_ingredients', 'no_claim', 'alcohol', 'neutral' ];

	/** @return array<string,array<string,mixed>> key => row */
	public static function all(): array {
		$raw = get_option( self::OPTION, [] );
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) ) {
				$out[ (string) $row['key'] ] = self::normalize( $row );
			}
		}
		return $out;
	}

	/** @return array<string,mixed>|null */
	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/** Approved text in a language, or null (never machine-filled, never mixed). */
	public static function text( string $key, string $lang ): ?string {
		$row = self::get( $key );
		if ( null === $row || ! $row['approved'] ) {
			return null;
		}
		if ( Lang::SOURCE === $lang ) {
			return '' !== $row['text_ja'] ? $row['text_ja'] : null;
		}
		$tr = $row['i18n'][ $lang ] ?? null;
		return ( is_array( $tr ) && 'approved' === ( $tr['status'] ?? '' ) && '' !== trim( (string) ( $tr['text'] ?? '' ) ) ) ? (string) $tr['text'] : null;
	}

	/** Approved row with an approved translation in every enabled language (G7 statement selection). */
	public static function is_ready_for_all_enabled( string $key ): bool {
		foreach ( Lang::enabled() as $lang ) {
			if ( null === self::text( $key, $lang ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,string> key => label (approved rows of the given types) */
	public static function choices( array $types, bool $approved_only = true ): array {
		$out = [];
		foreach ( self::all() as $key => $row ) {
			if ( in_array( $row['claim_type'], $types, true ) && ( ! $approved_only || $row['approved'] ) ) {
				$out[ $key ] = $key;
			}
		}
		return $out;
	}

	/**
	 * Create or update a row (CAP-DCA). Changing text_ja resets approved translations to needs_review.
	 *
	 * @param array{key:string,claim_type:string,text_ja:string,approved:bool,en?:string} $input
	 * @return list<string> errors (empty on success)
	 */
	public static function save_row( array $input, int $user_id, ?string $original_key = null ): array {
		if ( ! user_can( $user_id, 'momo_edit_dietary_claims' ) ) {
			momos_audit( 'access.denied', 'dietary_statement', null, null, [ 'reason' => 'cap_dca_required', 'key' => (string) ( $input['key'] ?? '' ) ] );
			return [ __( 'Only Dietary Claims Approvers can change these fields', 'momos-core' ) ];
		}
		$key  = strtolower( trim( (string) ( $input['key'] ?? '' ) ) );
		$type = (string) ( $input['claim_type'] ?? '' );
		$text = trim( sanitize_textarea_field( (string) ( $input['text_ja'] ?? '' ) ) );
		$all  = self::all();
		$errs = [];
		if ( ! preg_match( '/^[a-z0-9_.]{3,40}$/', $key ) ) {
			$errs[] = __( 'The key must be 3–40 characters: a–z, 0–9, underscore or dot.', 'momos-core' );
		} elseif ( isset( $all[ $key ] ) && $key !== $original_key ) {
			$errs[] = __( 'This key already exists.', 'momos-core' );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$errs[] = __( 'Choose a statement type.', 'momos-core' );
		}
		if ( '' === $text || mb_strlen( $text ) > 400 ) {
			$errs[] = __( 'The Japanese text is required (max. 400 characters).', 'momos-core' );
		}
		foreach ( WordingGuard::statement_violations( $text, $type, 'ja' ) as $v ) {
			/* translators: %s: matched term */
			$errs[] = sprintf( __( 'Prohibited wording: “%s”.', 'momos-core' ), $v['term'] );
		}
		if ( $errs ) {
			return $errs;
		}
		$before = null !== $original_key ? ( $all[ $original_key ] ?? null ) : ( $all[ $key ] ?? null );
		$row    = $before ?? self::normalize( [ 'key' => $key ] );
		$row['key']        = $key;
		$row['claim_type'] = $type;
		if ( $row['text_ja'] !== $text ) {
			foreach ( $row['i18n'] as $lang => $tr ) {
				if ( 'approved' === ( $tr['status'] ?? '' ) ) {
					$row['i18n'][ $lang ]['status'] = 'needs_review'; // Keep no stale approved translation.
				}
			}
		}
		$row['text_ja'] = $text;
		$approve        = ! empty( $input['approved'] );
		if ( $approve && ( ! $row['approved'] || null === $before || $before['text_ja'] !== $text ) ) {
			$row['approved_by'] = $user_id;
			$row['approved_at'] = Clock::mysql_utc();
		}
		$row['approved'] = $approve;
		if ( ! $approve ) {
			$row['approved_by'] = null;
			$row['approved_at'] = null;
		}
		if ( null !== $original_key && $original_key !== $key ) {
			unset( $all[ $original_key ] );
		}
		$all[ $key ] = $row;
		self::persist( $all );
		momos_audit( 'dietary.statement_updated', 'dietary_statement', null, null, [ 'key' => $key, 'before' => $before ? self::audit_view( $before ) : null, 'after' => self::audit_view( $row ) ] );
		self::changed( $key );
		return [];
	}

	/**
	 * Save a human translation of a row (translators: draft/needs_review; approvers may approve).
	 *
	 * @return list<string> errors
	 */
	public static function save_translation( string $key, string $lang, string $text, string $status, int $user_id ): array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) || Lang::SOURCE === $lang || ! Lang::is_valid( $lang ) || ! in_array( $status, [ 'draft', 'needs_review', 'approved' ], true ) ) {
			return [ __( 'Invalid translation target.', 'momos-core' ) ];
		}
		if ( ! user_can( $user_id, 'momo_translate' ) && ! user_can( $user_id, 'momo_edit_dietary_claims' ) ) {
			momos_audit( 'access.denied', 'dietary_statement', null, null, [ 'reason' => 'translate_denied', 'key' => $key, 'lang' => $lang ] );
			return [ __( 'You do not have permission to translate statements.', 'momos-core' ) ];
		}
		if ( 'approved' === $status && ! user_can( $user_id, 'momo_approve_translations' ) && ! user_can( $user_id, 'momo_edit_dietary_claims' ) ) {
			$status = 'needs_review';
		}
		$text = trim( sanitize_textarea_field( $text ) );
		if ( mb_strlen( $text ) > 600 ) {
			return [ __( 'The translation is too long.', 'momos-core' ) ];
		}
		foreach ( WordingGuard::statement_violations( $text, $all[ $key ]['claim_type'], $lang ) as $v ) {
			/* translators: %s: matched term */
			return [ sprintf( __( 'Prohibited wording: “%s”.', 'momos-core' ), $v['term'] ) ];
		}
		$before                     = $all[ $key ]['i18n'][ $lang ] ?? null;
		$all[ $key ]['i18n'][ $lang ] = [ 'text' => $text, 'status' => '' === $text ? 'missing' : $status, 'updated_by' => $user_id, 'updated_at' => Clock::mysql_utc() ];
		self::persist( $all );
		momos_audit( 'dietary.statement_updated', 'dietary_statement', null, null, [ 'key' => $key, 'lang' => $lang, 'before' => $before ? [ 'status' => $before['status'] ?? null, 'text' => $before['text'] ?? '' ] : null, 'after' => [ 'status' => $all[ $key ]['i18n'][ $lang ]['status'], 'text' => $text ] ] );
		self::changed( $key );
		return [];
	}

	/** @return array{confirmed:bool,confirmed_by:?int,confirmed_at:?string} */
	public static function cross_contact(): array {
		$v = get_option( self::CROSS_CONTACT, [] );
		$v = is_array( $v ) ? $v : [];
		return [ 'confirmed' => ! empty( $v['confirmed'] ), 'confirmed_by' => isset( $v['confirmed_by'] ) ? (int) $v['confirmed_by'] : null, 'confirmed_at' => $v['confirmed_at'] ?? null ];
	}

	public static function set_cross_contact( bool $confirmed, int $user_id ): bool {
		if ( ! user_can( $user_id, 'momo_edit_dietary_claims' ) ) {
			momos_audit( 'access.denied', 'dietary_statement', null, null, [ 'reason' => 'cap_dca_required', 'key' => self::CROSS_CONTACT ] );
			return false;
		}
		$before = self::cross_contact();
		if ( $before['confirmed'] === $confirmed ) {
			return true;
		}
		update_option( self::CROSS_CONTACT, [ 'confirmed' => $confirmed, 'confirmed_by' => $user_id, 'confirmed_at' => Clock::mysql_utc() ], false );
		momos_audit( 'dietary.statement_updated', 'dietary_statement', null, null, [ 'key' => self::CROSS_CONTACT, 'before' => $before['confirmed'], 'after' => $confirmed ] );
		CachePurge::tags( [ 'momo-halal', 'momo-home' ] );
		AfterCommit::dispatch( 'momos/dietary/claims_changed', 'cross_contact', 0 );
		return true;
	}

	/** Seed rows that do not exist yet (migration). @param list<array<string,mixed>> $rows */
	public static function seed( array $rows ): void {
		$all = self::all();
		foreach ( $rows as $r ) {
			if ( ! isset( $all[ $r['key'] ] ) ) {
				$all[ $r['key'] ] = self::normalize( $r );
			}
		}
		self::persist( $all );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function normalize( array $row ): array {
		$i18n = [];
		foreach ( (array) ( $row['i18n'] ?? [] ) as $lang => $tr ) {
			if ( Lang::is_valid( (string) $lang ) && is_array( $tr ) ) {
				$i18n[ (string) $lang ] = [
					'text'       => (string) ( $tr['text'] ?? '' ),
					'status'     => (string) ( $tr['status'] ?? 'missing' ),
					'updated_by' => isset( $tr['updated_by'] ) ? (int) $tr['updated_by'] : null,
					'updated_at' => $tr['updated_at'] ?? null,
				];
			}
		}
		return [
			'key'         => (string) ( $row['key'] ?? '' ),
			'claim_type'  => (string) ( $row['claim_type'] ?? 'neutral' ),
			'text_ja'     => (string) ( $row['text_ja'] ?? '' ),
			'approved'    => ! empty( $row['approved'] ),
			'approved_by' => isset( $row['approved_by'] ) ? (int) $row['approved_by'] : null,
			'approved_at' => $row['approved_at'] ?? null,
			'i18n'        => $i18n,
		];
	}

	/** @param array<string,array<string,mixed>> $all */
	private static function persist( array $all ): void {
		ksort( $all );
		update_option( self::OPTION, array_values( $all ), true );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function audit_view( array $row ): array {
		return [ 'claim_type' => $row['claim_type'], 'text_ja' => $row['text_ja'], 'approved' => $row['approved'] ];
	}

	private static function changed( string $key ): void {
		CachePurge::claims();
		AfterCommit::dispatch( 'momos/dietary/claims_changed', 'statement', 0 );
	}
}
