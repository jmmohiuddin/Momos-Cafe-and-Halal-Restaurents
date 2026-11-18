<?php
declare(strict_types=1);

namespace Momos\Dietary\Migration;

use Momos\Dietary\Allergens;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Migration\MigrationInterface;

/**
 * Seeds the 29 reference allergens (spec §2.5, TC-DIET-001): Japanese term name, approved English
 * translation, code/class/sort, `momo_alg_seeded = 1`. Idempotent (lookup by code); never overwrites
 * human edits of names.
 */
final class M0003_SeedAllergens implements MigrationInterface {
	public function id(): string {
		return 'M0003_SeedAllergens';
	}

	public function up(): void {
		if ( ! taxonomy_exists( Allergens::TAXONOMY ) ) {
			\Momos\Dietary\ContentTypes::register();
		}
		foreach ( Allergens::config() as $a ) {
			$term = Allergens::term_by_code( $a['code'] );
			if ( null === $term ) {
				$res = wp_insert_term( $a['ja'], Allergens::TAXONOMY, [ 'slug' => str_replace( '_', '-', $a['code'] ) ] );
				if ( is_wp_error( $res ) ) {
					$existing = get_term_by( 'slug', str_replace( '_', '-', $a['code'] ), Allergens::TAXONOMY );
					if ( ! $existing instanceof \WP_Term ) {
						throw new \RuntimeException( 'Allergen seed failed: ' . $a['code'] . ' ' . $res->get_error_message() );
					}
					$term_id = (int) $existing->term_id;
				} else {
					$term_id = (int) $res['term_id'];
				}
			} else {
				$term_id = (int) $term->term_id;
			}
			update_term_meta( $term_id, 'momo_alg_code', $a['code'] );
			update_term_meta( $term_id, 'momo_alg_class', $a['class'] );
			update_term_meta( $term_id, 'momo_alg_sort', (string) $a['sort'] );
			update_term_meta( $term_id, 'momo_alg_seeded', '1' );
			if ( 'missing' === Translations::status( 'term', $term_id, 'en' ) ) {
				Translations::save( 'term', $term_id, 'en', [ 'name' => $a['en'] ], 'approved' );
			}
		}
	}
}
