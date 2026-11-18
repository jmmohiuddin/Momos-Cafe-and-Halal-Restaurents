<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Fields\OfferFields;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Query\OfferQuery;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Jobs\Schedule;

/**
 * Offer lifecycle (FR-CORP-010/011, BR-CORP-02, spec §4.11):
 * draft → pending ("Submit for review", `offer.submitted`) → publish (HO "Approve and publish":
 * stamps `momo_offer_approved_by/_at`, `offer.published`, purge actions at start/end). HO "Return
 * to draft" requires `momo_offer_review_note`. A user without `publish_momo_offers` asking for
 * publish is forced to `pending`. Withdrawal (publish → draft/pending, or end moved to ≤ now) is
 * audited `offer.withdrawn`. "Duplicate as draft" for staff who may not edit published offers.
 */
final class OfferService {
	/** @var array<int,bool> */
	private static array $just_published = [];

	public static function register(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'before_save' ], 7, 2 );
		add_action( 'transition_post_status', [ self::class, 'transition' ], 10, 3 );
		add_action( 'save_post', [ self::class, 'after_save' ], 20, 2 );
		add_action( 'momos/fields/saved', [ self::class, 'fields_saved' ], 10, 5 );
		add_action( 'admin_post_momos_duplicate_offer', [ self::class, 'handle_duplicate' ] );
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function before_save( array $data, array $postarr ): array {
		if ( 'momo_offer' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$old     = $post_id ? get_post_status( $post_id ) : false;
		$new     = (string) ( $data['post_status'] ?? 'draft' );
		if ( in_array( $new, [ 'publish', 'future' ], true ) && is_user_logged_in() && ! current_user_can( 'publish_momo_offers' ) && 'publish' !== $old ) {
			$data['post_status'] = 'pending'; // BR-CORP-02: only HO publishes.
		}
		if ( 'pending' === $old && 'draft' === $new && current_user_can( 'publish_momo_offers' ) && (int) ( $data['post_author'] ?? 0 ) !== get_current_user_id() ) {
			$submitted = isset( $_POST['momos_fields']['momo_offer_review_note'] ) ? trim( (string) wp_unslash( $_POST['momos_fields']['momo_offer_review_note'] ) ) : null; // phpcs:ignore
			$note      = $submitted ?? trim( (string) get_post_meta( $post_id, 'momo_offer_review_note', true ) );
			if ( '' === $note ) {
				$data['post_status'] = 'pending';
				FieldRegistry::add_errors( [ __( 'Add a review note when returning an offer to draft.', 'momos-core' ) ] );
			}
		}
		return $data;
	}

	public static function transition( string $new, string $old, \WP_Post $post ): void {
		if ( 'momo_offer' !== $post->post_type || $new === $old ) {
			return;
		}
		$branch = self::first_branch( $post->ID );
		if ( 'publish' === $new ) {
			update_post_meta( $post->ID, 'momo_offer_approved_by', get_current_user_id() );
			update_post_meta( $post->ID, 'momo_offer_approved_at', Clock::mysql_utc() );
			delete_post_meta( $post->ID, 'momo_offer_expired_at' );
			self::$just_published[ $post->ID ] = true;
			return;
		}
		if ( 'pending' === $new && 'publish' !== $old ) {
			momos_audit( 'offer.submitted', 'offer', $post->ID, $branch, self::context( $post->ID ) );
		} elseif ( 'trash' === $new ) {
			momos_audit( 'offer.trashed', 'offer', $post->ID, $branch, self::context( $post->ID ) );
		} elseif ( 'publish' === $old ) {
			momos_audit( 'offer.withdrawn', 'offer', $post->ID, $branch, [ 'post_status' => [ $old, $new ] ] + self::context( $post->ID ) );
		}
		if ( 'publish' === $old ) {
			OfferQuery::sync_visibility( $post->ID );
		}
	}

	public static function after_save( int $post_id, \WP_Post $post ): void {
		if ( 'momo_offer' !== $post->post_type || empty( self::$just_published[ $post_id ] ) ) {
			return;
		}
		unset( self::$just_published[ $post_id ] );
		momos_audit( 'offer.published', 'offer', $post_id, self::first_branch( $post_id ), self::context( $post_id ) + [ 'approved_by' => get_current_user_id() ] );
		self::schedule_purges( $post_id );
		OfferQuery::sync_visibility( $post_id );
	}

	/** @param array<string, array{0:mixed,1:mixed}> $changes */
	public static function fields_saved( string $type, int|string $object_id, string $group_key, array $changes, int $user_id ): void {
		$id = (int) $object_id;
		if ( OfferFields::GROUP !== $group_key || ! $changes || 'publish' !== get_post_status( $id ) || ! empty( self::$just_published[ $id ] ) ) {
			return;
		}
		$changes = array_diff_key( $changes, [ 'momo_offer_reference_basis' => 1, 'momo_offer_review_note' => 1 ] ) + ( isset( $changes['momo_offer_reference_basis'] ) ? [ 'momo_offer_reference_basis' => [ '…', '…' ] ] : [] );
		$end     = ValidityMeta::instant( $id, 'end' );
		$action  = isset( $changes['momo_offer_end_at'] ) && null !== $end && $end <= Clock::utc() ? 'offer.withdrawn' : 'offer.updated';
		momos_audit( $action, 'offer', $id, self::first_branch( $id ), [ 'changes' => $changes ] + self::context( $id ) );
		self::schedule_purges( $id );
		OfferQuery::sync_visibility( $id );
	}

	public static function schedule_purges( int $id ): void {
		$now = Clock::utc();
		foreach ( [ 'start', 'end' ] as $which ) {
			$at = ValidityMeta::instant( $id, $which );
			if ( null !== $at && $at > $now ) {
				Schedule::at( $at, 'momos_purge_scheduled', [ $id ] );
			}
		}
	}

	/** Copy all fields except approval/expiry stamps into a new draft (spec §4.11). */
	public static function duplicate( int $offer_id, int $user_id ): int {
		$src = get_post( $offer_id );
		if ( ! $src || 'momo_offer' !== $src->post_type ) {
			throw new \InvalidArgumentException( 'Not an offer' );
		}
		$new_id = (int) wp_insert_post(
			[
				'post_type'   => 'momo_offer',
				'post_status' => 'draft',
				'post_title'  => $src->post_title,
				'post_author' => $user_id,
			],
			true
		);
		if ( ! $new_id ) {
			throw new \RuntimeException( 'Could not duplicate offer' );
		}
		$skip = [ 'momo_offer_approved_by', 'momo_offer_approved_at', 'momo_offer_expired_at', 'momo_offer_review_note', '_edit_lock', '_edit_last', '_momos_was_published' ];
		foreach ( get_post_meta( $offer_id ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) || str_starts_with( (string) $key, '_wp_' ) ) {
				continue;
			}
			update_post_meta( $new_id, $key, maybe_unserialize( $values[0] ) );
		}
		$terms = wp_get_object_terms( $offer_id, BranchScopeTaxonomy::TAX, [ 'fields' => 'ids' ] );
		if ( is_array( $terms ) ) {
			wp_set_object_terms( $new_id, array_map( 'intval', $terms ), BranchScopeTaxonomy::TAX );
		}
		$thumb = get_post_thumbnail_id( $offer_id );
		if ( $thumb ) {
			set_post_thumbnail( $new_id, $thumb );
		}
		momos_audit( 'offer.updated', 'offer', $new_id, self::first_branch( $new_id ), [ 'duplicated_from' => $offer_id ] );
		return $new_id;
	}

	public static function handle_duplicate(): void {
		$id = isset( $_GET['offer'] ) ? absint( $_GET['offer'] ) : 0; // phpcs:ignore -- nonce checked next.
		check_admin_referer( 'momos_duplicate_offer_' . $id );
		if ( ! current_user_can( 'create_momo_offers' ) || ! current_user_can( 'read_post', $id ) || 'momo_offer' !== get_post_type( $id ) ) {
			BranchAccess::deny( 'create_momo_offers', self::first_branch( $id ), 'offer', $id );
			wp_die( esc_html__( 'You are not allowed to duplicate this offer.', 'momos-core' ), '', [ 'response' => 403 ] );
		}
		$scope_error = BranchAccess::scope_error( 'all_branches' === get_post_meta( $id, 'momo_offer_scope', true ), BranchScopeTaxonomy::branch_ids_of_post( $id ) );
		if ( null !== $scope_error ) {
			BranchAccess::deny( 'momo_access_all_branches', self::first_branch( $id ), 'offer', $id, [ 'code' => $scope_error ] );
			wp_die( esc_html__( 'You do not have access to this branch.', 'momos-core' ), '', [ 'response' => 403 ] );
		}
		$new = self::duplicate( $id, get_current_user_id() );
		wp_safe_redirect( (string) get_edit_post_link( $new, 'raw' ) );
		exit;
	}

	/** @return array<string,mixed> */
	private static function context( int $id ): array {
		$u = wp_get_current_user();
		return [
			'scope'    => OfferQuery::scope( $id ),
			'branches' => BranchScopeTaxonomy::branch_ids_of_post( $id ),
			'role'     => $u && $u->roles ? implode( ',', $u->roles ) : 'system',
		];
	}

	private static function first_branch( int $id ): ?int {
		$b = BranchScopeTaxonomy::branch_ids_of_post( $id );
		return $b ? $b[0] : null;
	}
}
