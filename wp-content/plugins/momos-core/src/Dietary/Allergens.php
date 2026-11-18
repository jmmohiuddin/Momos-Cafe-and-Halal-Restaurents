<?php
declare(strict_types=1);

namespace Momos\Dietary;

/**
 * Allergen reference data (spec §2.5): the 29 seeded items from config/allergens.php plus custom terms
 * (class `other`) added by CAP-DCA holders. Dish states live in post meta `momo_alg_{code}` and
 * `momo_alg_custom`.
 */
final class Allergens {
	public const TAXONOMY = 'momo_allergen';

	/** @var list<array{code:string, ja:string, en:string, class:string, sort:int}>|null */
	private static ?array $config = null;

	/** @return list<array{code:string, ja:string, en:string, class:string, sort:int}> */
	public static function config(): array {
		if ( null === self::$config ) {
			self::$config = (array) require MOMOS_CORE_DIR . '/config/allergens.php';
		}
		return self::$config;
	}

	/** @return list<string> the 29 seeded codes in reference order */
	public static function codes(): array {
		return array_column( self::config(), 'code' );
	}

	public static function meta_key( string $code ): string {
		return 'momo_alg_' . $code;
	}

	public static function term_by_code( string $code ): ?\WP_Term {
		$terms = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'meta_key' => 'momo_alg_code', 'meta_value' => $code, 'number' => 1 ] ); // phpcs:ignore
		return ( is_array( $terms ) && $terms ) ? $terms[0] : null;
	}

	/** @return list<\WP_Term> custom allergen terms (class other), ordered by sort then name */
	public static function custom_terms(): array {
		$terms = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'meta_key' => 'momo_alg_class', 'meta_value' => 'other' ] ); // phpcs:ignore
		$terms = is_array( $terms ) ? $terms : [];
		usort( $terms, static fn( $a, $b ) => (int) get_term_meta( $a->term_id, 'momo_alg_sort', true ) <=> (int) get_term_meta( $b->term_id, 'momo_alg_sort', true ) ?: strcmp( $a->name, $b->name ) );
		return $terms;
	}

	/** @return array<string,\WP_Term> code => term for all allergen terms */
	public static function terms_by_code(): array {
		$terms = get_terms( [ 'taxonomy' => self::TAXONOMY, 'hide_empty' => false ] );
		$out   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			$code = (string) get_term_meta( $t->term_id, 'momo_alg_code', true );
			if ( '' !== $code ) {
				$out[ $code ] = $t;
			}
		}
		return $out;
	}
}
