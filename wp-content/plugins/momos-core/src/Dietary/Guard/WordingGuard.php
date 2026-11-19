<?php
declare(strict_types=1);

namespace Momos\Dietary\Guard;

use Momos\Kernel\Fields\FieldRegistry;

/**
 * Prohibited claim wording (BR-DIET-01/03, Doc 11 §8.6/§9.5, config/claim-keywords.php). The term lists
 * per language are those Head Office maintains in Settings (FR-DIET-002(f), SCR-A15 —
 * Fields\ProhibitedTermsFields); a language never saved there uses the file's master list.
 *  - check(): every matched master term (all classes) for a language (null = all languages).
 *  - statement_violations(): blocking check for G2 templates — absolute/group/practice/recognition/
 *    allergen/ordering classes are always blocked; certification wording only in `certified` and
 *    `halal_ingredients` rows (the latter states the kitchen is NOT certified).
 *  - Save-time warning (non-blocking) on dish/branch/offer/notice/page/post free text, including the
 *    base terms (free-text halal is never allowed — statements come from register-driven blocks).
 * Other modules (offers) call blocking_violations() to refuse absolute wording.
 */
final class WordingGuard {
	public const WARN_POST_TYPES = [ 'momo_dish', 'momo_branch', 'momo_offer', 'momo_notice', 'page', 'post' ];

	/** @var array<string,mixed>|null */
	private static ?array $config = null;

	public static function register(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'warn_on_save' ], 20, 2 );
	}

	/** @var array<string,mixed>|null */
	private static ?array $file = null;

	/** Shipped master list (config/claim-keywords.php), the seed of the settings lists. @return array<string,mixed> */
	public static function file_config(): array {
		if ( null === self::$file ) {
			self::$file = (array) require MOMOS_CORE_DIR . '/config/claim-keywords.php';
		}
		return self::$file;
	}

	/**
	 * Effective list: the file, with the `classes` terms of every language saved in Settings replaced by
	 * the Head Office list (FR-DIET-002(f)). `scan_base` and `image_terms` stay as shipped.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		if ( null === self::$config ) {
			$cfg   = self::file_config();
			$saved = class_exists( \Momos\Dietary\Fields\ProhibitedTermsFields::class ) ? \Momos\Dietary\Fields\ProhibitedTermsFields::saved_lists() : [];
			foreach ( $saved as $lang => $rows ) {
				foreach ( array_keys( (array) ( $cfg['classes'] ?? [] ) ) as $class ) {
					unset( $cfg['classes'][ $class ][ $lang ] );
				}
				foreach ( $rows as $row ) {
					$cfg['classes'][ $row['class'] ][ $lang ][] = $row['term'];
				}
			}
			self::$config = $cfg;
		}
		return self::$config;
	}

	/** Forget the effective list (after a Settings save). */
	public static function flush(): void {
		self::$config = null;
	}

	/**
	 * @return list<array{term:string,class:string,lang:string}>
	 */
	public static function check( string $text, ?string $lang = null, ?array $classes = null ): array {
		$text = wp_strip_all_tags( $text );
		if ( '' === trim( $text ) ) {
			return [];
		}
		$hay = mb_strtolower( $text );
		$out = [];
		foreach ( (array) ( self::config()['classes'] ?? [] ) as $class => $by_lang ) {
			if ( null !== $classes && ! in_array( $class, $classes, true ) ) {
				continue;
			}
			foreach ( (array) $by_lang as $term_lang => $terms ) {
				if ( null !== $lang && '*' !== $term_lang && $term_lang !== $lang ) {
					continue;
				}
				foreach ( (array) $terms as $term ) {
					$term = (string) $term;
					if ( '' !== $term && false !== mb_strpos( $hay, mb_strtolower( $term ) ) ) {
						$out[] = [ 'term' => $term, 'class' => (string) $class, 'lang' => (string) $term_lang ];
					}
				}
			}
		}
		return $out;
	}

	/** Base claim terms found in free text (halal, ハラール, 100% …). @return list<string> */
	public static function base_terms( string $text ): array {
		$hay = mb_strtolower( wp_strip_all_tags( $text ) );
		$out = [];
		foreach ( (array) ( self::config()['scan_base'] ?? [] ) as $term ) {
			if ( false !== mb_strpos( $hay, mb_strtolower( (string) $term ) ) ) {
				$out[] = (string) $term;
			}
		}
		return $out;
	}

	/** Never-allowed free text (absolute halal, group, practice, recognition, allergen). @return list<array{term:string,class:string,lang:string}> */
	public static function blocking_violations( string $text, ?string $lang = null ): array {
		return self::check( $text, $lang, [ 'absolute', 'group', 'practice', 'recognition', 'allergen' ] );
	}

	/** @return list<array{term:string,class:string,lang:string}> */
	public static function statement_violations( string $text, string $claim_type, ?string $lang = null ): array {
		$classes = [ 'absolute', 'group', 'practice', 'recognition', 'allergen', 'ordering' ];
		if ( ! in_array( $claim_type, [ 'certified', 'halal_ingredients' ], true ) ) {
			$classes[] = 'certification';
		}
		return self::check( $text, $lang, $classes );
	}

	/**
	 * Non-blocking save-time warning listing matched terms (Content Editors, dish/branch/offer text).
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function warn_on_save( array $data, array $postarr ): array {
		if ( ! in_array( $data['post_type'] ?? '', self::WARN_POST_TYPES, true ) || ! is_admin() ) {
			return $data;
		}
		$text = (string) ( $data['post_title'] ?? '' ) . "\n" . (string) ( $data['post_content'] ?? '' ) . "\n" . (string) ( $data['post_excerpt'] ?? '' );
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only check, the save itself is nonce-verified.
		if ( isset( $_POST['momos_fields']['momo_short_description'] ) && is_string( $_POST['momos_fields']['momo_short_description'] ) ) {
			$text .= "\n" . sanitize_textarea_field( wp_unslash( $_POST['momos_fields']['momo_short_description'] ) ); // phpcs:ignore
		}
		$terms = array_merge( array_column( self::check( $text ), 'term' ), self::base_terms( $text ) );
		if ( $terms ) {
			/* translators: %s: comma-separated list of terms */
			FieldRegistry::add_errors( [ sprintf( __( 'Possible claim wording found: %s. Halal, allergen and certification statements may only come from the approved register templates. Please review.', 'momos-core' ), implode( ', ', array_unique( $terms ) ) ) ] );
		}
		return $data;
	}
}
