<?php
declare(strict_types=1);

namespace Momos\Menu\Rest;

use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Menu\ContentTypes;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Service\BranchMenuService;
use Momos\Menu\Support\Branches;
use Momos\Menu\Support\MenuItemException;

/**
 * Staff branch menu API (spec §7.1/§7.2):
 *   API-14 GET   /momos/v1/staff/branches/{code}/menu-items
 *   API-15 PATCH /momos/v1/staff/branches/{code}/menu-items/{dish_id}
 * Cookie auth + REST nonce (core); capability + branch scope checked server-side (TC-ACL-005).
 */
final class BranchMenuController {
	public const NS = 'momos/v1';

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/staff/branches/(?P<code>[A-Za-z0-9]{2,10})/menu-items',
			[
				'methods'             => 'GET',
				'callback'            => [ self::class, 'index' ],
				'permission_callback' => [ self::class, 'can_access' ],
				'args'                => [
					'category'     => [ 'type' => 'string', 'required' => false ],
					'offered'      => [ 'type' => 'string', 'enum' => [ 'true', 'false' ], 'required' => false ],
					'availability' => [ 'type' => 'string', 'enum' => [ 'available', 'sold_out', 'unavailable' ], 'required' => false ],
					'q'            => [ 'type' => 'string', 'required' => false ],
					'page'         => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
					'per_page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/staff/branches/(?P<code>[A-Za-z0-9]{2,10})/menu-items/(?P<dish_id>\d+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ self::class, 'patch' ],
				'permission_callback' => [ self::class, 'can_access' ],
			]
		);
	}

	/** @return true|\WP_Error */
	public static function can_access( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Please sign in.', 'momos-core' ), [ 'status' => 401 ] );
		}
		foreach ( [ 'momo_edit_branch_menu', 'momo_edit_branch_prices', 'momo_edit_branch_availability' ] as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		momos_audit( 'access.denied', 'branch_menu_item', null, null, [ 'reason' => 'rest_forbidden', 'route' => 'menu-items' ] );
		return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to do this.', 'momos-core' ), [ 'status' => 403 ] );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function index( \WP_REST_Request $request ) {
		Locale::set( Locale::admin() );
		$branch_id = self::branch( (string) $request['code'] );
		if ( is_wp_error( $branch_id ) ) {
			return $branch_id;
		}
		$user_id  = get_current_user_id();
		$page     = max( 1, (int) $request['page'] );
		$per      = min( 100, max( 1, (int) $request['per_page'] ) );
		$result   = self::list_items(
			$branch_id,
			[
				'category'     => (string) ( $request['category'] ?? '' ),
				'offered'      => $request['offered'] ?? null,
				'availability' => (string) ( $request['availability'] ?? '' ),
				'q'            => (string) ( $request['q'] ?? '' ),
			],
			$page,
			$per,
			Locale::admin( $user_id )
		);
		$response = new \WP_REST_Response(
			[
				'branch_code' => Branches::code( $branch_id ),
				'permissions' => BranchMenuService::permissions( $user_id, $branch_id ),
				'items'       => $result['items'],
			],
			200
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $per ) ) );
		return $response;
	}

	/**
	 * Shared listing for the API and the SCR-A07 grid: every published dish (base and variants).
	 *
	 * @param array{category:string,offered:mixed,availability:string,q:string} $filters
	 * @return array{items:list<array<string,mixed>>,total:int}
	 */
	public static function list_items( int $branch_id, array $filters, int $page, int $per_page, string $admin_lang ): array {
		$args = [
			'post_type'      => ContentTypes::DISH,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => [ 'menu_order' => 'ASC', 'title' => 'ASC' ],
			'no_found_rows'  => true,
		];
		if ( '' !== $filters['category'] ) {
			$args['tax_query'] = [ [ 'taxonomy' => ContentTypes::CATEGORY, 'field' => 'slug', 'terms' => sanitize_title( $filters['category'] ) ] ]; // phpcs:ignore
		}
		$ids = array_map( 'intval', get_posts( $args ) );
		update_postmeta_cache( $ids );
		$q = trim( $filters['q'] );
		if ( '' !== $q ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( int $id ) use ( $q, $admin_lang ): bool {
						$p = get_post( $id );
						return false !== mb_stripos( (string) $p->post_title, $q ) || false !== mb_stripos( Translations::post( $p, 'title', $admin_lang ), $q ) || false !== stripos( (string) get_post_meta( $id, 'momo_dish_sku', true ), $q );
					}
				)
			);
		}
		$rows = BranchMenuItemRepository::for_branch( $branch_id, $ids );
		if ( null !== $filters['offered'] && '' !== (string) $filters['offered'] ) {
			$want = in_array( $filters['offered'], [ true, 'true', '1', 1 ], true );
			$ids  = array_values( array_filter( $ids, static fn( int $id ) => ( isset( $rows[ $id ] ) && 1 === (int) $rows[ $id ]['is_offered'] ) === $want ) );
		}
		if ( '' !== $filters['availability'] ) {
			$ids = array_values( array_filter( $ids, static fn( int $id ) => isset( $rows[ $id ] ) && $rows[ $id ]['availability'] === $filters['availability'] ) );
		}
		$total = count( $ids );
		$ids   = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
		$items = [];
		foreach ( $ids as $id ) {
			$p       = get_post( $id );
			$name    = Translations::post( $p, 'title', $admin_lang );
			$primary = get_term( (int) get_post_meta( $id, 'momo_primary_category', true ), ContentTypes::CATEGORY );
			$items[] = [
				'dish_id'             => $id,
				'dish_sku'            => (string) get_post_meta( $id, 'momo_dish_sku', true ),
				'name'                => '' !== $name ? $name : (string) $p->post_title,
				'category'            => $primary instanceof \WP_Term ? $primary->slug : '',
				'reference_price_jpy' => BranchMenuService::reference_price( $id ),
				'is_variant'          => (int) $p->post_parent > 0,
				'thumbnail_id'        => (int) get_post_thumbnail_id( $id ),
				'row'                 => BranchMenuService::present( $rows[ $id ] ?? null, $id ),
			];
		}
		return [ 'items' => $items, 'total' => $total ];
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function patch( \WP_REST_Request $request ) {
		Locale::set( Locale::admin() );
		$ct = (string) ( $request->get_content_type()['value'] ?? '' );
		if ( 'application/json' !== $ct ) {
			return new \WP_Error( 'unsupported_media_type', __( 'Send the request as application/json.', 'momos-core' ), [ 'status' => 415 ] );
		}
		if ( strlen( (string) $request->get_body() ) > 4096 ) {
			return new \WP_Error( 'payload_too_large', __( 'The request is too large.', 'momos-core' ), [ 'status' => 413 ] );
		}
		$body = json_decode( (string) $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'invalid_json', __( 'The request body is not valid JSON.', 'momos-core' ), [ 'status' => 400 ] );
		}
		$branch_id = self::branch( (string) $request['code'] );
		if ( is_wp_error( $branch_id ) ) {
			return $branch_id;
		}
		try {
			$row = BranchMenuService::update( $branch_id, (int) $request['dish_id'], $body, get_current_user_id() );
			return new \WP_REST_Response( [ 'dish_id' => (int) $request['dish_id'], 'row' => $row ], 200 );
		} catch ( MenuItemException $e ) {
			return $e->to_wp_error();
		} catch ( \Throwable $e ) {
			\Momos\Kernel\Log\Logger::error( 'menu.branch_item_patch_failed', [ 'error' => $e->getMessage() ] );
			return new \WP_Error( 'internal_error', __( 'Something went wrong. Please try again.', 'momos-core' ), [ 'status' => 500 ] );
		}
	}

	/** @return int|\WP_Error */
	private static function branch( string $code ) {
		$id = Branches::id_by_code( $code );
		if ( null === $id || ! Branches::is_published( $id ) ) {
			return new \WP_Error( 'branch_not_found', __( 'Branch not found.', 'momos-core' ), [ 'status' => 404 ] );
		}
		if ( ! momos_user_can_branch( $id ) ) {
			momos_audit( 'access.denied', 'branch', $id, $id, [ 'reason' => 'forbidden_branch', 'route' => 'menu-items' ] );
			return new \WP_Error( 'forbidden_branch', __( 'You do not have access to this branch.', 'momos-core' ), [ 'status' => 403 ] );
		}
		return $id;
	}
}
