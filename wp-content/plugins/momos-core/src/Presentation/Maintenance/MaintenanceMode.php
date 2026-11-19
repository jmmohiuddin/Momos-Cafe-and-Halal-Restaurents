<?php
declare(strict_types=1);

namespace Momos\Presentation\Maintenance;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Settings;

/**
 * ROLE-01 maintenance toggle (FR-CORP-007 A1, AC-CORP-07-02; F-CORP-07 "Permissions: maintenance mode ROLE-01").
 * Settings → Monitoring tab: `momo_maintenance_mode` (view/edit `manage_options` = ROLE-01 only). While on,
 * anonymous visitors — and every logged-in user without `manage_options` — receive SCR-C20 (MaintenancePage):
 * HTTP 503, `Retry-After`, `no-store`, all enabled languages with their own `lang`/`dir`; logged-in ROLE-01 users
 * see the site. Anonymous calls to the public `momos/v1` REST routes (reservation submissions) get a 503 too; staff
 * REST calls are untouched. wp-admin and wp-login stay reachable.
 *
 * Deviation note: core's `enable_maintenance_mode` filter is applied in wp-settings.php before any plugin loads
 * (wp_maintenance()), so a plugin cannot use it for a logged-in bypass; core `.maintenance` (runbook
 * `wp maintenance-mode activate`) still blocks everyone and serves the exported static drop-in.
 */
final class MaintenanceMode {
	public const OPTION = 'momo_maintenance_mode';

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_fields' ], 10 );
		add_action( 'template_redirect', [ self::class, 'maybe_serve' ], 0 );
		add_filter( 'rest_pre_dispatch', [ self::class, 'rest' ], 5, 3 );
	}

	public static function register_fields(): void {
		FieldRegistry::group(
			[
				'key'          => 'momo_settings_maintenance',
				'tab'          => 'monitoring',
				'title'        => __( 'Maintenance mode', 'momos-core' ),
				'options_page' => 'momo-settings',
				'order'        => 61,
				'view_cap'     => 'manage_options',
				'fields'       => [
					[ 'name' => self::OPTION, 'label' => __( 'Show the maintenance page to visitors', 'momos-core' ), 'type' => 'true_false', 'default' => '0', 'edit_cap' => 'manage_options', 'help' => __( 'Visitors get HTTP 503 with the multilingual maintenance page. Logged-in super administrators still see the site (FR-CORP-007).', 'momos-core' ) ],
				],
			]
		);
	}

	public static function active(): bool {
		return '1' === (string) Settings::get( self::OPTION, '0' );
	}

	/** Logged-in ROLE-01 (super administrator) sees the site while maintenance is on. */
	public static function bypass(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	public static function should_block(): bool {
		return self::active() && ! self::bypass();
	}

	public static function maybe_serve(): void {
		if ( ! self::should_block() ) {
			return;
		}
		self::send_headers();
		echo MaintenancePage::document(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		exit;
	}

	public static function send_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		status_header( 503 );
		nocache_headers();
		header( 'Retry-After: ' . MaintenancePage::RETRY_AFTER );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex' );
	}

	/**
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function rest( $result, $server, $request ) {
		if ( null !== $result || is_user_logged_in() || ! self::active() || ! str_starts_with( (string) $request->get_route(), '/momos/v1/' ) ) {
			return $result;
		}
		return new \WP_Error( 'momos_maintenance', momos_t( 'maintenance.body' ), [ 'status' => 503 ] );
	}
}
