<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Admin\Content\IllustrativeGuard;
use Momos\Kernel\Cli\Commands;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Admin\TranslationEditor;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\ReviewPolicy;
use Momos\Kernel\I18n\Router;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Media\AltText;
use Momos\Kernel\Media\MetadataStripper;
use Momos\Kernel\Monitoring\AlertMailer;
use Momos\Kernel\Settings;
use Momos\Presentation\SystemPages;

/**
 * Kernel / i18n / media fixes:
 *  - FR-FND-001(d), AC-FND-01-03, FR-CORP-007: hidden language → Japanese 404 state;
 *  - FR-I18N-007(b), AC-I18N-03-01: reviewer ≠ translator for every content class and role (ReviewPolicy);
 *  - FR-ADM-005(d), AC-ADM-04-02: approving a translation needs alt text in that language;
 *  - FR-ADM-005(c), AC-ADM-04-03: EXIF/GPS stripped from uploaded originals and sub-sizes;
 *  - FR-ADM-005(f): no illustrative image on the About / Halal pages;
 *  - FR-SEO-005: translated SEO/OG length guidance in the translation editor;
 *  - FR-SEC-012: critical monitoring conditions are emailed; the queue heartbeat pings its external URL;
 *  - AC-FND-01-08 (sanitize), AC-CORP-02-01 (About edit keeps the reviewed translation), FR-SEC-008
 *    (production secrets from the environment), PRD §18.4 external_ref never exposed, FR-FND-003(c) theme static.
 */
final class KernelI18nMediaFixTest extends AccessTestCaseTest {
	/** @var list<array<string,mixed>> */
	private array $mails = [];

	/** @var list<string> */
	private array $http = [];

	/** @var list<string> */
	private array $files = [];

	public function set_up(): void {
		parent::set_up();
		$this->mails = [];
		$this->http  = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 1, 2 );
		add_filter( 'pre_http_request', [ $this, 'capture_http' ], 1, 3 );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_momos\_alert\_%' OR option_name LIKE '\_transient\_timeout\_momos\_alert\_%'" ); // phpcs:ignore
		wp_cache_flush();
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 1 );
		remove_filter( 'pre_http_request', [ $this, 'capture_http' ], 1 );
		$_POST = [];
		delete_option( 'momos_languages_enabled' );
		delete_option( SystemPages::OPTION );
		SystemPages::flush();
		$_SERVER['REQUEST_URI'] = '/';
		Router::detect();
		foreach ( $this->files as $f ) {
			if ( is_file( $f ) ) {
				wp_delete_file( $f );
			}
		}
		Settings::flush();
		parent::tear_down();
	}

	/** @param array<string,mixed> $atts */
	public function capture_mail( $short, array $atts ): bool {
		$this->mails[] = $atts;
		return true;
	}

	/** @return array<string,mixed> */
	public function capture_http( $pre, $args, $url ): array {
		$this->http[] = (string) $url;
		return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
	}

	// ------------------------------------------------------------------ 1. hidden language → Japanese 404

	/** AC-FND-01-03: /ar/branches/ with Arabic hidden → 404 rendered in Japanese (lang=ja, ltr, ja strings). */
	public function test_hidden_language_request_renders_the_japanese_404(): void {
		update_option( 'momos_languages_enabled', [ 'ja', 'en' ] );
		wp_set_current_user( 0 );
		$_SERVER['REQUEST_URI'] = '/ar/branches/';
		Router::detect();
		$this->assertSame( 'ar', Locale::current(), 'detected from the prefix' );
		$this->assertTrue( Router::is_disabled_language_request() );

		Router::guard_disabled_language();

		$this->assertTrue( is_404() );
		$this->assertSame( Lang::SOURCE, Locale::current(), 'locale back to ja before the 404 renders' );
		$this->assertSame( 'lang="ja" dir="ltr"', Router::language_attributes( 'lang="ar" dir="rtl"' ) );
		$this->assertSame( 'ltr', $GLOBALS['wp_locale']->text_direction );
		$this->assertSame( 'ページが見つかりません', momos_t( '404.title' ) );
		$this->assertStringNotContainsString( '/ar/', Router::filter_link( home_url( '/menu/' ) ) );
		$this->assertNotSame( momos_t( '404.title', [], 'ar' ), momos_t( '404.title' ) );
	}

	/** Staff with momo_preview_languages keep the hidden-language preview (FR-FND-001(c)). */
	public function test_hidden_language_preview_for_staff_is_kept(): void {
		update_option( 'momos_languages_enabled', [ 'ja', 'en' ] );
		wp_set_current_user( $this->user_with_role( 'momo_translator' ) );
		$_SERVER['REQUEST_URI'] = '/ar/branches/';
		Router::detect();
		Router::guard_disabled_language();
		$this->assertSame( 'ar', Locale::current() );
		$this->assertFalse( is_404() );
		$_SERVER['REQUEST_URI'] = '/en/branches/';
		Router::detect();
		$this->assertFalse( Router::is_disabled_language_request(), 'enabled language is not flagged' );
	}

	// ------------------------------------------------------------------ 2. reviewer ≠ translator

	/** @param array<string,mixed> $fields */
	private function submit( string $type, int|string $id, string $lang, array $fields, string $status ): array {
		$_POST = [
			'momos_tr'        => [ $lang => $fields ],
			'momos_tr_status' => [ $lang => $status ],
			'_momos_tr_nonce' => wp_create_nonce( TranslationEditor::nonce_action( $type, $id ) ),
		];
		return TranslationEditor::persist( $type, $id );
	}

	public function test_review_policy_helper(): void {
		$this->assertTrue( ReviewPolicy::is_self_approval( 7, 7 ) );
		$this->assertFalse( ReviewPolicy::is_self_approval( 7, 8 ) );
		$this->assertFalse( ReviewPolicy::is_self_approval( 7, 0 ), 'unknown translator (seeded) does not block' );
		$this->assertNull( ReviewPolicy::refuse_self_approval( 'dietary_statement', null, 'ur', 7, 8 ) );
		$this->assertSame( 'You cannot approve your own translation of this content.', ReviewPolicy::refuse_self_approval( 'dietary_statement', null, 'ur', 7, 7, [ 'key' => 'halal_x' ] ) );
		$row = array_values( array_filter( $this->audit_rows( 'access.denied' ), static fn( $r ) => 'dietary_statement' === $r['object_type'] ) );
		$this->assertCount( 1, $row );
		$this->assertStringContainsString( 'self_approval', (string) $row[0]['changes'] );
	}

	/** AC-I18N-03-01: a translator's Urdu dish translation is not public until another person approves it. */
	public function test_translator_cannot_publish_own_dish_translation(): void {
		$dish = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_title' => 'ビリヤニ', 'post_status' => 'draft' ] );
		$tr   = $this->user_with_role( 'momo_translator' );
		wp_set_current_user( $tr );
		$errors = $this->submit( 'post', $dish, 'ur', [ 'title' => 'بریانی' ], 'approved' );
		$this->assertSame( [ ReviewPolicy::message() ], $errors );
		$this->assertSame( 'needs_review', Translations::status( 'post', $dish, 'ur' ) );
		$this->assertSame( '', Translations::post( $dish, 'title', 'ur' ), 'not public' );
		$this->assertNotEmpty( array_filter( $this->audit_rows( 'access.denied' ), static fn( $r ) => str_contains( (string) $r['changes'], 'self_approval' ) ) );

		// Approving later without changes is still self-approval.
		$this->assertSame( [ ReviewPolicy::message() ], $this->submit( 'post', $dish, 'ur', [ 'title' => 'بریانی' ], 'approved' ) );
		$this->assertSame( 'needs_review', Translations::status( 'post', $dish, 'ur' ) );

		// A second translator/reviewer approves the unchanged text.
		wp_set_current_user( $this->user_with_role( 'momo_translator' ) );
		$this->assertSame( [], $this->submit( 'post', $dish, 'ur', [ 'title' => 'بریانی' ], 'approved' ) );
		$this->assertSame( 'approved', Translations::status( 'post', $dish, 'ur' ) );
		$this->assertSame( 'بریانی', Translations::post( $dish, 'title', 'ur' ) );

		// Changing approved text and keeping "approved" needs another review.
		$editor = (int) get_current_user_id();
		$this->assertSame( [ ReviewPolicy::message() ], $this->submit( 'post', $dish, 'ur', [ 'title' => 'چکن بریانی' ], 'approved' ) );
		$this->assertSame( 'needs_review', Translations::status( 'post', $dish, 'ur' ) );
		$this->assertSame( $editor, Translations::translated_by( 'post', $dish, 'ur' ) );
	}

	/** The PRD names no exception: Head Office and Super Administrators cannot approve their own text either. */
	public function test_head_office_and_super_admin_cannot_approve_their_own_text(): void {
		$term = self::factory()->term->create( [ 'taxonomy' => 'momo_ingredient', 'name' => '鶏肉' ] );
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$this->assertSame( [ ReviewPolicy::message() ], $this->submit( 'term', $term, 'en', [ 'name' => 'Chicken' ], 'approved' ) );
		$this->assertSame( 'needs_review', Translations::status( 'term', $term, 'en' ) );

		$sa = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $sa );
		$this->assertSame( [], $this->submit( 'term', $term, 'en', [ 'name' => 'Chicken' ], 'approved' ), 'SA reviews the HO text' );
		$this->assertSame( 'approved', Translations::status( 'term', $term, 'en' ) );

		// Option texts (brand settings) follow the same rule.
		$this->assertSame( [ ReviewPolicy::message() ], $this->submit( 'option', 'momo-brand', 'en', [ 'momo_trading_name' => "Momo's" ], 'approved' ) );
		$this->assertSame( 'needs_review', Translations::option_status( 'momo-brand', 'en' ) );
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$this->assertSame( [], $this->submit( 'option', 'momo-brand', 'en', [ 'momo_trading_name' => "Momo's" ], 'approved' ) );
		$this->assertSame( 'approved', Translations::option_status( 'momo-brand', 'en' ) );
	}

	// ------------------------------------------------------------------ 3. alt text per language

	private function image( string $alt_ja = '写真' ): int {
		$id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg', 'post_title' => 'img', 'file' => 'qa-alt.jpg' ] );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt_ja );
		return (int) $id;
	}

	/** AC-ADM-04-02: a dish whose image lacks Urdu alt text cannot be published in Urdu. */
	public function test_urdu_publish_blocked_without_urdu_alt_text(): void {
		$dish    = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_title' => 'ビリヤニ', 'post_status' => 'draft' ] );
		$thumb   = $this->image();
		$gallery = $this->image();
		set_post_thumbnail( $dish, $thumb );
		update_post_meta( $dish, 'momo_gallery', [ (string) $gallery ] );
		$this->assertSame( [ $thumb, $gallery ], AltText::images( $dish ) );

		wp_set_current_user( $this->user_with_role( 'momo_translator' ) );
		$this->submit( 'post', $dish, 'ur', [ 'title' => 'بریانی' ], 'needs_review' );

		$reviewer = $this->user_with_role( 'momo_head_office' );
		update_user_meta( $reviewer, 'locale', 'en_US' );
		wp_set_current_user( $reviewer );
		$this->assertSame( [ 'Add alternative text before publishing' ], $this->submit( 'post', $dish, 'ur', [ 'title' => 'بریانی' ], 'approved' ) );
		$this->assertSame( 'needs_review', Translations::status( 'post', $dish, 'ur' ) );
		$this->assertSame( [ $thumb, $gallery ], AltText::missing( $dish, 'ur' ) );
		$this->assertNotEmpty( $this->audit_rows( 'translation.publish_blocked' ) );

		// Japanese admins get the Japanese catalog text.
		update_user_meta( $reviewer, 'locale', 'ja' );
		$this->assertSame( [ '公開前に代替テキストを入力してください' ], $this->submit( 'post', $dish, 'ur', [ 'title' => 'بریانی' ], 'approved' ) );

		// Alt text for one image only is not enough; a draft alt translation is not enough either.
		Translations::save( 'post', $thumb, 'ur', [ 'alt' => 'بریانی کی پلیٹ' ], 'approved', 0 );
		Translations::save( 'post', $gallery, 'ur', [ 'alt' => 'بریانی' ], 'draft', 0 );
		$this->assertSame( [ $gallery ], AltText::missing( $dish, 'ur' ) );
		$this->assertNotSame( [], $this->submit( 'post', $dish, 'ur', [ 'title' => 'بریانی' ], 'approved' ) );

		Translations::save( 'post', $gallery, 'ur', [ 'alt' => 'بریانی' ], 'approved', 0 );
		$this->assertSame( [], $this->submit( 'post', $dish, 'ur', [ 'title' => 'بریانی' ], 'approved' ) );
		$this->assertSame( 'approved', Translations::status( 'post', $dish, 'ur' ) );
	}

	/** FR-ADM-005(d) covers branches, offers and pages too (featured images); other types are not gated. */
	public function test_alt_gate_covers_branch_offer_and_page(): void {
		foreach ( [ 'momo_branch', 'momo_offer', 'page' ] as $pt ) {
			$p   = self::factory()->post->create( [ 'post_type' => $pt, 'post_title' => 'テスト', 'post_status' => 'draft' ] );
			$img = $this->image();
			set_post_thumbnail( $p, $img );
			$this->assertSame( [ $img ], AltText::missing( $p, 'en' ), $pt );
			$this->assertSame( [], AltText::missing( $p, 'ja' ), $pt . ' has Japanese alt' );
		}
		$news = self::factory()->post->create( [ 'post_title' => 'お知らせ' ] );
		set_post_thumbnail( $news, $this->image() );
		$this->assertSame( [], AltText::missing( $news, 'en' ), 'news posts are not in FR-ADM-005(d)' );
	}

	// ------------------------------------------------------------------ 4. EXIF / GPS stripping

	/** Minimal little-endian EXIF APP1 with Orientation=6 and a GPS IFD (lat 34°41'30" N, lon 135°30' E). */
	private static function exif_app1(): string {
		$entry = static fn( int $tag, int $type, int $count, string $value ): string => pack( 'vvV', $tag, $type, $count ) . str_pad( $value, 4, "\0" );
		$ifd0  = pack( 'v', 2 ) . $entry( 0x0112, 3, 1, pack( 'v', 6 ) ) . $entry( 0x8825, 4, 1, pack( 'V', 38 ) ) . pack( 'V', 0 );
		$gps   = pack( 'v', 5 )
			. $entry( 0x0000, 1, 4, "\x02\x03\x00\x00" )
			. $entry( 0x0001, 2, 2, "N\0" )
			. $entry( 0x0002, 5, 3, pack( 'V', 104 ) )
			. $entry( 0x0003, 2, 2, "E\0" )
			. $entry( 0x0004, 5, 3, pack( 'V', 128 ) )
			. pack( 'V', 0 );
		$data  = pack( 'V6', 34, 1, 41, 1, 30, 1 ) . pack( 'V6', 135, 1, 30, 1, 0, 1 );
		$tiff  = "II\x2A\x00" . pack( 'V', 8 ) . $ifd0 . $gps . $data;
		$body  = "Exif\0\0" . $tiff;
		return "\xFF\xE1" . pack( 'n', strlen( $body ) + 2 ) . $body;
	}

	private function gps_jpeg(): string {
		$im = imagecreatetruecolor( 400, 300 );
		imagefilledrectangle( $im, 0, 0, 399, 299, (int) imagecolorallocate( $im, 200, 120, 40 ) );
		ob_start();
		imagejpeg( $im, null, 90 );
		$jpeg = (string) ob_get_clean();
		// Insert APP1 after SOI + APP0 (JFIF).
		$app0_len = unpack( 'n', substr( $jpeg, 4, 2 ) )[1];
		$cut      = 4 + $app0_len;
		$jpeg     = substr( $jpeg, 0, $cut ) . self::exif_app1() . substr( $jpeg, $cut );
		$tmp      = wp_tempnam( 'qa-gps.jpg' );
		file_put_contents( $tmp, $jpeg ); // phpcs:ignore
		$this->files[] = $tmp;
		return $tmp;
	}

	/** @return array<string,mixed> */
	private static function exif( string $file ): array {
		$e = @exif_read_data( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return is_array( $e ) ? $e : [];
	}

	/** AC-ADM-04-03: a photo with GPS EXIF is stored (original + sub-sizes) without GPS; orientation applied. */
	public function test_uploaded_photo_loses_gps_exif(): void {
		$this->assertSame( 5, has_filter( 'wp_handle_upload', [ MetadataStripper::class, 'on_upload' ] ) );
		$tmp    = $this->gps_jpeg();
		$before = self::exif( $tmp );
		$this->assertArrayHasKey( 'GPSLatitude', $before, 'fixture carries GPS' );
		$this->assertSame( 6, (int) ( $before['Orientation'] ?? 0 ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$input  = [ 'name' => 'qa-gps.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize( $tmp ) ];
		$upload = wp_handle_sideload( $input, [ 'test_form' => false ] );
		$this->assertArrayNotHasKey( 'error', $upload, (string) ( $upload['error'] ?? '' ) );
		$file          = (string) $upload['file'];
		$this->files[] = $file;
		$this->assertStringEndsWith( '.jpg', $file, 'the original keeps its format' );

		$after = self::exif( $file );
		$this->assertSame( [], array_filter( array_keys( $after ), static fn( $k ) => str_starts_with( (string) $k, 'GPS' ) ), 'no GPS tag in the stored original' );
		$this->assertArrayNotHasKey( 'Orientation', $after );
		$this->assertStringNotContainsString( "Exif\0\0", (string) file_get_contents( $file ) ); // phpcs:ignore
		$size = getimagesize( $file );
		$this->assertSame( [ 300, 400 ], [ $size[0], $size[1] ], 'EXIF orientation applied to the pixels' );

		$att  = wp_insert_attachment( [ 'post_mime_type' => 'image/jpeg', 'post_title' => 'qa-gps' ], $file );
		$meta = wp_generate_attachment_metadata( $att, $file );
		$this->assertNotEmpty( $meta['sizes'] ?? [] );
		foreach ( (array) $meta['sizes'] as $name => $s ) {
			$path          = dirname( $file ) . '/' . $s['file'];
			$this->files[] = $path;
			$raw           = (string) file_get_contents( $path ); // phpcs:ignore
			$this->assertStringNotContainsString( "Exif\0\0", $raw, $name );
			$this->assertStringNotContainsString( 'EXIF', $raw, $name );
			$this->assertSame( [], array_filter( array_keys( self::exif( $path ) ), static fn( $k ) => str_starts_with( (string) $k, 'GPS' ) ), $name );
		}
		$this->assertArrayNotHasKey( 'latitude', (array) ( $meta['image_meta'] ?? [] ) );
	}

	// ------------------------------------------------------------------ 5. illustrative images on About / Halal

	/** FR-ADM-005(f): flagged images are refused as featured or content images of the About and Halal pages. */
	public function test_illustrative_images_refused_on_about_and_halal(): void {
		$about = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '私たちについて', 'post_status' => 'publish', 'post_content' => '<p>本文</p>' ] );
		$halal = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'ハラール情報', 'post_status' => 'publish', 'post_content' => '<p>本文</p>' ] );
		$other = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'ギャラリー', 'post_status' => 'publish' ] );
		update_option( SystemPages::OPTION, [ 'about' => $about, 'halal' => $halal ] );
		SystemPages::flush();
		$demo = $this->image();
		update_post_meta( $demo, 'momo_image_illustrative', '1' );
		$real = $this->image();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		foreach ( [ $about, $halal ] as $page ) {
			$this->assertFalse( set_post_thumbnail( $page, $demo ) );
			$this->assertSame( 0, (int) get_post_thumbnail_id( $page ) );
			wp_update_post( [ 'ID' => $page, 'post_content' => '<p>本文</p><img class="wp-image-' . $demo . '" src="x.jpg" alt="">' ] );
			$this->assertSame( '<p>本文</p>', get_post( $page )->post_content, 'content change with a demo image not stored' );
			$this->assertTrue( (bool) set_post_thumbnail( $page, $real ) );
		}
		$this->assertContains( 'Demo images cannot be used here. Upload a real photo.', \Momos\Kernel\Fields\FieldRegistry::last_errors() );
		$this->assertNotEmpty( $this->audit_rows( 'content.illustrative_refused' ) );
		$this->assertTrue( (bool) set_post_thumbnail( $other, $demo ), 'other pages are not guarded here' );

		// Block editor (REST): 400 with the message.
		$req = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $about );
		$req->set_param( 'featured_media', $demo );
		$res = rest_do_request( $req );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'momos_illustrative_image', $res->get_data()['code'] );
		$req = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $halal );
		$req->set_param( 'content', '<!-- wp:image {"id":' . $demo . '} --><figure class="wp-block-image"><img src="x.jpg" alt=""/></figure><!-- /wp:image -->' );
		$this->assertSame( 400, rest_do_request( $req )->get_status() );
		$this->assertSame( [ $demo ], IllustrativeGuard::content_images( '[gallery ids="' . $demo . '"]' ) );
	}

	// ------------------------------------------------------------------ 6. SEO length guidance

	/** FR-SEO-005: translated SEO/OG fields show live counters with the SEO module's recommended lengths. */
	public function test_translation_editor_shows_seo_length_guidance(): void {
		$this->assertSame( \Momos\Seo\SeoFields::TITLE_WARN[1], TranslationEditor::length_limit( 'seo_title', 'en' ) );
		$this->assertSame( \Momos\Seo\SeoFields::TITLE_WARN[1], TranslationEditor::length_limit( 'og_title', 'ar' ) );
		$this->assertSame( \Momos\Seo\SeoFields::DESC_WARN[1], TranslationEditor::length_limit( 'seo_description', 'bn' ) );
		$this->assertSame( \Momos\Seo\SeoFields::TITLE_WARN[0], TranslationEditor::length_limit( 'seo_title', 'ja' ) );
		$this->assertNull( TranslationEditor::length_limit( 'title', 'en' ) );

		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '私たちについて' ] );
		update_post_meta( $page, 'momo_seo_title', '私たちについて' );
		Translations::save( 'post', $page, 'en', [ 'seo_title' => str_repeat( 'a', 61 ), 'og_description' => 'Short' ], 'draft', 0 );
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		ob_start();
		TranslationEditor::render_tabs( 'post', $page, 'en' );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/<p class="description momos-tr-count momos-tr-count--over" data-momos-count-for="momos-tr-en-seo_title" data-max="60" aria-live="polite">Longer than recommended \(61\/60\)<\/p>/', $html );
		$this->assertStringContainsString( 'data-momos-count-for="momos-tr-en-og_description" data-max="160" aria-live="polite">5/160</p>', $html );
		$this->assertStringContainsString( 'data-momos-count-for="momos-tr-en-seo_description" data-max="160"', $html );
		$this->assertStringContainsString( 'data-momos-count-for="momos-tr-en-og_title" data-max="60"', $html );
		$this->assertStringNotContainsString( 'data-momos-count-for="momos-tr-en-title"', $html );

		wp_enqueue_script( 'momos-fields', 'https://example.org/admin-fields.js', [], '1', true );
		TranslationEditor::counter_script();
		$inline = implode( '', (array) wp_scripts()->get_data( 'momos-fields', 'after' ) );
		$this->assertStringContainsString( 'data-momos-count-for', $inline );
		wp_dequeue_script( 'momos-fields' );
	}

	// ------------------------------------------------------------------ 7. monitoring

	/** FR-SEC-012: critical conditions are emailed (throttled per type), not only logged. */
	public function test_critical_monitoring_events_are_emailed(): void {
		Settings::set( 'momo_alert_recipients', 'ops@example.org' );
		foreach ( [ 'notification.failure_rate', 'notification.queue_age', 'job.expiry_heartbeat_missing', 'reservation.integrity.pending_past_start', 'secret.missing', 'notification.transport_smtp_auth_failed' ] as $event ) {
			$this->assertTrue( AlertMailer::is_alert_event( $event ), $event );
		}
		$this->assertFalse( AlertMailer::is_alert_event( 'monitoring.alert' ), 'never re-routed (no loop)' );

		Logger::critical( 'notification.failure_rate', [ 'attempts' => 40, 'failed' => 5, 'email' => 'guest@example.org' ] );
		Logger::critical( 'notification.failure_rate', [ 'attempts' => 41, 'failed' => 6 ] );
		Logger::critical( 'job.expiry_heartbeat_missing', [ 'minutes' => 35 ] );
		Logger::critical( 'reservation.integrity.pending_past_start', [ 'count' => 1, 'reservation_ids' => [ 9 ] ] );
		Logger::critical( 'secret.missing', [ 'name' => 'MOMOS_HASH_PEPPER' ] );
		Logger::critical( 'notification.queue_age', [ 'oldest_utc' => '2026-11-19 00:00:00' ] );
		Logger::critical( 'qa.not_an_alert', [] );

		$subjects = array_map( static fn( $m ) => (string) $m['subject'], $this->mails );
		$this->assertCount( 5, $this->mails, implode( ' | ', $subjects ) );
		foreach ( [ 'notification_failure_rate', 'job_expiry_heartbeat_missing', 'reservation_integrity_pending_past_start', 'secret_missing', 'notification_queue_age' ] as $type ) {
			$this->assertCount( 1, array_filter( $subjects, static fn( $s ) => str_ends_with( $s, 'Alert: ' . $type ) ), $type );
		}
		$this->assertSame( [ 'ops@example.org' ], array_values( (array) $this->mails[0]['to'] ) );
		$this->assertStringContainsString( 'failed: 5', (string) $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'guest@example.org', (string) $this->mails[0]['message'], 'context redacted' );
	}

	/** FR-SEC-012(b): every notification-queue heartbeat also pings its external heartbeat URL. */
	public function test_queue_heartbeat_pings_the_external_monitor(): void {
		$url = 'https://hc.example.test/ping/queue';
		$cb  = static fn( string $u, string $job ) => 'queue' === $job ? $url : $u;
		add_filter( 'momos/monitoring/heartbeat_url', $cb, 10, 2 );
		delete_option( 'momos_job_heartbeat_queue' );
		update_option( 'momos_job_heartbeat_queue', '2026-11-19 00:00:00', false );
		update_option( 'momos_job_heartbeat_queue', '2026-11-19 00:01:00', false );
		$this->assertSame( [ $url, $url ], $this->http );

		Settings::set( 'momo_heartbeat_enabled', '0' );
		update_option( 'momos_job_heartbeat_queue', '2026-11-19 00:02:00', false );
		$this->assertCount( 2, $this->http, 'paused by the G3 switch' );
		Settings::set( 'momo_heartbeat_enabled', '1' );
		$this->assertTrue( Schedule::ping( 'queue' ), 'Schedule::ping is the shared transport' );
		$this->assertCount( 3, $this->http );
		remove_filter( 'momos/monitoring/heartbeat_url', $cb, 10 );
	}

	// ------------------------------------------------------------------ 8. untested ACs

	/** AC-FND-01-08: after sanitize only reserved test domains remain in reservations, notifications and users. */
	public function test_sanitize_leaves_only_reserved_test_domains(): void {
		global $wpdb;
		$branch = $this->branch( 'QSZ' );
		$res    = $this->reservation( $branch );
		$wpdb->update( Tables::reservations(), [ 'email' => 'real.person@gmail.com', 'guest_name' => 'Real Person', 'phone_e164' => '+819012345678', 'special_request' => 'peanut allergy' ], [ 'id' => $res ] );
		self::factory()->user->create( [ 'user_email' => 'staff.member@momos-real.jp' ] );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::reservations() . " WHERE email NOT LIKE '%@example.com'" ) ); // phpcs:ignore

		$out = Commands::sanitize_data();

		$this->assertSame( 0, $out['left'] );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::reservations() . " WHERE email IS NOT NULL AND email NOT LIKE '%@example.com'" ) ); // phpcs:ignore
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email NOT LIKE '%@example.com'" ) ); // phpcs:ignore
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::notifications() . " WHERE recipient_email NOT LIKE '%@example.com'" ) ); // phpcs:ignore
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::reservations() . ' WHERE id = %d', $res ), ARRAY_A ); // phpcs:ignore
		$this->assertSame( 'res' . $res . '@example.com', $row['email'] );
		$this->assertNull( $row['phone_e164'] );
		$this->assertNull( $row['special_request'] );
		$this->assertStringNotContainsString( 'Real Person', (string) $row['guest_name'] );
		$this->assertNotEmpty( $this->audit_rows( 'environment.sanitized' ) );
	}

	/** AC-CORP-02-01: editing the ja About page keeps the reviewed translation live, flagged outdated (IMPL-009). */
	public function test_about_edit_keeps_the_last_reviewed_translation_live(): void {
		$about = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '私たちについて', 'post_status' => 'publish', 'post_content' => '<p>旧本文</p>' ] );
		update_option( SystemPages::OPTION, [ 'about' => $about ] );
		SystemPages::flush();
		Translations::save( 'post', $about, 'en', [ 'title' => 'About us', 'content' => '<p>Old body</p>' ], 'approved', 0 );
		$this->assertFalse( Translations::is_outdated( 'post', $about, 'en' ) );

		wp_set_current_user( $this->user_with_role( 'momo_content_editor' ) );
		$this->assertTrue( current_user_can( 'edit_post', $about ) );
		wp_update_post( [ 'ID' => $about, 'post_content' => '<p>新しい本文</p>' ] );

		$this->assertSame( '<p>新しい本文</p>', Translations::post( $about, 'content', 'ja' ), 'live in Japanese' );
		$this->assertTrue( Translations::post_visible( $about, 'en' ) );
		$this->assertSame( '<p>Old body</p>', Translations::post( $about, 'content', 'en' ), 'English shows the last reviewed translation' );
		$this->assertSame( 'approved', Translations::status( 'post', $about, 'en' ) );
		$this->assertTrue( Translations::is_outdated( 'post', $about, 'en' ), 'flagged "needs update" for translators' );
	}

	/** FR-SEC-008: the production sample reads every key/secret/token/pepper/password from the environment. */
	public function test_production_sample_reads_secrets_from_the_environment(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 3 ) . '/config/wp-config.production.sample.php' ); // phpcs:ignore
		preg_match_all( "/^\s*define\(\s*'([A-Z0-9_]+)'\s*,\s*(.+?)\s*\);/m", $src, $m, PREG_SET_ORDER );
		$secrets = [];
		foreach ( $m as [ , $name, $value ] ) {
			if ( preg_match( '/KEY|SECRET|TOKEN|PEPPER|PASS|SALT/', $name ) ) {
				$secrets[ $name ] = $value;
				$this->assertMatchesRegularExpression( '/^(\(int\)\s*\(\s*)?getenv\(\s*\'[A-Z0-9_]+\'\s*\)/', $value, $name . ' must come from getenv()' );
			}
		}
		foreach ( [ 'DB_PASSWORD', 'AUTH_KEY', 'NONCE_SALT', 'MOMOS_TOKEN_KEY_V1', 'MOMOS_TOKEN_KEY_CURRENT', 'MOMOS_HASH_PEPPER', 'MOMOS_TURNSTILE_SECRET', 'MOMOS_SMTP_PASS' ] as $required ) {
			$this->assertArrayHasKey( $required, $secrets );
		}
		$this->assertDoesNotMatchRegularExpression( "/define\(\s*'[A-Z0-9_]*(KEY|SECRET|TOKEN|PEPPER|PASS|SALT)[A-Z0-9_]*'\s*,\s*'[^']+'/", $src, 'no literal secret value' );
	}

	/** PRD §18.4: external_ref is reserved for Phase 2 and never exposed in REST, HTML or JSON-LD. */
	public function test_external_ref_is_not_exposed(): void {
		foreach ( [ 'momo_branch', 'momo_dish' ] as $pt ) {
			$meta = get_registered_meta_keys( 'post', $pt );
			$this->assertTrue( ! isset( $meta['momo_external_ref'] ) || false === $meta['momo_external_ref']['show_in_rest'], $pt );
		}
		$dish = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_title' => 'カレー', 'post_status' => 'publish' ] );
		update_post_meta( $dish, 'momo_external_ref', 'POS-SECRET-REF-1' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		foreach ( [ '/wp/v2/momo_dish/' . $dish, '/wp/v2/search' ] as $route ) {
			$res = rest_do_request( new \WP_REST_Request( 'GET', $route ) );
			$this->assertStringNotContainsString( 'POS-SECRET-REF-1', (string) wp_json_encode( $res->get_data() ), $route );
			$this->assertStringNotContainsString( 'external_ref', (string) wp_json_encode( $res->get_data() ), $route );
		}
		// No render, JSON-LD or REST code reads the field (only the field definitions, visibility rules and schema).
		$readers = [];
		$it      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( MOMOS_CORE_DIR . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( str_ends_with( (string) $f, '.php' ) && str_contains( (string) file_get_contents( (string) $f ), 'external_ref' ) ) { // phpcs:ignore
				$readers[] = str_replace( MOMOS_CORE_DIR . '/src/', '', (string) $f );
			}
		}
		sort( $readers );
		$this->assertSame( [ 'Branch/Fields/BranchFields.php', 'Branch/Service/BranchVisibility.php', 'Kernel/Migration/M0001_CreateTables.php', 'Menu/Fields/MenuFields.php' ], $readers );
		$theme = (string) get_template_directory();
		$hits  = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $theme, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( preg_match( '/\.(php|js)$/', (string) $f ) && str_contains( (string) file_get_contents( (string) $f ), 'external_ref' ) ) { // phpcs:ignore
				$hits[] = (string) $f;
			}
		}
		$this->assertSame( [], $hits, 'the theme never prints external_ref' );
	}

	/** FR-FND-003(c), AC-FND-01-04 (static half): the theme registers no post types, taxonomies, REST routes, jobs or SQL. */
	public function test_theme_contains_no_business_logic(): void {
		$bad = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/wp-content/themes/momos', \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( ! str_ends_with( (string) $f, '.php' ) ) {
				continue;
			}
			$src = (string) file_get_contents( (string) $f ); // phpcs:ignore
			if ( preg_match( '/\b(register_post_type|register_taxonomy|register_rest_route|rest_api_init|wp_schedule_event|wp_schedule_single_event|as_schedule_\w+|as_enqueue_async_action|Schedule::recurring|dbDelta)\s*\(|\$wpdb\b|\'rest_api_init\'/', $src, $hit ) ) {
				$bad[] = basename( (string) $f ) . ': ' . $hit[0];
			}
		}
		$this->assertSame( [], $bad );
	}
}
