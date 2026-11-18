<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Fields\BranchFields;
use Momos\Branch\Repository\HoursRepository;
use Momos\Kernel\Clock\Clock;

/**
 * Publish / update rules for `momo_branch` (spec §4.1, BR-BR-03 GATE-01, DEC-047, DEC-052).
 * Hooked on `momos/fields/publish_errors/momo_branch` (runs inside FieldRegistry's
 * wp_insert_post_data validation): a failed first publication is saved as draft; a published
 * branch keeps its status and the invalid changes are rejected.
 */
final class BranchPublishValidator {
	/** Days a re-confirmation stays valid for identity changes (§4.1 ⟦7⟧). */
	public const RECONFIRM_DAYS = 7;

	public static function register(): void {
		add_filter( 'momos/fields/publish_errors/momo_branch', [ self::class, 'publish_errors' ], 10, 4 );
	}

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $merged field values after this save
	 * @param array<string,mixed> $data   post data
	 * @return list<string>
	 */
	public static function publish_errors( array $errors, array $merged, array $data, int $post_id ): array {
		return array_values( array_unique( array_merge( $errors, self::errors( $merged, $data, $post_id ) ) ) );
	}

	/**
	 * @param array<string,mixed> $v    merged field values
	 * @param array<string,mixed> $data post data (post_title, post_name, …)
	 * @return list<string>
	 */
	public static function errors( array $v, array $data, int $post_id ): array {
		$errors = [];
		if ( is_user_logged_in() && ! current_user_can( 'publish_momo_branches' ) && ! self::was_published( $post_id ) ) {
			$errors[] = __( 'Only Head Office can publish a branch.', 'momos-core' );
		}
		$title = trim( (string) ( $data['post_title'] ?? '' ) );
		if ( '' === $title || mb_strlen( $title ) > 80 ) {
			$errors[] = __( 'Enter a branch name of 1–80 characters.', 'momos-core' );
		}
		$slug_error = self::slug_error( (string) ( $data['post_name'] ?? '' ), $post_id, BranchGuard::requested_slug() );
		if ( null !== $slug_error ) {
			$errors[] = $slug_error;
		}
		$code_error = self::code_error( (string) ( $v['momo_branch_code'] ?? '' ), $post_id );
		if ( null !== $code_error ) {
			$errors[] = $code_error;
		}
		foreach ( [ [ 'momo_lat', 20.0, 46.0 ], [ 'momo_lng', 122.0, 154.0 ] ] as [ $k, $min, $max ] ) {
			if ( '' === (string) ( $v[ $k ] ?? '' ) || null !== BranchFields::coord_error( (string) $v[ $k ], $min, $max ) ) {
				$errors[] = __( 'Enter latitude and longitude with at least 5 decimal places.', 'momos-core' );
				break;
			}
		}
		if ( ! preg_match( '/^\+81\d{9,10}$/', (string) ( $v['momo_phone_e164'] ?? '' ) ) ) {
			$errors[] = __( 'Enter a valid phone number.', 'momos-core' );
		}
		// GATE-01: written facts confirmation (BR-BR-03).
		$by = trim( (string) ( $v['momo_facts_confirmed_by'] ?? '' ) );
		$at = (string) ( $v['momo_facts_confirmed_at'] ?? '' );
		if ( '' === $by || '' === $at || $at > Clock::jst()->format( 'Y-m-d' ) ) {
			$errors[] = self::gate_message();
		} elseif ( self::was_published( $post_id ) && self::identity_changed( $v, $data, $post_id ) ) {
			$prev = (string) get_post_meta( $post_id, 'momo_facts_confirmed_at', true );
			$min  = Clock::jst()->modify( '-' . self::RECONFIRM_DAYS . ' days' )->format( 'Y-m-d' );
			if ( $at < $prev || $at < $min ) {
				$errors[] = self::gate_message();
			}
		}
		// Reservations on: hours complete and an active recipient (§4.1 rule 5).
		if ( '1' === (string) ( $v['momo_res_enabled'] ?? '0' ) ) {
			$rows = HoursRepository::all_rows( $post_id );
			if ( ! $rows || array_filter( $rows, static fn( $r ) => null === $r['last_order_at'] ) || ! self::has_active_recipient( $v['momo_res_notification_recipients'] ?? [] ) ) {
				$errors[] = __( 'Online requests can be turned on only when the branch is published and open, has an active recipient and every opening period has a last-order time.', 'momos-core' );
			}
		}
		// No illustrative images on branches (DEC-047).
		$images = array_map( 'intval', (array) ( $v['momo_gallery'] ?? [] ) );
		$thumb  = isset( $_POST['_thumbnail_id'] ) ? (int) $_POST['_thumbnail_id'] : (int) get_post_thumbnail_id( $post_id ); // phpcs:ignore -- read-only check.
		if ( $thumb > 0 ) {
			$images[] = $thumb;
		}
		$ill = self::illustrative_error( $images );
		if ( null !== $ill ) {
			$errors[] = $ill;
		}
		return $errors;
	}

	public static function gate_message(): string {
		return __( "Record the owner's written confirmation before publishing (GATE-01).", 'momos-core' );
	}

	/** `^[A-Z]{3}$` and unique among branches (DEC-052). */
	public static function code_error( string $code, int $post_id ): ?string {
		$msg = __( 'Branch code must be 3 capital letters and unique.', 'momos-core' );
		if ( ! preg_match( '/^[A-Z]{3}$/', $code ) ) {
			return $msg;
		}
		$others = get_posts(
			[
				'post_type'        => 'momo_branch',
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'post__not_in'     => [ $post_id ],
				'meta_key'         => 'momo_branch_code', // phpcs:ignore
				'meta_value'       => $code, // phpcs:ignore
				'no_found_rows'    => true,
				'suppress_filters' => true,
			]
		);
		return $others ? $msg : null;
	}

	/**
	 * Slug `^[a-z0-9-]+$`, unique; an error instead of WordPress's silent "-2" suffix.
	 *
	 * @param string|null $requested the slug the user typed (null = not submitted)
	 */
	public static function slug_error( string $slug, int $post_id, ?string $requested = null ): ?string {
		$msg = __( 'Use lowercase letters, numbers and hyphens; this slug is already used.', 'momos-core' );
		if ( null !== $requested && '' !== $requested && $requested !== $slug ) {
			return $msg; // WordPress had to alter it (taken or invalid).
		}
		if ( '' === $slug || ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return $msg;
		}
		global $wpdb;
		$taken = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'momo_branch' AND post_name = %s AND ID <> %d AND post_status NOT IN ('trash','auto-draft') LIMIT 1", $slug, $post_id ) ); // phpcs:ignore
		return $taken ? $msg : null;
	}

	/** @param list<int> $attachment_ids */
	public static function illustrative_error( array $attachment_ids ): ?string {
		foreach ( $attachment_ids as $aid ) {
			if ( $aid > 0 && '1' === (string) get_post_meta( $aid, 'momo_image_illustrative', true ) ) {
				return __( 'Demo images cannot be used here. Upload a real photo.', 'momos-core' );
			}
		}
		return null;
	}

	private static function was_published( int $post_id ): bool {
		return $post_id > 0 && ( 'publish' === get_post_status( $post_id ) || '' !== (string) get_post_meta( $post_id, 'momo_first_published_at', true ) );
	}

	/**
	 * @param array<string,mixed> $v
	 * @param array<string,mixed> $data
	 */
	private static function identity_changed( array $v, array $data, int $post_id ): bool {
		$old = get_post( $post_id );
		if ( $old && isset( $data['post_title'] ) && (string) $data['post_title'] !== (string) $old->post_title ) {
			return true;
		}
		foreach ( BranchFields::RECONFIRM_KEYS as $k ) {
			if ( array_key_exists( $k, $v ) && (string) $v[ $k ] !== (string) get_post_meta( $post_id, $k, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function has_active_recipient( mixed $rows ): bool {
		foreach ( SettingsValidator::rows( $rows ) as $r ) {
			if ( ! empty( $r['active'] ) && '0' !== (string) $r['active'] && is_email( (string) ( $r['email'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
