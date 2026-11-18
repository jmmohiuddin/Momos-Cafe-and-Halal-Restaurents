<?php
declare(strict_types=1);

namespace Momos\Admin;

/**
 * Admin chrome per role (spec §9.2, screens.md §6): comments are off for everyone; core menus that
 * are irrelevant to Momo's staff are hidden for every role except ROLE-01. Hiding is cosmetic —
 * capabilities and screen guards stay the enforcement (SEC-014). Denied admin pages are audited
 * `access.denied` (E2, TC-ACL-018).
 */
final class MenuCleanup {
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menus' ], 999 );
		add_action( 'admin_bar_menu', [ self::class, 'admin_bar' ], 999 );
		add_action( 'admin_page_access_denied', [ self::class, 'audit_denied_page' ] );
	}

	public static function menus(): void {
		remove_menu_page( 'edit-comments.php' );
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		remove_menu_page( 'tools.php' );
		remove_submenu_page( 'index.php', 'update-core.php' );
		// HO keeps Appearance → Menus only (edit_theme_options); no Customizer/themes (M7).
		remove_submenu_page( 'themes.php', 'themes.php' );
		remove_submenu_page( 'themes.php', 'customize.php?return=' . rawurlencode( remove_query_arg( wp_removable_query_args(), wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) ); // phpcs:ignore
		remove_submenu_page( 'themes.php', 'site-editor.php' );
		remove_submenu_page( 'themes.php', 'widgets.php' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			remove_menu_page( 'edit.php' );
		}
	}

	/** @param \WP_Admin_Bar $bar */
	public static function admin_bar( $bar ): void {
		$bar->remove_node( 'comments' );
		if ( ! current_user_can( 'manage_options' ) ) {
			$bar->remove_node( 'wp-logo' );
			$bar->remove_node( 'customize' );
			$bar->remove_node( 'updates' );
		}
	}

	/** Core refused an admin page (missing capability): audit for momos screens. */
	public static function audit_denied_page(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore
		if ( '' !== $page && str_starts_with( $page, 'momo' ) && is_user_logged_in() ) {
			momos_audit( 'access.denied', 'admin_screen', null, null, [ 'channel' => 'admin', 'route' => $page ] );
		}
	}
}
