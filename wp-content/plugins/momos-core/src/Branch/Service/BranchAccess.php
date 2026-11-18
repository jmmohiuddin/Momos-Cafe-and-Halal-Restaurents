<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\PostType\BranchScopeTaxonomy;

/**
 * Branch-scope permission rules of the Branch module (spec §5, TRD §2.7.3).
 *
 *  - M2 `momo_edit_branch(branch_id, aspect)` ≡ aspect primitive + assigned(user, branch).
 *  - M1 `edit_post` on a branch for scoped users requires assignment.
 *  - M3 notice/offer: users without `momo_access_all_branches` may only touch items scoped to
 *    `branches` whose every scope term is an assigned branch.
 *
 * The Access module owns the central MetaCapMapper (SEC-010); the map_meta_cap filter below only
 * ever *removes* rights (adds `do_not_allow`), so both layers can coexist safely.
 */
final class BranchAccess {
	/** Aspect → primitive capability (spec §3.2 legend). */
	public const ASPECT_CAPS = [
		'identity' => 'momo_edit_branch_identity',
		'settings' => 'momo_edit_branch_settings',
		'hours'    => 'momo_edit_branch_hours',
		'content'  => 'edit_momo_branches',
	];

	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map_meta_cap' ], 20, 4 );
	}

	/** May the user edit an aspect of the branch (M2)? */
	public static function can_edit( int $branch_id, string $aspect, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		$cap     = self::ASPECT_CAPS[ $aspect ] ?? null;
		if ( ! $user_id || null === $cap || ! self::is_branch( $branch_id ) ) {
			return false;
		}
		return user_can( $user_id, $cap ) && momos_user_can_branch( $branch_id, $user_id );
	}

	/** May the user view branch data (any staff with a branch-related capability, scoped). */
	public static function can_view( int $branch_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id || ! self::is_branch( $branch_id ) || ! self::is_branch_staff( $user_id ) ) {
			return false;
		}
		return momos_user_can_branch( $branch_id, $user_id );
	}

	/** Staff roles that see branch data in admin (all roles except Translator, spec §5). */
	public static function is_branch_staff( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		foreach ( [ 'momo_edit_branch_hours', 'edit_momo_branches', 'momo_access_all_branches', 'momo_view_reservations' ] as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Branches the user may select in admin screens, as post IDs (all branch statuses).
	 *
	 * @return list<int>
	 */
	public static function visible_branch_ids( ?int $user_id = null ): array {
		$scope = momos_user_branch_ids( $user_id );
		$args  = [
			'post_type'      => 'momo_branch',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => 200,
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];
		if ( null !== $scope ) {
			if ( ! $scope ) {
				return [];
			}
			$args['post__in'] = $scope;
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	public static function is_branch( int $branch_id ): bool {
		return $branch_id > 0 && 'momo_branch' === get_post_type( $branch_id );
	}

	/** Record a denial (SEC-012/013); never includes request values. */
	public static function deny( string $capability, ?int $branch_id, string $object_type, ?int $object_id, array $context = [] ): void {
		$user = wp_get_current_user();
		$data = [
			'capability' => $capability,
			'role'       => $user && $user->roles ? implode( ',', $user->roles ) : '',
		] + $context;
		momos_audit( 'access.denied', $object_type, $object_id, $branch_id, $data );
		do_action( 'momos/access/denied', [ 'user_id' => get_current_user_id(), 'capability' => $capability, 'branch_id' => $branch_id, 'object_type' => $object_type, 'object_id' => $object_id ] + $context );
	}

	/**
	 * Scope check for a notice/offer about to be saved (M3). Returns null when allowed or
	 * 'forbidden_branch'.
	 *
	 * @param list<int> $branch_ids branches the item is scoped to ([] with a global scope)
	 */
	public static function scope_error( bool $global_scope, array $branch_ids, ?int $user_id = null ): ?string {
		$assigned = momos_user_branch_ids( $user_id );
		if ( null === $assigned ) {
			return null;
		}
		if ( $global_scope ) {
			return 'forbidden_branch';
		}
		foreach ( $branch_ids as $b ) {
			if ( ! in_array( (int) $b, $assigned, true ) ) {
				return 'forbidden_branch';
			}
		}
		return null;
	}

	/**
	 * @param list<string> $caps
	 * @param array<int,mixed> $args
	 * @return list<string>
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, [ 'edit_post', 'delete_post', 'publish_post', 'read_post' ], true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( ! $post || ! in_array( $post->post_type, [ 'momo_branch', 'momo_notice', 'momo_offer' ], true ) ) {
			return $caps;
		}
		$scope = momos_user_branch_ids( $user_id );
		if ( null === $scope ) {
			return $caps;
		}
		if ( 'momo_branch' === $post->post_type ) {
			if ( ! in_array( (int) $post->ID, $scope, true ) ) {
				$caps[] = 'do_not_allow';
			}
			return $caps;
		}
		if ( 'auto-draft' === $post->post_status ) {
			return $caps;
		}
		// Notices/offers (M3): global scope or any unassigned branch → not for scoped users.
		$scope_key = 'momo_notice' === $post->post_type ? 'momo_notice_scope' : 'momo_offer_scope';
		$global    = in_array( (string) get_post_meta( $post->ID, $scope_key, true ), [ 'site_wide', 'all_branches' ], true );
		$branches  = BranchScopeTaxonomy::branch_ids_of_post( $post->ID );
		if ( 'read_post' === $cap ) {
			// Reading: any overlap with own branches is enough; global items are readable.
			if ( ! $global && $branches && ! array_intersect( $branches, $scope ) ) {
				$caps[] = 'do_not_allow';
			}
			return $caps;
		}
		$own_unscoped_draft = ! $branches && 'draft' === $post->post_status && (int) $post->post_author === $user_id;
		if ( $global || ( ! $branches && ! $own_unscoped_draft ) || array_diff( $branches, $scope ) ) {
			$caps[] = 'do_not_allow';
		}
		return $caps;
	}
}
