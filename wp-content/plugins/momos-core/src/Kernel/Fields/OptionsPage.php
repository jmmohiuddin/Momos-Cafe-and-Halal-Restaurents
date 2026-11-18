<?php
declare(strict_types=1);

namespace Momos\Kernel\Fields;

/**
 * Options pages (G1 brand, G2 dietary statements, G3 settings) under the "Momo's" admin menu.
 */
final class OptionsPage {
	/** @var array<string, array{title:string,cap:string,parent:string}> */
	private static array $pages = [];

	public static function add( string $slug, string $title, string $cap, string $parent = 'momos' ): void {
		self::$pages[ $slug ] = [ 'title' => $title, 'cap' => $cap, 'parent' => $parent ];
	}

	public static function boot(): void {
		add_action( 'admin_menu', [ self::class, 'menus' ], 20 );
		add_action( 'admin_post_momos_save_options', [ self::class, 'save' ] );
	}

	public static function menus(): void {
		foreach ( self::$pages as $slug => $p ) {
			add_submenu_page( $p['parent'], $p['title'], $p['title'], $p['cap'], $slug, static fn() => self::render( $slug ) );
		}
	}

	public static function render( string $slug ): void {
		$p = self::$pages[ $slug ] ?? null;
		if ( ! $p || ! current_user_can( $p['cap'] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		echo '<div class="wrap momos-admin"><h1>' . esc_html( $p['title'] ) . '</h1>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'Settings saved.', 'momos-core' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="momos_save_options"><input type="hidden" name="page_slug" value="' . esc_attr( $slug ) . '">';
		wp_nonce_field( 'momos_options_' . $slug, '_momos_options_nonce' );
		$values = \Momos\Kernel\Settings::page( $slug );
		foreach ( FieldRegistry::groups_for( 'option:' . $slug ) as $g ) {
			if ( ! empty( $g['view_cap'] ) && ! current_user_can( $g['view_cap'] ) ) {
				continue;
			}
			echo '<h2>' . esc_html( (string) $g['title'] ) . '</h2>';
			$merged = [];
			foreach ( $g['fields'] as $f ) {
				$merged[ $f['name'] ] = array_key_exists( $f['name'], $values ) ? $values[ $f['name'] ] : ( $f['default'] ?? null );
			}
			Renderer::group( $g, $merged, $slug, 'option' );
		}
		do_action( 'momos/options_page/after_fields', $slug );
		submit_button();
		echo '</form></div>';
	}

	public static function save(): void {
		$slug = isset( $_POST['page_slug'] ) ? sanitize_key( wp_unslash( $_POST['page_slug'] ) ) : '';
		$p    = self::$pages[ $slug ] ?? null;
		if ( ! $p || ! current_user_can( $p['cap'] ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'momos-core' ), 403 );
		}
		check_admin_referer( 'momos_options_' . $slug, '_momos_options_nonce' );
		$submitted = isset( $_POST['momos_fields'] ) && is_array( $_POST['momos_fields'] ) ? wp_unslash( $_POST['momos_fields'] ) : []; // phpcs:ignore
		foreach ( FieldRegistry::groups_for( 'option:' . $slug ) as $g ) {
			if ( ! empty( $g['view_cap'] ) && ! current_user_can( $g['view_cap'] ) ) {
				continue;
			}
			FieldRegistry::save_group_values( 'option', $slug, $g, $submitted );
		}
		do_action( 'momos/options_page/saved', $slug );
		wp_safe_redirect( add_query_arg( [ 'page' => $slug, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
