<?php
declare(strict_types=1);

namespace Momos\Kernel\Media;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;

/**
 * Alt text per language before publishing (FR-ADM-005(d), AC-ADM-04-02, US-ADM-006, NFR-ACC-01).
 *
 * A dish, branch, offer or page cannot be published in a language (= its translation approved, IMPL-002)
 * while one of its displayed images — the featured image and every `gallery` field of the post type —
 * lacks alternative text approved in that language (attachment translation field `alt`, kind
 * `post:attachment`; Japanese: `_wp_attachment_image_alt`). The translation editor refuses the approval
 * with `admin.media.alt_required` "Add alternative text before publishing" in the admin language.
 */
final class AltText {
	/** Post types whose translations are gated (FR-ADM-005(d)). */
	public const POST_TYPES = [ 'momo_dish', 'momo_branch', 'momo_offer', 'page' ];

	/**
	 * Displayed images of a post: featured image + gallery fields (filter `momos/media/displayed_images`).
	 *
	 * @return list<int>
	 */
	public static function images( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return [];
		}
		$ids = [ (int) get_post_thumbnail_id( $post ) ];
		foreach ( FieldRegistry::groups_for( 'post:' . $post->post_type ) as $group ) {
			foreach ( (array) ( $group['fields'] ?? [] ) as $f ) {
				if ( 'gallery' === ( $f['type'] ?? '' ) ) {
					$value = FieldRegistry::get_post_field( $post_id, (string) $f['name'] );
					$ids   = array_merge( $ids, array_map( 'intval', is_array( $value ) ? $value : array_filter( explode( ',', (string) $value ) ) ) );
				}
			}
		}
		$ids = (array) apply_filters( 'momos/media/displayed_images', $ids, $post );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ) => $id > 0 && 'attachment' === get_post_type( $id ) ) ) );
		return $ids;
	}

	/** Does the attachment have alt text approved in the language? */
	public static function has_alt( int $attachment_id, string $lang ): bool {
		if ( Lang::SOURCE === $lang ) {
			return '' !== trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		}
		return '' !== trim( Translations::post( $attachment_id, 'alt', $lang ) );
	}

	/**
	 * Images of the post that lack alt text in the language ([] for post types that are not gated).
	 *
	 * @return list<int>
	 */
	public static function missing( int $post_id, string $lang ): array {
		if ( ! in_array( (string) get_post_type( $post_id ), self::POST_TYPES, true ) ) {
			return [];
		}
		return array_values( array_filter( self::images( $post_id ), static fn( int $id ) => ! self::has_alt( $id, $lang ) ) );
	}

	/** "Add alternative text before publishing" in the admin language of the user (ja/en, DEC-037). */
	public static function message( ?int $user_id = null ): string {
		return momos_t( 'admin.media.alt_required', [], Locale::admin( $user_id ) );
	}
}
