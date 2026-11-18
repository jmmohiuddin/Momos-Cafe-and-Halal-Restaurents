<?php
declare(strict_types=1);

namespace Momos\Presentation;

/**
 * What kind of customer page is being rendered (page_type enum, i18n-seo-analytics.md §3.5) and its
 * branch context. Used for body classes, nav "current", mobile action bar and analytics attributes.
 */
final class PageContext {
	/** page_type values without analytics (C10/C11) map to these internal types. */
	public const NO_ANALYTICS = [ 'reservation_submitted', 'reservation_status' ];

	private static ?string $type = null;

	public static function reset(): void {
		self::$type = null;
	}

	public static function page_type(): string {
		if ( null !== self::$type ) {
			return self::$type;
		}
		$type = 'page';
		if ( is_404() ) {
			$type = 'error_404';
		} elseif ( is_front_page() ) {
			$type = 'home';
		} elseif ( is_singular( 'momo_branch' ) ) {
			$type = 'branch';
		} elseif ( is_singular( 'momo_dish' ) ) {
			$type = 'dish';
		} elseif ( is_tax( 'momo_menu_category' ) ) {
			$type = 'menu_category';
		} elseif ( is_home() || is_singular( 'post' ) ) {
			$type = 'news';
		} elseif ( is_page() ) {
			$type = self::type_of_role( SystemPages::role_of( (int) get_queried_object_id() ) );
		}
		/** Lets later templates (e.g. the reservation status rewrite) declare their type. */
		self::$type = (string) apply_filters( 'momos/presentation/page_type', $type );
		return self::$type;
	}

	public static function type_of_role( string $role ): string {
		return match ( $role ) {
			'home' => 'home',
			'branches' => 'branch_directory',
			'menu' => 'menu_hub',
			'submitted' => 'reservation_submitted',
			'status' => 'reservation_status',
			'about', 'halal', 'reservation', 'contact', 'faq', 'offers', 'gallery', 'news', 'legal' => $role,
			default => 'page',
		};
	}

	/** Nav section a page type belongs to (header `aria-current`). */
	public static function nav_section( ?string $type = null ): string {
		$type = $type ?? self::page_type();
		return match ( $type ) {
			'menu_hub', 'menu_category', 'dish' => 'menu',
			'branch_directory', 'branch' => 'branches',
			'reservation', 'reservation_submitted', 'reservation_status' => 'reservation',
			'offers', 'halal', 'about', 'faq', 'contact', 'gallery', 'news' => $type,
			default => '',
		};
	}

	/** Branch post ID of a branch detail page, else 0. */
	public static function branch_id(): int {
		return is_singular( 'momo_branch' ) ? (int) get_queried_object_id() : 0;
	}

	/** Pages where the mobile action bar is not rendered (C09–C11). */
	public static function hides_action_bar( ?string $type = null ): bool {
		return in_array( $type ?? self::page_type(), [ 'reservation', 'reservation_submitted', 'reservation_status' ], true );
	}

	/** Pages without breadcrumbs / page banner trail (Home, C09–C11). */
	public static function has_breadcrumbs( ?string $type = null ): bool {
		return ! in_array( $type ?? self::page_type(), [ 'home', 'reservation', 'reservation_submitted', 'reservation_status', 'error_404' ], true );
	}
}
