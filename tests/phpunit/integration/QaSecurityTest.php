<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Access\Users\AccountService;
use Momos\Kernel\Log\Redactor;

/**
 * QA (security team) — white-box regression tests for the security review (Doc 11, SDD §6,
 * docs/specs/access-security-privacy.md). Each test asserts the DOCUMENTED behaviour, so it fails while
 * the product deviates and becomes a regression test once fixed.
 *
 *  - TC-SEC-003 / spec §9.2 E7: `/wp/v2/users*` closed to anonymous callers — in every letter case
 *    (core REST route matching is case-insensitive, `@i`).
 *  - SEC-023 / spec §7.4 E7: core `wp/v2/momo_*` routes are GET-only — in every letter case.
 *  - SEC-081 / spec §6.2: Redactor masks 43-char base64url status tokens.
 *  - SEC-021 / spec §7.2: the `<title>` text is HTML-escaped (core escapes it; a `pre_get_document_title`
 *    filter must do the same).
 *  - SEC-028 / spec §7.7: media MIME allow-list JPEG/PNG/WebP; SVG and AVIF rejected for every role.
 *  - SEC-008 / PRV-028 / DEC-035: a vendor account must not be able to hand out PII access.
 *  - SEC-040/042 / spec §9.1/§9.3: production wp-config template and shipped web-server deny rules.
 */
final class QaSecurityTest extends AccessTestCaseTest {

	public function set_up(): void {
		parent::set_up();
		// Fresh REST server so routes (incl. momos/v1 and CoreRestGuard) are registered for this test.
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	// ---------------------------------------------------------------- user enumeration (TC-SEC-003)

	/** @return array<string,array{0:string}> */
	public static function users_routes(): array {
		return [
			'lower'        => [ '/wp/v2/users' ],
			'upper'        => [ '/wp/v2/USERS' ],
			'mixed'        => [ '/wp/v2/Users' ],
			'single upper' => [ '/wp/v2/USERS/%d' ],
			'single mixed' => [ '/wp/v2/uSeRs/%d' ],
		];
	}

	/**
	 * @dataProvider users_routes
	 */
	public function test_core_users_route_is_closed_to_anonymous_in_any_letter_case( string $route ): void {
		$author = self::factory()->user->create( [ 'role' => 'administrator', 'user_login' => 'qa_sec_author_' . wp_rand( 1000, 9999 ) ] );
		self::factory()->post->create( [ 'post_author' => $author, 'post_status' => 'publish', 'post_type' => 'post' ] );
		wp_set_current_user( 0 );

		$response = rest_do_request( new \WP_REST_Request( 'GET', sprintf( $route, $author ) ) );
		$login    = get_userdata( $author )->user_login;

		$this->assertSame( 401, $response->get_status(), "Anonymous GET {$route} must answer 401 (TC-SEC-003, spec §9.2)." );
		$this->assertStringNotContainsString( $login, (string) wp_json_encode( $response->get_data() ), "Anonymous GET {$route} discloses a username." );
	}

	// ---------------------------------------------------------------- core REST write guard (SEC-023)

	/** @return array<string,array{0:string}> */
	public static function momo_write_routes(): array {
		return [
			'lower' => [ '/wp/v2/momo_branch/%d' ],
			'upper' => [ '/wp/v2/MOMO_BRANCH/%d' ],
			'mixed' => [ '/wp/v2/Momo_Branch/%d' ],
		];
	}

	/**
	 * @dataProvider momo_write_routes
	 */
	public function test_core_momo_routes_are_read_only_in_any_letter_case( string $route ): void {
		$branch = $this->branch( 'QSW' );
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new \WP_REST_Request( 'POST', sprintf( $route, $branch ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'content' => 'qa-sec-rest-write' ] ) );
		$response = rest_do_request( $request );

		$this->assertSame( 405, $response->get_status(), "POST {$route} must be refused with 405 method_not_allowed (spec §7.4, E7)." );
		$this->assertStringNotContainsString( 'qa-sec-rest-write', (string) get_post_field( 'post_content', $branch, 'raw' ), 'The branch was modified through core REST.' );
	}

	// ---------------------------------------------------------------- log redaction (SEC-081)

	public function test_redactor_masks_status_tokens_in_free_text(): void {
		$token = 'IZmqW0Rc6DHkYrpWuE5bTX_nMBlHhg7yLV-yrfwdtes';
		$this->assertSame( 1, preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) );

		$out = Redactor::redact(
			[
				'error' => 'render failed for http://127.0.0.1/en/reservation/status/5B43XSD4/#t=' . $token,
				'url'   => 'https://example.test/reservation/status/5B43XSD4/#t=' . $token,
				'raw'   => $token,
			]
		);
		$this->assertStringNotContainsString( $token, (string) wp_json_encode( $out ), 'A status token (43-char base64url) reached a log line unmasked (spec §6.2, SEC-081).' );
	}

	// ---------------------------------------------------------------- output encoding (SEC-021)

	public function test_document_title_is_html_escaped(): void {
		global $wpdb;
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA title', 'post_name' => 'qa-sec-title' ] );
		// Stored value reaching the title through any path (direct DB write = worst case of a missing input filter).
		$wpdb->update( $wpdb->posts, [ 'post_title' => 'QA </title><script>alert(1)</script>' ], [ 'ID' => $page ] );
		clean_post_cache( $page );

		$this->go_to( get_permalink( $page ) );
		$title = wp_get_document_title();

		$this->assertStringNotContainsString( '<script>', $title, '<title> text is not HTML-escaped (spec §7.2 output encoding).' );
		$this->assertStringNotContainsString( '</title>', $title, '<title> text is not HTML-escaped (spec §7.2 output encoding).' );
	}

	// ---------------------------------------------------------------- upload policy (SEC-028)

	/** @return array<string,array{0:string}> */
	public static function upload_roles(): array {
		return [
			'SA' => [ 'administrator' ],
			'HO' => [ 'momo_head_office' ],
			'MM' => [ 'momo_menu_manager' ],
			'CE' => [ 'momo_content_editor' ],
			'BM' => [ 'momo_branch_manager' ],
		];
	}

	/**
	 * @dataProvider upload_roles
	 */
	public function test_media_upload_mime_allow_list( string $role ): void {
		$user = self::factory()->user->create( [ 'role' => $role ] );
		wp_set_current_user( $user );
		$mimes = array_values( array_unique( get_allowed_mime_types( $user ) ) );

		$this->assertNotContains( 'image/svg+xml', $mimes, "SVG must be rejected for {$role} (spec §7.7)." );
		$this->assertNotContains( 'image/avif', $mimes, "AVIF must be rejected for {$role} (spec §7.7, SEC-028)." );
		$unexpected = array_values( array_diff( $mimes, [ 'image/jpeg', 'image/png', 'image/webp' ] ) );
		$this->assertSame( [], $unexpected, "Media uploads for {$role} accept types outside the JPEG/PNG/WebP allow-list (spec §7.7, SEC-028): " . implode( ', ', array_slice( $unexpected, 0, 12 ) ) );
	}

	// ---------------------------------------------------------------- vendor accounts (SEC-008, PRV-028)

	public function test_vendor_admin_cannot_create_or_promote_accounts_with_customer_pii_access(): void {
		$owner  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$vendor = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $owner );
		AccountService::set_vendor( $vendor, true, $owner );
		AccountService::set_vendor_window( $vendor, ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '+2 hours' ), $owner );

		wp_set_current_user( $vendor );
		$this->assertFalse( current_user_can( 'momo_view_customer_pii' ), 'Precondition: vendor denies are in force.' );

		$grantable = [];
		if ( current_user_can( 'create_users' ) || current_user_can( 'promote_users' ) ) {
			foreach ( array_keys( get_editable_roles() ) as $slug ) {
				$r = get_role( $slug );
				if ( $r && ( ! empty( $r->capabilities['momo_view_customer_pii'] ) || ! empty( $r->capabilities['momo_manage_staff'] ) ) ) {
					$grantable[] = $slug;
				}
			}
		}
		$this->assertSame( [], $grantable, 'A vendor account (no routine PII access, SEC-008/PRV-028) can create or promote accounts into roles that hold customer PII or staff management: ' . implode( ', ', $grantable ) );
	}

	// ---------------------------------------------------------------- shipped configuration (SEC-040/042)

	public function test_production_wp_config_template_disallows_unfiltered_html(): void {
		$sample = (string) file_get_contents( dirname( __DIR__, 3 ) . '/config/wp-config.production.sample.php' );
		$this->assertSame( 1, preg_match( "/define\\(\\s*'DISALLOW_UNFILTERED_HTML'\\s*,\\s*true\\s*\\)/", $sample ), 'Production wp-config template must define DISALLOW_UNFILTERED_HTML true (spec §9.1, SEC-040).' );
	}

	public function test_web_server_deny_rules_are_shipped(): void {
		$dir   = dirname( __DIR__, 3 ) . '/config/server';
		$rules = '';
		foreach ( is_dir( $dir ) ? (array) glob( $dir . '/{,.}*', GLOB_BRACE ) : [] as $f ) {
			if ( is_file( (string) $f ) ) {
				$rules .= (string) file_get_contents( (string) $f ) . "\n";
			}
		}
		$this->assertNotSame( '', trim( $rules ), 'config/server/ web-server rules (SEC-042, spec §9.3) are not shipped.' );
		foreach ( [ 'readme', 'license', 'xmlrpc', 'debug', 'wp-config' ] as $needle ) {
			$this->assertStringContainsStringIgnoringCase( $needle, $rules, "Shipped web-server rules do not deny {$needle} (spec §9.3)." );
		}
		$this->assertMatchesRegularExpression( '/php|phtml|phar/i', $rules, 'Shipped web-server rules do not block script execution in uploads (spec §9.3, TC-SEC-010).' );
	}

	// ---------------------------------------------------------------- client IP (spec §7.6, SDD §5.14)

	/**
	 * The CDN client-IP header may be trusted only when the request comes from the configured CDN/proxy
	 * ranges; a direct request to the origin must not be able to choose its own rate-limit / audit IP.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trusted_ip_header_is_ignored_for_requests_not_coming_from_the_proxy(): void {
		if ( ! defined( 'MOMOS_TRUSTED_IP_HEADER' ) ) {
			define( 'MOMOS_TRUSTED_IP_HEADER', 'CF-Connecting-IP' );
		}
		$_SERVER['REMOTE_ADDR']           = '198.51.100.23'; // Not a CDN address: a client talking to the origin directly.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.' . wp_rand( 1, 250 );
		$this->assertSame( '198.51.100.23', \Momos\Kernel\Http\RequestContext::ip(), 'A spoofed CF-Connecting-IP from a non-proxy peer was trusted (rate limits / audit IP can be chosen by the client).' );
	}
}
