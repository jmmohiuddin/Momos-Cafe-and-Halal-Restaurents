<?php
declare(strict_types=1);

namespace Momos\Seed;

/**
 * Go-live check `wp momos content sample-check` (content-strings.md §10.1 D7, Doc 11 CMP-044/045):
 * lists every item flagged `momo_is_sample = 1` (posts in any status, menu categories) and every
 * demo image (`momo_image_illustrative = 1`) used outside the allowed dish/offer contexts (D3/D4:
 * featured image of other content, branch galleries, the gallery page). Exit code 1 when a sample item
 * is published or a demo image is misused (target 0 before launch). Read-only; database checks only
 * (the crawl of rendered pages/JSON-LD/sitemaps for sample names is not part of this command).
 */
final class SampleCheck {
	/** Post types that can carry sample content. */
	public const TYPES = [ 'momo_branch', 'momo_dish', 'momo_offer', 'momo_notice', 'page', 'post' ];

	/** Contexts where demo images are allowed (D3). */
	public const IMAGE_OK_TYPES = [ 'momo_dish', 'momo_offer' ];

	public static function register(): void {
		\WP_CLI::add_command( 'momos content sample-check', [ self::class, 'run' ] );
	}

	/**
	 * List sample content and misused demo images; exit 1 if anything sample is public.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param list<string>         $args
	 * @param array<string,string> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		$rows   = self::findings();
		$public = count( array_filter( $rows, static fn( array $r ) => 'yes' === $r['blocking'] ) );
		$format = (string) ( $assoc['format'] ?? 'table' );
		if ( 'count' === $format ) {
			\WP_CLI::line( (string) $public );
		} elseif ( $rows ) {
			\WP_CLI\Utils\format_items( in_array( $format, [ 'table', 'csv', 'json' ], true ) ? $format : 'table', $rows, [ 'kind', 'type', 'id', 'status', 'title', 'issue', 'blocking' ] );
		}
		if ( $public > 0 ) {
			\WP_CLI::warning( sprintf( '%d published sample item(s) or misused demo image(s). Remove them before go-live (wp momos seed --reset for seeded demo data).', $public ) );
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( sprintf( 'No published sample content (%d unpublished sample item(s) listed).', count( $rows ) ) );
	}

	/** @return list<array{kind:string,type:string,id:int,status:string,title:string,issue:string,blocking:string}> */
	public static function findings(): array {
		$out = [];
		$ids = get_posts(
			[
				'post_type'        => self::TYPES,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => Writer::SAMPLE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		foreach ( $ids as $id ) {
			$status = (string) get_post_status( (int) $id );
			$out[]  = self::row( 'item', (string) get_post_type( (int) $id ), (int) $id, $status, get_the_title( (int) $id ), 'sample item', 'publish' === $status );
		}

		$terms = get_terms( [ 'taxonomy' => \Momos\Menu\ContentTypes::CATEGORY, 'hide_empty' => false, 'meta_key' => Writer::SAMPLE_META, 'meta_value' => '1' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			$out[] = self::row( 'item', $t->taxonomy, (int) $t->term_id, 'term', $t->name, 'sample category', true );
		}

		$images = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'momo_image_illustrative', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);
		if ( ! $images ) {
			return $out;
		}
		$images = array_map( 'intval', $images );
		global $wpdb;
		$in = implode( ',', $images );
		// Featured image of content other than dishes and offers (branches, pages, posts, notices).
		$thumbs = $wpdb->get_results( "SELECT p.ID, p.post_type, p.post_status, p.post_title, m.meta_value AS img FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_thumbnail_id' AND m.meta_value IN ({$in})", ARRAY_A ); // phpcs:ignore -- integer list.
		foreach ( (array) $thumbs as $r ) {
			if ( ! in_array( $r['post_type'], self::IMAGE_OK_TYPES, true ) && 'revision' !== $r['post_type'] ) {
				$out[] = self::row( 'image', (string) $r['post_type'], (int) $r['ID'], (string) $r['post_status'], (string) $r['post_title'], 'demo image ' . (int) $r['img'] . ' as featured image', 'publish' === $r['post_status'] );
			}
		}
		// Branch galleries (D4).
		$galleries = $wpdb->get_results( "SELECT p.ID, p.post_status, p.post_title, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'momo_gallery' AND p.post_type = 'momo_branch'", ARRAY_A ); // phpcs:ignore
		foreach ( (array) $galleries as $r ) {
			$value = maybe_unserialize( $r['meta_value'] );
			$used  = array_intersect( $images, array_map( 'intval', is_array( $value ) ? $value : explode( ',', (string) $value ) ) );
			if ( $used ) {
				$out[] = self::row( 'image', 'momo_branch', (int) $r['ID'], (string) $r['post_status'], (string) $r['post_title'], 'demo image(s) ' . implode( ',', $used ) . ' in branch gallery', true );
			}
		}
		// Gallery page (C22).
		foreach ( $images as $img ) {
			if ( '1' === (string) get_post_meta( $img, 'momo_gallery_include', true ) ) {
				$out[] = self::row( 'image', 'attachment', $img, 'inherit', get_the_title( $img ), 'demo image on the gallery page', true );
			}
		}
		return $out;
	}

	/** @return array{kind:string,type:string,id:int,status:string,title:string,issue:string,blocking:string} */
	private static function row( string $kind, string $type, int $id, string $status, string $title, string $issue, bool $blocking ): array {
		return [ 'kind' => $kind, 'type' => $type, 'id' => $id, 'status' => $status, 'title' => $title, 'issue' => $issue, 'blocking' => $blocking ? 'yes' : 'no' ];
	}
}
