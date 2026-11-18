<?php
declare(strict_types=1);

namespace Momos\Branch\PostType;

use Momos\Branch\Domain\OperatingStatus;

/**
 * System taxonomy `momo_branch_scope` (spec §3.1): one term per branch, slug = lower-case branch
 * code, term meta `momo_branch_id`; hidden (`momo_scope_hidden = 1`) when the branch is closed
 * permanently. Terms are never edited by hand (show_ui false); BranchScopeSync keeps them in step.
 */
final class BranchScopeTaxonomy {
	public const TAX = 'momo_branch_scope';

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_taxonomy' ], 6 );
		add_action( 'save_post', [ self::class, 'on_save_post' ], 30, 2 );
		add_action( 'added_post_meta', [ self::class, 'on_meta' ], 10, 3 );
		add_action( 'updated_post_meta', [ self::class, 'on_meta' ], 10, 3 );
		add_filter( 'momos/fields/taxonomy_choices', [ self::class, 'choices' ], 10, 2 );
	}

	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAX,
			[ PostTypes::NOTICE, PostTypes::OFFER ],
			[
				'labels'            => [ 'name' => __( 'Branch scope', 'momos-core' ), 'singular_name' => __( 'Branch scope', 'momos-core' ) ],
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'show_admin_column' => false,
				'hierarchical'      => false,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => [
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_momo_notices',
				],
			]
		);
	}

	public static function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( PostTypes::BRANCH === $post->post_type ) {
			self::sync( $post_id );
		}
	}

	public static function on_meta( int $meta_id, int $post_id, string $key ): void {
		if ( in_array( $key, [ 'momo_branch_code', 'momo_operating_status' ], true ) && PostTypes::BRANCH === get_post_type( $post_id ) ) {
			self::sync( $post_id );
		}
	}

	/** Create/rename the branch's scope term (BranchScopeSync). Idempotent. */
	public static function sync( int $branch_id ): ?int {
		$post = get_post( $branch_id );
		if ( ! $post || PostTypes::BRANCH !== $post->post_type || ! taxonomy_exists( self::TAX ) || in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
			return null;
		}
		$code = strtoupper( (string) get_post_meta( $branch_id, 'momo_branch_code', true ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
			return null;
		}
		$name = '' !== trim( $post->post_title ) ? $post->post_title : $code;
		$slug = strtolower( $code );
		$term = self::term_for_branch( $branch_id );
		if ( $term ) {
			if ( $term->name !== $name || $term->slug !== $slug ) {
				wp_update_term( $term->term_id, self::TAX, [ 'name' => $name, 'slug' => $slug ] );
			}
			$term_id = $term->term_id;
		} else {
			$res = wp_insert_term( $name, self::TAX, [ 'slug' => $slug ] );
			if ( is_wp_error( $res ) ) {
				$existing = get_term_by( 'slug', $slug, self::TAX );
				if ( ! $existing ) {
					return null;
				}
				$term_id = (int) $existing->term_id;
			} else {
				$term_id = (int) $res['term_id'];
			}
			update_term_meta( $term_id, 'momo_branch_id', $branch_id );
		}
		$hidden = OperatingStatus::ClosedPermanently->value === get_post_meta( $branch_id, 'momo_operating_status', true );
		update_term_meta( $term_id, 'momo_scope_hidden', $hidden ? 1 : 0 );
		return $term_id;
	}

	public static function term_for_branch( int $branch_id ): ?\WP_Term {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAX,
				'hide_empty' => false,
				'meta_key'   => 'momo_branch_id', // phpcs:ignore
				'meta_value' => $branch_id, // phpcs:ignore
				'number'     => 1,
			]
		);
		return is_array( $terms ) && $terms ? $terms[0] : null;
	}

	public static function branch_of_term( int $term_id ): int {
		return (int) get_term_meta( $term_id, 'momo_branch_id', true );
	}

	/** @return list<int> branch IDs the notice/offer is scoped to */
	public static function branch_ids_of_post( int $post_id ): array {
		$terms = wp_get_object_terms( $post_id, self::TAX, [ 'fields' => 'ids' ] );
		if ( ! is_array( $terms ) ) {
			return [];
		}
		return array_values( array_filter( array_map( static fn( $t ) => self::branch_of_term( (int) $t ), $terms ) ) );
	}

	/**
	 * @param list<int> $term_ids
	 * @return list<int>
	 */
	public static function branch_ids_of_terms( array $term_ids ): array {
		return array_values( array_filter( array_map( static fn( $t ) => self::branch_of_term( (int) $t ), $term_ids ) ) );
	}

	/**
	 * Scope selector choices: hidden terms are never offered; scoped users only see their own
	 * branches ("Site-wide"/"All branches" is removed separately).
	 *
	 * @param list<\WP_Term>      $terms
	 * @param array<string,mixed> $field
	 * @return list<\WP_Term>
	 */
	public static function choices( array $terms, array $field ): array {
		if ( self::TAX !== ( $field['taxonomy'] ?? '' ) ) {
			return $terms;
		}
		$scope = momos_user_branch_ids();
		return array_values(
			array_filter(
				$terms,
				static function ( $t ) use ( $scope ): bool {
					if ( ! $t instanceof \WP_Term || '1' === (string) get_term_meta( $t->term_id, 'momo_scope_hidden', true ) ) {
						return false;
					}
					return null === $scope || in_array( self::branch_of_term( $t->term_id ), $scope, true );
				}
			)
		);
	}
}
