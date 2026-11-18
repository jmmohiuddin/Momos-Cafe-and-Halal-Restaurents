<?php
declare(strict_types=1);

namespace Momos\Dietary\Guard;

use Momos\Dietary\ContentTypes;

/**
 * Certificate access rules (M4, spec §2.8/§3.12/§4):
 *  - `*_momo_certificates` primitives are effective only together with CAP-DCA (map_meta_cap);
 *    `read_private_momo_certificates` (MM/BM summary view) is unaffected.
 *  - Records are never deleted or trashed (revoke instead).
 *  - New records start `pending_verification`; creation is audited `certificate.created`.
 *  - Protected certification marks are hidden from the media picker for users without CAP-DCA.
 */
final class CertificateGuard {
	public const CAPS = [ 'edit_momo_certificates', 'edit_others_momo_certificates', 'edit_published_momo_certificates', 'publish_momo_certificates', 'create_momo_certificates', 'edit_private_momo_certificates' ];

	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map_caps' ], 20, 4 );
		add_filter( 'pre_delete_post', [ self::class, 'block_delete' ], 10, 2 );
		add_filter( 'pre_trash_post', [ self::class, 'block_delete' ], 10, 2 );
		add_action( 'transition_post_status', [ self::class, 'on_create' ], 10, 3 );
		add_filter( 'ajax_query_attachments_args', [ self::class, 'hide_marks' ] );
	}

	/** @param list<string> $caps @param array<int,mixed> $args @return list<string> */
	public static function map_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( in_array( $cap, self::CAPS, true ) ) {
			$caps[] = ClaimFieldGuard::CAP;
			return $caps;
		}
		if ( in_array( $cap, [ 'edit_post', 'publish_post', 'delete_post' ], true ) && isset( $args[0] ) && ContentTypes::CERTIFICATE === get_post_type( (int) $args[0] ) ) {
			if ( 'delete_post' === $cap ) {
				return [ 'do_not_allow' ];
			}
			$caps[] = ClaimFieldGuard::CAP;
		}
		return $caps;
	}

	/** @param mixed $check */
	public static function block_delete( $check, $post ) {
		if ( null === $check && $post instanceof \WP_Post && ContentTypes::CERTIFICATE === $post->post_type ) {
			\Momos\Kernel\Fields\FieldRegistry::add_errors( [ __( 'Claim records are never deleted. Revoke the record instead.', 'momos-core' ) ] );
			return false;
		}
		return $check;
	}

	public static function on_create( string $new, string $old, \WP_Post $post ): void {
		if ( ContentTypes::CERTIFICATE !== $post->post_type || ! in_array( $old, [ 'new', 'auto-draft' ], true ) || in_array( $new, [ 'auto-draft', 'inherit' ], true ) ) {
			return;
		}
		if ( '' === (string) get_post_meta( $post->ID, 'momo_cert_status', true ) ) {
			ClaimFieldGuard::system( static fn() => update_post_meta( $post->ID, 'momo_cert_status', 'pending_verification' ) );
		}
		momos_audit( 'certificate.created', 'certificate', $post->ID, null, [ 'status' => 'pending_verification' ] );
	}

	/** @param array<string,mixed> $query @return array<string,mixed> */
	public static function hide_marks( array $query ): array {
		if ( current_user_can( ClaimFieldGuard::CAP ) ) {
			return $query;
		}
		$query['meta_query']   = (array) ( $query['meta_query'] ?? [] ); // phpcs:ignore
		$query['meta_query'][] = [ 'key' => 'momo_protected_mark', 'compare' => 'NOT EXISTS' ];
		return $query;
	}
}
