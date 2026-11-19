<?php
declare(strict_types=1);

namespace Momos\Dietary\Guard;

use Momos\Kernel\Events\AfterCommit;
use Momos\Menu\Support\CachePurge;

/**
 * Claim-natured dietary tags (`momo_tag_is_claim = 1`, e.g. no_pork_ingredients) can be assigned or
 * removed only by CAP-DCA holders (FR-DIET-003, Doc 11 §8.1, TRD FR-DIET-003). Compares old/new
 * tt_ids on `set_object_terms`; other users' changes to claim tags are reverted and audited
 * `access.denied`; CAP-DCA changes are audited `dietary.dish_claims_updated`.
 */
final class ClaimTagGuard {
	public const TAXONOMY = 'momo_dietary_tag';

	private static bool $reverting = false;

	public static function register(): void {
		add_action( 'set_object_terms', [ self::class, 'on_set_terms' ], 10, 6 );
	}

	/**
	 * @param array<int,mixed> $terms
	 * @param list<int>        $tt_ids
	 * @param list<int>        $old_tt_ids
	 */
	public static function on_set_terms( int $object_id, $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( self::TAXONOMY !== $taxonomy || self::$reverting ) {
			return;
		}
		$new     = array_map( 'intval', $tt_ids );
		$old     = array_map( 'intval', $old_tt_ids );
		$added   = array_values( array_filter( array_diff( $new, $old ), [ self::class, 'is_claim_tt' ] ) );
		$removed = array_values( array_filter( array_diff( $old, $new ), [ self::class, 'is_claim_tt' ] ) );
		if ( ! $added && ! $removed ) {
			return;
		}
		if ( ClaimFieldGuard::allowed() ) {
			momos_audit( 'dietary.dish_claims_updated', 'dish', $object_id, null, [ 'claim_tags_added' => self::term_ids( $added ), 'claim_tags_removed' => self::term_ids( $removed ) ] );
			AfterCommit::dispatch( 'momos/dietary/claims_changed', 'dish', $object_id );
			CachePurge::dish( $object_id );
			return;
		}
		// Revert: keep non-claim changes, restore claim tags exactly as before.
		$keep            = array_values( array_unique( array_merge( array_diff( $new, $added ), $removed ) ) );
		self::$reverting = true;
		try {
			wp_set_object_terms( $object_id, self::term_ids( $keep ), self::TAXONOMY, false );
		} finally {
			self::$reverting = false;
		}
		momos_audit( 'access.denied', 'dish', $object_id, null, [ 'reason' => 'cap_dca_required', 'fields' => [ 'claim_tags' ], 'attempted_add' => self::term_ids( $added ), 'attempted_remove' => self::term_ids( $removed ) ] );
		\Momos\Kernel\Fields\FieldRegistry::add_errors( [ __( 'Only Dietary Claims Approvers can change these fields', 'momos-core' ) ] );
	}

	public static function is_claim_tt( int $tt_id ): bool {
		$term = get_term_by( 'term_taxonomy_id', $tt_id, self::TAXONOMY );
		return $term instanceof \WP_Term && '1' === (string) get_term_meta( $term->term_id, 'momo_tag_is_claim', true );
	}

	/** @param list<int> $tt_ids @return list<int> */
	private static function term_ids( array $tt_ids ): array {
		$out = [];
		foreach ( $tt_ids as $tt ) {
			$t = get_term_by( 'term_taxonomy_id', (int) $tt, self::TAXONOMY );
			if ( $t instanceof \WP_Term ) {
				$out[] = (int) $t->term_id;
			}
		}
		return $out;
	}
}
