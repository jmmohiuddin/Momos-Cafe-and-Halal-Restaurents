<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Url;
use Momos\Menu\ContentTypes;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Support\CachePurge;

/**
 * Dish lifecycle (FR-MENU-004/009, spec §3.8): draft → pending → publish → retire.
 * Retire = status draft + `momo_retired = 1` + every branch row `is_offered = 0` in one transaction,
 * audited `dish.retired`; permalinks recorded for 301s. A dish that was ever published, has offered
 * rows or published variants is never hard-deleted. Price changes are audited
 * `dish.reference_price_changed`; edits of published dishes `dish.updated`.
 */
final class DishLifecycle {
	/** @var array<string,mixed> old meta values captured before update */
	private static array $old = [];

	public static function register(): void {
		add_action( 'transition_post_status', [ self::class, 'on_transition' ], 10, 3 );
		add_filter( 'pre_delete_post', [ self::class, 'guard_delete' ], 10, 2 );
		add_filter( 'pre_trash_post', [ self::class, 'guard_delete' ], 10, 2 );
		add_filter( 'map_meta_cap', [ self::class, 'map_delete_cap' ], 10, 4 );
		add_filter( 'update_post_metadata', [ self::class, 'before_meta_update' ], 50, 5 );
		add_action( 'updated_post_meta', [ self::class, 'after_meta_update' ], 10, 4 );
		add_action( 'added_post_meta', [ self::class, 'after_meta_update' ], 10, 4 );
		add_action( 'post_updated', [ self::class, 'on_post_updated' ], 10, 3 );
		add_action( 'momos/fields/saved', [ self::class, 'on_fields_saved' ], 10, 5 );
	}

	/**
	 * Retire a published dish.
	 *
	 * @throws \RuntimeException with a staff message
	 */
	public static function retire( int $dish_id, int $user_id ): void {
		$post = get_post( $dish_id );
		if ( ! $post || ContentTypes::DISH !== $post->post_type ) {
			throw new \RuntimeException( __( 'Dish not found.', 'momos-core' ) );
		}
		if ( ! user_can( $user_id, 'publish_momo_dishes' ) ) {
			momos_audit( 'access.denied', 'dish', $dish_id, null, [ 'reason' => 'retire_without_publish_cap' ] );
			throw new \RuntimeException( __( 'You do not have permission to retire dishes.', 'momos-core' ) );
		}
		if ( '1' === (string) get_post_meta( $dish_id, 'momo_retired', true ) ) {
			return;
		}
		$variants = self::published_variant_count( $dish_id );
		if ( $variants > 0 ) {
			/* translators: %d: number of variants */
			throw new \RuntimeException( sprintf( __( 'Retire or move the %d variants first.', 'momos-core' ), $variants ) );
		}
		$paths = [];
		foreach ( Lang::codes() as $lang ) {
			$paths[ $lang ] = Url::localize( (string) get_permalink( $dish_id ), $lang );
		}
		Transaction::run(
			static function () use ( $dish_id, $user_id, $paths, $post ): void {
				$ended = BranchMenuItemRepository::end_offers_for_dish( $dish_id, $user_id );
				update_post_meta( $dish_id, '_momo_retired_paths', $paths );
				update_post_meta( $dish_id, 'momo_retired', '1' );
				update_post_meta( $dish_id, '_momos_was_published', 1 );
				$GLOBALS['momos_dish_retiring'] = true;
				wp_update_post( [ 'ID' => $dish_id, 'post_status' => 'draft', 'post_name' => $post->post_name ] );
				unset( $GLOBALS['momos_dish_retiring'] );
				momos_audit( 'dish.retired', 'dish', $dish_id, null, [ 'branch_rows_ended' => array_map( static fn( $r ) => (int) $r['branch_id'], $ended ) ] );
				AfterCommit::dispatch( 'momos/dish/retired', $dish_id );
			}
		);
		CachePurge::dish( $dish_id );
	}

	public static function published_variant_count( int $dish_id ): int {
		return count( get_posts( [ 'post_type' => ContentTypes::DISH, 'post_parent' => $dish_id, 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 500 ] ) );
	}

	/** Reason a dish cannot be deleted, or null. */
	public static function delete_block_reason( int $dish_id ): ?string {
		$variants = self::published_variant_count( $dish_id );
		if ( $variants > 0 ) {
			/* translators: %d: number of variants */
			return sprintf( __( 'Retire or move the %d variants first.', 'momos-core' ), $variants );
		}
		$post = get_post( $dish_id );
		if ( ( $post && 'publish' === $post->post_status ) || get_post_meta( $dish_id, '_momos_was_published', true ) || '1' === (string) get_post_meta( $dish_id, 'momo_retired', true ) ) {
			return __( 'Published dishes cannot be deleted. Retire the dish instead.', 'momos-core' );
		}
		if ( BranchMenuItemRepository::count_offered_rows_for_dish( $dish_id ) > 0 ) {
			return __( 'This dish is offered at a branch. Remove it from the branch menus first.', 'momos-core' );
		}
		return null;
	}

	/**
	 * @param mixed $check
	 * @return mixed false blocks
	 */
	public static function guard_delete( $check, $post ) {
		if ( null !== $check || ! $post instanceof \WP_Post || ContentTypes::DISH !== $post->post_type ) {
			return $check;
		}
		$reason = self::delete_block_reason( (int) $post->ID );
		if ( null !== $reason ) {
			\Momos\Kernel\Fields\FieldRegistry::add_errors( [ $reason ] );
			return false;
		}
		return $check;
	}

	/** @param list<string> $caps @param array<int,mixed> $args @return list<string> */
	public static function map_delete_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_post' === $cap && isset( $args[0] ) ) {
			$post = get_post( (int) $args[0] );
			if ( $post && ContentTypes::DISH === $post->post_type && null !== self::delete_block_reason( (int) $post->ID ) ) {
				return [ 'do_not_allow' ];
			}
		}
		return $caps;
	}

	public static function on_transition( string $new, string $old, \WP_Post $post ): void {
		if ( ContentTypes::DISH !== $post->post_type || $new === $old ) {
			return;
		}
		if ( 'publish' === $new ) {
			update_post_meta( $post->ID, '_momos_was_published', 1 );
			if ( '1' === (string) get_post_meta( $post->ID, 'momo_retired', true ) ) {
				update_post_meta( $post->ID, 'momo_retired', '0' ); // Re-published after retirement.
			}
			momos_audit( 'dish.published', 'dish', $post->ID, null, [ 'from' => $old ] );
			AfterCommit::dispatch( 'momos/dish/published', (int) $post->ID );
			CachePurge::dish( (int) $post->ID );
		} elseif ( 'publish' === $old && empty( $GLOBALS['momos_dish_retiring'] ) ) {
			momos_audit( 'dish.updated', 'dish', $post->ID, null, [ 'status' => [ $old, $new ] ] );
			CachePurge::dish( (int) $post->ID );
		}
	}

	/** @param mixed $check */
	public static function before_meta_update( $check, int $object_id, string $meta_key, $meta_value, $prev_value ) {
		if ( null !== $check || ! in_array( $meta_key, [ 'momo_reference_price_jpy', 'momo_dish_sku' ], true ) || ContentTypes::DISH !== get_post_type( $object_id ) ) {
			return $check;
		}
		$old = (string) get_post_meta( $object_id, $meta_key, true );
		if ( 'momo_dish_sku' === $meta_key && '' !== $old && $old !== (string) $meta_value && get_post_meta( $object_id, '_momos_was_published', true ) ) {
			\Momos\Kernel\Fields\FieldRegistry::add_errors( [ __( 'Dish code cannot be changed after publication.', 'momos-core' ) ] );
			return false; // DEC-052: immutable after first publish (any write path).
		}
		self::$old[ $object_id . ':' . $meta_key ] = $old;
		return $check;
	}

	/** @param mixed $value */
	public static function after_meta_update( $meta_id, int $object_id, string $meta_key, $value ): void {
		if ( 'momo_reference_price_jpy' !== $meta_key || ContentTypes::DISH !== get_post_type( $object_id ) ) {
			return;
		}
		$k      = $object_id . ':' . $meta_key;
		$before = self::$old[ $k ] ?? '';
		unset( self::$old[ $k ] );
		if ( (string) $before === (string) $value ) {
			return;
		}
		momos_audit( 'dish.reference_price_changed', 'dish', $object_id, null, [ 'before' => '' === $before ? null : (int) $before, 'after' => '' === (string) $value ? null : (int) $value ] );
		CachePurge::dish( $object_id );
	}

	public static function on_post_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( ContentTypes::DISH !== $after->post_type || 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}
		$changed = [];
		foreach ( [ 'post_title', 'post_content', 'post_name', 'menu_order', 'post_parent' ] as $f ) {
			if ( (string) $before->$f !== (string) $after->$f ) {
				$changed[] = $f;
			}
		}
		if ( $changed ) {
			momos_audit( 'dish.updated', 'dish', $post_id, null, [ 'fields' => $changed ] );
			CachePurge::dish( $post_id );
		}
	}

	/** @param array<string,array{0:mixed,1:mixed}> $changes */
	public static function on_fields_saved( string $type, $object_id, string $group_key, array $changes, int $user_id ): void {
		if ( 'post' !== $type || \Momos\Menu\Fields\MenuFields::G8 !== $group_key || ! $changes ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( $post && 'publish' === $post->post_status ) {
			momos_audit( 'dish.updated', 'dish', (int) $object_id, null, [ 'fields' => array_keys( $changes ) ] );
			CachePurge::dish( (int) $object_id );
		}
	}
}
