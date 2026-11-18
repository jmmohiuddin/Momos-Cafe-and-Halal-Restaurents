<?php
declare(strict_types=1);

namespace Momos\Dietary\Guard;

use Momos\Dietary\Allergens;
use Momos\Dietary\ContentTypes;

/**
 * Server-side guard for claim fields (BR-DIET-04, FR-DIET-008, spec §3.12, TC-DIET-009/010, TC-ACL-023):
 * dish G10, branch G7 and certificate G14 meta can be written only by users holding the per-user
 * capability `momo_edit_dietary_claims` (CAP-DCA) — whatever the write path (meta box, crafted POST,
 * REST, plugin code running as that user). Denied writes keep the old value and are audited
 * `access.denied` (field names only, never the request body). System writes (jobs, CLI, watchers) run
 * inside system() or with no current user.
 */
final class ClaimFieldGuard {
	public const CAP = 'momo_edit_dietary_claims';

	public const BRANCH_KEYS = [ 'momo_halal_claim_type', 'momo_halal_claim_record', 'momo_halal_statement_key', 'momo_alcohol_served', 'momo_alcohol_in_cooking', 'momo_alcohol_cooking_attest', 'momo_alcohol_note_key', 'momo_claims_verified_by', 'momo_claims_verified_at' ];

	public const DISH_KEYS = [ 'momo_alg_custom', 'momo_alg_source_document', 'momo_alg_sourced_by', 'momo_alg_attest_not_source', 'momo_dish_halal_basis', 'momo_dish_claim_record', 'momo_allergen_review_required', 'momo_claims_verified_by', 'momo_claims_verified_at' ];

	private static int $system = 0;

	/** @var array<string,bool> */
	private static array $audited = [];

	public static function register(): void {
		add_filter( 'update_post_metadata', [ self::class, 'filter_update' ], 5, 5 );
		add_filter( 'add_post_metadata', [ self::class, 'filter_add' ], 5, 5 );
		add_filter( 'delete_post_metadata', [ self::class, 'filter_delete' ], 5, 5 );
		add_action( 'momos/fields/denied', [ self::class, 'on_fields_denied' ], 10, 5 );
	}

	/**
	 * Run trusted system code (jobs, watchers, stamps) that writes claim meta.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function system( callable $fn ) {
		++self::$system;
		try {
			return $fn();
		} finally {
			--self::$system;
		}
	}

	public static function is_claim_key( string $post_type, string $key ): bool {
		return match ( $post_type ) {
			'momo_dish'              => in_array( $key, self::DISH_KEYS, true ) || ( str_starts_with( $key, 'momo_alg_' ) && in_array( substr( $key, 9 ), Allergens::codes(), true ) ),
			'momo_branch'            => in_array( $key, self::BRANCH_KEYS, true ),
			ContentTypes::CERTIFICATE => str_starts_with( $key, 'momo_cert_' ),
			default                  => false,
		};
	}

	public static function allowed(): bool {
		if ( self::$system > 0 ) {
			return true;
		}
		$uid = get_current_user_id();
		return 0 === $uid || current_user_can( self::CAP );
	}

	/** @param mixed $check */
	public static function filter_update( $check, int $object_id, string $meta_key, $meta_value, $prev_value ) {
		return self::decide( $check, $object_id, $meta_key, (string) maybe_serialize( get_post_meta( $object_id, $meta_key, true ) ) !== (string) maybe_serialize( $meta_value ) );
	}

	/** @param mixed $check */
	public static function filter_add( $check, int $object_id, string $meta_key, $meta_value, $unique ) {
		return self::decide( $check, $object_id, $meta_key, true );
	}

	/** @param mixed $check */
	public static function filter_delete( $check, int $object_id, string $meta_key, $meta_value, $delete_all ) {
		return self::decide( $check, $object_id, $meta_key, metadata_exists( 'post', $object_id, $meta_key ) );
	}

	/** @param mixed $check */
	private static function decide( $check, int $object_id, string $meta_key, bool $is_change ) {
		if ( null !== $check || ! $is_change || $object_id <= 0 ) {
			return $check;
		}
		$type = get_post_type( $object_id );
		if ( ! $type || ! self::is_claim_key( $type, $meta_key ) || self::allowed() ) {
			return $check;
		}
		self::deny( $type, $object_id, [ $meta_key ] );
		return false;
	}

	/** @param list<string> $denied */
	public static function on_fields_denied( string $type, $object_id, string $group_key, array $denied, int $user_id ): void {
		if ( 'post' !== $type || ! in_array( $group_key, [ \Momos\Dietary\Fields\DietaryFields::G10, \Momos\Dietary\Fields\DietaryFields::G7, \Momos\Dietary\Fields\DietaryFields::G14 ], true ) ) {
			return;
		}
		self::deny( (string) get_post_type( (int) $object_id ), (int) $object_id, $denied );
	}

	/** @param list<string> $fields */
	private static function deny( string $type, int $object_id, array $fields ): void {
		$k = $object_id . ':' . implode( ',', $fields );
		if ( isset( self::$audited[ $k ] ) ) {
			return;
		}
		self::$audited[ $k ] = true;
		$branch              = 'momo_branch' === $type ? $object_id : null;
		momos_audit( 'access.denied', str_replace( 'momo_', '', $type ), $object_id, $branch, [ 'reason' => 'cap_dca_required', 'fields' => array_values( $fields ) ] );
		\Momos\Kernel\Fields\FieldRegistry::add_errors( [ __( 'Only Dietary Claims Approvers can change these fields', 'momos-core' ) ] );
	}

	/** Reset per-request dedupe (tests). */
	public static function reset(): void {
		self::$audited = [];
	}
}
