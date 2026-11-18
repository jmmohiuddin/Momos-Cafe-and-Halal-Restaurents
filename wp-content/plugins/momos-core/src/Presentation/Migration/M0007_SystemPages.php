<?php
declare(strict_types=1);

namespace Momos\Presentation\Migration;

use Momos\Kernel\Migration\MigrationInterface;
use Momos\Presentation\SystemPages;

/**
 * M0007 — system pages (screens.md §0.1, i18n-seo-analytics.md §3.2/§6.4). Creates the role pages
 * (Must pages published, optional S/C pages contact/faq/gallery/news as drafts; Japanese title, empty body — content is written by Content/Head Office, demo content by
 * `wp momos seed`), the five legal pages as **drafts** (HO publishes after counsel review), stores the
 * role map in `momo_system_pages`, sets the static front page / posts page and flags LCCS pages
 * (`momo_lccs = 1`). Idempotent: existing pages (matched by stored ID or slug) are reused, never
 * overwritten. Revision 2 (`M0007_SystemPages_r2`) re-runs the same idempotent steps to add the C11 status
 * page shell and the noindex flags of C10/C11; rewrite rules are refreshed by TemplateRouter.
 */
final class M0007_SystemPages implements MigrationInterface {
	public const LCCS = [ 'home', 'branches', 'menu', 'halal', 'reservation', 'submitted' ];
	public const LCCS_LEGAL = [ 'privacy', 'res_policy', 'ext_trans' ];

	public function id(): string {
		return 'M0007_SystemPages';
	}

	public function up(): void {
		$map = get_option( SystemPages::OPTION, [] );
		$map = is_array( $map ) ? array_map( 'intval', $map ) : [];
		foreach ( SystemPages::ROLES as $role => [ $slug, $parent_role, $title ] ) {
			$parent = null !== $parent_role ? (int) ( $map[ $parent_role ] ?? 0 ) : 0;
			$id     = (int) ( $map[ $role ] ?? 0 );
			if ( ! $id || 'page' !== get_post_type( $id ) ) {
				// Optional (S/C) pages start as drafts: their nav/footer entries appear once HO publishes them.
				$id = $this->ensure_page( $slug, $title, $parent, in_array( $role, SystemPages::OPTIONAL, true ) ? 'draft' : 'publish' );
			}
			if ( $id ) {
				$map[ $role ] = $id;
				if ( in_array( $role, self::LCCS, true ) ) {
					update_post_meta( $id, 'momo_lccs', '1' );
				}
				if ( in_array( $role, SystemPages::NOINDEX, true ) ) {
					update_post_meta( $id, 'momo_noindex', '1' ); // C10/C11: noindex + X-Robots-Tag (TemplateRouter).
				}
			}
		}
		update_option( SystemPages::OPTION, $map, true );
		SystemPages::flush();

		foreach ( SystemPages::LEGAL as $key => [ , $slug, $title ] ) {
			$id = $this->ensure_page( $slug, $title, 0, 'draft' );
			if ( $id ) {
				update_post_meta( $id, 'momo_page_type', 'legal' );
				if ( in_array( $key, self::LCCS_LEGAL, true ) ) {
					update_post_meta( $id, 'momo_lccs', '1' );
				}
			}
		}

		$front = (int) get_option( 'page_on_front' );
		if ( ! empty( $map['home'] ) && ( 'page' !== get_option( 'show_on_front' ) || ! $front || 'publish' !== get_post_status( $front ) ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) $map['home'] );
		}
		if ( ! empty( $map['news'] ) && ! (int) get_option( 'page_for_posts' ) ) {
			update_option( 'page_for_posts', (int) $map['news'] );
		}
	}

	private function ensure_page( string $slug, string $title, int $parent, string $status ): int {
		$path     = $parent ? get_page_uri( $parent ) . '/' . $slug : $slug;
		$existing = get_page_by_path( $path, OBJECT, 'page' );
		if ( $existing instanceof \WP_Post ) {
			return (int) $existing->ID;
		}
		$id = wp_insert_post(
			[
				'post_type'      => 'page',
				'post_status'    => $status,
				'post_title'     => $title,
				'post_name'      => $slug,
				'post_parent'    => $parent,
				'post_content'   => '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			],
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}
}
