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
 * `_momos_i18n_translated_by_{lang}` records the last user who changed the text (translator ≠ reviewer).
 *
 * Field types: text | textarea | html, and `repeater` for list values (FAQ items): the source is a JSON
 * list (or array) in meta, the translation an array of rows keyed by `row_key` (default `id`) holding
 * only the `sub_fields` (each {type, max?}). post()/term() return list values JSON-encoded (string API
 * kept); post_rows() returns them decoded.
 *
 * Option texts (kind `option:{page}`) are stored in option `momos_option_i18n` {page: {lang: {field…,
 * _status, _srchash, _updated, _approved_by, _translated_by}}} — see option_data()/save_option().
 */
final class Translations {
	public const STATUSES = [ 'missing', 'draft', 'needs_review', 'approved' ];

	/**
	 * Registered translatable fields per object kind.
	 * kind = "post:{post_type}" | "term:{taxonomy}" | "option:{page}".
	 * field def: [label, type (text|textarea|html|repeater), source (post_title|post_content|post_excerpt|name|description|meta:{key}|option:{key}),
	 * repeater only: sub_fields {name: {label,type,max?}}, row_key (default id)]
	 *
	 * @var array<string, array<string, array<string,mixed>>>
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
		return isset( $data[ $field ] ) ? self::to_string( $data[ $field ] ) : '';
	}

	/**
	 * Rows of a repeater field in a language (approved only unless $any_status). For the source language
	 * the source rows are returned.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function post_rows( int|\WP_Post $post, string $field, ?string $lang = null, bool $any_status = false ): array {
		$post = get_post( $post );
		if ( ! $post ) {
			return [];
		}
		$lang = $lang ?? Locale::current();
		$raw  = Lang::SOURCE === $lang ? self::source_post_value( $post, $field ) : self::post( $post, $field, $lang, $any_status );
		return self::decode_rows( $raw );
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
		return isset( $data[ $field ] ) ? self::to_string( $data[ $field ] ) : '';
	}

	/** Translated option text (brand settings, statement templates). */
	public static function option( string $page, string $field, ?string $lang = null ): string {
		$lang = $lang ?? Locale::current();
		if ( Lang::SOURCE === $lang ) {
			return (string) \Momos\Kernel\Settings::get( $field );
		}
		if ( 'approved' !== self::option_status( $page, $lang ) ) {
			return '';
		}
		return self::to_string( self::option_data( $page, $lang )[ $field ] ?? '' );
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

	/**
	 * Stored translation values: strings, and lists of rows for repeater fields.
	 *
	 * @return array<string,string|array<int,array<string,string>>>
	 */
	public static function data( string $type, int|string $id, string $lang ): array {
		if ( 'option' === $type ) {
			return self::option_data( (string) $id, $lang );
		}
		$raw = 'term' === $type ? get_term_meta( (int) $id, '_momos_i18n_' . $lang, true ) : get_post_meta( (int) $id, '_momos_i18n_' . $lang, true );
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		$out = [];
		foreach ( is_array( $raw ) ? $raw : [] as $k => $v ) {
			$out[ (string) $k ] = is_array( $v ) ? array_values( array_filter( $v, 'is_array' ) ) : ( is_scalar( $v ) || null === $v ? (string) $v : '' );
		}
		return $out;
	}

	public static function status( string $type, int|string $id, string $lang ): string {
		if ( Lang::SOURCE === $lang ) {
			return 'approved';
		}
		if ( 'option' === $type ) {
			return self::option_status( (string) $id, $lang );
		}
		$id = (int) $id;
		$s  = 'term' === $type ? get_term_meta( $id, '_momos_i18n_status_' . $lang, true ) : get_post_meta( $id, '_momos_i18n_status_' . $lang, true );
		return in_array( $s, self::STATUSES, true ) ? (string) $s : 'missing';
	}

	/** True when the source changed after this translation was approved. */
	public static function is_outdated( string $type, int|string $id, string $lang ): bool {
		if ( 'approved' !== self::status( $type, $id, $lang ) ) {
			return false;
		}
		if ( 'option' === $type ) {
			$stored = (string) ( self::option_row( (string) $id, $lang )['_srchash'] ?? '' );
			return '' !== $stored && $stored !== self::source_hash( 'option', $id );
		}
		$id     = (int) $id;
		$stored = 'term' === $type ? get_term_meta( $id, '_momos_i18n_srchash_' . $lang, true ) : get_post_meta( $id, '_momos_i18n_srchash_' . $lang, true );
		return '' !== (string) $stored && (string) $stored !== self::source_hash( $type, $id );
	}

	// ---------------------------------------------------------------- writing

	/**
	 * Save a translation. Fields not registered for the kind are ignored.
	 *
	 * @param array<string,string> $values
	 */
	public static function save( string $type, int|string $id, string $lang, array $values, string $status, int $user_id = 0 ): void {
		if ( Lang::SOURCE === $lang || ! Lang::is_valid( $lang ) || ! in_array( $status, self::STATUSES, true ) ) {
			throw new \InvalidArgumentException( 'Invalid translation target' );
		}
		if ( 'option' === $type ) {
			self::save_option( (string) $id, $lang, $values, $status, $user_id );
			return;
		}
		$id      = (int) $id;
		$kind    = self::kind_of( $type, $id );
		$clean   = self::clean_values( $kind, $values, fn( string $key ) => self::source_value( $type, $id, $key ) );
		$before  = self::data( $type, $id, $lang );
		$old_st  = self::status( $type, $id, $lang );
		$merged  = array_merge( $before, $clean );
		$changed = self::changed_fields( $before, $clean );
		$updater = 'term' === $type ? 'update_term_meta' : 'update_post_meta';
		$updater( $id, '_momos_i18n_' . $lang, wp_slash( (string) wp_json_encode( $merged, JSON_UNESCAPED_UNICODE ) ) );
		$updater( $id, '_momos_i18n_status_' . $lang, $status );
		$updater( $id, '_momos_i18n_updated_' . $lang, Clock::mysql_utc() );
		if ( $changed && $user_id ) {
			$updater( $id, '_momos_i18n_translated_by_' . $lang, $user_id );
		}
		if ( 'approved' === $status ) {
			$updater( $id, '_momos_i18n_srchash_' . $lang, self::source_hash( $type, $id ) );
			$updater( $id, '_momos_i18n_approved_by_' . $lang, $user_id );
		}
		do_action( 'momos/i18n/translation_saved', $type, $id, $lang, $old_st, $status, array_keys( $clean ), $user_id );
	}

	/**
	 * Would saving these values change the stored text (after sanitising)?
	 *
	 * @param array<string,mixed> $values
	 */
	public static function would_change( string $type, int|string $id, string $lang, array $values ): bool {
		$kind  = self::kind_of( $type, $id );
		$clean = self::clean_values( $kind, $values, fn( string $key ) => self::source_value( $type, $id, $key ) );
		return self::changed_fields( self::data( $type, $id, $lang ), $clean );
	}

	/** User who last changed the translated text (0 = unknown). */
	public static function translated_by( string $type, int|string $id, string $lang ): int {
		if ( 'option' === $type ) {
			return (int) ( self::option_row( (string) $id, $lang )['_translated_by'] ?? 0 );
		}
		return (int) ( 'term' === $type ? get_term_meta( (int) $id, '_momos_i18n_translated_by_' . $lang, true ) : get_post_meta( (int) $id, '_momos_i18n_translated_by_' . $lang, true ) );
	}

	/**
	 * Change only the status (bulk "mark needs review" / return to translator). Approval is not possible
	 * here (it needs the editor and the review rules).
	 */
	public static function set_status( string $type, int|string $id, string $lang, string $status, int $user_id = 0 ): void {
		if ( 'approved' === $status ) {
			throw new \InvalidArgumentException( 'Use save() to approve' );
		}
		self::save( $type, $id, $lang, [], $status, $user_id );
	}

	// ---------------------------------------------------------------- options

	/** @return array<string,mixed> */
	private static function option_row( string $page, string $lang ): array {
		$all = get_option( 'momos_option_i18n', [] );
		$row = is_array( $all ) ? ( $all[ $page ][ $lang ] ?? [] ) : [];
		return is_array( $row ) ? $row : [];
	}

	/** @return array<string,string> translated option fields (meta keys stripped) */
	public static function option_data( string $page, string $lang ): array {
		$out = [];
		foreach ( self::option_row( $page, $lang ) as $k => $v ) {
			if ( ! str_starts_with( (string) $k, '_' ) && is_scalar( $v ) ) {
				$out[ (string) $k ] = (string) $v;
			}
		}
		return $out;
	}

	public static function option_status( string $page, string $lang ): string {
		if ( Lang::SOURCE === $lang ) {
			return 'approved';
		}
		$s = self::option_row( $page, $lang )['_status'] ?? 'missing';
		return in_array( $s, self::STATUSES, true ) ? (string) $s : 'missing';
	}

	/** @param array<string,mixed> $values */
	public static function save_option( string $page, string $lang, array $values, string $status, int $user_id = 0 ): void {
		if ( Lang::SOURCE === $lang || ! Lang::is_valid( $lang ) || ! in_array( $status, self::STATUSES, true ) || ! self::fields( 'option:' . $page ) ) {
			throw new \InvalidArgumentException( 'Invalid translation target' );
		}
		$clean           = self::clean_values( 'option:' . $page, $values, fn( string $key ) => self::source_value( 'option', $page, $key ) );
		$before          = self::option_data( $page, $lang );
		$old_st          = self::option_status( $page, $lang );
		$all             = get_option( 'momos_option_i18n', [] );
		$all             = is_array( $all ) ? $all : [];
		$row             = array_merge( self::option_row( $page, $lang ), $clean );
		$row['_status']  = $status;
		$row['_updated'] = Clock::mysql_utc();
		if ( self::changed_fields( $before, $clean ) && $user_id ) {
			$row['_translated_by'] = $user_id;
		}
		if ( 'approved' === $status ) {
			$row['_srchash']     = self::source_hash( 'option', $page );
			$row['_approved_by'] = $user_id;
		}
		$all[ $page ][ $lang ] = $row;
		update_option( 'momos_option_i18n', $all, true );
		// Not `momos/i18n/translation_saved` (its listeners take an int object id).
		do_action( 'momos/i18n/option_translation_saved', $page, $lang, $old_st, $status, array_keys( $clean ), $user_id );
		if ( $old_st !== $status || $clean ) {
			do_action( 'momos/cache/purge', [ 'momo-global' ] ); // Brand texts render on every page.
			$action = 'approved' === $status && 'approved' !== $old_st ? 'translation.approved' : ( 'draft' === $status && in_array( $old_st, [ 'needs_review', 'approved' ], true ) ? 'translation.returned' : 'translation.updated' );
			momos_audit( $action, 'option', null, null, [ 'page' => $page, 'lang' => $lang, 'status' => [ 'from' => $old_st, 'to' => $status ], 'fields' => array_keys( $clean ) ] );
		}
	}

	// ---------------------------------------------------------------- value helpers

	public static function kind_of( string $type, int|string $id ): string {
		return match ( $type ) {
			'term' => 'term:' . ( get_term( (int) $id )->taxonomy ?? '' ),
			'option' => 'option:' . $id,
			default => self::kind_of_post( (int) $id ),
		};
	}

	/** Source (Japanese) value of a field as a string (repeaters: JSON of the translatable columns). */
	public static function source_value( string $type, int|string $id, string $field ): string {
		return match ( $type ) {
			'term' => self::source_term_value( get_term( (int) $id ), $field ),
			'option' => self::source_option_value( (string) $id, $field ),
			default => self::source_post_value( get_post( (int) $id ), $field ),
		};
	}

	public static function source_option_value( string $page, string $field ): string {
		$def    = self::fields( 'option:' . $page )[ $field ] ?? null;
		$source = (string) ( $def['source'] ?? $field );
		$name   = str_starts_with( $source, 'option:' ) ? substr( $source, 7 ) : $source;
		return self::to_string( \Momos\Kernel\Settings::get( $name ) );
	}

	/**
	 * Sanitise submitted values against the registered field definitions.
	 *
	 * @param array<string,mixed>       $values
	 * @param callable(string):string   $source
	 * @return array<string,string|list<array<string,string>>>
	 */
	private static function clean_values( string $kind, array $values, callable $source ): array {
		$clean = [];
		foreach ( self::fields( $kind ) as $key => $def ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			if ( 'repeater' === $def['type'] ) {
				$clean[ $key ] = self::clean_rows( $def, $values[ $key ], self::decode_rows( $source( $key ) ) );
				continue;
			}
			$clean[ $key ] = self::clean_scalar( $def, $values[ $key ] );
		}
		return $clean;
	}

	/** @param array<string,mixed> $def */
	private static function clean_scalar( array $def, mixed $raw ): string {
		$v = is_scalar( $raw ) ? (string) $raw : '';
		$v = 'html' === $def['type'] ? wp_kses_post( $v ) : ( 'textarea' === $def['type'] ? sanitize_textarea_field( $v ) : sanitize_text_field( $v ) );
		// Digits are always Western (DEC-062, QA-050): "৩ মিনিট" is stored as "3 মিনিট" in every field type.
		$v = Digits::western( $v );
		if ( isset( $def['max'] ) ) {
			$v = mb_substr( $v, 0, (int) $def['max'] );
		}
		return $v;
	}

	/**
	 * Repeater rows: only rows whose key exists in the source, only the registered sub fields, source order.
	 *
	 * @param array<string,mixed>             $def
	 * @param list<array<string,mixed>>       $source_rows
	 * @return list<array<string,string>>
	 */
	private static function clean_rows( array $def, mixed $raw, array $source_rows ): array {
		$row_key = (string) ( $def['row_key'] ?? 'id' );
		$rows    = is_string( $raw ) ? self::decode_rows( $raw ) : ( is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [] );
		$by_key  = [];
		foreach ( $rows as $r ) {
			$k = isset( $r[ $row_key ] ) && is_scalar( $r[ $row_key ] ) ? (string) $r[ $row_key ] : '';
			if ( '' !== $k ) {
				$by_key[ $k ] = $r;
			}
		}
		$out = [];
		foreach ( $source_rows as $src ) {
			$k = isset( $src[ $row_key ] ) && is_scalar( $src[ $row_key ] ) ? (string) $src[ $row_key ] : '';
			if ( '' === $k || ! isset( $by_key[ $k ] ) ) {
				continue;
			}
			$row = [ $row_key => $k ];
			$any = false;
			foreach ( (array) ( $def['sub_fields'] ?? [] ) as $name => $sub ) {
				$row[ $name ] = self::clean_scalar( (array) $sub, $by_key[ $k ][ $name ] ?? '' );
				$any          = $any || '' !== trim( $row[ $name ] );
			}
			if ( $any ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/** @return list<array<string,mixed>> */
	public static function decode_rows( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? [] : json_decode( $raw, true );
		}
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	private static function to_string( mixed $v ): string {
		if ( is_array( $v ) ) {
			return [] === $v ? '' : (string) wp_json_encode( array_values( $v ), JSON_UNESCAPED_UNICODE );
		}
		return is_scalar( $v ) ? (string) $v : '';
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $clean
	 */
	private static function changed_fields( array $before, array $clean ): bool {
		foreach ( $clean as $k => $v ) {
			if ( self::to_string( $before[ $k ] ?? '' ) !== self::to_string( $v ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Source value used for hashing/completeness: repeaters reduce to row key + translatable sub fields so
	 * re-ordering or regrouping does not outdate translations.
	 */
	private static function comparable_source( string $kind, string $field, string $value ): string {
		$def = self::fields( $kind )[ $field ] ?? null;
		if ( ! $def || 'repeater' !== $def['type'] ) {
			return $value;
		}
		$row_key = (string) ( $def['row_key'] ?? 'id' );
		$subs    = array_keys( (array) ( $def['sub_fields'] ?? [] ) );
		$out     = [];
		foreach ( self::decode_rows( $value ) as $r ) {
			$row = [ $row_key => (string) ( $r[ $row_key ] ?? '' ) ];
			foreach ( $subs as $s ) {
				$row[ $s ] = is_scalar( $r[ $s ] ?? '' ) ? (string) ( $r[ $s ] ?? '' ) : '';
			}
			$out[] = $row;
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a[ $row_key ], $b[ $row_key ] ) );
		return [] === $out ? '' : (string) wp_json_encode( $out, JSON_UNESCAPED_UNICODE );
	}

	/** Translation completeness: required fields with non-empty source but empty translation. */
	public static function missing_fields( string $type, int|string $id, string $lang ): array {
		$kind = self::kind_of( $type, $id );
		$data = self::data( $type, $id, $lang );
		$out  = [];
		foreach ( self::fields( $kind ) as $key => $def ) {
			$src = self::source_value( $type, $id, $key );
			if ( 'repeater' === $def['type'] ) {
				$row_key = (string) ( $def['row_key'] ?? 'id' );
				$have    = [];
				foreach ( is_array( $data[ $key ] ?? null ) ? $data[ $key ] : self::decode_rows( $data[ $key ] ?? '' ) as $r ) {
					$have[ (string) ( $r[ $row_key ] ?? '' ) ] = $r;
				}
				foreach ( self::decode_rows( $src ) as $r ) {
					foreach ( array_keys( (array) ( $def['sub_fields'] ?? [] ) ) as $sub ) {
						$sv = is_scalar( $r[ $sub ] ?? '' ) ? (string) ( $r[ $sub ] ?? '' ) : '';
						if ( '' !== trim( wp_strip_all_tags( $sv ) ) && '' === trim( (string) ( $have[ (string) ( $r[ $row_key ] ?? '' ) ][ $sub ] ?? '' ) ) ) {
							$out[] = $key;
							break 2;
						}
					}
				}
				continue;
			}
			if ( '' !== trim( wp_strip_all_tags( $src ) ) && '' === trim( self::to_string( $data[ $key ] ?? '' ) ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	public static function source_hash( string $type, int|string $id ): string {
		$kind = self::kind_of( $type, $id );
		$buf  = '';
		foreach ( self::fields( $kind ) as $key => $def ) {
			$value = self::comparable_source( $kind, $key, self::source_value( $type, $id, $key ) );
			if ( '' === $value ) {
				continue; // Adding an empty (new or optional) field must not outdate existing approvals.
			}
			$buf .= $key . '=' . $value . "\n";
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
			'post_title' === $source || ( 'title' === $field && null === $def ) => (string) $post->post_title,
			'post_content' === $source => (string) $post->post_content,
			'post_excerpt' === $source => (string) $post->post_excerpt,
			str_starts_with( $source, 'meta:' ) => self::to_string( get_post_meta( $post->ID, substr( $source, 5 ), true ) ),
			default => self::to_string( get_post_meta( $post->ID, $source, true ) ),
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
			str_starts_with( $source, 'meta:' ) => self::to_string( get_term_meta( $term->term_id, substr( $source, 5 ), true ) ),
			default => self::to_string( get_term_meta( $term->term_id, $source, true ) ),
		};
	}
}
