<?php
declare(strict_types=1);

namespace Momos\Dietary\Guard;

use Momos\Dietary\Allergens;

/**
 * Seeded reference allergens (spec §2.4/§2.5) can never be deleted; their code/class are immutable
 * (validated in DietaryFields G12). Custom allergens (class other) may be deleted by CAP-DCA holders
 * only while no dish records a state for them.
 */
final class AllergenGuard {
	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map_caps' ], 10, 4 );
		add_action( 'pre_delete_term', [ self::class, 'pre_delete' ], 10, 2 );
	}

	public static function delete_block_reason( int $term_id ): ?string {
		$t = get_term( $term_id );
		if ( ! $t instanceof \WP_Term || Allergens::TAXONOMY !== $t->taxonomy ) {
			return null;
		}
		if ( '1' === (string) get_term_meta( $term_id, 'momo_alg_seeded', true ) ) {
			return __( 'Reference allergens cannot be deleted.', 'momos-core' );
		}
		global $wpdb;
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'momo_alg_custom' AND meta_value LIKE %s", '%' . $wpdb->esc_like( '"term_id";s:' . strlen( (string) $term_id ) . ':"' . $term_id . '"' ) . '%' ) );
		return $used > 0 ? __( 'This allergen is recorded on dishes. Remove it from them first.', 'momos-core' ) : null;
	}

	/** @param list<string> $caps @param array<int,mixed> $args @return list<string> */
	public static function map_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'delete_term' === $cap && isset( $args[0] ) && null !== self::delete_block_reason( (int) $args[0] ) ) {
			return [ 'do_not_allow' ];
		}
		return $caps;
	}

	public static function pre_delete( int $term_id, string $taxonomy ): void {
		if ( Allergens::TAXONOMY !== $taxonomy ) {
			return;
		}
		$reason = self::delete_block_reason( $term_id );
		if ( null !== $reason ) {
			throw new \RuntimeException( esc_html( $reason ) );
		}
	}
}
