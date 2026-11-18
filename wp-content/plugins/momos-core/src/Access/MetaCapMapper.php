<?php
declare(strict_types=1);

namespace Momos\Access;

use Momos\Kernel\Db\Tables;
use Momos\Kernel\Log\Logger;

/**
 * Central meta-capability rules M1–M12 (TRD §2.7.3, Access spec §4.2, SEC-010). Runs on
 * `map_meta_cap` priority 10; `user_has_cap` is not used. Module guards (Branch, Dietary,
 * Reservation) apply the same rules for their objects and only ever add restrictions, so the
 * layers compose. Any exception while resolving scope fails closed (`do_not_allow`).
 *
 * Request-originated denials of the custom object caps (M2, M8, M10, M11) are audited
 * `access.denied` by the caller that renders the 403 (never the request body).
 */
final class MetaCapMapper {
	/** M2 aspect → primitive capability. */
	public const ASPECTS = [
		'identity'     => 'momo_edit_branch_identity',
		'settings'     => 'momo_edit_branch_settings',
		'hours'        => 'momo_edit_branch_hours',
		'content'      => 'edit_momo_branches',
		'menu'         => 'momo_edit_branch_menu',
		'availability' => 'momo_edit_branch_availability',
		'prices'       => 'momo_edit_branch_prices',
	];

	/** M10 field → primitive capability. */
	public const MENU_ITEM_FIELDS = [
		'offered'      => 'momo_edit_branch_menu',
		'is_offered'   => 'momo_edit_branch_menu',
		'price'        => 'momo_edit_branch_prices',
		'price_jpy'    => 'momo_edit_branch_prices',
		'availability' => 'momo_edit_branch_availability',
	];

	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map' ], 10, 4 );
		add_filter( 'editable_roles', [ self::class, 'editable_roles' ] );
	}

	/**
	 * @param list<string>     $caps
	 * @param array<int,mixed> $args
	 * @return list<string>
	 */
	public static function map( $caps, $cap, $user_id, $args ): array {
		$caps    = (array) $caps;
		$user_id = (int) $user_id;
		try {
			return self::resolve( $caps, (string) $cap, $user_id, (array) $args );
		} catch ( \Throwable $e ) {
			Logger::error( 'access.map_failed', [ 'cap' => (string) $cap ] );
			return [ 'do_not_allow' ];
		}
	}

	/**
	 * @param list<string>     $caps
	 * @param array<int,mixed> $args
	 * @return list<string>
	 */
	private static function resolve( array $caps, string $cap, int $user_id, array $args ): array {
		switch ( $cap ) {
			case 'momo_edit_branch': // M2
				$branch = (int) ( $args[0] ?? 0 );
				$prim   = self::ASPECTS[ (string) ( $args[1] ?? 'content' ) ] ?? null;
				if ( null === $prim || ! self::is_post_type( $branch, 'momo_branch' ) || ! BranchScope::assigned( $user_id, $branch ) ) {
					return [ 'do_not_allow' ];
				}
				return [ $prim ];

			case 'momo_edit_branch_menu_item': // M10
				$branch = (int) ( $args[0] ?? 0 );
				$fields = array_values( array_filter( (array) ( $args[2] ?? [ 'offered' ] ) ) );
				if ( ! self::is_post_type( $branch, 'momo_branch' ) || ! BranchScope::assigned( $user_id, $branch ) || ! $fields ) {
					return [ 'do_not_allow' ];
				}
				$out = [];
				foreach ( $fields as $f ) {
					$p = self::MENU_ITEM_FIELDS[ (string) $f ] ?? null;
					if ( null === $p ) {
						return [ 'do_not_allow' ];
					}
					$out[] = $p;
				}
				return array_values( array_unique( $out ) );

			case 'momo_view_reservation': // M8
			case 'momo_process_reservation':
				$row = self::reservation( (int) ( $args[0] ?? 0 ) );
				if ( null === $row || ! BranchScope::assigned( $user_id, (int) $row['branch_id'] ) ) {
					return [ 'do_not_allow' ];
				}
				return [ 'momo_view_reservation' === $cap ? 'momo_view_reservations' : 'momo_process_reservations' ];

			case 'momo_download_evidence': // M11
				return self::is_post_type( (int) ( $args[0] ?? 0 ), 'momo_certificate' ) ? [ 'momo_manage_certificates', 'momo_edit_dietary_claims' ] : [ 'do_not_allow' ];

			case 'customize': // M7
				return [ 'manage_options' ];

			case 'edit_user':
			case 'promote_user':
			case 'delete_user':
			case 'remove_user':
				return self::map_user( $caps, $cap, $user_id, (int) ( $args[0] ?? 0 ) );

			case 'edit_post':
			case 'delete_post':
			case 'publish_post':
			case 'read_post':
				return self::map_post( $caps, $cap, $user_id, (int) ( $args[0] ?? 0 ) );
		}
		return $caps;
	}

	/**
	 * M9: administrators are untouchable without `manage_options`; nobody edits their own role;
	 * delete/remove is never offered in web requests (BR-ADM-01, accounts are disabled instead).
	 *
	 * @param list<string> $caps
	 * @return list<string>
	 */
	private static function map_user( array $caps, string $cap, int $actor, int $target ): array {
		if ( in_array( $cap, [ 'delete_user', 'remove_user' ], true ) ) {
			return ( defined( 'WP_CLI' ) && WP_CLI ) ? $caps : [ 'do_not_allow' ];
		}
		if ( $target <= 0 ) {
			return $caps;
		}
		if ( 'promote_user' === $cap && $target === $actor ) {
			return [ 'do_not_allow' ];
		}
		$t = get_userdata( $target );
		if ( $t && in_array( 'administrator', $t->roles, true ) && $target !== $actor && ! user_can( $actor, 'manage_options' ) ) {
			return [ 'do_not_allow' ];
		}
		return $caps;
	}

	/**
	 * M1 branch posts, M3 notices/offers, M4 certificates (CAP-DCA), M5 legal pages, M12 certificate
	 * read scope.
	 *
	 * @param list<string> $caps
	 * @return list<string>
	 */
	private static function map_post( array $caps, string $cap, int $user_id, int $post_id ): array {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return $caps;
		}
		switch ( $post->post_type ) {
			case 'momo_branch': // M1
				if ( 'read_post' !== $cap && 'auto-draft' !== $post->post_status && ! BranchScope::assigned( $user_id, (int) $post->ID ) ) {
					$caps[] = 'do_not_allow';
				}
				return $caps;

			case 'momo_notice':
			case 'momo_offer': // M3
				if ( 'read_post' === $cap || 'auto-draft' === $post->post_status ) {
					return $caps;
				}
				if ( 'momo_offer' === $post->post_type && 'edit_post' === $cap && in_array( $post->post_status, [ 'publish', 'future' ], true ) && ! user_can( $user_id, 'publish_momo_offers' ) ) {
					$caps[] = 'do_not_allow';
					return $caps;
				}
				$scope = momos_user_branch_ids( $user_id );
				if ( null === $scope ) {
					return $caps;
				}
				$key      = 'momo_notice' === $post->post_type ? 'momo_notice_scope' : 'momo_offer_scope';
				$global   = in_array( (string) get_post_meta( $post->ID, $key, true ), [ 'site_wide', 'all_branches' ], true );
				$branches = class_exists( \Momos\Branch\PostType\BranchScopeTaxonomy::class ) ? \Momos\Branch\PostType\BranchScopeTaxonomy::branch_ids_of_post( $post->ID ) : [];
				$own_new  = ! $branches && 'draft' === $post->post_status && (int) $post->post_author === $user_id;
				if ( $global || ( ! $branches && ! $own_new ) || array_diff( $branches, $scope ) ) {
					$caps[] = 'do_not_allow';
				}
				return $caps;

			case 'momo_certificate':
				if ( 'read_post' === $cap ) { // M12
					if ( ! user_can( $user_id, 'read_private_momo_certificates' ) ) {
						return [ 'do_not_allow' ];
					}
					$scope = momos_user_branch_ids( $user_id );
					if ( null !== $scope && ! array_intersect( self::certificate_branches( (int) $post->ID ), $scope ) ) {
						return [ 'do_not_allow' ];
					}
					return [ 'read_private_momo_certificates' ];
				}
				if ( 'delete_post' === $cap ) {
					return [ 'do_not_allow' ]; // Claim records are revoked, never deleted.
				}
				$caps[] = 'momo_edit_dietary_claims'; // M4
				return array_values( array_unique( $caps ) );

			case 'page': // M5
				if ( 'legal' !== (string) get_post_meta( $post->ID, 'momo_page_type', true ) || 'read_post' === $cap ) {
					return $caps;
				}
				if ( 'publish_post' === $cap || in_array( $post->post_status, [ 'publish', 'future' ], true ) ) {
					$caps[] = 'momo_publish_legal_pages';
				}
				return array_values( array_unique( $caps ) );
		}
		return $caps;
	}

	/** Remove `administrator` from the role picker for users without manage_options (M9). @param array<string,mixed> $roles */
	public static function editable_roles( $roles ): array {
		$roles = (array) $roles;
		if ( ! current_user_can( 'manage_options' ) ) {
			unset( $roles['administrator'] );
		}
		foreach ( \Momos\Access\Roles\RoleInstaller::REMOVED_CORE_ROLES as $r ) {
			unset( $roles[ $r ] );
		}
		return $roles;
	}

	/** Branches linked to a certificate directly or through a dish offered there. @return list<int> */
	public static function certificate_branches( int $cert_id ): array {
		$branches = array_map( 'intval', (array) get_post_meta( $cert_id, 'momo_cert_branches', true ) );
		$dishes   = array_filter( array_map( 'intval', (array) get_post_meta( $cert_id, 'momo_cert_dishes', true ) ) );
		if ( $dishes ) {
			global $wpdb;
			$t        = Tables::menu_items();
			$in       = implode( ',', array_fill( 0, count( $dishes ), '%d' ) );
			$branches = array_merge( $branches, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT branch_id FROM {$t} WHERE is_offered = 1 AND dish_id IN ({$in})", $dishes ) ) ) ); // phpcs:ignore
		}
		return array_values( array_unique( array_filter( $branches ) ) );
	}

	private static function is_post_type( int $id, string $type ): bool {
		return $id > 0 && get_post_type( $id ) === $type;
	}

	/** @return array<string,mixed>|null */
	private static function reservation( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		if ( class_exists( \Momos\Reservation\Repository\ReservationRepository::class ) ) {
			return \Momos\Reservation\Repository\ReservationRepository::find( $id );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, branch_id FROM ' . Tables::reservations() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore
		return $row ?: null;
	}
}
