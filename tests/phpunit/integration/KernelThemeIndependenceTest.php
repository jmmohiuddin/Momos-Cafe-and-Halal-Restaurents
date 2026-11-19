<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

/**
 * AC-FND-01-04 / FR-FND-003(c): with a default-style theme active instead of `momos`, the reservation REST API
 * (momos/v1) and the staff inbox keep working — CPTs, routes and jobs live in momos-core, not in the theme.
 * A minimal classic theme (style.css + index.php, like a bundled default theme) is registered from a temporary
 * directory because the test install ships no core themes.
 */
final class KernelThemeIndependenceTest extends ReservationTestCaseTest {
	private string $theme_root = '';

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' );
		$this->theme_root = get_temp_dir() . 'momos-qa-themes-' . getmypid();
		wp_mkdir_p( $this->theme_root . '/qa-default' );
		file_put_contents( $this->theme_root . '/qa-default/style.css', "/*\nTheme Name: QA Default\nVersion: 1.0\n*/\n" ); // phpcs:ignore
		file_put_contents( $this->theme_root . '/qa-default/index.php', "<?php\n" ); // phpcs:ignore
		register_theme_directory( $this->theme_root );
		search_theme_directories( true );
		wp_clean_themes_cache();
	}

	public function tear_down(): void {
		switch_theme( 'momos' );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- reset the core REST server.
		foreach ( [ 'style.css', 'index.php' ] as $f ) {
			wp_delete_file( $this->theme_root . '/qa-default/' . $f );
		}
		@rmdir( $this->theme_root . '/qa-default' ); // phpcs:ignore
		@rmdir( $this->theme_root ); // phpcs:ignore
		unset( $GLOBALS['wp_theme_directories'][ array_search( $this->theme_root, (array) $GLOBALS['wp_theme_directories'], true ) ] );
		wp_clean_themes_cache();
		parent::tear_down();
	}

	public function test_reservation_api_and_inbox_work_with_a_default_theme(): void {
		switch_theme( 'qa-default' );
		$this->assertSame( 'qa-default', get_stylesheet() );
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- routes are registered again on rest_api_init, without the momos theme.
		$routes = rest_get_server()->get_routes();
		foreach ( [ '/momos/v1/reservations', '/momos/v1/staff/reservations', '/momos/v1/staff/reservations/(?P<id>\d+)/transitions' ] as $route ) {
			$this->assertArrayHasKey( $route, $routes, $route );
		}
		$this->assertTrue( post_type_exists( 'momo_branch' ) && post_type_exists( 'momo_dish' ) && taxonomy_exists( 'momo_menu_category' ) );

		$branch = $this->make_branch( 'QTI' );
		$res    = $this->make_reservation( $branch, '2026-12-03', '19:00' );
		$ho     = $this->staff( 'momo_head_office' );
		wp_set_current_user( $ho );
		$list = $this->rest( 'GET', '/momos/v1/staff/reservations' );
		$this->assertSame( 200, $list->get_status(), (string) wp_json_encode( $list->get_data() ) );
		$this->assertSame( [ $res ], array_column( $list->get_data(), 'id' ) );

		// Public submission (API-02) still accepted.
		$sub = $this->submit( $this->body( 'QTI', '2026-12-04', '19:00' ) );
		$this->assertContains( $sub->get_status(), [ 200, 201, 202 ], (string) wp_json_encode( $sub->get_data() ) );

		// Staff inbox page callback (SCR-A01) renders the request.
		set_current_screen( 'toplevel_page_momos-reservations' );
		ob_start();
		\Momos\Reservation\Admin\InboxPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'QTI', $html );
		wp_set_current_user( 0 );
	}
}
