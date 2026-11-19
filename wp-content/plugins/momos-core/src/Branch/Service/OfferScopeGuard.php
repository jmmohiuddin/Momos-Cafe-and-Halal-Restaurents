<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\PostType\BranchScopeTaxonomy;

/**
 * M3 scope enforcement for notices and offers on save (spec §5, §4.10/§4.11, TC-ACL-021):
 * a user without `momo_access_all_branches` may only save items scoped to `branches` whose every
 * branch is assigned to them. A crafted request (the UI never offers these choices) is answered
 * with 403 `forbidden_branch` and audited `access.denied`.
 */
final class OfferScopeGuard {
	/** post type => [scope meta key, global scope value, terms field] */
	public const TYPES = [
		'momo_notice' => [ 'momo_notice_scope', 'site_wide', 'momo_notice_branches' ],
		'momo_offer'  => [ 'momo_offer_scope', 'all_branches', 'momo_offer_branches' ],
	];

	public static function register(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'check' ], 6, 2 );
	}

	/**
	 * Runs for the plugin's field submission (`momos_fields`) and for the core taxonomy path
	 * (`tax_input[momo_branch_scope]` of post.php editpost, bulk edit or any wp_insert_post call):
	 * core assigns those terms itself, so they are checked here too (QA-002, TC-ACL-021).
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function check( array $data, array $postarr ): array {
		$type = (string) ( $data['post_type'] ?? '' );
		if ( ! isset( self::TYPES[ $type ] ) || ! is_user_logged_in() ) {
			return $data;
		}
		$has_fields = isset( $_POST['momos_fields'] ) && is_array( $_POST['momos_fields'] ); // phpcs:ignore
		$has_tax    = isset( $postarr['tax_input'] ) && is_array( $postarr['tax_input'] ) && array_key_exists( BranchScopeTaxonomy::TAX, $postarr['tax_input'] );
		if ( ! $has_fields && ! $has_tax ) {
			return $data;
		}
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$fields  = $has_fields ? (array) wp_unslash( $_POST['momos_fields'] ) : []; // phpcs:ignore -- values only compared.
		$error   = $has_fields ? self::error_for_submission( $type, $post_id, $fields ) : null;
		if ( null === $error && $has_tax ) {
			$error = self::error_for_tax_input( $type, $post_id, $fields, $postarr['tax_input'][ BranchScopeTaxonomy::TAX ] );
		}
		if ( null !== $error ) {
			self::forbid( $type, $post_id, $error['branch_id'] );
		}
		return $data;
	}

	/**
	 * M3 check of the terms core would assign from `tax_input` (term IDs, names or slugs, as
	 * wp_set_post_terms() accepts them). A value that is not an existing scope term is refused,
	 * because core would create a new term for it.
	 *
	 * @param array<string,mixed> $fields submitted momos_fields (may be empty)
	 * @param mixed               $value  tax_input value for the scope taxonomy
	 * @return array{code:string,branch_id:?int}|null
	 */
	public static function error_for_tax_input( string $type, int $post_id, array $fields, $value, ?int $user_id = null ): ?array {
		if ( null === momos_user_branch_ids( $user_id ) ) {
			return null;
		}
		$term_ids = self::term_ids_of_tax_input( $value );
		if ( null === $term_ids ) {
			return [ 'code' => 'forbidden_branch', 'branch_id' => null ];
		}
		$fields[ self::TYPES[ $type ][2] ] = $term_ids;
		return self::error_for_submission( $type, $post_id, $fields, $user_id );
	}

	/**
	 * Resolve a tax_input value like wp_set_object_terms() does (ints = IDs, strings = slug or name).
	 *
	 * @param mixed $value
	 * @return list<int>|null null when a value matches no existing scope term
	 */
	private static function term_ids_of_tax_input( $value ): ?array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$ids   = [];
		foreach ( $items as $item ) {
			if ( is_int( $item ) ) {
				$term = get_term( $item, BranchScopeTaxonomy::TAX );
				if ( ! $term instanceof \WP_Term ) {
					return null;
				}
				$ids[] = (int) $term->term_id;
				continue;
			}
			if ( ! is_scalar( $item ) ) {
				return null;
			}
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$found = term_exists( $item, BranchScopeTaxonomy::TAX );
			if ( ! $found ) {
				return null;
			}
			$ids[] = (int) ( is_array( $found ) ? $found['term_id'] : $found );
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<string,mixed> $fields submitted momos_fields
	 * @return array{code:string,branch_id:?int}|null
	 */
	public static function error_for_submission( string $type, int $post_id, array $fields, ?int $user_id = null ): ?array {
		[ $scope_key, $global_value, $terms_key ] = self::TYPES[ $type ];
		$scope                                    = array_key_exists( $scope_key, $fields ) ? (string) $fields[ $scope_key ] : (string) ( $post_id ? get_post_meta( $post_id, $scope_key, true ) : '' );
		$global                                   = $global_value === $scope;
		$term_ids                                 = array_key_exists( $terms_key, $fields ) ? array_map( 'absint', (array) $fields[ $terms_key ] ) : ( $post_id ? (array) wp_get_object_terms( $post_id, BranchScopeTaxonomy::TAX, [ 'fields' => 'ids' ] ) : [] );
		$branches                                 = $global ? [] : BranchScopeTaxonomy::branch_ids_of_terms( array_values( array_filter( array_map( 'intval', $term_ids ) ) ) );
		if ( ! $global && count( $branches ) < count( array_filter( $term_ids ) ) ) {
			return [ 'code' => 'forbidden_branch', 'branch_id' => null ]; // Unknown term IDs.
		}
		$code = BranchAccess::scope_error( $global, $branches, $user_id );
		if ( null === $code ) {
			return null;
		}
		$assigned = momos_user_branch_ids( $user_id ) ?? [];
		$foreign  = array_values( array_diff( $branches, $assigned ) );
		return [ 'code' => $code, 'branch_id' => $foreign[0] ?? null ];
	}

	private static function forbid( string $type, int $post_id, ?int $branch_id ): void {
		BranchAccess::deny( 'momo_access_all_branches', $branch_id, 'momo_notice' === $type ? 'notice' : 'offer', $post_id ?: null, [ 'code' => 'forbidden_branch' ] );
		wp_die( esc_html( momos_t( 'staff.err.forbidden_branch', [], 'en' === substr( get_user_locale(), 0, 2 ) ? 'en' : 'ja' ) ), esc_html__( 'Forbidden', 'momos-core' ), [ 'response' => 403 ] );
	}
}
