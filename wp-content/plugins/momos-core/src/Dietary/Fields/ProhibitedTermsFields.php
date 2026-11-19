<?php
declare(strict_types=1);

namespace Momos\Dietary\Fields;

use Momos\Dietary\Guard\WordingGuard;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\OptionsPage;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\Settings;

/**
 * Prohibited-wording list maintained by Head Office in Settings (SCR-A15, tab "Other"):
 * FR-DIET-002(f), Doc 07 §6.6, BR-DIET-01/03. One repeater per language (`*` = every language) with rows
 * {class, term}; each list is seeded from config/claim-keywords.php (the field default) and, once saved,
 * replaces the file's terms for that language. Native reviewers' ar/ur/hi/bn equivalents are added here
 * (GATE-04). Editable only with `momo_manage_brand` (Head Office, Super Admin); every save is audited
 * (`dietary.prohibited_terms_updated`, added/removed terms per language, plus the generic `settings.updated`).
 * WordingGuard (save-time checks, statement validation, claim-term scan) reads the lists.
 */
final class ProhibitedTermsFields {
	public const PAGE    = 'momo-settings';
	public const GROUP   = 'momo_settings_prohibited_terms';
	public const CAP     = 'momo_manage_brand';
	public const PREFIX  = 'momo_prohibited_terms_';
	public const ALL     = 'all';
	public const MAX_LEN = 100;
	public const AUDIT   = 'dietary.prohibited_terms_updated';

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_group' ], 9 );
		add_action( 'momos/options_page/saved', [ WordingGuard::class, 'flush' ] );
		add_filter( 'momos/audit/catalogue', static fn( array $list ): array => array_merge( $list, [ self::AUDIT ] ) );
	}

	/** Language keys of the lists: `all` (config `*`) + the six site languages. @return list<string> */
	public static function langs(): array {
		return array_merge( [ self::ALL ], Lang::codes() );
	}

	public static function field( string $lang ): string {
		return self::PREFIX . ( '*' === $lang ? self::ALL : $lang );
	}

	/** @return array<string,string> class => admin label */
	public static function classes(): array {
		return [
			'certification' => __( 'Certification wording (only through an active certified template)', 'momos-core' ),
			'absolute'      => __( 'Absolute halal claim', 'momos-core' ),
			'group'         => __( 'Group-wide claim', 'momos-core' ),
			'practice'      => __( 'Kitchen practice claim', 'momos-core' ),
			'recognition'   => __( 'Recognition / religious ruling', 'momos-core' ),
			'allergen'      => __( 'Allergen safety claim', 'momos-core' ),
			'ordering'      => __( 'Ordering wording', 'momos-core' ),
		];
	}

	/**
	 * Rows of the shipped master list (config/claim-keywords.php) for one language key.
	 *
	 * @return list<array{class:string,term:string}>
	 */
	public static function seed_rows( string $lang ): array {
		$key  = self::ALL === $lang ? '*' : $lang;
		$rows = [];
		foreach ( (array) ( WordingGuard::file_config()['classes'] ?? [] ) as $class => $by_lang ) {
			foreach ( (array) ( $by_lang[ $key ] ?? [] ) as $term ) {
				$rows[] = [ 'class' => (string) $class, 'term' => (string) $term ];
			}
		}
		return $rows;
	}

	public static function register_group(): void {
		OptionsPage::add( self::PAGE, __( 'Settings', 'momos-core' ), self::CAP );
		$classes = self::classes();
		$fields  = [
			[
				'name'    => self::PREFIX . 'intro',
				'label'   => __( 'About this list', 'momos-core' ),
				'type'    => 'message',
				'message' => __( 'Terms that must not appear in customer-facing text (Doc 07 §6.6). Used by the save-time wording check, statement template validation and the claim-term scan. Each list starts from the master list; native reviewers add the ar/ur/hi/bn equivalents. Matching ignores case.', 'momos-core' ),
			],
		];
		foreach ( self::langs() as $lang ) {
			$fields[] = [
				'name'         => self::field( $lang ),
				/* translators: %s: language name */
				'label'        => self::ALL === $lang ? __( 'All languages', 'momos-core' ) : sprintf( __( 'Prohibited terms: %s', 'momos-core' ), Lang::native( $lang ) . ' (' . $lang . ')' ),
				'type'         => 'repeater',
				'edit_cap'     => self::CAP,
				'max_rows'     => 300,
				'default'      => self::seed_rows( $lang ),
				'button_label' => __( 'Add term', 'momos-core' ),
				'validate'     => [ self::class, 'validate_rows' ],
				'sub_fields'   => [
					[ 'name' => 'class', 'label' => __( 'Class', 'momos-core' ), 'type' => 'select', 'choices' => $classes, 'no_empty' => true, 'default' => 'absolute', 'required' => true ],
					[ 'name' => 'term', 'label' => __( 'Term', 'momos-core' ), 'type' => 'text', 'maxlength' => self::MAX_LEN, 'required' => true ],
				],
			];
		}
		FieldRegistry::group(
			[
				'key'          => self::GROUP,
				'tab'          => 'other',
				'title'        => __( 'Prohibited wording (claims)', 'momos-core' ),
				'options_page' => self::PAGE,
				'order'        => 70,
				'view_cap'     => self::CAP,
				'on_save'      => [ self::class, 'on_save' ],
				'fields'       => $fields,
			]
		);
	}

	/** @param mixed $rows */
	public static function validate_rows( mixed $rows ): ?string {
		$seen = [];
		foreach ( (array) $rows as $i => $row ) {
			$term = mb_strtolower( trim( (string) ( $row['term'] ?? '' ) ) );
			if ( isset( $seen[ $term ] ) ) {
				/* translators: 1: row number, 2: term */
				return sprintf( __( 'Row %1$d: “%2$s” is already in this list.', 'momos-core' ), (int) $i + 1, (string) ( $row['term'] ?? '' ) );
			}
			$seen[ $term ] = true;
		}
		return null;
	}

	/**
	 * Lists that override the file, per config language key (`*`, ja, en …). Null for a language that was
	 * never saved (the master list applies).
	 *
	 * @return array<string, list<array{class:string,term:string}>>
	 */
	public static function saved_lists(): array {
		$out    = [];
		$values = Settings::page( self::PAGE );
		foreach ( self::langs() as $lang ) {
			$rows = $values[ self::field( $lang ) ] ?? null;
			if ( ! is_array( $rows ) ) {
				continue;
			}
			$clean = [];
			foreach ( $rows as $r ) {
				$class = (string) ( $r['class'] ?? '' );
				$term  = trim( (string) ( $r['term'] ?? '' ) );
				if ( '' !== $term && isset( self::classes()[ $class ] ) ) {
					$clean[] = [ 'class' => $class, 'term' => $term ];
				}
			}
			$out[ self::ALL === $lang ? '*' : $lang ] = $clean;
		}
		return $out;
	}

	/**
	 * Domain audit with the added/removed terms per language (no personal data).
	 *
	 * @param array<string,mixed>                  $values
	 * @param array<string,array{0:mixed,1:mixed}> $changes
	 */
	public static function on_save( int|string $page, array $values, array $changes ): void {
		$diff = [];
		foreach ( self::langs() as $lang ) {
			$name = self::field( $lang );
			if ( ! isset( $changes[ $name ] ) ) {
				continue;
			}
			$old     = is_array( $changes[ $name ][0] ) ? $changes[ $name ][0] : self::seed_rows( $lang );
			$new     = is_array( $changes[ $name ][1] ) ? $changes[ $name ][1] : [];
			$key     = static fn( array $r ): string => (string) ( $r['class'] ?? '' ) . ':' . (string) ( $r['term'] ?? '' );
			$old_k   = array_map( $key, $old );
			$new_k   = array_map( $key, $new );
			$added   = array_values( array_diff( $new_k, $old_k ) );
			$removed = array_values( array_diff( $old_k, $new_k ) );
			if ( $added || $removed ) {
				$diff[ $lang ] = [ 'added' => $added, 'removed' => $removed ];
			}
		}
		WordingGuard::flush();
		if ( $diff ) {
			momos_audit( self::AUDIT, 'settings', null, null, [ 'page' => (string) $page, 'languages' => $diff ] );
		}
	}
}
