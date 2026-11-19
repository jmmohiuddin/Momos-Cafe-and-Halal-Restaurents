<?php
declare(strict_types=1);

namespace Momos\Access;

use Momos\Kernel\Env;

/**
 * WordPress attack-surface reduction (SEC-040…043, FR-SEC-003/004, TC-WP-013, TC-SEC-003/009/010;
 * spec §9.2): XML-RPC and pingbacks off, user enumeration closed (author archives, ?author=N,
 * users sitemap, oEmbed author), comments off, registration off, Application Passwords off,
 * generator removed, no Gravatar, admin bar hidden for TR/BM/RS, Site Health checks for the
 * file-edit/file-mods constants. `/wp/v2/users*` is guarded by Api\CoreRestGuard.
 */
final class Hardening {
	public static function register(): void {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', static fn() => [] );
		add_action( 'init', [ self::class, 'deny_xmlrpc' ], 0 );
		add_filter( 'wp_headers', [ self::class, 'strip_headers' ] );
		add_filter( 'pings_open', '__return_false', 99 );
		add_filter( 'comments_open', '__return_false', 99 );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'rest_endpoints', [ self::class, 'rest_endpoints' ] );
		add_action( 'init', [ self::class, 'remove_comment_support' ], 100 );
		add_action( 'template_redirect', [ self::class, 'block_author_archives' ], 1 );
		add_filter( 'wp_sitemaps_add_provider', static fn( $provider, $name ) => 'users' === $name ? false : $provider, 10, 2 );
		add_filter( 'oembed_response_data', [ self::class, 'oembed_data' ] );
		add_filter( 'pre_option_users_can_register', static fn() => '0' );
		add_filter( 'pre_option_default_role', static fn() => '' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		add_filter( 'the_generator', '__return_empty_string' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		add_filter( 'pre_option_show_avatars', static fn() => '0' );
		add_filter( 'pre_get_avatar_data', [ self::class, 'local_avatar' ], 10, 2 );
		add_filter( 'show_admin_bar', [ self::class, 'show_admin_bar' ] );
		add_filter( 'site_status_tests', [ self::class, 'site_health_tests' ] );
		add_filter( 'auto_update_core', '__return_false' );
		add_filter( 'auto_update_plugin', '__return_false' );
		add_filter( 'auto_update_theme', '__return_false' );
	}

	/** XML-RPC endpoint answers 403 (also denied at the server/WAF, SEC-042). */
	public static function deny_xmlrpc(): void {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'XML-RPC is disabled.';
			exit;
		}
	}

	/** @param array<string,string> $headers */
	public static function strip_headers( $headers ): array {
		$headers = (array) $headers;
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	/** Comment routes removed (spec §9.2). @param array<string,mixed> $endpoints */
	public static function rest_endpoints( $endpoints ): array {
		foreach ( array_keys( (array) $endpoints ) as $route ) {
			if ( str_starts_with( (string) $route, '/wp/v2/comments' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return (array) $endpoints;
	}

	public static function remove_comment_support(): void {
		foreach ( [ 'post', 'page', 'attachment' ] as $type ) {
			remove_post_type_support( $type, 'comments' );
			remove_post_type_support( $type, 'trackbacks' );
		}
	}

	/** Author archives and ?author=N → 301 home (user enumeration, TC-SEC-010). */
	public static function block_author_archives(): void {
		if ( is_admin() ) {
			return;
		}
		if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/** @param array<string,mixed> $data */
	public static function oembed_data( $data ): array {
		$data = (array) $data;
		unset( $data['author_name'], $data['author_url'] );
		return $data;
	}

	/** No Gravatar requests from admin screens (PRV-024): neutral local placeholder. @param array<string,mixed> $args */
	public static function local_avatar( $args, $id_or_email ): array {
		$args                 = (array) $args;
		$args['url']          = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" fill="#c3c4c7"/><circle cx="16" cy="12" r="6" fill="#fff"/><path d="M5 30c1-7 6-10 11-10s10 3 11 10z" fill="#fff"/></svg>' );
		$args['found_avatar'] = true;
		return $args;
	}

	public static function show_admin_bar( $show ): bool {
		$user = wp_get_current_user();
		if ( $user->exists() && array_intersect( [ 'momo_translator', 'momo_branch_manager', 'momo_reservation_staff' ], $user->roles ) ) {
			return false;
		}
		return (bool) $show;
	}

	/** @param array<string,mixed> $tests */
	public static function site_health_tests( $tests ): array {
		$tests                                 = (array) $tests;
		$tests['direct']['momos_file_editing'] = [ 'label' => __( "Momo's: file editing and installs", 'momos-core' ), 'test' => [ self::class, 'health_file_editing' ] ];
		return $tests;
	}

	/** @return array<string,mixed> */
	public static function health_file_editing(): array {
		$edit  = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$mods  = ! wp_is_file_mod_allowed( 'momos_site_health' );
		$prod  = Env::is_production();
		$ok    = $edit && ( ! $prod || $mods );
		$items = [
			'DISALLOW_FILE_EDIT' => $edit ? 'true' : 'false',
			'DISALLOW_FILE_MODS' => $mods ? 'true' : 'false',
		];
		$desc  = '';
		foreach ( $items as $k => $v ) {
			$desc .= '<li><code>' . esc_html( $k ) . '</code> = ' . esc_html( $v ) . '</li>';
		}
		return [
			'label'       => $ok ? __( 'Theme/plugin file editing is disabled', 'momos-core' ) : __( 'Theme/plugin file editing or installation is allowed', 'momos-core' ),
			'status'      => $ok ? 'good' : 'critical',
			'badge'       => [ 'label' => __( 'Security', 'momos-core' ), 'color' => $ok ? 'blue' : 'red' ],
			'description' => '<p>' . esc_html__( 'DISALLOW_FILE_EDIT must be true everywhere and DISALLOW_FILE_MODS must be true in production; changes reach the site only through the deployment pipeline (SEC-040, DEC-065).', 'momos-core' ) . '</p><ul>' . $desc . '</ul>',
			'actions'     => '',
			'test'        => 'momos_file_editing',
		];
	}
}
