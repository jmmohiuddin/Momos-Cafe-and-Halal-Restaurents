<?php
declare(strict_types=1);

namespace Momos\Admin\Settings;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\Renderer;
use Momos\Kernel\Settings;

/**
 * SCR-A15 Settings screen with tabs (screens.md §5.15: Brand, Notifications, Reservations,
 * Languages, Privacy, Monitoring, Security + Analytics, Maps). Replaces the kernel's single-form
 * rendering of `momo-settings` with one form per tab; field tabs still post to the kernel handler
 * (`momos_save_options`), which only saves the fields rendered in the submitted form. Each tab has
 * its own capability (technical tabs `manage_options`, Languages `momo_manage_languages`).
 */
final class SettingsScreen {
	public const SLUG = 'momo-settings';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'hook_page' ], 30 );
		add_action( 'momos/options_page/saved', [ self::class, 'after_save' ], 5 );
	}

	/** @return array<string, array{label:string,cap:string}> */
	public static function tabs(): array {
		return [
			'reservations'  => [ 'label' => __( 'Reservations', 'momos-core' ), 'cap' => 'momo_manage_brand' ],
			'notifications' => [ 'label' => __( 'Notifications', 'momos-core' ), 'cap' => 'momo_manage_brand' ],
			'languages'     => [ 'label' => __( 'Languages', 'momos-core' ), 'cap' => 'momo_manage_languages' ],
			'privacy'       => [ 'label' => __( 'Privacy', 'momos-core' ), 'cap' => 'momo_manage_brand' ],
			'analytics'     => [ 'label' => __( 'Analytics', 'momos-core' ), 'cap' => 'manage_options' ],
			'maps'          => [ 'label' => __( 'Maps', 'momos-core' ), 'cap' => 'momo_manage_brand' ],
			'monitoring'    => [ 'label' => __( 'Monitoring', 'momos-core' ), 'cap' => 'manage_options' ],
			'security'      => [ 'label' => __( 'Security', 'momos-core' ), 'cap' => 'manage_options' ],
			'other'         => [ 'label' => __( 'Other', 'momos-core' ), 'cap' => 'momo_manage_brand' ],
		];
	}

	/** @param array<string,mixed> $group */
	public static function tab_of_group( array $group ): string {
		if ( ! empty( $group['tab'] ) ) {
			return (string) $group['tab'];
		}
		return str_contains( (string) $group['key'], 'notification' ) ? 'notifications' : 'other';
	}

	/** @return list<array<string,mixed>> */
	public static function groups_of( string $tab ): array {
		return array_values( array_filter( FieldRegistry::groups_for( 'option:' . self::SLUG ), static fn( $g ) => self::tab_of_group( $g ) === $tab ) );
	}

	/** @return list<string> tabs visible to the current user */
	public static function visible_tabs(): array {
		$out = [];
		foreach ( self::tabs() as $key => $t ) {
			if ( ! current_user_can( $t['cap'] ) ) {
				continue;
			}
			if ( in_array( $key, [ 'reservations', 'languages' ], true ) || self::groups_of( $key ) || 'monitoring' === $key ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	public static function hook_page(): void {
		$hook = get_plugin_page_hookname( self::SLUG, \Momos\Admin\AdminModule::MENU );
		if ( ! $hook ) {
			return;
		}
		add_action(
			'load-' . $hook,
			static function () use ( $hook ): void {
				remove_all_actions( $hook );
				add_action( $hook, [ self::class, 'render' ] );
			}
		);
	}

	public static function current_tab(): string {
		$visible = self::visible_tabs();
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore
		return in_array( $tab, $visible, true ) ? $tab : ( $visible[0] ?? '' );
	}

	public static function render(): void {
		if ( ! current_user_can( 'momo_manage_brand' ) && ! current_user_can( 'momo_manage_languages' ) ) {
			momos_audit( 'access.denied', 'settings', null, null, [ 'cap' => 'momo_manage_brand', 'channel' => 'admin', 'route' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$tab     = self::current_tab();
		$visible = self::visible_tabs();
		echo '<div class="wrap momos-admin momos-settings"><h1>' . esc_html__( 'Settings', 'momos-core' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'momos-core' ) . '">';
		if ( current_user_can( 'momo_manage_brand' ) ) {
			echo '<a class="nav-tab" href="' . esc_url( admin_url( 'admin.php?page=' . BrandFields::PAGE ) ) . '">' . esc_html__( 'Brand', 'momos-core' ) . '</a>';
		}
		foreach ( $visible as $key ) {
			$cls = 'nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $cls ) . '"' . ( $key === $tab ? ' aria-current="page"' : '' ) . ' href="' . esc_url( add_query_arg( [ 'page' => self::SLUG, 'tab' => $key ], admin_url( 'admin.php' ) ) ) . '">' . esc_html( self::tabs()[ $key ]['label'] ) . '</a>';
		}
		echo '</nav>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html__( 'Settings saved.', 'momos-core' ) . '</p></div>';
		}
		if ( 'reservations' === $tab ) {
			ReservationOptions::render_form();
		} elseif ( 'languages' === $tab ) {
			LanguageGate::render();
		} elseif ( '' !== $tab ) {
			self::render_groups( $tab );
		}
		echo '</div>';
	}

	private static function render_groups( string $tab ): void {
		$groups = self::groups_of( $tab );
		$values = Settings::page( self::SLUG );
		if ( $groups ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="momos_save_options"><input type="hidden" name="page_slug" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="_momos_tab" value="' . esc_attr( $tab ) . '">';
			wp_nonce_field( 'momos_options_' . self::SLUG, '_momos_options_nonce' );
			foreach ( $groups as $g ) {
				if ( ! empty( $g['view_cap'] ) && ! current_user_can( $g['view_cap'] ) ) {
					continue;
				}
				echo '<h2>' . esc_html( (string) $g['title'] ) . '</h2>';
				$merged = [];
				foreach ( $g['fields'] as $f ) {
					$merged[ $f['name'] ] = array_key_exists( $f['name'], $values ) ? $values[ $f['name'] ] : ( $f['default'] ?? null );
				}
				Renderer::group( $g, $merged, self::SLUG, 'option' );
			}
			submit_button();
			echo '</form>';
		}
		if ( 'monitoring' === $tab ) {
			// Read-only panels (job heartbeats, plugin register, email transport) registered by other modules.
			do_action( 'momos/options_page/after_fields', self::SLUG );
		}
	}

	/** Keep the tab after a save through the kernel handler. */
	public static function after_save( $slug ): void {
		if ( self::SLUG !== $slug || empty( $_POST['_momos_tab'] ) ) { // phpcs:ignore -- nonce verified by the kernel handler.
			return;
		}
		$tab = sanitize_key( wp_unslash( (string) $_POST['_momos_tab'] ) ); // phpcs:ignore
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'tab' => $tab, 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
