<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n\Admin;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Strings;
use Momos\Kernel\I18n\Translations;

/**
 * Translation gaps service (SCR-A11, FR-I18N-008, i18n-seo-analytics.md §6.3/§6.4; Doc 06 §4.6 LCCS).
 *
 * Items: published pages, news posts, non-test branches (not closed permanently), dishes (not retired /
 * sample), offers/notices not yet ended, gallery attachments, every other registered post kind (published),
 * menu category / dietary tag / allergen / ingredient terms, brand option texts, approved halal/alcohol
 * statement templates and every string group (catalog module file, customer-facing keys only).
 * Languages: every target language, enabled or hidden.
 * Gap state per item × language: missing | draft | needs_review | outdated (approved, source hash changed);
 * '' = approved and current. String groups aggregate their keys (worst state, with per-state key counts).
 *
 * LCCS: pages with `momo_lccs=1`, branches, dishes, menu categories, dietary tags, allergens, brand texts
 * with an LCCS field, statements, string groups with LCCS keys. LCCS completeness % = floor(100 × LCCS
 * items approved & current / LCCS items), 100 only with 0 gaps.
 *
 * Language gate (FR-I18N-009, content-strings §3.2): `momos/i18n/language_gate_errors` gets one entry per
 * LCCS item with a gap and one per string group with any customer-facing key not approved.
 */
final class TranslationGaps {
	public const STATES = [ 'missing', 'draft', 'needs_review', 'outdated' ];

	/** Content types in display order (also the filter values). */
	public const TYPES = [ 'notice', 'offer', 'page', 'branch', 'dish', 'category', 'dietary_tag', 'allergen', 'ingredient', 'news', 'gallery', 'brand', 'statement', 'strings', 'other' ];

	private const POST_TYPES = [
		'page'        => 'page',
		'post'        => 'news',
		'momo_branch' => 'branch',
		'momo_dish'   => 'dish',
		'momo_offer'  => 'offer',
		'momo_notice' => 'notice',
		'attachment'  => 'gallery',
	];

	private const TAXONOMIES = [
		'momo_menu_category' => [ 'category', true ],
		'momo_dietary_tag'   => [ 'dietary_tag', true ],
		'momo_allergen'      => [ 'allergen', true ],
		'momo_ingredient'    => [ 'ingredient', false ],
	];

	/** @var list<array<string,mixed>>|null request cache */
	private static ?array $items = null;

	public static function register(): void {
		add_filter( 'momos/i18n/language_gate_errors', [ self::class, 'gate_errors' ], 10, 2 );
		foreach ( [ 'momos/i18n/translation_saved', 'momos/i18n/option_translation_saved', 'momos/strings/status_changed', 'save_post', 'edited_term' ] as $hook ) {
			add_action( $hook, [ self::class, 'flush' ] );
		}
	}

	public static function flush(): void {
		self::$items = null;
	}

	public static function type_label( string $type ): string {
		return match ( $type ) {
			'notice' => __( 'Notice', 'momos-core' ),
			'offer' => __( 'Offer', 'momos-core' ),
			'page' => __( 'Page', 'momos-core' ),
			'branch' => __( 'Branch', 'momos-core' ),
			'dish' => __( 'Dish', 'momos-core' ),
			'category' => __( 'Menu category', 'momos-core' ),
			'dietary_tag' => __( 'Dietary tag', 'momos-core' ),
			'allergen' => __( 'Allergen', 'momos-core' ),
			'ingredient' => __( 'Ingredient', 'momos-core' ),
			'news' => __( 'News', 'momos-core' ),
			'gallery' => __( 'Gallery image', 'momos-core' ),
			'brand' => __( 'Brand texts', 'momos-core' ),
			'statement' => __( 'Halal / alcohol statement', 'momos-core' ),
			'strings' => __( 'Interface strings', 'momos-core' ),
			default => __( 'Other', 'momos-core' ),
		};
	}

	public static function state_label( string $state ): string {
		return match ( $state ) {
			'missing' => __( 'Missing', 'momos-core' ),
			'draft' => __( 'Draft', 'momos-core' ),
			'needs_review' => __( 'Needs review', 'momos-core' ),
			'outdated' => __( 'Needs update', 'momos-core' ),
			default => __( 'Published', 'momos-core' ),
		};
	}

	// ---------------------------------------------------------------- items

	/**
	 * Every translatable item. Keys: type (post|term|option|statement|strings), id, content_type, title,
	 * lccs, since (UTC mysql of JA publish), changed (UTC mysql of JA last change), urgent (bool).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function items(): array {
		if ( null !== self::$items ) {
			return self::$items;
		}
		$items = [];
		$now   = Clock::mysql_utc();
		foreach ( Translations::kinds() as $kind ) {
			[ $k, $name ] = array_pad( explode( ':', $kind, 2 ), 2, '' );
			if ( 'post' === $k && post_type_exists( $name ) ) {
				array_push( $items, ...self::post_items( $name, $now ) );
			} elseif ( 'term' === $k && taxonomy_exists( $name ) ) {
				array_push( $items, ...self::term_items( $name ) );
			} elseif ( 'option' === $k && '' !== $name ) {
				$item = self::option_item( $name );
				if ( $item ) {
					$items[] = $item;
				}
			}
		}
		array_push( $items, ...self::statement_items(), ...self::string_items() );
		self::$items = $items;
		return $items;
	}

	/** @return list<array<string,mixed>> */
	private static function post_items( string $post_type, string $now ): array {
		$ctype = self::POST_TYPES[ $post_type ] ?? 'other';
		$args  = [
			'post_type'        => $post_type,
			'post_status'      => in_array( $post_type, [ 'momo_offer' ], true ) ? [ 'publish', 'pending' ] : 'publish',
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'no_found_rows'    => true,
		];
		if ( 'attachment' === $post_type ) {
			$args['post_status'] = 'inherit';
			$args['meta_query']  = [ [ 'key' => 'momo_gallery_include', 'value' => '1' ] ]; // phpcs:ignore
		}
		$out = [];
		foreach ( get_posts( $args ) as $p ) {
			$id = (int) $p->ID;
			if ( ! self::post_included( $p, $now ) ) {
				continue;
			}
			$lccs = match ( $post_type ) {
				'page' => '1' === (string) get_post_meta( $id, 'momo_lccs', true ),
				'momo_branch', 'momo_dish' => true,
				default => false,
			};
			$title = (string) $p->post_title;
			if ( 'attachment' === $post_type && '' === trim( $title ) ) {
				$title = basename( (string) get_post_meta( $id, '_wp_attached_file', true ) );
			}
			$out[] = [
				'type'         => 'post',
				'id'           => $id,
				'content_type' => $ctype,
				'title'        => '' !== trim( $title ) ? $title : '#' . $id,
				'lccs'         => $lccs,
				'since'        => (string) $p->post_date_gmt,
				'changed'      => (string) $p->post_modified_gmt,
				'urgent'       => in_array( $post_type, [ 'momo_notice', 'momo_offer' ], true ),
			];
		}
		return $out;
	}

	private static function post_included( \WP_Post $p, string $now ): bool {
		$id = (int) $p->ID;
		switch ( $p->post_type ) {
			case 'momo_branch':
				if ( '1' === (string) get_post_meta( $id, 'momo_is_test_branch', true ) || 'closed_permanently' === (string) get_post_meta( $id, 'momo_operating_status', true ) ) {
					return false;
				}
				break;
			case 'momo_dish':
				if ( '1' === (string) get_post_meta( $id, 'momo_retired', true ) || '1' === (string) get_post_meta( $id, 'momo_is_sample', true ) ) {
					return false;
				}
				break;
			case 'momo_offer':
			case 'momo_notice':
				$end = (string) get_post_meta( $id, 'momo_notice' === $p->post_type ? '_momo_notice_end_utc' : '_momo_offer_end_utc', true );
				if ( '' !== $end && $end <= $now ) {
					return false;
				}
				break;
		}
		return (bool) apply_filters( 'momos/i18n/gaps/include_post', true, $p );
	}

	/** @return list<array<string,mixed>> */
	private static function term_items( string $taxonomy ): array {
		[ $ctype, $lccs ] = self::TAXONOMIES[ $taxonomy ] ?? [ 'other', false ];
		$terms            = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		$out              = [];
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			$out[] = [
				'type'         => 'term',
				'id'           => (int) $t->term_id,
				'content_type' => $ctype,
				'title'        => (string) $t->name,
				'lccs'         => $lccs,
				'since'        => '',
				'changed'      => '',
				'urgent'       => false,
			];
		}
		return $out;
	}

	/** @return array<string,mixed>|null */
	private static function option_item( string $page ): ?array {
		$fields = Translations::fields( 'option:' . $page );
		$has    = false;
		$lccs   = false;
		foreach ( $fields as $key => $def ) {
			if ( '' !== trim( Translations::source_option_value( $page, $key ) ) ) {
				$has  = true;
				$lccs = $lccs || ! empty( $def['lccs'] );
			}
		}
		if ( ! $has ) {
			return null;
		}
		return [
			'type'         => 'option',
			'id'           => $page,
			'content_type' => 'momo-brand' === $page ? 'brand' : 'other',
			'title'        => 'momo-brand' === $page ? __( 'Brand settings', 'momos-core' ) : $page,
			'lccs'         => $lccs,
			'since'        => '',
			'changed'      => '',
			'urgent'       => false,
		];
	}

	/** @return list<array<string,mixed>> */
	private static function statement_items(): array {
		if ( ! class_exists( \Momos\Dietary\Service\StatementRepository::class ) ) {
			return [];
		}
		$out = [];
		foreach ( \Momos\Dietary\Service\StatementRepository::all() as $key => $row ) {
			if ( empty( $row['approved'] ) || '' === trim( (string) $row['text_ja'] ) ) {
				continue;
			}
			$out[] = [
				'type'         => 'statement',
				'id'           => (string) $key,
				'content_type' => 'statement',
				'title'        => (string) $key,
				'lccs'         => true,
				'since'        => '',
				'changed'      => '',
				'urgent'       => false,
				'row'          => $row,
			];
		}
		return $out;
	}

	/** @return list<array<string,mixed>> one item per catalog module file with customer-facing keys */
	private static function string_items(): array {
		$groups = [];
		foreach ( Strings::keys() as $key ) {
			if ( ! Strings::is_customer_key( $key ) ) {
				continue;
			}
			$g                      = Strings::group_of( $key ) ?: 'other';
			$groups[ $g ]['keys'][] = $key;
			$groups[ $g ]['lccs']   = ( $groups[ $g ]['lccs'] ?? false ) || Strings::is_lccs_key( $key );
		}
		ksort( $groups );
		$out = [];
		foreach ( $groups as $g => $info ) {
			$out[] = [
				'type'         => 'strings',
				'id'           => (string) $g,
				'content_type' => 'strings',
				/* translators: %s: string group (catalog file) */
				'title'        => sprintf( __( 'Strings: %s', 'momos-core' ), $g ),
				'lccs'         => (bool) $info['lccs'],
				'since'        => '',
				'changed'      => '',
				'urgent'       => false,
				'keys'         => $info['keys'],
			];
		}
		return $out;
	}

	// ---------------------------------------------------------------- states

	/**
	 * Gap state of an item in a language ('' = approved and current) plus per-state key counts for strings.
	 *
	 * @param array<string,mixed> $item
	 * @return array{state:string,counts:array<string,int>}
	 */
	public static function state( array $item, string $lang ): array {
		switch ( $item['type'] ) {
			case 'strings':
				$counts = array_fill_keys( self::STATES, 0 );
				foreach ( (array) $item['keys'] as $key ) {
					$s = Strings::status( (string) $key, $lang );
					if ( 'approved' === $s ) {
						if ( Strings::is_outdated( (string) $key, $lang ) ) {
							++$counts['outdated'];
						}
						continue;
					}
					++$counts[ $s ];
				}
				$state = '';
				foreach ( self::STATES as $s ) {
					if ( $counts[ $s ] > 0 ) {
						$state = $s;
						break;
					}
				}
				return [ 'state' => $state, 'counts' => $counts ];
			case 'statement':
				$st = (string) ( $item['row']['i18n'][ $lang ]['status'] ?? 'missing' );
				$st = in_array( $st, Translations::STATUSES, true ) ? $st : 'missing';
				return [ 'state' => 'approved' === $st ? '' : $st, 'counts' => [] ];
			default:
				$st = Translations::status( (string) $item['type'], $item['id'], $lang );
				if ( 'approved' === $st ) {
					return [ 'state' => Translations::is_outdated( (string) $item['type'], $item['id'], $lang ) ? 'outdated' : '', 'counts' => [] ];
				}
				return [ 'state' => $st, 'counts' => [] ];
		}
	}

	/**
	 * Gap rows (item × language with a gap), filtered and sorted: urgent (active notices/offers) first, then
	 * LCCS, then age desc.
	 *
	 * @param array{lang?:string,type?:string,state?:string,lccs?:bool,langs?:list<string>} $filters
	 * @return list<array<string,mixed>>
	 */
	public static function rows( array $filters = [] ): array {
		$langs = $filters['langs'] ?? Lang::targets();
		if ( ! empty( $filters['lang'] ) ) {
			$langs = array_values( array_intersect( $langs, [ $filters['lang'] ] ) );
		}
		$today = Clock::jst()->setTime( 0, 0 );
		$out   = [];
		foreach ( self::items() as $item ) {
			if ( ! empty( $filters['type'] ) && $item['content_type'] !== $filters['type'] ) {
				continue;
			}
			if ( ! empty( $filters['lccs'] ) && ! $item['lccs'] ) {
				continue;
			}
			foreach ( $langs as $lang ) {
				$st = self::state( $item, $lang );
				if ( '' === $st['state'] || ( ! empty( $filters['state'] ) && $st['state'] !== $filters['state'] ) ) {
					continue;
				}
				$ref   = 'outdated' === $st['state'] ? (string) $item['changed'] : (string) $item['since'];
				$out[] = $item + [
					'lang'   => $lang,
					'state'  => $st['state'],
					'counts' => $st['counts'],
					'age'    => self::age_days( $ref, $today ),
				];
			}
		}
		usort(
			$out,
			static fn( $a, $b ) => [ $b['urgent'], $b['lccs'], $b['age'] ?? -1, $a['title'] ] <=> [ $a['urgent'], $a['lccs'], $a['age'] ?? -1, $b['title'] ]
		);
		return $out;
	}

	private static function age_days( string $utc, \DateTimeImmutable $today_jst ): ?int {
		if ( '' === $utc || str_starts_with( $utc, '0000' ) ) {
			return null;
		}
		$d = Clock::from_mysql_utc( $utc );
		if ( null === $d ) {
			return null;
		}
		$day = $d->setTimezone( Clock::tz() )->setTime( 0, 0 );
		return max( 0, (int) $day->diff( $today_jst )->format( '%r%a' ) );
	}

	/**
	 * Counts per language × content type × state (strings count keys, other types count items).
	 *
	 * @param list<string>|null $langs
	 * @return array<string, array<string, array<string,int>>>
	 */
	public static function counts( ?array $langs = null, bool $lccs_only = false ): array {
		$langs = $langs ?? Lang::targets();
		$out   = [];
		foreach ( $langs as $lang ) {
			$out[ $lang ] = [];
		}
		foreach ( self::items() as $item ) {
			if ( $lccs_only && ! $item['lccs'] ) {
				continue;
			}
			foreach ( $langs as $lang ) {
				$st  = self::state( $item, $lang );
				$row = $out[ $lang ][ $item['content_type'] ] ?? array_fill_keys( self::STATES, 0 );
				if ( 'strings' === $item['type'] ) {
					foreach ( $st['counts'] as $s => $n ) {
						$row[ $s ] += $n;
					}
				} elseif ( '' !== $st['state'] ) {
					++$row[ $st['state'] ];
				}
				$out[ $lang ][ $item['content_type'] ] = $row;
			}
		}
		return $out;
	}

	/** LCCS completeness % (floor; 100 only when there are no LCCS gaps). */
	public static function lccs_percent( string $lang ): int {
		$total = 0;
		$done  = 0;
		foreach ( self::items() as $item ) {
			if ( ! $item['lccs'] ) {
				continue;
			}
			++$total;
			if ( '' === self::state( $item, $lang )['state'] ) {
				++$done;
			}
		}
		return 0 === $total ? 100 : (int) floor( 100 * $done / $total );
	}

	/**
	 * Launch-gate gaps of a language: LCCS items with a gap + string groups with any customer-facing key
	 * not approved (content-strings §3.2).
	 *
	 * @return list<string>
	 */
	public static function lccs_gaps( string $lang ): array {
		$out = [];
		foreach ( self::items() as $item ) {
			$st = self::state( $item, $lang );
			if ( '' === $st['state'] || ( ! $item['lccs'] && 'strings' !== $item['type'] ) ) {
				continue;
			}
			if ( 'strings' === $item['type'] ) {
				$n = array_sum( $st['counts'] );
				/* translators: 1: string group 2: number of keys */
				$out[] = sprintf( _n( 'Interface strings “%1$s”: %2$d key not approved', 'Interface strings “%1$s”: %2$d keys not approved', $n, 'momos-core' ), (string) $item['id'], $n );
				continue;
			}
			/* translators: 1: content type 2: title 3: state */
			$out[] = sprintf( __( '%1$s “%2$s”: %3$s', 'momos-core' ), self::type_label( (string) $item['content_type'] ), wp_strip_all_tags( (string) $item['title'] ), self::state_label( $st['state'] ) );
		}
		return $out;
	}

	/**
	 * @param list<string> $errors
	 * @return list<string>
	 */
	public static function gate_errors( $errors, $lang ): array {
		$errors = is_array( $errors ) ? $errors : [];
		if ( ! is_string( $lang ) || ! Lang::is_valid( $lang ) || Lang::SOURCE === $lang ) {
			return $errors;
		}
		return array_values( array_merge( $errors, self::lccs_gaps( $lang ) ) );
	}

	// ---------------------------------------------------------------- bulk

	/**
	 * Bulk "mark needs review when source changed": every approved translation whose source hash changed
	 * (posts, terms, options, string keys) moves to `needs_review` — it is then hidden until re-approved.
	 * Returns the number of changed item × language pairs.
	 *
	 * @param list<string> $langs
	 * @param list<array{type:string,id:int|string}>|null $only restrict to these items
	 */
	public static function mark_outdated_needs_review( array $langs, int $user_id, ?array $only = null ): int {
		$n     = 0;
		$allow = null;
		if ( null !== $only ) {
			$allow = [];
			foreach ( $only as $o ) {
				$allow[ $o['type'] . ':' . $o['id'] ] = true;
			}
		}
		foreach ( self::items() as $item ) {
			if ( null !== $allow && ! isset( $allow[ $item['type'] . ':' . $item['id'] ] ) ) {
				continue;
			}
			foreach ( $langs as $lang ) {
				if ( ! Lang::is_valid( $lang ) || Lang::SOURCE === $lang ) {
					continue;
				}
				if ( 'strings' === $item['type'] ) {
					$keys = array_values( array_filter( (array) $item['keys'], static fn( $k ) => Strings::is_outdated( (string) $k, $lang ) ) );
					if ( $keys ) {
						Strings::set_status( $lang, $keys, 'needs_review', $user_id );
						$n += count( $keys );
					}
					continue;
				}
				if ( in_array( $item['type'], [ 'post', 'term', 'option' ], true ) && Translations::is_outdated( (string) $item['type'], $item['id'], $lang ) ) {
					Translations::set_status( (string) $item['type'], $item['id'], $lang, 'needs_review', $user_id );
					++$n;
				}
			}
		}
		self::flush();
		return $n;
	}

	/** Deep link to the editor of an item in a language. */
	public static function edit_url( array $item, string $lang ): string {
		return match ( $item['type'] ) {
			'strings' => add_query_arg( [ 'page' => StringsPage::SLUG, 'lang' => $lang, 'group' => (string) $item['id'] ], admin_url( 'admin.php' ) ),
			'statement' => add_query_arg( [ 'page' => 'momo-dietary' ], admin_url( 'admin.php' ) ),
			default => TranslationEditor::url( (string) $item['type'], $item['id'], $lang ),
		};
	}
}
