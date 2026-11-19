<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Cache\CacheInvalidator;
use Momos\Kernel\Http\RequestContext;
use Momos\Kernel\Log\Redactor;
use Momos\Kernel\Media\UploadPolicy;
use Momos\Kernel\Support\Hash;
use Momos\Tests\TestCase;

/**
 * Regression tests for the kernel QA fixes (QA-018, QA-019, QA-023, QA-062, QA-063, QA-065, QA-066, QA-075).
 * The QA reproducers live in QaPerformanceTest / QaSecurityTest; these cover the edges around them.
 */
final class KernelQaFixesTest extends TestCase {
	public function tear_down(): void {
		delete_option( 'momo_system_pages' );
		delete_option( 'momos_languages_enabled' );
		( new \ReflectionProperty( CacheInvalidator::class, 'tags' ) )->setValue( null, [ 'momo-global' ] );
		CacheInvalidator::flush();
		parent::tear_down();
	}

	/** @return list<string> tags purged while $fn runs */
	private function purged( callable $fn ): array {
		CacheInvalidator::flush();
		$purged = [];
		$spy    = static function ( $tags ) use ( &$purged ): void {
			$purged = array_merge( $purged, (array) $tags );
		};
		add_action( 'momos/cache/purged', $spy );
		$fn();
		CacheInvalidator::flush();
		remove_action( 'momos/cache/purged', $spy );
		return array_values( array_unique( $purged ) );
	}

	// ------------------------------------------------------------- QA-018 tags known at send_headers

	public function test_queried_object_is_tagged_on_send_headers_before_the_header_is_built(): void {
		$this->assertSame( 1, has_action( 'send_headers', [ CacheInvalidator::class, 'tag_queried_object' ] ) );
		$this->assertSame( 99, has_action( 'send_headers', [ CacheInvalidator::class, 'headers' ] ) );
		$this->assertFalse( has_action( 'template_redirect', [ CacheInvalidator::class, 'tag_queried_object' ] ) );
	}

	/** System listing pages carry the tag their purges use (momo-menu, momo-halal, …), not only momo-post-{id}. */
	public function test_system_listing_pages_carry_their_listing_tag(): void {
		$this->set_permalink_structure( '/news/%postname%/' );
		$menu  = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'qa-kernel-menu' ] );
		$halal = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'qa-kernel-halal' ] );
		update_option( 'momo_system_pages', [ 'menu' => $menu, 'halal' => $halal ] );
		$prop = new \ReflectionProperty( CacheInvalidator::class, 'tags' );

		foreach ( [ $menu => 'momo-menu', $halal => 'momo-halal' ] as $id => $tag ) {
			$prop->setValue( null, [ 'momo-global' ] );
			$this->go_to( (string) get_permalink( $id ) );
			$tags = $prop->getValue();
			$this->assertContains( 'momo-post-' . $id, $tags );
			$this->assertContains( $tag, $tags );
		}
	}

	// ------------------------------------------------------------- QA-019 purge map

	public function test_dish_events_purge_category_tags_and_urls(): void {
		$this->set_permalink_structure( '/news/%postname%/' );
		$cat  = self::factory()->term->create( [ 'taxonomy' => 'momo_menu_category', 'slug' => 'qa-kernel-cat' ] );
		$dish = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_status' => 'publish', 'post_name' => 'qa-kernel-dish' ] );
		wp_set_object_terms( $dish, [ $cat ], 'momo_menu_category' );

		foreach ( [ 'momos/dish/published', 'momos/dish/retired', 'momos/dietary/review_required' ] as $hook ) {
			$tags = $this->purged( static fn() => do_action( $hook, $dish ) );
			$this->assertContains( 'momo-dish-' . $dish, $tags, $hook );
			$this->assertContains( 'momo-cat-' . $cat, $tags, $hook );
		}
		$claims = $this->purged( static fn() => do_action( 'momos/dietary/claims_changed', 'dish', $dish ) );
		foreach ( [ 'momo-halal', 'momo-home', 'momo-dish-' . $dish, 'momo-cat-' . $cat ] as $t ) {
			$this->assertContains( $t, $claims, 'claims_changed(dish)' );
		}
		$branch = $this->purged( static fn() => do_action( 'momos/dietary/claims_changed', 'branch', 77 ) );
		$this->assertContains( 'momo-branch-77', $branch );
		$this->assertContains( 'momo-halal', $branch );
		$this->assertSame( [ 'momo-global' ], $this->purged( static fn() => do_action( 'momos/dietary/claims_changed', 'statement', 0 ) ), 'site-wide statements render on every dish/branch page' );

		update_option( 'momos_languages_enabled', [ 'en' ] );
		$paths = array_map( static fn( $u ) => (string) wp_parse_url( $u, PHP_URL_PATH ), CacheInvalidator::urls_for_tags( [ 'momo-cat-' . $cat, 'momo-branch-' . $dish ] ) );
		foreach ( [ '/menu/qa-kernel-cat/', '/en/menu/qa-kernel-cat/', '/menu/', '/en/menu/', '/branches/', '/', '/en/' ] as $p ) {
			$this->assertContains( $p, $paths );
		}
	}

	// ------------------------------------------------------------- QA-023 upload policy

	public function test_upload_policy_allows_only_small_jpeg_png_webp(): void {
		$this->assertSame( UploadPolicy::MIMES, get_allowed_mime_types( self::factory()->user->create( [ 'role' => 'administrator' ] ) ) );
		foreach ( [ 'a.jpg', 'b.JPEG', 'c.png', 'd.webp' ] as $ok ) {
			$this->assertEmpty( UploadPolicy::prefilter( [ 'name' => $ok, 'tmp_name' => '', 'size' => 1000, 'error' => 0 ] )['error'], $ok );
		}
		foreach ( [ 'a.svg', 'b.avif', 'c.pdf', 'd.zip', 'e.php', 'f.jpg.php', 'g.heic', 'h.gif' ] as $bad ) {
			$this->assertNotEmpty( UploadPolicy::prefilter( [ 'name' => $bad, 'tmp_name' => '', 'size' => 1000, 'error' => 0 ] )['error'] ?? '', $bad );
		}
		$this->assertNotEmpty( UploadPolicy::prefilter( [ 'name' => 'big.jpg', 'tmp_name' => '', 'size' => UploadPolicy::MAX_BYTES + 1, 'error' => 0 ] )['error'] ?? '' );
		$this->assertLessThanOrEqual( UploadPolicy::MAX_BYTES, wp_max_upload_size() );
	}

	public function test_media_handle_sideload_rejects_a_disallowed_type(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( 'qa-kernel' );
		file_put_contents( $tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" ); // phpcs:ignore
		$id = media_handle_sideload( [ 'name' => 'qa-kernel.pdf', 'tmp_name' => $tmp ], 0 );
		if ( is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		$this->assertWPError( $id );
	}

	// ------------------------------------------------------------- QA-065 redactor

	public function test_redactor_masks_tokens_but_keeps_other_text(): void {
		$token = str_repeat( 'aB3_-', 8 ) . 'xyz'; // 43 chars
		$this->assertSame( 43, strlen( $token ) );
		$this->assertSame( 'status [token] ok', Redactor::scrub( 'status ' . $token . ' ok' ) );
		$this->assertSame( '/status/5B43XSD4/#t=[token]', Redactor::scrub( '/status/5B43XSD4/#t=' . $token ) );
		$this->assertSame( 'sha ' . str_repeat( 'a', 64 ), Redactor::scrub( 'sha ' . str_repeat( 'a', 64 ) ), 'longer runs (hashes) are not tokens' );
		$this->assertSame( 'mail [email] tel [phone]', Redactor::scrub( 'mail guest@example.test tel 090-1234-5678' ) );
	}

	// ------------------------------------------------------------- QA-066 trusted proxy ranges

	public function test_cidr_matching(): void {
		$this->assertTrue( RequestContext::in_range( '173.245.48.10', '173.245.48.0/20' ) );
		$this->assertTrue( RequestContext::in_range( '173.245.63.255', '173.245.48.0/20' ) );
		$this->assertFalse( RequestContext::in_range( '173.245.64.0', '173.245.48.0/20' ) );
		$this->assertTrue( RequestContext::in_range( '2400:cb00:1::1', '2400:cb00::/32' ) );
		$this->assertFalse( RequestContext::in_range( '2400:cb01::1', '2400:cb00::/32' ) );
		$this->assertFalse( RequestContext::in_range( '173.245.48.10', '2400:cb00::/32' ) );
		$this->assertTrue( RequestContext::in_range( '10.0.0.5', '10.0.0.5' ) );
		$this->assertFalse( RequestContext::in_range( '10.0.0.5', '10.0.0.0/33' ) );
		$this->assertFalse( RequestContext::in_range( 'garbage', '10.0.0.0/8' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_trusted_ip_header_is_honoured_from_a_configured_proxy_range(): void {
		define( 'MOMOS_TRUSTED_IP_HEADER', 'CF-Connecting-IP' );
		define( 'MOMOS_TRUSTED_PROXIES', '173.245.48.0/20, 2400:cb00::/32' );
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.45';

		$_SERVER['REMOTE_ADDR'] = '173.245.50.1';
		$this->assertSame( '203.0.113.45', RequestContext::ip() );
		$_SERVER['REMOTE_ADDR'] = '2400:cb00:2::9';
		$this->assertSame( '203.0.113.45', RequestContext::ip() );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
		$this->assertSame( '198.51.100.23', RequestContext::ip(), 'not from the proxy range → REMOTE_ADDR' );
		$_SERVER['REMOTE_ADDR']           = '173.245.50.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$this->assertSame( '173.245.50.1', RequestContext::ip(), 'invalid header value → REMOTE_ADDR' );
	}

	// ------------------------------------------------------------- QA-075 purpose-separated HMAC

	public function test_purpose_hmacs_are_separated_and_keyed(): void {
		$ip = '203.0.113.45';
		$this->assertSame( hash_hmac( 'sha256', 'ip|' . $ip, Hash::key() ), Hash::purpose( 'ip', $ip ) );
		$this->assertNotSame( Hash::purpose( 'ip', $ip ), Hash::purpose( 'rl', $ip ) );
		$this->assertNotSame( Hash::purpose( 'ip', $ip ), Hash::hmac( $ip ) );
		$this->assertNotSame( hash( 'sha256', $ip ), Hash::hmac( $ip ) );
		$this->assertNotSame( '', Hash::key() );
		$this->expectException( \InvalidArgumentException::class );
		Hash::purpose( 'nope', $ip );
	}

	// ------------------------------------------------------------- QA-062 / QA-063 shipped configuration

	public function test_release_ships_server_rules_and_dev_router_applies_them(): void {
		$root   = dirname( __DIR__, 3 );
		$build  = (string) file_get_contents( $root . '/bin/build-release.sh' );
		$router = (string) file_get_contents( $root . '/bin/router.php' );
		$this->assertStringContainsString( 'config/server', $build );
		$this->assertStringContainsString( 'wp-content/uploads/.htaccess', $build );
		foreach ( [ 'readme', 'license', 'install|setup-config', 'debug', 'uploads' ] as $needle ) {
			$this->assertStringContainsString( $needle, $router );
		}
		$uploads = (string) file_get_contents( $root . '/config/server/htaccess-uploads.conf' );
		$this->assertMatchesRegularExpression( '/FilesMatch "\\\\\.\(php\\\\d\?\|phtml\|phar/', $uploads );
	}
}
