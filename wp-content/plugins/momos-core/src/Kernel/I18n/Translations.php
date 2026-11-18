<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

use Momos\Kernel\Clock\Clock;

/**
 * Per-entity translations (IMPL-002).
 *
 * Source (Japanese) values live in native fields: post_title, post_content, post_excerpt, term
 * name/description, or regular meta. Each target language is stored as meta
 * `_momos_i18n_{lang}` = {field: value} with status `_momos_i18n_status_{lang}`:
 *   missing | draft | needs_review | approved
 * Only `approved` translations are shown publicly (DEC-027: no mixed-language fallback).
 * `_momos_i18n_srchash_{lang}` records the source hash at approval to flag outdated translations.
 */
final class Translations {
	public const STATUSES = [ 'missing', 'draft', 'needs_review', 'approved' ];

	/**
	 * Registered translatable fields per object kind.
	 * kind = "post:{post_type}" | "term:{taxonomy}" | "option:{page}".
	 * field def: [label, type (text|textarea|html), source (post_title|post_content|post_excerpt|name|description|meta:{key}|option:{key})]
	 *
	 * @var array<string, array<string, array{label:string,type:string,source:string,max?:int,lccs?:bool}>>
	 */
	private static array $fields = [];

	/** @param array<string, array{label:string,type:string,source:string,max?:int,lccs?:bool}> $fields */
	public static function register( string $kind, array $fields ): void {
		self::$fields[ $kind ] = array_merge( self::$fields[ $kind ] ?? [], $fields );
	}

	/** @return array<string, array{label:string,type:string,source:string,max?:int,lccs?:bool}> */
	public static function fields( string $kind ): array {
		return self::$fields[ $kind ] ?? [];
	}

	/** @return list<string> */
	public static function kinds(): array {
		return array_keys( self::$fields );
	}

	public static function kind_of_post( int|\WP_Post $post ): string {
		$post = get_post( $post );
		return $post ? 'post:' . $post->post_type : '';
	}

	// ---------------------------------------------------------------- reading

	/**
	 * Value of a translatable post field in a language. Returns '' when no approved translation
	 * exists (caller decides whether to hide the item). $any_status=true is for admin previews.
	 */
	public static function post( int|\WP_Post $post, string $field, ?string $lang = null, bool $any_status = false ): string {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$lang = $lang ?? Locale::current();
		if ( Lang::SOURCE === $lang ) {
			return self::source_post_value( $post, $field );
		}
		if ( ! $any_status && 'approved' !== self::status( 'post', $post->ID, $lang ) ) {
			return '';
		}
		$data = self::data( 'post', $post->ID, $lang );
		return isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
	}

	public static function term( int|\WP_Term $term, string $field, ?string $lang = null, bool $any_status = false ): string {
		$term = $term instanceof \WP_Term ? $term : get_term( $term );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$lang = $lang ?? Locale::current();
		if ( Lang::SOURCE === $lang ) {
			return self::source_term_value( $term, $field );
		}
		if ( ! $any_status && 'approved' !== self::status( 'term', $term->term_id, $lang ) ) {
			return '';
		}
		$data = self::data( 'term', $term->term_id, $lang );
		return isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
	}

	/** Translated option text (brand settings, statement templates). */
	public static function option( string $page, string $field, ?string $lang = null ): string {
		$lang = $lang ?? Locale::current();
		if ( Lang::SOURCE === $lang ) {
			return (string) \Momos\Kernel\Settings::get( $field );
		}
		$all = (array) get_option( 'momos_option_i18n', [] );
		$row = $all[ $page ][ $lang ] ?? [];
		if ( 'approved' !== ( $row['_status'] ?? 'missing' ) ) {
			return '';
		}
		return (string) ( $row[ $field ] ?? '' );
	}

	/** Is the post publicly visible in the language (published + approved translation + language enabled)? */
	public static function post_visible( int|\WP_Post $post, ?string $lang = null ): bool {
		$post = get_post( $post );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		$lang = $lang ?? Locale::current();
		if ( Lang::SOURCE === $lang ) {
			return true;
		}
		return Lang::is_enabled( $lang ) && 'approved' === self::status( 'post', $post->ID, $lang );
	}

	public static function term_visible( int|\WP_Term $term, ?string $lang = null ): bool {
		$term = $term instanceof \WP_Term ? $term : get_term( $term );
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}
		$lang = $lang ?? Locale::current();
		return Lang::SOURCE === $lang || 'approved' === self::status( 'term', $term->term_id, $lang );
	}

	/**
	 * meta_query clause restricting WP_Query to items approved in the language.
	 *
	 * @return array<int|string, mixed>
	 */
	public static function meta_query( ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		if ( Lang::SOURCE === $lang ) {
			return [];
		}
		return [ [ 'key' => '_momos_i18n_status_' . $lang, 'value' => 'approved' ] ];
	}

	/** @return array<string,string> */
	public static function data( string $type, int $id, string $lang ): array {
		$raw = 'term' === $type ? get_term_meta( $id, '_momos_i18n_' . $lang, true ) : get_post_meta( $id, '_momos_i18n_' . $lang, true );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? array_map( 'strval', $raw ) : [];
	}

	public static function status( string $type, int $id, string $lang ): string {
		if ( Lang::SOURCE === $lang ) {
			return 'approved';
		}
		$s = 'term' === $type ? get_term_meta( $id, '_momos_i18n_status_' . $lang, true ) : get_post_meta( $id, '_momos_i18n_status_' . $lang, true );
		return in_array( $s, self::STATUSES, true ) ? (string) $s : 'missing';
	}

	/** True when the source changed after this translation was approved. */
	public static function is_outdated( string $type, int $id, string $lang ): bool {
		if ( 'approved' !== self::status( $type, $id, $lang ) ) {
			return false;
		}
		$stored = 'term' === $type ? get_term_meta( $id, '_momos_i18n_srchash_' . $lang, true ) : get_post_meta( $id, '_momos_i18n_srchash_' . $lang, true );
		return '' !== (string) $stored && (string) $stored !== self::source_hash( $type, $id );
	}

	// ---------------------------------------------------------------- writing

	/**
	 * Save a translation. Fields not registered for the kind are ignored.
	 *
	 * @param array<string,string> $values
	 */
	public static function save( string $type, int $id, string $lang, array $values, string $status, int $user_id = 0 ): void {
		if ( Lang::SOURCE === $lang || ! Lang::is_valid( $lang ) || ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid translation target' );
		}
		$kind   = 'term' === $type ? 'term:' . ( get_term( $id )->taxonomy ?? '' ) : self::kind_of_post( $id );
		$fields = self::fields( $kind );
		$clean  = [];
		foreach ( $fields as $key => $def ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$v = (string) $values[ $key ];
			$v = 'html' === $def['type'] ? wp_kses_post( $v ) : ( 'textarea' === $def['type'] ? sanitize_textarea_field( $v ) : sanitize_text_field( $v ) );
			if ( isset( $def['max'] ) ) {
				$v = mb_substr( $v, 0, (int) $def['max'] );
			}
			$clean[ $key ] = $v;
		}
		$before  = self::data( $type, $id, $lang );
		$old_st  = self::status( $type, $id, $lang );
		$merged  = array_merge( $before, $clean );
		$updater = 'term' === $type ? 'update_term_meta' : 'update_post_meta';
		$updater( $id, '_momos_i18n_' . $lang, wp_slash( (string) wp_json_encode( $merged, JSON_UNESCAPED_UNICODE ) ) );
		$updater( $id, '_momos_i18n_status_' . $lang, $status );
		$updater( $id, '_momos_i18n_updated_' . $lang, Clock::mysql_utc() );
		if ( 'approved' === $status ) {
			$updater( $id, '_momos_i18n_srchash_' . $lang, self::source_hash( $type, $id ) );
			$updater( $id, '_momos_i18n_approved_by_' . $lang, $user_id );
		}
		do_action( 'momos/i18n/translation_saved', $type, $id, $lang, $old_st, $status, array_keys( $clean ), $user_id );
	}

	/** Translation completeness: required fields with non-empty source but empty translation. */
	public static function missing_fields( string $type, int $id, string $lang ): array {
		$kind   = 'term' === $type ? 'term:' . ( get_term( $id )->taxonomy ?? '' ) : self::kind_of_post( $id );
		$data   = self::data( $type, $id, $lang );
		$out    = [];
		foreach ( self::fields( $kind ) as $key => $def ) {
			$src = 'term' === $type ? self::source_term_value( get_term( $id ), $key ) : self::source_post_value( get_post( $id ), $key );
			if ( '' !== trim( wp_strip_all_tags( $src ) ) && '' === trim( (string) ( $data[ $key ] ?? '' ) ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	public static function source_hash( string $type, int $id ): string {
		$kind = 'term' === $type ? 'term:' . ( get_term( $id )->taxonomy ?? '' ) : self::kind_of_post( $id );
		$buf  = '';
		foreach ( array_keys( self::fields( $kind ) ) as $key ) {
			$buf .= $key . '=' . ( 'term' === $type ? self::source_term_value( get_term( $id ), $key ) : self::source_post_value( get_post( $id ), $key ) ) . "\n";
		}
		return hash( 'sha256', $buf );
	}

	// ---------------------------------------------------------------- source values

	public static function source_post_value( ?\WP_Post $post, string $field ): string {
		if ( ! $post ) {
			return '';
		}
		$def    = self::fields( 'post:' . $post->post_type )[ $field ] ?? null;
		$source = $def['source'] ?? $field;
		return match ( true ) {
			'post_title' === $source || 'title' === $field && null === $def => (string) $post->post_title,
			'post_content' === $source => (string) $post->post_content,
			'post_excerpt' === $source => (string) $post->post_excerpt,
			str_starts_with( $source, 'meta:' ) => (string) get_post_meta( $post->ID, substr( $source, 5 ), true ),
			default => (string) get_post_meta( $post->ID, $source, true ),
		};
	}

	public static function source_term_value( $term, string $field ): string {
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}
		$def    = self::fields( 'term:' . $term->taxonomy )[ $field ] ?? null;
		$source = $def['source'] ?? $field;
		return match ( true ) {
			'name' === $source => (string) $term->name,
			'description' === $source => (string) $term->description,
			str_starts_with( $source, 'meta:' ) => (string) get_term_meta( $term->term_id, substr( $source, 5 ), true ),
			default => (string) get_term_meta( $term->term_id, $source, true ),
		};
	}
}
