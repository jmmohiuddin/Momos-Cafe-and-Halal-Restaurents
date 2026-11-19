<?php
declare(strict_types=1);

namespace Momos\Seed;

use Momos\Kernel\Db\Tables;
use Momos\Menu\ContentTypes;
use Momos\Seed\Data\People;

/**
 * `wp momos seed --reset` (content-strings.md §10 D8, qa.md §3.3): removes ONLY what the demo seed
 * created — objects carrying `_momo_seed_key` + `momo_is_sample = 1`, sample reservations (fixed
 * idempotency-key prefix) with their events/notifications, the seeded branches' hours/closures/menu
 * rows and staff assignments — and restores the snapshot of every pre-existing page and setting the
 * seed changed. Real content (no seed key) is never touched. The audit log is append-only and keeps
 * its entries. Refused in production.
 */
final class Resetter {
	/** @var array<string,int> */
	private array $counts = [];

	/** @return array<string,int> */
	public function run(): array {
		DemoSeeder::assert_allowed();
		global $wpdb;
		$previous_user = get_current_user_id();
		$admin         = DemoSeeder::admin_id();
		wp_set_current_user( $admin );
		try {
			$branches = $this->seeded_posts( 'momo_branch' );
			$dishes   = $this->seeded_posts( ContentTypes::DISH );

			// 1. Sample reservations (+ events, notifications; FK order).
			$res_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Tables::reservations() . ' WHERE idempotency_key LIKE %s', $wpdb->esc_like( People::RES_KEY_PREFIX ) . '%' ) ) ); // phpcs:ignore
			if ( $res_ids ) {
				$in = implode( ',', $res_ids );
				$wpdb->query( 'DELETE FROM ' . Tables::notifications() . " WHERE reservation_id IN ({$in})" ); // phpcs:ignore -- integer list.
				$wpdb->query( 'DELETE FROM ' . Tables::events() . " WHERE reservation_id IN ({$in})" ); // phpcs:ignore
				$this->counts['reservations'] = (int) $wpdb->query( 'DELETE FROM ' . Tables::reservations() . " WHERE id IN ({$in})" ); // phpcs:ignore
			}

			// 2. Branch-menu rows, hours, closures and assignments of seeded branches / dishes.
			if ( $branches || $dishes ) {
				$where = [];
				if ( $branches ) {
					$where[] = 'branch_id IN (' . implode( ',', $branches ) . ')';
				}
				if ( $dishes ) {
					$where[] = 'dish_id IN (' . implode( ',', $dishes ) . ')';
				}
				$this->counts['branch_menu_rows'] = (int) $wpdb->query( 'DELETE FROM ' . Tables::menu_items() . ' WHERE ' . implode( ' OR ', $where ) ); // phpcs:ignore
			}
			if ( $branches ) {
				$in = implode( ',', $branches );
				$wpdb->query( 'DELETE FROM ' . Tables::hours() . " WHERE branch_id IN ({$in})" ); // phpcs:ignore
				$wpdb->query( 'DELETE FROM ' . Tables::closures() . " WHERE branch_id IN ({$in})" ); // phpcs:ignore
				$wpdb->query( 'DELETE FROM ' . Tables::user_branches() . " WHERE branch_id IN ({$in})" ); // phpcs:ignore
			}

			// 3. Posts: offers, notices, dishes, branches (guards pass once their data rows are gone; the
			// dish guard blocks deleting any once-published dish, so it is bypassed for seeded samples only).
			foreach ( [ 'momo_offer', 'momo_notice' ] as $type ) {
				foreach ( $this->seeded_posts( $type ) as $id ) {
					$this->delete_post( $id, $type );
				}
			}
			$guard = [ \Momos\Menu\Service\DishLifecycle::class, 'guard_delete' ];
			$had   = has_filter( 'pre_delete_post', $guard );
			if ( false !== $had ) {
				remove_filter( 'pre_delete_post', $guard, (int) $had );
			}
			try {
				foreach ( $dishes as $id ) {
					$this->delete_post( $id, ContentTypes::DISH );
				}
			} finally {
				if ( false !== $had ) {
					add_filter( 'pre_delete_post', $guard, (int) $had, 2 );
				}
			}
			foreach ( $branches as $id ) {
				$this->delete_scope_term( $id );
				$this->delete_post( $id, 'momo_branch' );
			}

			// 4. Terms (categories, tags, ingredients) — empty once the sample dishes are gone.
			foreach ( [ ContentTypes::CATEGORY, ContentTypes::TAG, ContentTypes::INGREDIENT ] as $tax ) {
				$ids = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids', 'meta_key' => Writer::KEY_META, 'meta_compare' => 'EXISTS' ] ); // phpcs:ignore
				foreach ( is_array( $ids ) ? $ids : [] as $tid ) {
					if ( '1' === (string) get_term_meta( (int) $tid, Writer::SAMPLE_META, true ) ) {
						$res = wp_delete_term( (int) $tid, $tax );
						if ( true === $res ) {
							$this->counts['terms'] = ( $this->counts['terms'] ?? 0 ) + 1;
						}
					}
				}
			}

			// 5. Images.
			foreach ( $this->seeded_posts( 'attachment' ) as $id ) {
				if ( wp_delete_attachment( $id, true ) ) {
					$this->counts['images'] = ( $this->counts['images'] ?? 0 ) + 1;
				}
			}

			// 6. Demo users (their content, if any, is reassigned to the administrator).
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$users = get_users( [ 'meta_key' => Writer::KEY_META, 'meta_compare' => 'EXISTS', 'fields' => 'ID' ] ); // phpcs:ignore
			foreach ( $users as $uid ) {
				$uid = (int) $uid;
				if ( '1' !== (string) get_user_meta( $uid, Writer::SAMPLE_META, true ) || $uid === $admin ) {
					continue;
				}
				$wpdb->delete( Tables::user_branches(), [ 'user_id' => $uid ], [ '%d' ] );
				if ( wp_delete_user( $uid, $admin ) ) {
					$this->counts['users'] = ( $this->counts['users'] ?? 0 ) + 1;
				}
			}

			// 7. Restore pages and settings edited by the seed.
			$this->restore();
		} finally {
			wp_set_current_user( $previous_user );
		}
		\Momos\Branch\Service\ScheduleService::flush();
		if ( class_exists( \Momos\Kernel\Cache\CacheInvalidator::class ) ) {
			\Momos\Kernel\Cache\CacheInvalidator::purge_all();
		}
		wp_cache_flush();
		flush_rewrite_rules( false );
		return $this->counts;
	}

	/** @return list<int> IDs of posts of $type created by the seed (seed key + sample flag). */
	private function seeded_posts( string $type ): array {
		$ids = get_posts(
			[
				'post_type'        => $type,
				'post_status'      => 'attachment' === $type ? 'inherit' : [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery
					[ 'key' => Writer::KEY_META, 'compare' => 'EXISTS' ],
					[ 'key' => Writer::SAMPLE_META, 'value' => '1' ],
				],
			]
		);
		return array_map( 'intval', $ids );
	}

	private function delete_post( int $id, string $type ): void {
		if ( wp_delete_post( $id, true ) ) {
			$this->counts[ $type ] = ( $this->counts[ $type ] ?? 0 ) + 1;
			return;
		}
		throw new SeedException( "Could not delete sample {$type} {$id}: " . implode( ' | ', \Momos\Kernel\Fields\FieldRegistry::last_errors() ) );
	}

	private function delete_scope_term( int $branch_id ): void {
		if ( ! class_exists( \Momos\Branch\PostType\BranchScopeTaxonomy::class ) ) {
			return;
		}
		$term = \Momos\Branch\PostType\BranchScopeTaxonomy::term_for_branch( $branch_id );
		if ( $term ) {
			wp_delete_term( $term->term_id, \Momos\Branch\PostType\BranchScopeTaxonomy::TAX );
		}
	}

	private function restore(): void {
		global $wpdb;
		$m = DemoSeeder::manifest();
		foreach ( (array) ( $m['pages'] ?? [] ) as $id => $snap ) {
			$id = (int) $id;
			if ( ! get_post( $id ) ) {
				continue;
			}
			wp_update_post( wp_slash( [ 'ID' => $id, 'post_title' => $snap['post_title'], 'post_content' => $snap['post_content'], 'post_excerpt' => $snap['post_excerpt'], 'post_status' => $snap['post_status'] ] ) );
			foreach ( (array) get_post_meta( $id ) as $k => $vals ) {
				if ( str_starts_with( (string) $k, '_momos_i18n_' ) || in_array( $k, [ 'momo_faq_items', 'momo_legal_version', 'momo_legal_last_updated', Writer::SAMPLE_META ], true ) ) {
					delete_post_meta( $id, (string) $k );
				}
			}
			foreach ( (array) $snap['meta'] as $k => $v ) {
				update_post_meta( $id, (string) $k, wp_slash( maybe_unserialize( $v ) ) );
			}
			// Revisions written while the page held sample text (and by this restore) are removed.
			$revs = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d AND ID > %d", $id, (int) ( $snap['rev_max'] ?? 0 ) ) ); // phpcs:ignore
			foreach ( $revs as $rev ) {
				wp_delete_post_revision( (int) $rev );
			}
			$this->counts['pages_restored'] = ( $this->counts['pages_restored'] ?? 0 ) + 1;
		}
		foreach ( (array) ( $m['options'] ?? [] ) as $page => $keys ) {
			$values = \Momos\Kernel\Settings::page( (string) $page );
			foreach ( (array) $keys as $k => $snap ) {
				if ( $snap['exists'] ) {
					$values[ $k ] = $snap['value'];
				} else {
					unset( $values[ $k ] );
				}
			}
			\Momos\Kernel\Settings::save_page( (string) $page, $values );
			$i18n = get_option( 'momos_option_i18n', [] );
			$i18n = is_array( $i18n ) ? $i18n : [];
			if ( null === ( $m['option_i18n'][ $page ] ?? null ) ) {
				unset( $i18n[ $page ] );
			} else {
				$i18n[ $page ] = $m['option_i18n'][ $page ];
			}
			update_option( 'momos_option_i18n', $i18n, true );
			$this->counts['settings_restored'] = ( $this->counts['settings_restored'] ?? 0 ) + 1;
		}
		foreach ( (array) ( $m['term_i18n'] ?? [] ) as $term_id => $langs ) {
			foreach ( (array) $langs as $lang ) {
				foreach ( [ '', 'status_', 'srchash_', 'updated_', 'approved_by_', 'translated_by_' ] as $part ) {
					delete_term_meta( (int) $term_id, '_momos_i18n_' . $part . $lang );
				}
			}
			$this->counts['reference_translations_removed'] = ( $this->counts['reference_translations_removed'] ?? 0 ) + count( (array) $langs );
		}
		if ( ! empty( $m['consent_keys'] ) ) {
			$used = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::reservations() . " WHERE consent_version IN ('" . implode( "','", array_map( 'esc_sql', (array) $m['consent_keys'] ) ) . "')" ); // phpcs:ignore
			if ( 0 === $used ) {
				$rows = get_option( \Momos\Privacy\Consent\ConsentRegistry::OPTION, [] );
				$rows = array_values( array_filter( is_array( $rows ) ? $rows : [], static fn( $r ) => ! is_array( $r ) || ! in_array( (string) ( $r['consent_key'] ?? '' ), (array) $m['consent_keys'], true ) ) );
				update_option( \Momos\Privacy\Consent\ConsentRegistry::OPTION, $rows, false );
			}
		}
		delete_option( DemoSeeder::MANIFEST );
	}
}
