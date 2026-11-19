<?php
declare(strict_types=1);

namespace Momos\Admin\Content;

/**
 * Illustrative (demo) images never on the About and Halal pages (FR-ADM-005(f), BR-CORP-03, DEC-047):
 * images flagged `momo_image_illustrative` are refused as the featured image and inside the content of the
 * system pages with roles `about` and `halal` — "Demo images cannot be used here. Upload a real photo."
 * (same rule as the SEO share image, `Seo\SeoFields::validate_image`, and branch galleries).
 *
 * - Block editor / REST: `rest_pre_insert_page` returns a 400 error (featured_media or content).
 * - Classic editor and programmatic saves: the content change is not stored (`wp_insert_post_data`) and
 *   the featured image is not set (`update/add_post_metadata` for `_thumbnail_id`); the message is shown
 *   as an admin notice (FieldRegistry errors).
 */
final class IllustrativeGuard {
	public const ROLES = [ 'about', 'halal' ];

	public static function register(): void {
		add_filter( 'rest_pre_insert_page', [ self::class, 'rest_pre_insert' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ self::class, 'filter_post_data' ], 20, 2 );
		add_filter( 'update_post_metadata', [ self::class, 'filter_thumbnail' ], 10, 4 );
		add_filter( 'add_post_metadata', [ self::class, 'filter_thumbnail' ], 10, 4 );
		add_filter( 'momos/audit/catalogue', static fn( $a ) => array_merge( (array) $a, [ 'content.illustrative_refused' ] ) );
	}

	public static function message(): string {
		return __( 'Demo images cannot be used here. Upload a real photo.', 'momos-core' );
	}

	/** Is the page one of the guarded system pages? */
	public static function is_guarded( int $page_id ): bool {
		if ( $page_id <= 0 || 'page' !== get_post_type( $page_id ) || ! class_exists( \Momos\Presentation\SystemPages::class ) ) {
			return false;
		}
		return in_array( \Momos\Presentation\SystemPages::role_of( $page_id ), self::ROLES, true );
	}

	public static function is_illustrative( int $attachment_id ): bool {
		return $attachment_id > 0 && '1' === (string) get_post_meta( $attachment_id, MediaFields::ILLUS, true );
	}

	/**
	 * Attachment IDs referenced by post content: `wp-image-N` classes, block `"id":N`, `[gallery ids=…]` and
	 * `<img src>` URLs of the media library.
	 *
	 * @return list<int>
	 */
	public static function content_images( string $content ): array {
		$ids = [];
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/<!--\s+wp:(?:image|media-text|cover)\s+\{[^}]*"(?:id|mediaId)":(\d+)/', $content, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/\[gallery[^\]]*\bids="([\d,\s]+)"/', $content, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, explode( ',', $list ) );
			}
		}
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $src ) {
				$ids[] = attachment_url_to_postid( (string) preg_replace( '/-\d+x\d+(?=\.[a-z]+$)/i', '', html_entity_decode( $src ) ) );
			}
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/** True when the content shows an illustrative image. */
	public static function content_has_illustrative( string $content ): bool {
		foreach ( self::content_images( $content ) as $id ) {
			if ( self::is_illustrative( $id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param \stdClass|\WP_Error $prepared
	 * @return \stdClass|\WP_Error
	 */
	public static function rest_pre_insert( $prepared, \WP_REST_Request $request ) {
		if ( ! $prepared instanceof \stdClass ) {
			return $prepared;
		}
		$id = (int) ( $prepared->ID ?? 0 );
		if ( ! self::is_guarded( $id ) ) {
			return $prepared;
		}
		$featured = $request->get_param( 'featured_media' );
		$content  = isset( $prepared->post_content ) ? (string) $prepared->post_content : '';
		if ( ( null !== $featured && self::is_illustrative( (int) $featured ) ) || ( '' !== $content && self::content_has_illustrative( $content ) ) ) {
			self::deny( $id, 'rest' );
			return new \WP_Error( 'momos_illustrative_image', self::message(), [ 'status' => 400 ] );
		}
		return $prepared;
	}

	/**
	 * Classic editor / wp_update_post(): keep the stored content when the new one shows a flagged image.
	 *
	 * @param array<string,mixed> $data    slashed post data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function filter_post_data( $data, $postarr ): array {
		$data = (array) $data;
		$id   = (int) ( $postarr['ID'] ?? 0 );
		if ( ! self::is_guarded( $id ) || ! self::content_has_illustrative( wp_unslash( (string) ( $data['post_content'] ?? '' ) ) ) ) {
			return $data;
		}
		$old = get_post( $id );
		$data['post_content'] = wp_slash( $old instanceof \WP_Post ? (string) $old->post_content : '' );
		self::deny( $id, 'content' );
		\Momos\Kernel\Fields\FieldRegistry::add_errors( [ self::message() ] );
		return $data;
	}

	/**
	 * Refuse a flagged featured image (`_thumbnail_id`) on a guarded page.
	 *
	 * @param null|bool $check
	 * @return null|bool
	 */
	public static function filter_thumbnail( $check, $object_id, $meta_key, $meta_value ) {
		if ( '_thumbnail_id' !== $meta_key || ! self::is_illustrative( (int) $meta_value ) || ! self::is_guarded( (int) $object_id ) ) {
			return $check;
		}
		self::deny( (int) $object_id, 'featured' );
		\Momos\Kernel\Fields\FieldRegistry::add_errors( [ self::message() ] );
		return false;
	}

	private static function deny( int $page_id, string $where ): void {
		momos_audit( 'content.illustrative_refused', 'page', $page_id, null, [ 'field' => $where, 'rule' => 'FR-ADM-005(f)' ] );
	}
}
