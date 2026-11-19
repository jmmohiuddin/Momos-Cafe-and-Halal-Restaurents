<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Cache\CacheInvalidator;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Locale;
use Momos\Presentation\PageContext;
use Momos\Presentation\SystemPages;
use Momos\Reservation\Domain\ReservationStatus;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\ReportService;
use Momos\Seed\DemoSeeder;
use Momos\Tests\TestCase;

/**
 * QA (performance team) — server-side budgets and cache correctness.
 *
 *  - TRD §5 "Supporting technical budgets": ≤ 60 DB queries per uncached front-end page, no queries inside
 *    template loops (NFR-PERF-02, gaps.md §4). Measured with a test-only `query` filter while the real
 *    theme templates render the fictional demo content (wp momos seed --demo).
 *  - TC-PERF-006 / gaps.md §4.1–4.2 / menu-dietary.md §3.18: the page-cache tag of the queried object is known
 *    when the cache headers are sent; a branch-menu change purges the dish page **and its category pages**.
 *  - TC-PERF-005 / NFR-PERF-04 / TRD API-10: 10,000 stored reservations — inbox list/filters, counts, report and
 *    CSV export stay ≤ 2 s and the inbox queries use indexes (no full table scans on filters).
 */
final class QaPerformanceTest extends TestCase {
	/** Front-end query budget per uncached page (Doc 09 TRD §5). */
	private const QUERY_BUDGET = 60;

	public function tear_down(): void {
		PageContext::reset();
		remove_filter( 'wp_using_themes', '__return_true' );
		$_SERVER['REQUEST_URI'] = '/';
		parent::tear_down();
	}

	// ---------------------------------------------------------------- helpers

	/**
	 * Render a front-end URL through the real WordPress template loader and count the SQL it runs.
	 * Object cache is flushed first so every page is measured "uncached" (no warm-up from a previous render).
	 *
	 * @return array{queries:int,html:string,top:array<string,int>}
	 */
	private function render( string $path, string $lang = 'ja' ): array {
		wp_cache_flush();
		PageContext::reset();
		\Momos\Kernel\I18n\Strings::flush();
		$sql   = [];
		$count = static function ( $q ) use ( &$sql ) {
			$sql[] = (string) $q;
			return $q;
		};
		remove_action( 'template_redirect', 'redirect_canonical' );
		add_filter( 'wp_using_themes', '__return_true' );
		add_filter( 'query', $count );
		Locale::set( 'ja' );
		$this->go_to( home_url( $path ) );
		Locale::set( $lang );
		ob_start();
		try {
			require ABSPATH . WPINC . '/template-loader.php';
		} finally {
			$html = (string) ob_get_clean();
			remove_filter( 'query', $count );
		}
		$top = [];
		foreach ( $sql as $q ) {
			$k         = substr( (string) preg_replace( '/\d+/', 'N', (string) preg_replace( '/\s+/', ' ', $q ) ), 0, 110 );
			$top[ $k ] = ( $top[ $k ] ?? 0 ) + 1;
		}
		arsort( $top );
		return [ 'queries' => count( $sql ), 'html' => $html, 'top' => array_slice( $top, 0, 3, true ) ];
	}

	private function seed_demo(): void {
		( new \Momos\Presentation\Migration\M0007_SystemPages() )->up();
		SystemPages::flush();
		( new \Momos\Dietary\Migration\M0003_SeedAllergens() )->up();
		( new DemoSeeder( null, false ) )->run();
		SystemPages::flush();
		// CPT permastructs are only added when permalinks are on at registration time (the test DB starts without).
		add_rewrite_rule( '^branches/([^/]+)/?$', 'index.php?momo_branch=$matches[1]', 'top' );
		flush_rewrite_rules( false );
	}

	/** @return array<string,int> slug => id */
	private function ids( string $type ): array {
		$out = [];
		foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => -1 ] ) as $p ) {
			$out[ $p->post_name ] = (int) $p->ID;
		}
		return $out;
	}

	// ---------------------------------------------------------------- 1. query budget (TRD §5)

	/**
	 * TRD §5 / gaps.md §4: every key customer template renders with ≤ 60 queries (ja and en), and no query
	 * pattern repeats per item in a loop (≤ 10 repetitions of the same statement shape).
	 */
	public function test_customer_templates_stay_within_query_budget(): void {
		$this->set_permalink_structure( '/news/%postname%/' );
		$this->seed_demo();
		$branches = $this->ids( 'momo_branch' );
		$dishes   = $this->ids( 'momo_dish' );
		$this->assertNotEmpty( $branches );
		$this->assertNotEmpty( $dishes );
		$cat   = get_term_by( 'slug', 'curry', 'momo_menu_category' );
		$pages = [
			'home'       => '/',
			'branches'   => '/branches/',
			'branch'     => '/branches/demo-kitahama/',
			'menu hub'   => '/menu/',
			'category'   => $cat ? (string) wp_parse_url( (string) get_term_link( $cat ), PHP_URL_PATH ) : '/menu/curry/',
			'dish'       => (string) wp_parse_url( (string) get_permalink( $dishes['butter-chicken-curry'] ?? reset( $dishes ) ), PHP_URL_PATH ),
			'reservation'=> '/reservation/',
			'offers'     => '/offers/',
			'halal'      => '/halal/',
			'about'      => '/about/',
		];
		$over = [];
		$loop = [];
		foreach ( [ 'ja', 'en' ] as $lang ) {
			foreach ( $pages as $name => $path ) {
				$r = $this->render( $path, $lang );
				$this->assertNotSame( '', $r['html'], "{$name} ({$lang}) rendered nothing" );
				$this->assertFalse( is_404(), "{$name} ({$lang}) {$path} resolved to 404" );
				if ( $r['queries'] > self::QUERY_BUDGET ) {
					$over[] = sprintf( '%s %s [%s]: %d queries', $lang, $name, $path, $r['queries'] );
				}
				foreach ( $r['top'] as $shape => $n ) {
					if ( $n > 10 ) {
						$loop[] = sprintf( '%s %s: %d× %s', $lang, $name, $n, $shape );
						break;
					}
				}
			}
		}
		$this->assertSame( [], $over, "Pages over the TRD §5 budget of ≤ 60 queries per uncached page:\n" . implode( "\n", $over ) . "\nRepeated query shapes (queries inside template loops):\n" . implode( "\n", $loop ) );
		$this->assertSame( [], $loop, "Queries inside template loops (TRD §5 'no queries inside template loops'):\n" . implode( "\n", $loop ) );
	}

	// ---------------------------------------------------------------- 2. cache tags / purge (TC-PERF-006)

	/**
	 * gaps.md §4.2: the LiteSpeed `X-LiteSpeed-Tag` header is built from CacheInvalidator's tag list in
	 * `send_headers`. That list must already contain the queried object's tag (momo-dish-{id}), otherwise a
	 * purge of momo-dish-{id} never reaches the cached dish page. WP fires `send_headers` inside wp() — before
	 * `template_redirect`, where tag_queried_object() runs.
	 */
	public function test_cache_tags_of_queried_object_are_known_when_headers_are_sent(): void {
		$this->set_permalink_structure( '/news/%postname%/' );
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA perf page', 'post_name' => 'qa-perf-page' ] );

		$prop = new \ReflectionProperty( CacheInvalidator::class, 'tags' );
		$prop->setValue( null, [ 'momo-global' ] );
		$seen = null;
		$spy  = static function () use ( $prop, &$seen ): void {
			$seen = $prop->getValue();
		};
		add_action( 'send_headers', $spy, 98 ); // CacheInvalidator::headers() runs at 99 and sends X-LiteSpeed-Tag from this list.
		$this->go_to( (string) get_permalink( $page ) );
		remove_action( 'send_headers', $spy, 98 );
		CacheInvalidator::tag_queried_object(); // what WordPress runs next, on template_redirect (template-loader.php).
		$after = $prop->getValue();

		$this->assertTrue( is_page( $page ), 'go_to resolved the page' );
		$this->assertContains( 'momo-post-' . $page, $after, 'tag_queried_object() tags the page — but only on template_redirect' );
		$this->assertIsArray( $seen, 'send_headers fired during wp()' );
		$this->assertContains( 'momo-post-' . $page, $seen, 'X-LiteSpeed-Tag is sent before the queried object is tagged: tags at send_headers = ' . implode( ',', (array) $seen ) . '; after template_redirect = ' . implode( ',', (array) $after ) );
	}

	/**
	 * menu-dietary.md §3.18 + gaps.md §4.2 tag→URL map: a branch menu change (price/offered/sold-out) purges the
	 * dish page AND its category pages in every enabled language (category cards show price and availability).
	 */
	public function test_branch_menu_change_purges_dish_category_pages(): void {
		$this->set_permalink_structure( '/news/%postname%/' );
		$cat  = self::factory()->term->create( [ 'taxonomy' => 'momo_menu_category', 'slug' => 'qa-perf-cat2', 'name' => 'QA perf cat 2' ] );
		$dish = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_status' => 'publish', 'post_title' => 'QA perf dish 2', 'post_name' => 'qa-perf-dish-2' ] );
		wp_set_object_terms( $dish, [ $cat ], 'momo_menu_category' );
		update_option( 'momos_languages_enabled', [ 'en', 'ar' ] );

		CacheInvalidator::flush(); // drop purges queued by fixture saves (save_post) before measuring.
		$purged = [];
		$spy    = static function ( $tags ) use ( &$purged ): void {
			$purged = array_merge( $purged, (array) $tags );
		};
		add_action( 'momos/cache/purged', $spy );
		do_action( 'momos/branch_menu_item/updated', [ 'dish_id' => $dish, 'branch_id' => 999 ] );
		CacheInvalidator::flush();
		remove_action( 'momos/cache/purged', $spy );

		$urls  = CacheInvalidator::urls_for_tags( array_values( array_unique( $purged ) ) );
		$paths = array_map( static fn( $u ) => (string) wp_parse_url( $u, PHP_URL_PATH ), $urls );
		delete_option( 'momos_languages_enabled' );

		$this->assertContains( 'momo-dish-' . $dish, $purged );
		foreach ( [ '/menu/qa-perf-cat2/', '/en/menu/qa-perf-cat2/', '/ar/menu/qa-perf-cat2/' ] as $expected ) {
			$this->assertContains( $expected, $paths, 'Category page not purged after a branch menu change. Purged tags: ' . implode( ',', $purged ) . ' → ' . implode( ' ', $paths ) );
		}
	}

	// ---------------------------------------------------------------- 3. 10,000 reservations (TC-PERF-005)

	/** Insert $n synthetic reservations (all statuses, 4 branches, −365…+60 days). */
	private function bulk_reservations( int $n, array $branches ): void {
		global $wpdb;
		$t        = Tables::reservations();
		$statuses = [ 'completed', 'completed', 'completed', 'confirmed', 'confirmed', 'pending', 'pending', 'rejected', 'cancelled', 'expired', 'no_show' ];
		$alpha    = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
		$now      = time();
		$rows     = [];
		for ( $i = 1; $i <= $n; $i++ ) {
			$st      = $statuses[ $i % count( $statuses ) ];
			$days    = in_array( $st, [ 'pending', 'confirmed' ], true ) ? ( $i % 60 ) + 1 : -( ( $i % 365 ) + 1 );
			$start   = $now + $days * 86400 + ( $i % 10 ) * 1800;
			$created = min( $start - 86400 * ( 1 + $i % 14 ), $now - 60 );
			$ref     = '';
			for ( $k = 0, $x = $i * 7919; $k < 7; $k++, $x = intdiv( $x, 32 ) + $k * 13 ) {
				$ref .= $alpha[ $x % 32 ];
			}
			$rows[] = $wpdb->prepare(
				'(%s,%s,%d,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
				'Z' . $ref,
				wp_generate_uuid4(),
				$branches[ $i % count( $branches ) ],
				$st,
				gmdate( 'Y-m-d', $start + 9 * 3600 ),
				gmdate( 'H:i:00', $start + 9 * 3600 ),
				gmdate( 'Y-m-d H:i:s', $start ),
				1 + $i % 8,
				'Perf Guest ' . $i,
				'perf' . $i . '@example.com',
				0 === $i % 9 ? 'Window seat' : '',
				0 === $i % 3 ? 'en' : 'ja',
				0 === $i % 3 ? 'en' : 'ja',
				'PP1.0-RP1.0',
				gmdate( 'Y-m-d H:i:s', $created ),
				hash( 'sha256', 't' . $i ),
				gmdate( 'Y-m-d H:i:s', $start + 30 * 86400 ),
				gmdate( 'Y-m-d H:i:s', $created ),
				gmdate( 'Y-m-d H:i:s', $created + 600 ),
				'pending' === $st ? null : gmdate( 'Y-m-d H:i:s', $created + 600 )
			);
			if ( count( $rows ) >= 500 || $i === $n ) {
				$wpdb->query( "INSERT INTO {$t} (public_ref,idempotency_key,branch_id,status,requested_date,requested_time,requested_start_utc,party_size,guest_name,email,special_request,preferred_lang,source_lang,consent_version,consent_at,token_hash,token_expires_utc,created_at,updated_at,confirmed_at) VALUES " . implode( ',', $rows ) ); // phpcs:ignore
				$this->assertSame( '', $wpdb->last_error );
				$rows = [];
			}
		}
	}

	/**
	 * Refresh the optimizer statistics of $table as production has them.
	 *
	 * WP_UnitTestCase runs every test inside one open transaction. InnoDB recalculates persistent statistics
	 * in a background thread a few seconds after ≥ 10 % of a table changed, so 10,000 rows inserted by the
	 * test are still costed as the empty table of the bootstrap: the optimizer then believes a full scan
	 * reads one page and prefers it to any index, which is not the plan a real 10,000-row table gets (shown on
	 * MariaDB 10.11: the same queries use idx_start / idx_created once the statistics are current).
	 * ANALYZE TABLE would commit the test transaction implicitly, so it runs on a second connection: it
	 * samples the B-tree pages, which already hold the uncommitted rows, while this transaction (and its
	 * rollback in tear_down) stays untouched.
	 */
	private function refresh_statistics( string $table ): void {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->query( 'SET SESSION lock_wait_timeout = 10' );
		$res = (array) $other->get_results( "ANALYZE TABLE {$table}", ARRAY_A ); // phpcs:ignore -- test-only, table name from Tables.
		$other->close();
		$this->assertSame( 'OK', (string) ( end( $res )['Msg_text'] ?? '' ), 'ANALYZE TABLE on a second connection: ' . wp_json_encode( $res ) );
	}

	/** @return array{type:string,key:?string,rows:int} first table row of EXPLAIN */
	private function explain( string $sql ): array {
		global $wpdb;
		$r = (array) $wpdb->get_row( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore
		return [ 'type' => (string) ( $r['type'] ?? '' ), 'key' => $r['key'] ?? null, 'rows' => (int) ( $r['rows'] ?? 0 ), 'extra' => (string) ( $r['Extra'] ?? '' ) ];
	}

	/**
	 * TC-PERF-005 / NFR-PERF-04: with 10,000 stored reservations every inbox view and filter (API-10 / SCR-A08)
	 * answers well under 2 s, and the list queries use an index — no full table scan + filesort on a filter.
	 */
	public function test_inbox_with_10000_reservations_is_fast_and_uses_indexes(): void {
		global $wpdb;
		$branches = [];
		for ( $b = 0; $b < 4; $b++ ) {
			$branches[] = self::factory()->post->create( [ 'post_type' => 'momo_branch', 'post_status' => 'publish', 'post_title' => 'Perf branch ' . $b ] );
		}
		$this->bulk_reservations( 10000, $branches );
		$t = Tables::reservations();
		$this->assertGreaterThanOrEqual( 10000, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) ); // phpcs:ignore
		$this->refresh_statistics( $t );

		$ho    = Scope::for_user( $this->user_with_role( 'momo_head_office' ) );
		$bm    = Scope::for_user( $this->user_with_role( 'momo_branch_manager', [ $branches[0] ] ) );
		$this->assertTrue( $ho->is_all() );
		$this->assertSame( [ $branches[0] ], $bm->branch_ids );
		$all   = ReservationStatus::values();
		$today = gmdate( 'Y-m-d' );
		$views = [
			'pending (default)'      => [ 'statuses' => [ 'pending' ] ],
			'all statuses'           => [ 'statuses' => $all ],
			'today'                  => [ 'statuses' => [ 'pending', 'confirmed' ], 'date_from' => $today, 'date_to' => $today ],
			'upcoming confirmed'     => [ 'statuses' => [ 'confirmed' ], 'date_from' => $today ],
			'needs action'           => [ 'statuses' => [ 'pending', 'confirmed' ], 'flags' => [ 'needs_action' ] ],
			'unclaimed'              => [ 'statuses' => $all, 'flags' => [ 'unclaimed' ] ],
			'has special request'    => [ 'statuses' => $all, 'has_special_request' => true ],
			'date range Q1'          => [ 'statuses' => $all, 'date_from' => gmdate( 'Y-m-d', strtotime( '-120 days' ) ), 'date_to' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ],
			'sort created desc p100' => [ 'statuses' => $all, 'sort' => 'created_at', 'order' => 'desc', 'page' => 100 ],
		];
		$slow  = [];
		$page  = [];
		$scans = [];
		foreach ( [ 'HO' => $ho, 'BM' => $bm ] as $who => $scope ) {
			foreach ( $views as $name => $f ) {
				$t0     = microtime( true );
				$res    = ReservationRepository::search( $scope, $f + [ 'per_page' => 20 ] );
				$page_ms = ( microtime( true ) - $t0 ) * 1000;
				ReservationRepository::counts_by_status( $scope );
				ReservationRepository::pending_light( $scope );
				$ms = ( microtime( true ) - $t0 ) * 1000;
				if ( $ms > 2000 ) {
					$slow[] = sprintf( '%s %s: %.0f ms', $who, $name, $ms );
				}
				if ( $page_ms > 300 ) {
					$page[] = sprintf( '%s %s: %.0f ms', $who, $name, $page_ms );
				}
				$this->assertIsArray( $res['rows'] );
				$where = (string) ReservationRepository::scoped_where( $scope, $f );
				$sort  = 'created_at' === ( $f['sort'] ?? '' ) ? 'created_at' : 'requested_start_utc';
				$order = 'desc' === ( $f['order'] ?? 'asc' ) ? 'DESC' : 'ASC';
				// The plan of the first page (LIMIT 20) is checked. A deep page (p100 = OFFSET 1980, ~20 % of the
				// table) may legitimately be read with one sequential scan + filesort rather than ~2,000 random
				// index lookups; that page is held to the measured per-page budget above instead.
				$e = $this->explain( "SELECT * FROM {$t} WHERE {$where} ORDER BY {$sort} {$order}, id {$order} LIMIT 20" );
				if ( 'ALL' === $e['type'] ) {
					$scans[] = sprintf( '%s %s: EXPLAIN type=ALL key=NULL rows=%d %s', $who, $name, $e['rows'], $e['extra'] );
				}
			}
		}
		$this->assertSame( [], $slow, 'Inbox views slower than 2 s: ' . implode( '; ', $slow ) );
		$this->assertSame( [], $page, 'Inbox list pages slower than 300 ms at 10,000 rows: ' . implode( '; ', $page ) );
		$this->assertSame( [], $scans, "TC-PERF-005 'indexes used; no full table scans on filters' — full scans:\n" . implode( "\n", $scans ) );
	}

	/** NFR-PERF-04 supporting: report (12 months) and CSV export of the full allowed range run < 2 s with 10,000 rows. */
	public function test_report_and_csv_export_with_10000_reservations(): void {
		$branches = [];
		for ( $b = 0; $b < 4; $b++ ) {
			$branches[] = self::factory()->post->create( [ 'post_type' => 'momo_branch', 'post_status' => 'publish', 'post_title' => 'Perf report ' . $b ] );
		}
		$this->bulk_reservations( 10000, $branches );
		$scope = Scope::system();

		$t0     = microtime( true );
		$report = ReportService::build( $scope, [ 'date_from' => gmdate( 'Y-m-d', strtotime( '-365 days' ) ), 'date_to' => gmdate( 'Y-m-d' ), 'basis' => 'created', 'branch_ids' => null, 'lang' => null ] );
		$report_ms = ( microtime( true ) - $t0 ) * 1000;
		$this->assertIsArray( $report );
		$this->assertLessThan( 2000, $report_ms, sprintf( 'Report over 12 months took %.0f ms', $report_ms ) );

		$where = (string) ReservationRepository::scoped_where( $scope, [ 'statuses' => ReservationStatus::values(), 'date_from' => gmdate( 'Y-m-d', strtotime( '-365 days' ) ), 'date_to' => gmdate( 'Y-m-d', strtotime( '+60 days' ) ) ] );
		$out   = fopen( 'php://temp', 'w+' );
		$mem0  = memory_get_usage();
		$t0    = microtime( true );
		$n     = \Momos\Reservation\Admin\ExportHandler::stream( $where, false, $out );
		$csv_ms = ( microtime( true ) - $t0 ) * 1000;
		$this->assertGreaterThanOrEqual( 9000, $n );
		$this->assertLessThan( 2000, $csv_ms, sprintf( 'CSV export of %d rows took %.0f ms', $n, $csv_ms ) );
		$this->assertLessThan( 64 * 1048576, memory_get_peak_usage() - $mem0, 'CSV export streams in batches (bounded memory)' );
		fclose( $out );
	}
}
