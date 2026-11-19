<?php
declare(strict_types=1);

namespace Momos\Admin;

use Momos\Kernel\ModuleInterface;

/**
 * Admin shell: the top-level "Momo's" menu (TRD §2.1 admin menu structure). Modules add their
 * screens as submenus of slug `momos` on `admin_menu` priority ≥ 20.
 *
 * Also owns: G1 Brand (`momo-brand`) and G3 Settings (`momo-settings`, tabbed SCR-A15 incl. the
 * language gate), the role-aware dashboard (SCR-A02), admin chrome per role and migration M0005.
 */
final class AdminModule implements ModuleInterface {
	public const MENU = 'momos';

	public function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 9 );
		add_filter(
			'momos/migrations',
			static function ( array $list ): array {
				$list[] = new Migration\M0005_DefaultSettings();
				return $list;
			}
		);
		Settings\BrandFields::register();
		Settings\SettingsFields::register();
		Settings\ReservationOptions::register();
		Settings\LanguageGate::register();
		Settings\SettingsScreen::register();
		Dashboard\Widgets::register();
		MenuCleanup::register();
	}

	public static function menu(): void {
		add_menu_page(
			__( "Momo's", 'momos-core' ),
			__( "Momo's", 'momos-core' ),
			'read',
			self::MENU,
			[ self::class, 'landing' ],
			'dashicons-store',
			3
		);
	}

	public static function landing(): void {
		echo '<div class="wrap momos-admin"><h1>' . esc_html__( "Momo's", 'momos-core' ) . '</h1>';
		do_action( 'momos/admin/landing' );
		echo '</div>';
	}
}
