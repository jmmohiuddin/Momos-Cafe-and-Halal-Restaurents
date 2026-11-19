<?php
declare(strict_types=1);

namespace Momos\Menu\Admin;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Locale;
use Momos\Menu\ContentTypes;
use Momos\Menu\Rest\BranchMenuController;
use Momos\Menu\Service\BranchMenuService;
use Momos\Menu\Support\Branches;
use Momos\Menu\Support\MenuItemException;

/**
 * SCR-A07 Branch menu grid (FR-MENU-006). Server-rendered rows with one inline form each (works without
 * JavaScript; 44 px targets; "Sold out today" quick action ≤ 3 taps). Field-level permissions (M10) are
 * enforced by BranchMenuService; inputs the user may not change are rendered read-only.
 */
final class BranchMenuGrid {
	public const SLUG = 'momo-branch-menu';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 22 );
		add_action( 'admin_post_momos_branch_menu_save', [ self::class, 'save' ] );
	}

	public static function menu(): void {
		$hook = add_submenu_page( 'momos', __( 'Branch menus', 'momos-core' ), __( 'Branch menus', 'momos-core' ), 'momo_edit_branch_availability', self::SLUG, [ self::class, 'render' ] );
		if ( $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'check_branch_access' ] );
		}
	}

	/** Refuse other branches before any output (proper 403 status). */
	public static function check_branch_access(): void {
		$branch_id = isset( $_GET['branch'] ) ? (int) $_GET['branch'] : 0; // phpcs:ignore
		if ( $branch_id && ! in_array( $branch_id, self::branches_for_user( get_current_user_id() ), true ) ) {
			momos_audit( 'access.denied', 'branch', $branch_id, $branch_id, [ 'reason' => 'forbidden_branch', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have access to this branch.', 'momos-core' ), '', [ 'response' => 403 ] );
		}
	}

	/** @return list<int> branches the user may open (assigned branches, TEST included for staff). */
	public static function branches_for_user( int $user_id ): array {
		$scope = momos_user_branch_ids( $user_id );
		$ids   = Branches::published_ids( true );
		return null === $scope ? $ids : array_values( array_intersect( $ids, $scope ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'momo_edit_branch_availability' ) && ! current_user_can( 'momo_edit_branch_menu' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$user_id  = get_current_user_id();
		$branches = self::branches_for_user( $user_id );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$branch_id = isset( $_GET['branch'] ) ? (int) $_GET['branch'] : ( $branches[0] ?? 0 );
		$category  = isset( $_GET['cat'] ) ? sanitize_title( wp_unslash( (string) $_GET['cat'] ) ) : '';
		$q         = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$paged     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable
		if ( $branch_id && ! in_array( $branch_id, $branches, true ) ) {
			momos_audit( 'access.denied', 'branch', $branch_id, $branch_id, [ 'reason' => 'forbidden_branch', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have access to this branch.', 'momos-core' ), 403 );
		}
		$per    = 100;
		$list   = $branch_id ? BranchMenuController::list_items( $branch_id, [ 'category' => $category, 'offered' => null, 'availability' => '', 'q' => $q ], $paged, $per, Locale::admin( $user_id ) ) : [ 'items' => [], 'total' => 0 ];
		$notice = get_transient( 'momos_bmg_notice_' . $user_id );
		delete_transient( 'momos_bmg_notice_' . $user_id );
		$view = [
			'branches'    => $branches,
			'branch_id'   => $branch_id,
			'category'    => $category,
			'q'           => $q,
			'paged'       => $paged,
			'pages'       => max( 1, (int) ceil( $list['total'] / $per ) ),
			'items'       => $list['items'],
			'permissions' => $branch_id ? BranchMenuService::permissions( $user_id, $branch_id ) : [ 'offered' => false, 'price' => false, 'availability' => false ],
			'categories'  => get_terms( [ 'taxonomy' => ContentTypes::CATEGORY, 'hide_empty' => false ] ),
			'notice'      => is_array( $notice ) ? $notice : null,
		];
		include MOMOS_CORE_DIR . '/templates/admin/menu-branch-grid.php';
	}

	public static function save(): void {
		$branch_id = isset( $_POST['branch_id'] ) ? (int) $_POST['branch_id'] : 0;
		$dish_id   = isset( $_POST['dish_id'] ) ? (int) $_POST['dish_id'] : 0;
		check_admin_referer( 'momos_bmg_' . $branch_id . '_' . $dish_id );
		if ( ! current_user_can( 'momo_edit_branch_availability' ) && ! current_user_can( 'momo_edit_branch_menu' ) ) {
			momos_audit( 'access.denied', 'branch', $branch_id, $branch_id, [ 'reason' => 'forbidden', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to do this.', 'momos-core' ), 403 );
		}
		$input = [ 'version' => isset( $_POST['version'] ) ? (int) $_POST['version'] : 0 ];
		$quick = isset( $_POST['quick'] ) ? sanitize_key( wp_unslash( $_POST['quick'] ) ) : '';
		if ( 'sold_out_today' === $quick ) {
			$input['availability']        = 'sold_out';
			$input['availability_reason'] = 'sold_out_today';
		} elseif ( 'available' === $quick ) {
			$input['availability'] = 'available';
		} else {
			if ( isset( $_POST['has_offered'] ) ) {
				$input['offered'] = ! empty( $_POST['offered'] );
			}
			if ( isset( $_POST['price_override_jpy'] ) ) {
				$p                           = trim( sanitize_text_field( wp_unslash( $_POST['price_override_jpy'] ) ) );
				$input['price_override_jpy'] = '' === $p ? null : ( ctype_digit( $p ) ? (int) $p : $p );
			}
			if ( isset( $_POST['availability'] ) ) {
				$input['availability'] = sanitize_key( wp_unslash( $_POST['availability'] ) );
				$reason                = isset( $_POST['availability_reason'] ) ? sanitize_key( wp_unslash( $_POST['availability_reason'] ) ) : '';
				if ( 'available' !== $input['availability'] && '' !== $reason ) {
					$input['availability_reason'] = $reason;
				}
				$until = isset( $_POST['sold_out_until'] ) ? sanitize_text_field( wp_unslash( $_POST['sold_out_until'] ) ) : '';
				if ( 'sold_out' === $input['availability'] && '' !== $until ) {
					$input['sold_out_until'] = $until; // datetime-local in JST.
				}
			}
			foreach ( [ 'confirm_large_change', 'confirm_variant_overlap' ] as $c ) {
				if ( ! empty( $_POST[ $c ] ) ) {
					$input[ $c ] = true;
				}
			}
		}
		$user_id = get_current_user_id();
		try {
			BranchMenuService::update( $branch_id, $dish_id, $input, $user_id );
			$notice = [ 'dish_id' => $dish_id, 'type' => 'success', 'message' => __( 'Saved', 'momos-core' ) ];
		} catch ( MenuItemException $e ) {
			$msg = $e->getMessage();
			if ( 'version_conflict' === $e->error_code && ! empty( $e->data['row']['updated_by_name'] ) ) {
				$time = (string) ( $e->data['row']['updated_at'] ?? '' );
				/* translators: 1: user display name 2: time (JST) */
				$msg .= ' ' . sprintf( __( 'Updated by %1$s at %2$s.', 'momos-core' ), $e->data['row']['updated_by_name'], '' !== $time ? ( new \DateTimeImmutable( $time ) )->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) . ' JST' : '' );
			}
			$notice = [ 'dish_id' => $dish_id, 'type' => 'error', 'message' => $msg, 'fields' => $e->fields ];
		}
		set_transient( 'momos_bmg_notice_' . $user_id, $notice, 120 );
		$back = add_query_arg(
			array_filter(
				[
					'page'   => self::SLUG,
					'branch' => $branch_id,
					'cat'    => isset( $_POST['cat'] ) ? sanitize_title( wp_unslash( $_POST['cat'] ) ) : '',
					'q'      => isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '',
					'paged'  => isset( $_POST['paged'] ) ? (int) $_POST['paged'] : 0,
				]
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $back . '#dish-' . $dish_id );
		exit;
	}
}
