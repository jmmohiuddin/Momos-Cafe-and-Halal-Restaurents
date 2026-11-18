<?php
declare(strict_types=1);

namespace Momos\Dietary\Fields;

use Momos\Dietary\Allergens;
use Momos\Dietary\ContentTypes;
use Momos\Dietary\Domain\AlcoholValue;
use Momos\Dietary\Domain\AllergenClass;
use Momos\Dietary\Domain\AllergenState;
use Momos\Dietary\Domain\CertificateScope;
use Momos\Dietary\Domain\ClaimStatus;
use Momos\Dietary\Domain\ClaimType;
use Momos\Dietary\Domain\HalalBasis;
use Momos\Dietary\Guard\ClaimFieldGuard;
use Momos\Dietary\Service\CertificateService;
use Momos\Dietary\Service\StatementRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Locale;
use Momos\Menu\Support\CachePurge;

/**
 * Field groups G10 (dish dietary & allergens), G7 (branch dietary), G12 (allergen term) and G14
 * (claim/certificate record) — TRD §2.5, spec §2.3/§2.7/§2.8. Every claim field has edit cap CAP-DCA.
 * Cross-field rules use `momos/fields/validate_group/{key}` (rejects the whole change set); stamps and
 * audits run in the group `on_save` callbacks.
 */
final class DietaryFields {
	public const G7  = 'group_momo_branch_dietary';
	public const G10 = 'group_momo_dish_dietary';
	public const G12 = 'group_momo_allergen';
	public const G14 = 'group_momo_certificate';

	public static function register(): void {
		$cap   = ClaimFieldGuard::CAP;
		$ja    = 'ja' === Locale::admin();
		$state = AllergenState::choices();

		// ------------------------------------------------------------------ G10
		$fields = [];
		foreach ( Allergens::config() as $a ) {
			$fields[] = [
				'name'     => Allergens::meta_key( $a['code'] ),
				'label'    => ( $ja ? $a['ja'] : $a['en'] ) . ( 'mandatory' === $a['class'] ? ' *' : '' ),
				'type'     => 'radio',
				'choices'  => $state,
				'default'  => AllergenState::NotVerified->value,
				'edit_cap' => $cap,
			];
		}
		$fields[] = [
			'name'       => 'momo_alg_custom',
			'label'      => __( 'Other allergens (custom items)', 'momos-core' ),
			'type'       => 'repeater',
			'edit_cap'   => $cap,
			'max_rows'   => 30,
			'validate'   => [ self::class, 'validate_custom_allergens' ],
			'sub_fields' => [
				[ 'name' => 'term_id', 'label' => __( 'Allergen', 'momos-core' ), 'type' => 'taxonomy', 'taxonomy' => Allergens::TAXONOMY, 'required' => true ],
				[ 'name' => 'state', 'label' => __( 'State', 'momos-core' ), 'type' => 'select', 'choices' => $state, 'no_empty' => true, 'default' => 'not_verified' ],
			],
		];
		$fields[] = [ 'name' => 'momo_alg_source_document', 'label' => __( 'Kitchen source document', 'momos-core' ), 'type' => 'text', 'maxlength' => 120, 'edit_cap' => $cap, 'help' => __( 'e.g. “Recipe card D0123 v2, 20 Nov 2026”. Required when any state is not “Not verified”.', 'momos-core' ) ];
		$fields[] = [ 'name' => 'momo_alg_sourced_by', 'label' => __( 'Provided by (person 1)', 'momos-core' ), 'type' => 'text', 'maxlength' => 80, 'edit_cap' => $cap, 'help' => __( 'Kitchen person who supplied the information; must not be you.', 'momos-core' ) ];
		$fields[] = [ 'name' => 'momo_alg_attest_not_source', 'label' => __( 'Two-person rule', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => $cap, 'message' => __( 'I am not the source person for this record', 'momos-core' ) ];
		$fields[] = [ 'name' => 'momo_dish_halal_basis', 'label' => __( 'Halal basis', 'momos-core' ), 'type' => 'select', 'choices' => HalalBasis::choices(), 'no_empty' => true, 'default' => HalalBasis::BranchDefault->value, 'edit_cap' => $cap ];
		$fields[] = [
			'name'        => 'momo_dish_claim_record',
			'label'       => __( 'Claim record', 'momos-core' ),
			'type'        => 'post_object',
			'post_type'   => ContentTypes::CERTIFICATE,
			'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
			'meta_query'  => [ [ 'key' => 'momo_cert_status', 'value' => 'active' ] ],
			'edit_cap'    => $cap,
			'show_if'     => [ 'momo_dish_halal_basis' => [ 'certified_item', 'halal_ingredients' ] ],
		];
		$fields[] = [ 'name' => 'momo_allergen_review_required', 'label' => __( 'Review required', 'momos-core' ), 'type' => 'true_false', 'readonly' => true, 'default' => '0' ];
		$fields[] = [ 'name' => 'momo_claims_verified_by', 'label' => __( 'Verified by', 'momos-core' ), 'type' => 'user', 'stamp' => 'user' ];
		$fields[] = [ 'name' => 'momo_claims_verified_at', 'label' => __( 'Verified at (UTC)', 'momos-core' ), 'type' => 'text', 'stamp' => 'datetime' ];

		FieldRegistry::group(
			[
				'key'         => self::G10,
				'title'       => __( 'Dietary & allergens', 'momos-core' ),
				'post_type'   => 'momo_dish',
				'order'       => 30,
				'description' => __( 'Allergen states and halal basis. Only Dietary Claims Approvers can change these fields. No “set all” — each item is decided individually.', 'momos-core' ),
				'fields'      => $fields,
				'on_save'     => [ self::class, 'on_save_dish' ],
			]
		);
		add_filter( 'momos/fields/validate_group/' . self::G10, [ self::class, 'validate_dish' ], 10, 4 );

		// ------------------------------------------------------------------ G7
		$statement_choices = StatementRepository::choices( [ 'certified', 'halal_ingredients', 'no_claim' ] );
		$alcohol_choices   = StatementRepository::choices( [ 'alcohol' ] );
		FieldRegistry::group(
			[
				'key'       => self::G7,
				'title'     => __( 'Dietary', 'momos-core' ),
				'post_type' => 'momo_branch',
				'order'     => 40,
				'on_save'   => [ self::class, 'on_save_branch' ],
				'fields'    => [
					[ 'name' => 'momo_halal_claim_type', 'label' => __( 'Halal claim type', 'momos-core' ), 'type' => 'select', 'choices' => ClaimType::choices(), 'no_empty' => true, 'default' => ClaimType::NoClaim->value, 'edit_cap' => $cap ],
					[
						'name'        => 'momo_halal_claim_record',
						'label'       => __( 'Claim record', 'momos-core' ),
						'type'        => 'post_object',
						'post_type'   => ContentTypes::CERTIFICATE,
						'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
						'meta_query'  => [ [ 'key' => 'momo_cert_status', 'value' => 'active' ] ],
						'edit_cap'    => $cap,
						'show_if'     => [ 'momo_halal_claim_type' => [ 'certified', 'halal_ingredients' ] ],
					],
					[ 'name' => 'momo_halal_statement_key', 'label' => __( 'Statement template', 'momos-core' ), 'type' => 'select', 'choices' => $statement_choices, 'edit_cap' => $cap, 'help' => __( 'Approved template, translated in every enabled language. Empty = record default / “no claim”.', 'momos-core' ) ],
					[ 'name' => 'momo_alcohol_served', 'label' => __( 'Alcohol served', 'momos-core' ), 'type' => 'select', 'choices' => AlcoholValue::choices(), 'no_empty' => true, 'default' => 'unknown', 'edit_cap' => $cap ],
					[ 'name' => 'momo_alcohol_in_cooking', 'label' => __( 'Alcohol used in cooking', 'momos-core' ), 'type' => 'select', 'choices' => AlcoholValue::choices(), 'no_empty' => true, 'default' => 'unknown', 'edit_cap' => $cap ],
					[ 'name' => 'momo_alcohol_cooking_attest', 'label' => __( 'Evidence', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => $cap, 'show_if' => [ 'momo_alcohol_in_cooking' => 'no' ], 'message' => __( 'Specification sheets of every component confirm that no alcohol is used', 'momos-core' ) ],
					[ 'name' => 'momo_alcohol_note_key', 'label' => __( 'Alcohol note template', 'momos-core' ), 'type' => 'select', 'choices' => $alcohol_choices, 'edit_cap' => $cap ],
					[ 'name' => 'momo_claims_verified_by', 'label' => __( 'Verified by', 'momos-core' ), 'type' => 'user', 'stamp' => 'user' ],
					[ 'name' => 'momo_claims_verified_at', 'label' => __( 'Verified at (UTC)', 'momos-core' ), 'type' => 'text', 'stamp' => 'datetime' ],
				],
			]
		);
		add_filter( 'momos/fields/validate_group/' . self::G7, [ self::class, 'validate_branch' ], 10, 4 );

		// ------------------------------------------------------------------ G12
		FieldRegistry::group(
			[
				'key'      => self::G12,
				'title'    => __( 'Allergen settings', 'momos-core' ),
				'taxonomy' => Allergens::TAXONOMY,
				'fields'   => [
					[ 'name' => 'momo_alg_code', 'label' => __( 'Code', 'momos-core' ), 'type' => 'text', 'maxlength' => 30, 'pattern' => '^[a-z_]{2,30}$', 'validate' => [ self::class, 'validate_alg_code' ], 'edit_cap' => $cap ],
					[ 'name' => 'momo_alg_class', 'label' => __( 'Class', 'momos-core' ), 'type' => 'select', 'choices' => AllergenClass::choices(), 'default' => 'other', 'no_empty' => true, 'validate' => [ self::class, 'validate_alg_class' ], 'edit_cap' => $cap ],
					[ 'name' => 'momo_alg_icon', 'label' => __( 'Icon', 'momos-core' ), 'type' => 'image', 'edit_cap' => $cap ],
					[ 'name' => 'momo_alg_sort', 'label' => __( 'Sort order', 'momos-core' ), 'type' => 'number', 'step' => 1, 'min' => 0, 'max' => 999, 'default' => '100', 'edit_cap' => $cap ],
					[ 'name' => 'momo_alg_seeded', 'label' => __( 'Seeded reference item', 'momos-core' ), 'type' => 'true_false', 'readonly' => true, 'default' => '0' ],
				],
			]
		);
		add_action( 'created_' . Allergens::TAXONOMY, [ self::class, 'default_custom_allergen' ], 20 );

		// ------------------------------------------------------------------ G14
		FieldRegistry::group(
			[
				'key'       => self::G14,
				'title'     => __( 'Claim record', 'momos-core' ),
				'post_type' => ContentTypes::CERTIFICATE,
				'order'     => 10,
				'on_save'   => [ self::class, 'on_save_certificate' ],
				'fields'    => [
					[ 'name' => 'momo_cert_claim_type', 'label' => __( 'Claim type', 'momos-core' ), 'type' => 'select', 'choices' => ClaimType::choices(), 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_statement_key', 'label' => __( 'Statement template', 'momos-core' ), 'type' => 'select', 'choices' => $statement_choices, 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_certifier_name', 'label' => __( 'Certifier', 'momos-core' ), 'type' => 'text', 'maxlength' => 120, 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_scope', 'label' => __( 'Scope', 'momos-core' ), 'type' => 'select', 'choices' => CertificateScope::choices(), 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_number', 'label' => __( 'Certificate number', 'momos-core' ), 'type' => 'text', 'maxlength' => 60, 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_valid_from', 'label' => __( 'Valid from (JST)', 'momos-core' ), 'type' => 'date', 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_valid_until', 'label' => __( 'Valid until (JST)', 'momos-core' ), 'type' => 'date', 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_covered_ingredients', 'label' => __( 'Covered ingredients', 'momos-core' ), 'type' => 'taxonomy', 'taxonomy' => 'momo_ingredient', 'multiple' => true, 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_public_register_url', 'label' => __( 'Certifier public register URL', 'momos-core' ), 'type' => 'url', 'https_only' => true, 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_source_document', 'label' => __( 'Source document (name/version and person 1)', 'momos-core' ), 'type' => 'text', 'maxlength' => 200, 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_mark_permission', 'label' => __( 'Mark permission', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => $cap, 'message' => __( 'Written permission to show the certification mark', 'momos-core' ) ],
					[ 'name' => 'momo_cert_mark_image', 'label' => __( 'Mark image (PNG/WebP)', 'momos-core' ), 'type' => 'image', 'edit_cap' => $cap, 'show_if' => [ 'momo_cert_mark_permission' => '1' ] ],
					[ 'name' => 'momo_cert_branches', 'label' => __( 'Branches', 'momos-core' ), 'type' => 'relationship', 'post_type' => 'momo_branch', 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_dishes', 'label' => __( 'Dishes', 'momos-core' ), 'type' => 'relationship', 'post_type' => 'momo_dish', 'post_status' => [ 'publish' ], 'edit_cap' => $cap ],
					[ 'name' => 'momo_cert_status', 'label' => __( 'Status', 'momos-core' ), 'type' => 'select', 'choices' => ClaimStatus::choices(), 'readonly' => true, 'default' => 'pending_verification' ],
					[ 'name' => 'momo_cert_revocation_reason', 'label' => __( 'Revocation reason', 'momos-core' ), 'type' => 'text', 'readonly' => true ],
					[ 'name' => 'momo_cert_verified_by', 'label' => __( 'Verified by', 'momos-core' ), 'type' => 'user', 'stamp' => 'user' ],
					[ 'name' => 'momo_cert_verified_at', 'label' => __( 'Verified at (UTC)', 'momos-core' ), 'type' => 'text', 'stamp' => 'datetime' ],
					[ 'name' => 'momo_cert_internal_note', 'label' => __( 'Internal note (never public)', 'momos-core' ), 'type' => 'textarea', 'maxlength' => 2000, 'edit_cap' => $cap ],
				],
			]
		);
		add_filter( 'momos/fields/validate_group/' . self::G14, [ self::class, 'validate_certificate' ], 10, 4 );
	}

	// ================================================================= G10

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $v
	 * @param array<string,mixed> $changes
	 * @return list<string>
	 */
	public static function validate_dish( array $errors, array $v, array $changes, int|string $dish_id ): array {
		$material = array_diff( array_keys( $changes ), [ 'momo_alg_attest_not_source' ] );
		if ( ! $changes ) {
			return $errors;
		}
		if ( self::any_state_set( $v ) ) {
			$doc = trim( (string) ( $v['momo_alg_source_document'] ?? '' ) );
			$by  = trim( (string) ( $v['momo_alg_sourced_by'] ?? '' ) );
			if ( '' === $doc || '' === $by ) {
				$errors[] = __( 'Record the kitchen source document and who provided it.', 'momos-core' );
			} elseif ( self::is_current_user_name( $by ) ) {
				$errors[] = __( 'The source person must be someone other than you (two-person rule).', 'momos-core' );
			}
			if ( '1' !== (string) ( $v['momo_alg_attest_not_source'] ?? '0' ) ) {
				$errors[] = __( 'Confirm that you are not the source person for this record.', 'momos-core' );
			}
		} elseif ( ! $material ) {
			return $errors;
		}
		$basis  = (string) ( $v['momo_dish_halal_basis'] ?? 'branch_default' );
		$record = (int) ( $v['momo_dish_claim_record'] ?? 0 );
		if ( 'certified_item' === $basis && ! CertificateService::record_links_dish( $record, (int) $dish_id, 'certified', 'specific_menu' ) ) {
			$errors[] = __( 'Link an active specific-menu certificate that lists this dish before choosing “Certified item”.', 'momos-core' );
		}
		if ( 'halal_ingredients' === $basis && ! CertificateService::record_links_dish( $record, (int) $dish_id, 'halal_ingredients', null ) ) {
			$errors[] = __( 'Link an active halal-ingredients record that lists this dish before choosing “Halal ingredients”.', 'momos-core' );
		}
		return $errors;
	}

	/** @param array<string,mixed> $v */
	public static function any_state_set( array $v ): bool {
		foreach ( Allergens::codes() as $code ) {
			if ( AllergenState::NotVerified->value !== (string) ( $v[ Allergens::meta_key( $code ) ] ?? 'not_verified' ) ) {
				return true;
			}
		}
		foreach ( (array) ( $v['momo_alg_custom'] ?? [] ) as $row ) {
			if ( AllergenState::NotVerified->value !== (string) ( $row['state'] ?? 'not_verified' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_current_user_name( string $name ): bool {
		$u = wp_get_current_user();
		if ( ! $u->exists() ) {
			return false;
		}
		$n = mb_strtolower( trim( $name ) );
		return in_array( $n, array_map( static fn( $x ) => mb_strtolower( trim( (string) $x ) ), [ $u->display_name, $u->user_login, trim( $u->first_name . ' ' . $u->last_name ) ] ), true );
	}

	/** @param array<string,mixed> $all */
	public static function validate_custom_allergens( mixed $rows ): ?string {
		$seen = [];
		foreach ( (array) $rows as $row ) {
			$tid = (int) ( $row['term_id'] ?? 0 );
			if ( 'other' !== (string) get_term_meta( $tid, 'momo_alg_class', true ) ) {
				return __( 'Only custom allergens (class “other”) can be added here.', 'momos-core' );
			}
			if ( isset( $seen[ $tid ] ) ) {
				return __( 'Each custom allergen can be listed only once.', 'momos-core' );
			}
			$seen[ $tid ] = true;
		}
		return null;
	}

	/**
	 * Stamp, clear the review flag, reset the per-save attestation, audit (two-person rule, FR-DIET-008).
	 *
	 * @param array<string,mixed>                  $v
	 * @param array<string,array{0:mixed,1:mixed}> $changes
	 */
	public static function on_save_dish( int|string $dish_id, array $v, array $changes ): void {
		$dish_id = (int) $dish_id;
		$uid     = get_current_user_id();
		ClaimFieldGuard::system(
			static function () use ( $dish_id, $uid ): void {
				update_post_meta( $dish_id, 'momo_claims_verified_by', $uid );
				update_post_meta( $dish_id, 'momo_claims_verified_at', Clock::mysql_utc() );
				update_post_meta( $dish_id, 'momo_allergen_review_required', '0' );
				update_post_meta( $dish_id, 'momo_alg_attest_not_source', '0' );
			}
		);
		unset( $changes['momo_alg_attest_not_source'] );
		$before = [];
		$after  = [];
		foreach ( $changes as $k => [ $old, $new ] ) {
			$before[ $k ] = $old;
			$after[ $k ]  = $new;
		}
		momos_audit( 'dietary.dish_claims_updated', 'dish', $dish_id, null, [ 'before' => $before, 'after' => $after, 'source_document' => (string) ( $v['momo_alg_source_document'] ?? '' ) ] );
		AfterCommit::dispatch( 'momos/dietary/claims_changed', 'dish', $dish_id );
		CachePurge::dish( $dish_id );
		CachePurge::claims( [], [ $dish_id ] );
	}

	// ================================================================= G7

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $v
	 * @param array<string,mixed> $changes
	 * @return list<string>
	 */
	public static function validate_branch( array $errors, array $v, array $changes, int|string $branch_id ): array {
		if ( ! $changes ) {
			return $errors;
		}
		$type   = (string) ( $v['momo_halal_claim_type'] ?? 'no_claim' );
		$record = (int) ( $v['momo_halal_claim_record'] ?? 0 );
		$key    = (string) ( $v['momo_halal_statement_key'] ?? '' );
		if ( in_array( $type, [ 'certified', 'halal_ingredients' ], true ) && ! CertificateService::record_links_branch( $record, (int) $branch_id, $type ) ) {
			$errors[] = __( 'Link an active certificate before selecting a certified statement.', 'momos-core' );
		}
		if ( '' !== $key ) {
			$row      = StatementRepository::get( $key );
			$expected = 'certified' === $type ? 'certified.' . (string) get_post_meta( $record, 'momo_cert_scope', true ) : $type;
			if ( null === $row || ! $row['approved'] || $row['claim_type'] !== $type || ( 'certified' === $type && $key !== $expected ) ) {
				$errors[] = __( 'Choose an approved statement template that matches the claim type and scope.', 'momos-core' );
			} elseif ( ! StatementRepository::is_ready_for_all_enabled( $key ) ) {
				$errors[] = __( 'The statement template must be approved and translated in every enabled language.', 'momos-core' );
			}
		}
		if ( 'no' === (string) ( $v['momo_alcohol_in_cooking'] ?? 'unknown' ) && '1' !== (string) ( $v['momo_alcohol_cooking_attest'] ?? '0' ) ) {
			$errors[] = __( '“Not used in cooking” requires specification-sheet evidence for every component.', 'momos-core' );
		}
		$note = (string) ( $v['momo_alcohol_note_key'] ?? '' );
		if ( '' !== $note ) {
			$row = StatementRepository::get( $note );
			if ( null === $row || 'alcohol' !== $row['claim_type'] || ! $row['approved'] ) {
				$errors[] = __( 'Choose an approved alcohol template.', 'momos-core' );
			}
		}
		return $errors;
	}

	/**
	 * @param array<string,mixed>                  $v
	 * @param array<string,array{0:mixed,1:mixed}> $changes
	 */
	public static function on_save_branch( int|string $branch_id, array $v, array $changes ): void {
		$branch_id = (int) $branch_id;
		$uid       = get_current_user_id();
		ClaimFieldGuard::system(
			static function () use ( $branch_id, $uid ): void {
				update_post_meta( $branch_id, 'momo_claims_verified_by', $uid );
				update_post_meta( $branch_id, 'momo_claims_verified_at', Clock::mysql_utc() );
			}
		);
		$before = [];
		$after  = [];
		foreach ( $changes as $k => [ $old, $new ] ) {
			$before[ $k ] = $old;
			$after[ $k ]  = $new;
		}
		momos_audit( 'dietary.branch_claims_updated', 'branch', $branch_id, $branch_id, [ 'before' => $before, 'after' => $after ] );
		AfterCommit::dispatch( 'momos/dietary/claims_changed', 'branch', $branch_id );
		CachePurge::claims( [ $branch_id ] );
	}

	// ================================================================= G12

	public static function validate_alg_code( mixed $value, int|string $term_id ): ?string {
		$code = (string) $value;
		$old  = (string) get_term_meta( (int) $term_id, 'momo_alg_code', true );
		if ( '1' === (string) get_term_meta( (int) $term_id, 'momo_alg_seeded', true ) && $old !== $code ) {
			return __( 'The code of a seeded allergen cannot be changed.', 'momos-core' );
		}
		$other = Allergens::term_by_code( $code );
		return ( null !== $other && (int) $other->term_id !== (int) $term_id ) ? __( 'This allergen code is already used.', 'momos-core' ) : null;
	}

	public static function validate_alg_class( mixed $value, int|string $term_id ): ?string {
		$seeded = '1' === (string) get_term_meta( (int) $term_id, 'momo_alg_seeded', true );
		$old    = (string) get_term_meta( (int) $term_id, 'momo_alg_class', true );
		if ( $seeded && $old !== (string) $value ) {
			return __( 'The class of a seeded allergen cannot be changed.', 'momos-core' );
		}
		if ( ! $seeded && 'other' !== (string) $value ) {
			return __( 'Custom allergens have the class “other”.', 'momos-core' );
		}
		return null;
	}

	/** New custom allergen without a code: derive it from the slug, class other (every dish reads not_verified). */
	public static function default_custom_allergen( int $term_id ): void {
		if ( '' === (string) get_term_meta( $term_id, 'momo_alg_code', true ) ) {
			$t    = get_term( $term_id );
			$code = $t instanceof \WP_Term ? substr( (string) preg_replace( '/[^a-z_]/', '_', strtolower( str_replace( '-', '_', $t->slug ) ) ), 0, 30 ) : '';
			if ( preg_match( '/^[a-z_]{2,30}$/', $code ) && null === Allergens::term_by_code( $code ) ) {
				update_term_meta( $term_id, 'momo_alg_code', $code );
			}
		}
		if ( '' === (string) get_term_meta( $term_id, 'momo_alg_class', true ) ) {
			update_term_meta( $term_id, 'momo_alg_class', 'other' );
		}
	}

	// ================================================================= G14

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $v
	 * @param array<string,mixed> $changes
	 * @return list<string>
	 */
	public static function validate_certificate( array $errors, array $v, array $changes, int|string $cert_id ): array {
		if ( ! $changes ) {
			return $errors;
		}
		$from  = (string) ( $v['momo_cert_valid_from'] ?? '' );
		$until = (string) ( $v['momo_cert_valid_until'] ?? '' );
		if ( '' !== $from && '' !== $until && $until < $from ) {
			$errors[] = __( 'Valid until must be on or after valid from.', 'momos-core' );
		}
		$key = (string) ( $v['momo_cert_statement_key'] ?? '' );
		if ( '' !== $key ) {
			$row = StatementRepository::get( $key );
			if ( null === $row || $row['claim_type'] !== (string) ( $v['momo_cert_claim_type'] ?? '' ) ) {
				$errors[] = __( 'The statement template must be of the same claim type.', 'momos-core' );
			}
		}
		$mark = (int) ( $v['momo_cert_mark_image'] ?? 0 );
		if ( $mark > 0 && ! in_array( get_post_mime_type( $mark ), [ 'image/png', 'image/webp' ], true ) ) {
			$errors[] = __( 'The mark image must be PNG or WebP.', 'momos-core' );
		}
		return $errors;
	}

	/**
	 * @param array<string,mixed>                  $v
	 * @param array<string,array{0:mixed,1:mixed}> $changes
	 */
	public static function on_save_certificate( int|string $cert_id, array $v, array $changes ): void {
		$cert_id = (int) $cert_id;
		$mark    = (int) ( $v['momo_cert_mark_image'] ?? 0 );
		if ( $mark > 0 ) {
			update_post_meta( $mark, 'momo_protected_mark', 1 );
		}
		$log = [];
		foreach ( $changes as $k => [ $old, $new ] ) {
			$log[ $k ] = 'momo_cert_internal_note' === $k ? '(changed)' : [ $old, $new ];
		}
		momos_audit( 'certificate.updated', 'certificate', $cert_id, null, [ 'changes' => $log ] );
		CertificateService::after_fields_changed( $cert_id, array_keys( $changes ) );
	}
}
