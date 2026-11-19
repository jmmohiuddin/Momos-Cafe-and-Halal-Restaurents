<?php
declare(strict_types=1);

namespace Momos\Presentation\Migration;

use Momos\Kernel\Log\Logger;
use Momos\Kernel\Migration\MigrationInterface;
use Momos\Presentation\SystemPages;

/**
 * M0008 — WordPress install defaults are never published on a Momo's site (QA-030; TC-I18N-013 "no
 * placeholder/fallback text", TC-CORP-004). The "Hello world!" post (`hello-world`) and the "Sample Page"
 * (`sample-page`) that `wp_install_defaults()` creates are set to draft — only while they are still the
 * untouched install defaults: default slug, published, never edited (modified = created), not a system or
 * legal page and not a seeded object. Anything an editor has changed is left alone. Idempotent: a second run
 * finds no published default and does nothing. The demo seeder (`wp momos seed`) applies the same rule with a
 * restorable snapshot; this migration covers real installs that are never seeded.
 */
final class M0008_UnpublishWpDefaults implements MigrationInterface {
	/** post type => default slug created by wp_install_defaults(). */
	public const DEFAULTS = [ 'post' => 'hello-world', 'page' => 'sample-page' ];

	public function id(): string {
		return 'M0008_UnpublishWpDefaults';
	}

	public function up(): void {
		$protected = array_map( 'intval', array_values( SystemPages::map() ) );
		foreach ( array_keys( SystemPages::LEGAL ) as $key ) {
			$protected[] = SystemPages::legal_id( $key );
		}
		foreach ( self::DEFAULTS as $type => $slug ) {
			$posts = get_posts(
				[
					'post_type'        => $type,
					'name'             => $slug,
					'post_status'      => 'publish',
					'post_parent'      => 0,
					'numberposts'      => 1,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => true,
				]
			);
			$post  = $posts[0] ?? null;
			if ( ! $post instanceof \WP_Post || ! self::is_untouched_default( $post, $protected ) ) {
				continue;
			}
			// A status change needs no revision (and must not consume post IDs on a fresh install).
			$had_revisions = remove_action( 'post_updated', 'wp_save_post_revision', 10 );
			wp_update_post(
				[
					'ID'          => (int) $post->ID,
					'post_status' => 'draft',
				]
			);
			if ( $had_revisions ) {
				add_action( 'post_updated', 'wp_save_post_revision', 10, 1 );
			}
			Logger::info(
				'presentation.wp_default_unpublished',
				[
					'post_id' => (int) $post->ID,
					'type'    => $type,
				]
			);
		}
	}

	/** @param list<int> $protected system/legal page IDs */
	public static function is_untouched_default( \WP_Post $post, array $protected ): bool {
		if ( in_array( (int) $post->ID, $protected, true ) || '' !== (string) get_post_meta( (int) $post->ID, '_momo_seed_key', true ) ) {
			return false;
		}
		// Never edited since install: wp_install_defaults() writes the same created and modified time.
		return '' !== (string) $post->post_date_gmt && $post->post_modified_gmt === $post->post_date_gmt;
	}
}
