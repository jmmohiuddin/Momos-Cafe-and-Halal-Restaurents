<?php
declare(strict_types=1);

namespace Momos\Branch\Fields;

use Momos\Branch\Domain\OfferCta;
use Momos\Branch\Domain\OfferScope;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Query\OfferQuery;
use Momos\Branch\Service\ValidityMeta;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Env;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Translations;

/**
 * G17 offer fields and save/publish validation (spec §3.6, §4.11, FR-CORP-010, BR-CORP-01/02/03,
 * CMP-040..044). No coupon, redemption, cart or payment field exists (BR-CORP-01).
 */
final class OfferFields {
	public const GROUP = 'momo_offer_content';

	public const MAX_DAYS = 366;

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_groups' ], 8 );
		add_filter( 'momos/fields/validate_group/' . self::GROUP, [ self::class, 'validate_group' ], 10, 4 );
		add_filter( 'momos/fields/publish_errors/momo_offer', [ self::class, 'publish_errors' ], 10, 4 );
	}

	public static function register_groups(): void {
		$scope_choices = [
			OfferScope::AllBranches->value => __( 'All branches', 'momos-core' ),
			OfferScope::Branches->value    => __( 'Selected branches', 'momos-core' ),
		];
		if ( is_user_logged_in() && null !== momos_user_branch_ids() ) {
			unset( $scope_choices[ OfferScope::AllBranches->value ] );
		}
		$pub = 'publish_momo_offers';
		FieldRegistry::group(
			[
				'key'         => self::GROUP,
				'title'       => __( 'Offer', 'momos-core' ),
				'post_type'   => 'momo_offer',
				'description' => __( "Enter prices including tax. Coupon codes, online redemption and 'usual price' comparisons without evidence are not allowed.", 'momos-core' ),
				'fields'      => [
					[ 'name' => 'momo_offer_summary', 'label' => __( 'Summary', 'momos-core' ), 'type' => 'textarea', 'rows' => 2, 'maxlength' => 200, 'required' => 'publish', 'translatable' => true ],
					[ 'name' => 'momo_offer_conditions', 'label' => __( 'Conditions', 'momos-core' ), 'type' => 'textarea', 'rows' => 4, 'maxlength' => 500, 'required' => 'publish', 'translatable' => true, 'help' => __( 'Days, times, branches, party limits, "cannot be combined"…', 'momos-core' ) ],
					[ 'name' => 'momo_offer_start_at', 'label' => __( 'Start (JST)', 'momos-core' ), 'type' => 'datetime', 'required' => 'publish', 'column' => true ],
					[ 'name' => 'momo_offer_end_at', 'label' => __( 'End (JST)', 'momos-core' ), 'type' => 'datetime', 'required' => 'publish', 'column' => true ],
					[ 'name' => 'momo_offer_scope', 'label' => __( 'Branches', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'required' => true, 'default' => OfferScope::Branches->value, 'choices' => $scope_choices ],
					[ 'name' => 'momo_offer_branches', 'label' => __( 'Selected branches', 'momos-core' ), 'type' => 'taxonomy', 'taxonomy' => BranchScopeTaxonomy::TAX, 'multiple' => true, 'save_terms' => true, 'show_if' => [ 'momo_offer_scope' => OfferScope::Branches->value ] ],
					[ 'name' => 'momo_offer_price_jpy', 'label' => __( 'Offer price (JPY, tax incl.)', 'momos-core' ), 'type' => 'number', 'min' => 0, 'max' => 100000 ],
					[ 'name' => 'momo_offer_reference_price_jpy', 'label' => __( 'Reference price (JPY, tax incl.)', 'momos-core' ), 'type' => 'number', 'min' => 0, 'max' => 1000000, 'edit_cap' => $pub ],
					[ 'name' => 'momo_offer_reference_basis', 'label' => __( 'Reference price evidence (internal, never shown)', 'momos-core' ), 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500, 'edit_cap' => $pub ],
					[ 'name' => 'momo_offer_cta_type', 'label' => __( 'Button', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'required' => true, 'default' => OfferCta::RequestTable->value, 'choices' => [ 'none' => __( 'No button', 'momos-core' ), 'request_table' => __( 'Request a table', 'momos-core' ), 'menu' => __( 'Menu', 'momos-core' ), 'dish' => __( 'A dish', 'momos-core' ) ] ],
					[ 'name' => 'momo_offer_cta_dish', 'label' => __( 'Linked dish', 'momos-core' ), 'type' => 'post_object', 'post_type' => 'momo_dish', 'post_status' => [ 'publish' ], 'show_if' => [ 'momo_offer_cta_type' => OfferCta::Dish->value ] ],
					[ 'name' => 'momo_offer_cta_category', 'label' => __( 'Menu category (optional)', 'momos-core' ), 'type' => 'taxonomy', 'taxonomy' => 'momo_menu_category', 'show_if' => [ 'momo_offer_cta_type' => OfferCta::Menu->value ] ],
					[ 'name' => 'momo_offer_cta_label', 'label' => __( 'Button text (optional)', 'momos-core' ), 'type' => 'text', 'maxlength' => 30, 'translatable' => true ],
					[ 'name' => 'momo_offer_homepage_featured', 'label' => __( 'Feature on the homepage', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => $pub, 'column' => true ],
					[ 'name' => 'momo_offer_order', 'label' => __( 'Order', 'momos-core' ), 'type' => 'number', 'min' => -999, 'max' => 999, 'default' => '0' ],
					[ 'name' => 'momo_offer_review_note', 'label' => __( 'Review note (internal, required when returning to draft)', 'momos-core' ), 'type' => 'textarea', 'rows' => 2, 'maxlength' => 1000, 'edit_cap' => $pub ],
					[ 'name' => 'momo_is_sample', 'label' => __( 'Sample / demo content', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => 'momo_manage_brand', 'message' => __( 'Demo offer (never public on the live site)', 'momos-core' ) ],
					[ 'name' => 'momo_offer_approved_by', 'label' => __( 'Approved by', 'momos-core' ), 'type' => 'user', 'stamp' => 'user' ],
					[ 'name' => 'momo_offer_approved_at', 'label' => __( 'Approved at (UTC)', 'momos-core' ), 'type' => 'text', 'stamp' => 'datetime' ],
					[ 'name' => 'momo_offer_expired_at', 'label' => __( 'Expired at (UTC)', 'momos-core' ), 'type' => 'text', 'stamp' => 'datetime' ],
				],
			]
		);
		Translations::register( 'post:momo_offer', [ 'title' => [ 'label' => __( 'Title', 'momos-core' ), 'type' => 'text', 'source' => 'post_title', 'max' => 80 ] ] );
	}

	/**
	 * Rules for every save (spec §4.11 table).
	 *
	 * @param array<string,mixed> $v
	 * @return list<string>
	 */
	public static function errors( array $v, int $post_id, ?string $title = null ): array {
		$errors = [];
		$start  = ValidityMeta::to_instant( (string) ( $v['momo_offer_start_at'] ?? '' ) );
		$end    = ValidityMeta::to_instant( (string) ( $v['momo_offer_end_at'] ?? '' ) );
		if ( null !== $start && null !== $end ) {
			if ( $end <= $start ) {
				$errors[] = __( 'The end must be after the start.', 'momos-core' );
			} elseif ( $end > $start->modify( '+' . self::MAX_DAYS . ' days' ) ) {
				/* translators: %d: maximum days */
				$errors[] = sprintf( __( 'An offer can run for at most %d days.', 'momos-core' ), self::MAX_DAYS );
			}
		}
		$terms = (array) ( $v['momo_offer_branches'] ?? [] );
		if ( ! $terms && $post_id && ! metadata_exists( 'post', $post_id, 'momo_offer_branches' ) ) {
			$terms = (array) wp_get_object_terms( $post_id, BranchScopeTaxonomy::TAX, [ 'fields' => 'ids' ] ); // Created outside the form.
		}
		if ( OfferScope::Branches->value === (string) ( $v['momo_offer_scope'] ?? OfferScope::Branches->value ) && ! array_filter( $terms ) ) {
			$errors[] = __( 'Select at least one branch.', 'momos-core' );
		}
		$cta = (string) ( $v['momo_offer_cta_type'] ?? OfferCta::RequestTable->value );
		if ( OfferCta::Dish->value === $cta ) {
			$dish = (int) ( $v['momo_offer_cta_dish'] ?? 0 );
			if ( ! $dish || 'momo_dish' !== get_post_type( $dish ) || 'publish' !== get_post_status( $dish ) || '1' === (string) get_post_meta( $dish, 'momo_dish_retired', true ) ) {
				$errors[] = __( 'The linked dish is not published.', 'momos-core' );
			}
		}
		if ( '' !== (string) ( $v['momo_offer_reference_price_jpy'] ?? '' ) && '' === trim( (string) ( $v['momo_offer_reference_basis'] ?? '' ) ) ) {
			$errors[] = __( 'A reference price needs written evidence (internal note).', 'momos-core' );
		}
		// BR-CORP-01 / CMP-040: refuse absolute claim wording ("100% halal", seals …) via Dietary.
		if ( class_exists( \Momos\Dietary\Guard\WordingGuard::class ) ) {
			foreach ( [ $title ?? (string) get_the_title( $post_id ), (string) ( $v['momo_offer_summary'] ?? '' ), (string) ( $v['momo_offer_conditions'] ?? '' ) ] as $text ) {
				foreach ( \Momos\Dietary\Guard\WordingGuard::blocking_violations( $text ) as $hit ) {
					/* translators: %s: forbidden phrase */
					$errors[] = sprintf( __( 'This wording is not allowed in offers: "%s".', 'momos-core' ), $hit['term'] );
				}
			}
		}
		return array_values( array_unique( $errors ) );
	}

	/**
	 * Required set for submit/publish ("Complete title, conditions and validity dates.").
	 *
	 * @param array<string,mixed> $v
	 */
	public static function required_error( array $v, string $title ): ?string {
		foreach ( [ 'momo_offer_conditions', 'momo_offer_start_at', 'momo_offer_end_at' ] as $k ) {
			if ( '' === trim( (string) ( $v[ $k ] ?? '' ) ) ) {
				return __( 'Complete title, conditions and validity dates.', 'momos-core' );
			}
		}
		return '' === trim( $title ) ? __( 'Complete title, conditions and validity dates.', 'momos-core' ) : null;
	}

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $new_all
	 * @param array<string,mixed> $changes
	 * @return list<string>
	 */
	public static function validate_group( array $errors, array $new_all, array $changes, int|string $object_id ): array {
		$errors = array_merge( $errors, self::errors( $new_all, (int) $object_id ) );
		if ( 'pending' === get_post_status( (int) $object_id ) ) {
			$req = self::required_error( $new_all, (string) get_the_title( (int) $object_id ) );
			if ( null !== $req ) {
				$errors[] = $req;
			}
		}
		if ( ! $errors && '1' === (string) ( $new_all['momo_offer_homepage_featured'] ?? '0' ) ) {
			$featured = count( array_diff( OfferQuery::featured_active_ids(), [ (int) $object_id ] ) ) + 1;
			if ( $featured > OfferQuery::HOME_LIMIT ) {
				/* translators: %d: number of featured offers shown */
				FieldRegistry::add_errors( [ sprintf( __( 'Only the first %d featured offers are shown on the homepage.', 'momos-core' ), OfferQuery::HOME_LIMIT ) ] );
			}
		}
		return $errors;
	}

	/**
	 * Publish-time rules: required set, already ended, sample content in production.
	 *
	 * @param list<string>        $errors
	 * @param array<string,mixed> $merged
	 * @param array<string,mixed> $data
	 * @return list<string>
	 */
	public static function publish_errors( array $errors, array $merged, array $data, int $post_id ): array {
		$title = (string) ( $data['post_title'] ?? '' );
		$req   = self::required_error( $merged, $title );
		if ( null !== $req ) {
			$errors[] = $req;
		}
		if ( mb_strlen( $title ) > 80 ) {
			$errors[] = __( 'The title can have at most 80 characters.', 'momos-core' );
		}
		$end = ValidityMeta::to_instant( (string) ( $merged['momo_offer_end_at'] ?? '' ) );
		if ( null !== $end && $end <= Clock::utc() ) {
			$errors[] = __( 'This offer has already ended.', 'momos-core' );
		}
		if ( '1' === (string) ( $merged['momo_is_sample'] ?? '0' ) && Env::is_production() ) {
			$errors[] = __( 'Sample content cannot be published on the live site.', 'momos-core' );
		}
		return array_values( array_unique( array_merge( $errors, self::errors( $merged, $post_id, $title ) ) ) );
	}
}
