<?php
declare(strict_types=1);

namespace Momos\Seed;

use Momos\Kernel\Env;
use Momos\Kernel\Fields\FieldRegistry;

/**
 * Central production publish block for sample content (BR-CORP-03, content-strings.md §10.1 D2, DEF-012/QA-024).
 *
 * In production no post flagged `momo_is_sample = 1` (branches, offers, notices, pages, posts and dishes —
 * SampleCheck::TYPES) can be public. The block works on every channel:
 *  - `wp_insert_post_data`: a save with status publish/future (post.php, REST, wp_insert_post with
 *    `meta_input`, quick edit) is stored as a draft;
 *  - `transition_post_status`: publications that skip wp_insert_post_data (wp_publish_post, the scheduled
 *    future → publish cron) are reverted to draft;
 *  - `added_post_meta` / `updated_post_meta`: flagging an already-public post as sample unpublishes it.
 * Each block adds the admin error of the dish/offer rules ("Sample content cannot be published on the live
 * site.") and writes the audit entry `content.sample_publish_blocked` (post type, channel; no content).
 * Dishes and offers keep their own publish validators; this guard is the shared backstop. Outside
 * production the rule is off (demo/staging/E2E sites show the seed); the filter
 * `momos/seed/block_sample_publish` can switch it on (tests, staging rehearsals) but never off in production.
 * `wp momos purge-samples` stays the way to remove sample content.
 */
final class SampleGuard {
	public const AUDIT_ACTION = 'content.sample_publish_blocked';

	private const PUBLIC_STATUSES = [ 'publish', 'future' ];

	/** @var array<int,true> posts already reported in this request (one error + one audit row per post) */
	private static array $reported = [];

	/** Post type of a new post that before_save kept as draft; reported once wp_insert_post knows its ID. */
	private static ?string $pending_new = null;

	public static function register(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'before_save' ], 98, 2 );
		add_action( 'transition_post_status', [ self::class, 'on_transition' ], 1, 3 );
		add_action( 'wp_insert_post', [ self::class, 'after_insert' ], 1, 2 );
		add_action( 'added_post_meta', [ self::class, 'on_meta' ], 10, 4 );
		add_action( 'updated_post_meta', [ self::class, 'on_meta' ], 10, 4 );
	}

	/** Whether sample content must stay unpublished (always in production). */
	public static function applies(): bool {
		return Env::is_production() || (bool) apply_filters( 'momos/seed/block_sample_publish', false );
	}

	public static function message(): string {
		return __( 'Sample content cannot be published on the live site.', 'momos-core' );
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function before_save( array $data, array $postarr ): array {
		if ( ! in_array( (string) ( $data['post_status'] ?? '' ), self::PUBLIC_STATUSES, true ) || ! self::guarded_type( (string) ( $data['post_type'] ?? '' ) ) || ! self::applies() ) {
			return $data;
		}
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$meta    = isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ? $postarr['meta_input'] : [];
		$sample  = array_key_exists( Writer::SAMPLE_META, $meta )
			? '1' === (string) $meta[ Writer::SAMPLE_META ]
			: ( $post_id > 0 && self::is_sample( $post_id ) );
		if ( ! $sample ) {
			return $data;
		}
		$data['post_status'] = 'draft';
		if ( $post_id > 0 ) {
			self::report( $post_id, (string) $data['post_type'], 'save' );
		} else {
			self::$pending_new = (string) $data['post_type'];
		}
		return $data;
	}

	/** Reports a new post blocked in before_save, now that it has an ID. */
	public static function after_insert( int $post_id, \WP_Post $post ): void {
		if ( null === self::$pending_new || self::$pending_new !== $post->post_type ) {
			return;
		}
		self::$pending_new = null;
		self::report( $post_id, $post->post_type, 'save' );
	}

	public static function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( ! in_array( $new_status, self::PUBLIC_STATUSES, true ) || ! self::guarded_type( $post->post_type ) || ! self::applies() || ! self::is_sample( $post->ID ) ) {
			return;
		}
		self::unpublish( $post->ID );
		self::report( $post->ID, $post->post_type, 'publish' );
	}

	/** @param mixed $value */
	public static function on_meta( int $meta_id, int $object_id, string $meta_key, $value ): void {
		if ( Writer::SAMPLE_META !== $meta_key || '1' !== (string) ( is_scalar( $value ) ? $value : '' ) || ! self::applies() ) {
			return;
		}
		$post = get_post( $object_id );
		if ( ! $post || ! self::guarded_type( $post->post_type ) || ! in_array( $post->post_status, self::PUBLIC_STATUSES, true ) ) {
			return;
		}
		self::unpublish( $post->ID );
		self::report( $post->ID, $post->post_type, 'flag' );
	}

	public static function is_sample( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, Writer::SAMPLE_META, true );
	}

	private static function guarded_type( string $post_type ): bool {
		return in_array( $post_type, SampleCheck::TYPES, true );
	}

	/** Back to draft without re-running the save pipeline (no nested wp_update_post inside a transition). */
	private static function unpublish( int $post_id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'draft' ], [ 'ID' => $post_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post_id );
	}

	private static function report( int $post_id, string $post_type, string $channel ): void {
		if ( isset( self::$reported[ $post_id ] ) ) {
			return;
		}
		self::$reported[ $post_id ] = true;
		FieldRegistry::add_errors( [ self::message() ] );
		momos_audit( self::AUDIT_ACTION, $post_type, $post_id, 'momo_branch' === $post_type ? $post_id : null, [ 'channel' => $channel, 'environment' => Env::name() ] );
	}

	/** Test helper: forget which posts were reported in this request. */
	public static function reset(): void {
		self::$reported    = [];
		self::$pending_new = null;
	}
}
