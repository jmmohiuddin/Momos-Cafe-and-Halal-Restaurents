<?php
declare(strict_types=1);

namespace Momos\Seed;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\Sanitizer;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Write helpers for the demo seed. Every field value goes through the module's documented write path
 * (FieldRegistry::save_group_values → per-field sanitising/validation, group validators, guards,
 * audits, domain hooks) as the current (capability-holding) user; a rejected or silently denied value
 * is turned into a SeedException so the seed never reports success on partial data.
 *
 * Seeded objects carry `_momo_seed_key` (stable identity for idempotent re-runs and `--reset`) and
 * `momo_is_sample = 1` (DEC-047).
 */
final class Writer {
	public const KEY_META    = '_momo_seed_key';
	public const SAMPLE_META = 'momo_is_sample';

	// ------------------------------------------------------------------ lookup

	public static function find_post( string $post_type, string $key ): int {
		$ids = get_posts(
			[
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		if ( ! $ids && 'attachment' === $post_type ) {
			$ids = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => self::KEY_META, 'meta_value' => $key ] ); // phpcs:ignore
		}
		return $ids ? (int) $ids[0] : 0;
	}

	public static function find_term( string $taxonomy, string $key ): int {
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 1, 'fields' => 'ids', 'meta_key' => self::KEY_META, 'meta_value' => $key ] ); // phpcs:ignore
		return is_array( $terms ) && $terms ? (int) $terms[0] : 0;
	}

	// ------------------------------------------------------------------ posts

	/**
	 * Create (as draft) or update the core fields of a seeded post. Returns the post ID.
	 *
	 * @param array<string,mixed> $core post_title, post_content, post_excerpt, post_name, menu_order, post_parent
	 */
	public static function upsert_post( string $post_type, string $key, array $core ): int {
		$id = self::find_post( $post_type, $key );
		if ( ! $id ) {
			$id = wp_insert_post( array_merge( [ 'post_type' => $post_type, 'post_status' => 'draft', 'comment_status' => 'closed', 'ping_status' => 'closed' ], $core ), true );
			if ( is_wp_error( $id ) ) {
				throw new SeedException( sprintf( 'Could not create %s "%s": %s', $post_type, $key, $id->get_error_message() ) );
			}
			update_post_meta( (int) $id, self::KEY_META, $key );
			update_post_meta( (int) $id, self::SAMPLE_META, '1' );
			return (int) $id;
		}
		self::update_core( $id, $core );
		return $id;
	}

	/** wp_update_post only for core fields that differ (keeps re-runs free of revisions/audits). @param array<string,mixed> $core */
	public static function update_core( int $id, array $core ): void {
		$post = get_post( $id );
		if ( ! $post ) {
			throw new SeedException( "Post {$id} vanished" );
		}
		$diff = [];
		foreach ( $core as $k => $v ) {
			if ( (string) $post->$k !== (string) $v ) {
				$diff[ $k ] = $v;
			}
		}
		if ( $diff ) {
			$res = wp_update_post( wp_slash( [ 'ID' => $id ] + $diff ), true );
			if ( is_wp_error( $res ) ) {
				throw new SeedException( sprintf( 'Could not update post %d: %s', $id, $res->get_error_message() ) );
			}
		}
	}

	/** Publish through wp_update_post so the module publish validators run; refusal → exception. */
	public static function publish( int $id ): void {
		if ( 'publish' === get_post_status( $id ) ) {
			return;
		}
		$GLOBALS['momos_last_field_errors'] = [];
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		clean_post_cache( $id );
		// Re-read the stored status (publish validators may have kept the post a draft).
		if ( 'publish' !== get_post_field( 'post_status', $id ) ) {
			throw new SeedException( sprintf( 'Publishing %s %d was refused: %s', get_post_type( $id ), $id, implode( ' | ', FieldRegistry::last_errors() ) ) );
		}
		self::clear_notices();
	}

	/**
	 * Save field values through the registered field groups of the object, then verify that every
	 * value was stored (silent denials and validator rejections both throw).
	 *
	 * @param 'post'|'term'|'option' $type
	 * @param array<string,mixed>     $values field name => raw value (as a form would submit it)
	 */
	public static function save_fields( string $type, int|string $object_id, array $values ): void {
		$kind = match ( $type ) {
			'post'   => 'post:' . get_post_type( (int) $object_id ),
			'term'   => 'term:' . ( get_term( (int) $object_id )->taxonomy ?? '' ),
			default  => 'option:' . $object_id,
		};
		$post    = 'post' === $type ? get_post( (int) $object_id ) : null;
		$handled = [];
		foreach ( FieldRegistry::groups_for( $kind ) as $group ) {
			$names = array_column( $group['fields'], 'name' );
			$sub   = array_intersect_key( $values, array_flip( $names ) );
			if ( ! $sub ) {
				continue;
			}
			$GLOBALS['momos_last_field_errors'] = [];
			$denied                             = [];
			$listener                           = static function ( $t, $oid, $gkey, $fields ) use ( &$denied ): void {
				$denied = array_merge( $denied, (array) $fields );
			};
			add_action( 'momos/fields/denied', $listener, 1, 4 );
			FieldRegistry::save_group_values( $type, $object_id, $group, $sub, $post );
			remove_action( 'momos/fields/denied', $listener, 1 );
			$errors = FieldRegistry::last_errors();
			if ( $errors || $denied ) {
				throw new SeedException( sprintf( 'Saving %s %s (%s) was refused: %s%s', $kind, (string) $object_id, $group['key'], implode( ' | ', $errors ), $denied ? ' denied: ' . implode( ',', $denied ) : '' ) );
			}
			self::verify( $type, $object_id, $group, $sub );
			$handled = array_merge( $handled, array_keys( $sub ) );
		}
		$unknown = array_diff( array_keys( $values ), $handled );
		if ( $unknown ) {
			throw new SeedException( sprintf( 'No registered field for %s: %s', $kind, implode( ', ', $unknown ) ) );
		}
		self::clear_notices();
	}

	/**
	 * @param array<string,mixed> $group
	 * @param array<string,mixed> $sub
	 */
	private static function verify( string $type, int|string $object_id, array $group, array $sub ): void {
		$stored = match ( $type ) {
			'post'  => FieldRegistry::post_values( (int) $object_id, $group ),
			'term'  => FieldRegistry::term_values( (int) $object_id, $group ),
			default => \Momos\Kernel\Settings::page( (string) $object_id ),
		};
		foreach ( $group['fields'] as $f ) {
			if ( ! array_key_exists( $f['name'], $sub ) || ! empty( $f['readonly'] ) || 'momo_alg_attest_not_source' === $f['name'] ) {
				continue; // The attestation flag is reset by the module after a successful save.
			}
			$want = Sanitizer::sanitize( $f, $sub[ $f['name'] ] );
			$have = $stored[ $f['name'] ] ?? null;
			$same = is_array( $want ) || is_array( $have )
				? wp_json_encode( array_values( (array) $want ) ) === wp_json_encode( array_values( (array) ( $have ?? [] ) ) )
				: (string) $want === (string) $have;
			if ( ! $same ) {
				throw new SeedException( sprintf( 'Field %s of %s %s was not stored (expected %s, got %s).', $f['name'], $type, (string) $object_id, wp_json_encode( $want ), wp_json_encode( $have ) ) );
			}
		}
	}

	/** Remove the per-user admin notice transient filled by FieldRegistry::add_errors(). */
	public static function clear_notices(): void {
		delete_transient( 'momos_field_errors_' . get_current_user_id() );
		$GLOBALS['momos_last_field_errors'] = [];
	}

	// ------------------------------------------------------------------ translations

	/**
	 * Store a translation and approve it (dev data only): the translator drafts it as `needs_review`,
	 * the reviewer approves (two people, i18n-seo-analytics.md §6.2). Skipped when the stored text is
	 * already approved and unchanged (idempotent re-runs). Values for fields that the kind has not
	 * registered (e.g. while a module is still being built) are merged into the documented storage
	 * `_momos_i18n_{lang}` (IMPL-002) after sanitising, so the item is complete once it registers them.
	 *
	 * @param 'post'|'term'          $type
	 * @param array<string,string|array<int,array<string,string>>> $values
	 */
	public static function translate( string $type, int $id, string $lang, array $values, int $translator, int $reviewer ): void {
		if ( Lang::SOURCE === $lang ) {
			return;
		}
		$values = array_filter( $values, static fn( $v ) => is_array( $v ) ? [] !== $v : '' !== trim( (string) $v ) );
		$kind   = Translations::kind_of( $type, $id );
		$known  = array_intersect_key( $values, Translations::fields( $kind ) );
		$extra  = array_diff_key( $values, $known );
		$status = Translations::status( $type, $id, $lang );
		if ( 'approved' !== $status || Translations::would_change( $type, $id, $lang, $known ) || Translations::is_outdated( $type, $id, $lang ) || self::extra_differs( $type, $id, $lang, $extra ) ) {
			Translations::save( $type, $id, $lang, $known, 'needs_review', $translator );
			self::store_extra( $type, $id, $lang, $extra );
			Translations::save( $type, $id, $lang, [], 'approved', $reviewer );
		}
	}

	/** @param array<string,mixed> $extra */
	private static function extra_differs( string $type, int $id, string $lang, array $extra ): bool {
		if ( ! $extra ) {
			return false;
		}
		$data = Translations::data( $type, $id, $lang );
		foreach ( $extra as $k => $v ) {
			if ( wp_json_encode( self::clean_extra( $v ) ) !== wp_json_encode( $data[ $k ] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string,mixed> $extra */
	private static function store_extra( string $type, int $id, string $lang, array $extra ): void {
		if ( ! $extra ) {
			return;
		}
		$data = Translations::data( $type, $id, $lang );
		foreach ( $extra as $k => $v ) {
			$data[ (string) $k ] = self::clean_extra( $v );
		}
		$json = wp_slash( (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
		'term' === $type ? update_term_meta( $id, '_momos_i18n_' . $lang, $json ) : update_post_meta( $id, '_momos_i18n_' . $lang, $json );
	}

	/** @return string|list<array<string,string>> */
	private static function clean_extra( mixed $v ): string|array {
		if ( is_array( $v ) ) {
			$rows = [];
			foreach ( $v as $row ) {
				$rows[] = array_map( static fn( $x ) => wp_kses_post( (string) $x ), (array) $row );
			}
			return $rows;
		}
		return sanitize_textarea_field( (string) $v );
	}

	/** Option-page translations (brand texts), merged into existing rows and approved. @param array<string,string> $values */
	public static function translate_option( string $page, string $lang, array $values, int $reviewer ): void {
		$current = Translations::option_data( $page, $lang );
		$changed = false;
		foreach ( $values as $k => $v ) {
			if ( (string) ( $current[ $k ] ?? '' ) !== $v ) {
				$changed = true;
			}
		}
		if ( $changed || 'approved' !== Translations::option_status( $page, $lang ) || Translations::is_outdated( 'option', $page, $lang ) ) {
			Translations::save_option( $page, $lang, $values, 'approved', $reviewer );
		}
	}
}
