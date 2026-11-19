<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

use Momos\Kernel\Fields\FieldRegistry;

/**
 * Latin slugs (FR-I18N-012, i18n-seo-analytics §8.1 rule 1): one ASCII `[a-z0-9-]` slug per item, shared by
 * every language, never a percent-encoded path. Categories, branches and FAQ ids have their own module
 * guards; this kernel guard covers dishes, offers, notices, pages and news posts.
 *
 * WordPress already transliterates Latin accents (`Crème brûlée` → `creme-brulee`, remove_accents()); what is
 * left is non-Latin script (Japanese, Arabic, Devanagari, Bengali …), which cannot be romanised reliably:
 *  - a non-Latin slug typed by the editor is rejected with an admin error (REST/block editor: 400): the
 *    previous Latin slug is kept, or a first publication is saved as a draft without a slug;
 *  - a slug WordPress would derive from a non-Latin title is replaced by a neutral Latin slug (`dish`,
 *    `page-2`, `offer-3` …, unique per type) so publishing never stores a percent-encoded path; for types
 *    with public URLs the editor is told to enter a descriptive slug (offers and notices have no single URL).
 */
final class SlugPolicy {
	/** Post types guarded here => [neutral slug base, has public single URLs]. */
	public const TYPES = [
		'page'        => [ 'page', true ],
		'post'        => [ 'post', true ],
		'momo_dish'   => [ 'dish', true ],
		'momo_offer'  => [ 'offer', false ],
		'momo_notice' => [ 'notice', false ],
	];

	public static function register(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'guard' ], 8, 2 );
		foreach ( array_keys( self::TYPES ) as $type ) {
			add_filter( 'rest_pre_insert_' . $type, [ self::class, 'rest_guard' ], 10, 2 );
		}
	}

	public static function is_latin( string $slug ): bool {
		return (bool) preg_match( '/^[a-z0-9-]+$/', $slug );
	}

	public static function message(): string {
		return __( 'Enter a URL slug with lowercase Latin letters a–z, digits and hyphens only, e.g. chicken-momo. The slug is shared by all languages, so Japanese or other non-Latin text cannot be used for it.', 'momos-core' );
	}

	/**
	 * @param array<string,mixed> $data    slashed post data about to be written
	 * @param array<string,mixed> $postarr raw arguments
	 * @return array<string,mixed>
	 */
	public static function guard( array $data, array $postarr ): array {
		$type   = (string) ( $data['post_type'] ?? '' );
		$slug   = (string) ( $data['post_name'] ?? '' );
		$status = (string) ( $data['post_status'] ?? 'draft' );
		if ( ! isset( self::TYPES[ $type ] ) || '' === $slug || self::is_latin( $slug ) || 'trash' === $status || str_ends_with( $slug, '__trashed' ) ) {
			return $data;
		}
		[ $base, $public ] = self::TYPES[ $type ];
		$post_id           = (int) ( $postarr['ID'] ?? 0 );
		$old               = $post_id ? get_post( $post_id ) : null;
		$typed             = '' !== (string) ( $postarr['post_name'] ?? '' );

		if ( $old instanceof \WP_Post && self::is_latin( (string) $old->post_name ) ) {
			$data['post_name'] = $old->post_name;
			if ( $typed ) {
				/* translators: %s: the slug that was kept */
				FieldRegistry::add_errors( [ self::message(), sprintf( __( 'The previous slug "%s" was kept.', 'momos-core' ), $old->post_name ) ] );
			}
			return $data;
		}
		if ( $old instanceof \WP_Post && 'publish' === $old->post_status && $old->post_name === $slug ) {
			FieldRegistry::add_errors( [ self::message() ] ); // Legacy item live under this URL: keep it reachable.
			return $data;
		}
		if ( $typed && $public ) {
			$data['post_name'] = '';
			if ( in_array( $status, [ 'publish', 'future', 'private' ], true ) ) {
				$data['post_status'] = 'draft';
				FieldRegistry::add_errors( [ self::message(), __( 'The item was saved as a draft and not published.', 'momos-core' ) ] );
			} else {
				FieldRegistry::add_errors( [ self::message() ] );
			}
			return $data;
		}
		$data['post_name'] = wp_unique_post_slug( $base, $post_id, $status, $type, (int) ( $data['post_parent'] ?? 0 ) );
		if ( $typed ) {
			/* translators: %s: the generated slug */
			FieldRegistry::add_errors( [ self::message(), sprintf( __( 'The slug was replaced by "%s".', 'momos-core' ), $data['post_name'] ) ] );
		} elseif ( $public ) {
			/* translators: %s: the generated slug */
			FieldRegistry::add_errors( [ sprintf( __( 'No Latin URL slug can be formed from the title, so "%s" was used. Enter a descriptive slug with lowercase Latin letters a–z, digits and hyphens (shared by all languages).', 'momos-core' ), $data['post_name'] ) ] );
		}
		return $data;
	}

	/**
	 * Block editor / REST: a typed non-Latin slug answers 400 instead of being changed silently.
	 *
	 * @param \stdClass|\WP_Error $prepared
	 * @param \WP_REST_Request    $request
	 * @return \stdClass|\WP_Error
	 */
	public static function rest_guard( $prepared, $request ) {
		if ( $prepared instanceof \stdClass && isset( $prepared->post_name ) && '' !== (string) $prepared->post_name && ! self::is_latin( sanitize_title( (string) $prepared->post_name ) ) ) {
			return new \WP_Error( 'momos_invalid_slug', self::message(), [ 'status' => 400 ] );
		}
		return $prepared;
	}
}
