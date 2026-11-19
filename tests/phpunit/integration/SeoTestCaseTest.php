<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\I18n\Locale;
use Momos\Presentation\Migration\M0007_SystemPages;
use Momos\Presentation\Migration\M0007_SystemPagesR2;
use Momos\Presentation\PageContext;
use Momos\Presentation\SystemPages;
use Momos\Seo\Context;
use Momos\Tests\TestCase;

/**
 * Fixtures for the SEO & analytics tests (branches, menu, translations, page visits in a language).
 * Abstract-by-convention: it has one smoke test so PHPUnit does not warn about an empty class.
 */
class SeoTestCaseTest extends TestCase {
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/news/%postname%/' );
		// Re-add the CPT permastructs and module rewrite rules lost by the permalink reset.
		\Momos\Branch\PostType\PostTypes::register_types();
		\Momos\Menu\Rewrite\MenuRewrite::rules();
		flush_rewrite_rules( false );
		delete_option( 'momos_languages_enabled' );
		( new M0007_SystemPages() )->up();
		( new M0007_SystemPagesR2() )->up();
		SystemPages::flush();
		PageContext::reset();
		Context::reset();
		\Momos\Kernel\I18n\Strings::flush();
		\Momos\Seo\Redirects\RedirectStore::flush();
		if ( class_exists( \Momos\Branch\Service\ScheduleService::class ) ) {
			\Momos\Branch\Service\ScheduleService::flush();
		}
		$_GET = [];
	}

	public function tear_down(): void {
		PageContext::reset();
		Context::reset();
		$_GET = [];
		parent::tear_down();
	}

	public function test_fixtures_build(): void {
		$b = $this->branch( 'QSE', [ 'en' => [ 'title' => 'Q branch' ] ] );
		$this->assertSame( 'publish', get_post_status( $b ) );
	}

	/** Approved translation for a post or term. @param array<string,string> $vals */
	protected function translate( string $type, int $id, string $lang, array $vals ): void {
		$f = 'term' === $type ? 'update_term_meta' : 'update_post_meta';
		$f( $id, '_momos_i18n_' . $lang, wp_slash( (string) wp_json_encode( $vals, JSON_UNESCAPED_UNICODE ) ) );
		$f( $id, '_momos_i18n_status_' . $lang, 'approved' );
		$f( $id, '_momos_i18n_updated_' . $lang, gmdate( 'Y-m-d H:i:s' ) );
	}

	/** Published post without publish validation (fixtures only). */
	protected function published( array $args ): int {
		$id = self::factory()->post->create( array_merge( [ 'post_status' => 'draft' ], $args ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $id ] );
		clean_post_cache( $id );
		return (int) $id;
	}

	/** @param array<string,array<string,string>> $tr lang => values */
	protected function branch( string $code, array $tr = [], array $meta = [] ): int {
		$id = $this->published( [ 'post_type' => 'momo_branch', 'post_title' => '店舗' . $code, 'post_name' => strtolower( $code ) . '-branch', 'post_content' => '駅近のハラールレストラン。' ] );
		$defaults = [
			'momo_branch_code'      => $code,
			'momo_operating_status' => 'open',
			'momo_postal_code'      => '542-0085',
			'momo_prefecture'       => '大阪府',
			'momo_city'             => '大阪市中央区',
			'momo_address_line1'    => '心斎橋筋1-1-1',
			'momo_lat'              => '34.672510',
			'momo_lng'              => '135.501230',
			'momo_phone_e164'       => '+81600000000',
			'momo_res_enabled'      => '1',
		];
		foreach ( array_merge( $defaults, $meta ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		foreach ( $tr as $lang => $vals ) {
			$this->translate( 'post', $id, $lang, $vals );
		}
		return $id;
	}

	protected function category( string $slug, array $tr = [] ): int {
		$t  = wp_insert_term( 'カテゴリ' . $slug, 'momo_menu_category', [ 'slug' => $slug, 'description' => '説明' . $slug ] );
		$id = (int) $t['term_id'];
		foreach ( $tr as $lang => $vals ) {
			$this->translate( 'term', $id, $lang, $vals );
		}
		return $id;
	}

	protected function dish( string $sku, int $cat, array $tr = [], array $meta = [] ): int {
		$id = $this->published( [ 'post_type' => 'momo_dish', 'post_title' => '料理' . $sku, 'post_name' => strtolower( $sku ), 'post_content' => '<p>おいしい料理です。</p>' ] );
		wp_set_object_terms( $id, [ $cat ], 'momo_menu_category' );
		foreach ( array_merge( [ 'momo_dish_sku' => $sku, 'momo_primary_category' => (string) $cat, 'momo_reference_price_jpy' => '1500', 'momo_spice_level' => '1' ], $meta ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		foreach ( $tr as $lang => $vals ) {
			$this->translate( 'post', $id, $lang, $vals );
		}
		return $id;
	}

	/** @param list<array{0:int,1:string,2:string,3?:bool}> $rows weekday, opens, closes, next_day */
	protected function hours( int $branch, array $rows ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $rows as $i => $r ) {
			$wpdb->insert( \Momos\Kernel\Db\Tables::hours(), [ 'branch_id' => $branch, 'weekday' => $r[0], 'period_no' => $i + 1, 'period_label' => 'other', 'opens_at' => $r[1] . ':00', 'closes_at' => $r[2] . ':00', 'closes_next_day' => ! empty( $r[3] ) ? 1 : 0, 'created_at' => $now, 'updated_at' => $now ] );
		}
		\Momos\Branch\Service\ScheduleService::flush();
	}

	/** Visit a post/page (by ID) or a URL (ja path, optional query) in a language, like the Router would. */
	protected function visit( int|string $target, string $lang = 'ja' ): void {
		Locale::set( 'ja' );
		$this->go_to( is_int( $target ) ? (string) get_permalink( $target ) : $target );
		Locale::set( $lang );
		PageContext::reset();
		Context::reset();
	}
}
