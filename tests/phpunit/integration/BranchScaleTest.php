<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Query\NoticeQuery;
use Momos\Branch\Query\OfferQuery;
use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Service\ScheduleService;
use Momos\Menu\Repository\BranchMenuItemRepository;
use Momos\Menu\Service\MenuQueryService;
use Momos\Notification\Recipient\RecipientResolver;
use Momos\Presentation\View\BranchLinks;
use Momos\Reservation\Service\SlotGenerator;

/**
 * Adding branches is data, not code (FR-BR-011, NFR-SCL-01, TC-BR-015 / E2E-13 at integration level):
 * a 5th and 6th branch created only through the CPT, its fields, hours, branch-menu rows, scope term and
 * recipients appear in every branch-driven output, and the schedule/offer/notice reads keep a fixed
 * number of queries up to the ⟦20⟧-branch design load (NFR-PERF-02, QA-052).
 */
final class BranchScaleTest extends BranchTestCaseTest {
	/** Lunch + dinner every day, with last orders (reservations need LO on every period). */
	private const WEEK = [ [ '11:00', '15:00', '14:30', 'lunch' ], [ '17:00', '22:00', '21:30', 'dinner' ] ];

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-12-01 10:00:00' ); // Tuesday, JST.
		if ( class_exists( BranchLinks::class ) ) {
			BranchLinks::flush();
		}
		BranchMenuItemRepository::flush_cache();
	}

	public function tear_down(): void {
		if ( class_exists( BranchLinks::class ) ) {
			BranchLinks::flush();
		}
		BranchMenuItemRepository::flush_cache();
		parent::tear_down();
	}

	/** A complete branch from data only: CPT + G1–G6 fields + weekly hours + recipients (no code change). */
	private function data_branch( string $code, int $order ): int {
		$id = $this->make_branch(
			$code,
			[
				'momo_res_enabled'                 => '1',
				'momo_res_notification_recipients' => [ [ 'email' => strtolower( $code ) . '-staff@momos.example', 'language' => 'ja', 'active' => '1' ] ],
			],
			'draft'
		);
		$this->set_week( $id, array_fill( 1, 7, self::WEEK ) );
		// Publish after the hours exist (BranchPublishValidator, spec §4.2).
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish', 'menu_order' => $order ] );
		$this->assertSame( 'publish', get_post_status( $id ), "Branch {$code} publishes from data only" );
		return $id;
	}

	private function dish( string $slug ): int {
		return self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_status' => 'publish', 'post_title' => 'テスト料理 ' . $slug, 'post_name' => $slug ] );
	}

	/** Count the SQL statements run by $fn. */
	private function queries( callable $fn ): int {
		$n     = 0;
		$count = static function ( $q ) use ( &$n ) {
			++$n;
			return $q;
		};
		add_filter( 'query', $count );
		try {
			$fn();
		} finally {
			remove_filter( 'query', $count );
		}
		return $n;
	}

	/** TC-BR-015 / E2E-13: the 5th and 6th branch reach the directory, menu, booking and notification routing. */
	public function test_fifth_and_sixth_branch_are_added_through_data_only_tc_br_015(): void {
		$existing = [];
		foreach ( [ 'QAA', 'QAB', 'QAC', 'QAD' ] as $i => $code ) {
			$existing[ $code ] = $this->data_branch( $code, $i + 1 );
		}
		$fifth = $this->data_branch( 'QAE', 5 );
		$sixth = $this->data_branch( 'QAF', 6 );

		// Directory / selectors: published, public and listed in Head Office order.
		$published = BranchRepository::published_ids();
		$this->assertSame( array_merge( array_values( $existing ), [ $fifth, $sixth ] ), $published );
		$this->assertContains( $fifth, BranchRepository::public_ids( 'ja' ) );
		$this->assertContains( $sixth, BranchRepository::public_ids( 'ja' ) );
		if ( class_exists( BranchLinks::class ) ) {
			$rows = array_column( BranchLinks::all( 'ja' ), 'code', 'id' );
			$this->assertSame( 'QAE', $rows[ $fifth ] ?? null, 'Branch directory data lists the 5th branch' );
			$this->assertSame( 'QAF', $rows[ $sixth ] ?? null, 'Branch directory data lists the 6th branch' );
		}
		$this->assertSame( $sixth, BranchRepository::find_by_code( 'qaf' )['id'] ?? null );

		// Scope term (notices/offers) is created by the data save, slug = lower-case code.
		$term = BranchScopeTaxonomy::term_for_branch( $sixth );
		$this->assertNotNull( $term );
		$this->assertSame( 'qaf', $term->slug );
		$this->assertSame( $sixth, BranchScopeTaxonomy::branch_of_term( $term->term_id ) );

		// Branch menu: availability comes from the branch_menu row only.
		$dish = $this->dish( 'qa-scale-curry' );
		$this->assertGreaterThan( 0, BranchMenuItemRepository::insert( $sixth, $dish, [ 'offered' => 1 ], null ) );
		BranchMenuItemRepository::flush_cache();
		$this->assertNotNull( MenuQueryService::branch_offers_dish( $sixth, $dish ) );
		$this->assertNull( MenuQueryService::branch_offers_dish( $fifth, $dish ), 'No row → not offered at the 5th branch' );
		$this->assertContains( $sixth, MenuQueryService::branches_offering( $dish ) );

		// Reservations: accepting, and slots generated from the branch's own hours.
		$settings = BranchSettingsRepository::get( $sixth );
		$this->assertTrue( $settings['valid'] );
		$this->assertTrue( $settings['enabled'] );
		$this->assertSame( [ 'accepting' => true, 'reason' => null ], SlotGenerator::accepting( (array) BranchRepository::get( $sixth ), $settings ) );
		$days = SlotGenerator::days( $sixth, $settings );
		$this->assertSame( '2026-12-01', $days[0]['date'] );
		$this->assertContains( '17:00', $days[0]['slots'] );
		$this->assertNotContains( '11:00', $days[0]['slots'], 'Lead time (120 min from 10:00) applies' );
		$this->assertContains( '11:00', $days[1]['slots'] );
		$this->assertTrue( SlotGenerator::is_valid_slot( $sixth, '2026-12-02', '21:30' ) );

		// A data-only closure on the 6th branch affects that branch only.
		ClosureRepository::replace_dates( $sixth, [ '2026-12-03' ], 'closed_all_day', [], 'private_event', '', AccessContext::system() );
		$this->assertSame( [], SlotGenerator::slots_on( $sixth, '2026-12-03', 30 ) );
		$this->assertNotSame( [], SlotGenerator::slots_on( $fifth, '2026-12-03', 30 ) );
		$this->assertSame( 'private_event', SlotGenerator::closed_reason( $sixth, '2026-12-03', 30 ) );

		// Notification routing: the branch's own recipients only, never another branch's.
		$emails = array_column( RecipientResolver::branch( $sixth ), 'email' );
		$this->assertSame( [ 'qaf-staff@momos.example' ], $emails );
		$this->assertSame( [ 'qae-staff@momos.example' ], array_column( RecipientResolver::branch( $fifth ), 'email' ) );

		// Offers scoped to the new branch through its scope term.
		$offer = self::factory()->post->create( [ 'post_type' => 'momo_offer', 'post_status' => 'draft', 'post_title' => '新店舗オープン' ] );
		foreach ( [ 'momo_offer_summary' => '開店記念', 'momo_offer_conditions' => '期間限定', 'momo_offer_start_at' => '2026-11-30T00:00', 'momo_offer_end_at' => '2026-12-31T23:00', 'momo_offer_scope' => 'branches', 'momo_offer_cta_type' => 'request_table' ] as $k => $v ) {
			update_post_meta( $offer, $k, $v );
		}
		wp_set_object_terms( $offer, [ $term->term_id ], BranchScopeTaxonomy::TAX );
		update_post_meta( $offer, 'momo_offer_branches', [ $term->term_id ] );
		wp_update_post( [ 'ID' => $offer, 'post_status' => 'publish' ] );
		$this->assertSame( [ $offer ], OfferQuery::for_branch( $sixth, 'ja' ) );
		$this->assertSame( [], OfferQuery::for_branch( $fifth, 'ja' ) );
		$this->assertSame( [ $sixth ], OfferQuery::card_branch_ids( $offer ) );
	}

	/**
	 * NFR-SCL-01 / NFR-PERF-02 (QA-052): schedule reads for every branch card (open-now, client schedule,
	 * today's periods) and the offer/notice listings cost a fixed number of queries at the 20-branch limit,
	 * not one per branch or per day.
	 */
	public function test_twenty_branches_keep_schedule_offer_and_notice_queries_bounded_nfr_scl_01(): void {
		$ids    = [];
		$offers = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$code  = 'Q' . chr( 65 + intdiv( $i, 26 ) ) . chr( 65 + $i % 26 );
			$ids[] = $this->data_branch( $code, $i + 1 );
		}
		// Closures and special hours inside the open-now window for half of the branches.
		foreach ( array_slice( $ids, 0, 10 ) as $n => $id ) {
			if ( 0 === $n % 2 ) {
				ClosureRepository::replace_dates( $id, [ '2026-12-02' ], 'closed_all_day', [], 'maintenance', '', AccessContext::system() );
			} else {
				ClosureRepository::replace_dates( $id, [ '2026-12-01', '2026-12-04' ], 'special_hours', [ [ 'label' => 'dinner', 'opens_at' => '18:00', 'closes_at' => '21:00', 'last_order_at' => '20:30' ] ], 'private_event', '', AccessContext::system() );
			}
		}
		foreach ( $ids as $id ) {
			$offer = self::factory()->post->create( [ 'post_type' => 'momo_offer', 'post_status' => 'draft', 'post_title' => 'オファー ' . $id ] );
			foreach ( [ 'momo_offer_summary' => '概要', 'momo_offer_conditions' => '条件', 'momo_offer_start_at' => '2026-11-30T00:00', 'momo_offer_end_at' => '2026-12-31T23:00', 'momo_offer_scope' => 'branches', 'momo_offer_cta_type' => 'request_table' ] as $k => $v ) {
				update_post_meta( $offer, $k, $v );
			}
			$term = BranchScopeTaxonomy::term_for_branch( $id );
			wp_set_object_terms( $offer, [ $term->term_id ], BranchScopeTaxonomy::TAX );
			update_post_meta( $offer, 'momo_offer_branches', [ $term->term_id ] );
			wp_update_post( [ 'ID' => $offer, 'post_status' => 'publish' ] );
			$offers[] = $offer;

			$notice = self::factory()->post->create( [ 'post_type' => 'momo_notice', 'post_status' => 'draft', 'post_title' => 'お知らせ ' . $id ] );
			foreach ( [ 'momo_notice_body' => '本日は混雑しています。', 'momo_notice_severity' => 'info', 'momo_notice_scope' => 'branches', 'momo_notice_start_at' => '2026-11-30 09:00' ] as $k => $v ) {
				update_post_meta( $notice, $k, $v );
			}
			wp_set_object_terms( $notice, [ $term->term_id ], BranchScopeTaxonomy::TAX );
			update_post_meta( $notice, 'momo_notice_branches', [ $term->term_id ] );
			wp_update_post( [ 'ID' => $notice, 'post_status' => 'publish' ] );
		}

		// Reference results with a cold cache, one branch at a time (the pre-cache behaviour).
		$expected = [];
		foreach ( $ids as $id ) {
			ScheduleService::flush();
			$expected[ $id ] = [ ScheduleService::open_status( $id ), ScheduleService::schedule_json( $id ), ScheduleService::periods_on( $id, '2026-12-01' ), ScheduleService::closure_on( $id, '2026-12-02' ) ];
		}

		// Branch cards for all 20 branches: two schedule queries in total (closures window + weekly rows).
		wp_cache_flush();
		ScheduleService::flush();
		$actual = [];
		$n      = $this->queries(
			static function () use ( $ids, &$actual ): void {
				foreach ( $ids as $id ) {
					$actual[ $id ] = [ ScheduleService::open_status( $id ), ScheduleService::schedule_json( $id ), ScheduleService::periods_on( $id, '2026-12-01' ), ScheduleService::closure_on( $id, '2026-12-02' ) ];
				}
			}
		);
		$this->assertSame( $expected, $actual, 'Request-cached schedule reads return the same results as per-branch reads' );
		// 2 schedule queries + get_post_meta(momo_operating_status / momo_reopen_date) per branch post (meta
		// loads once per branch without a primed cache; directory pages prime it via published_ids()).
		$this->assertLessThanOrEqual( 2 + 20 + 1, $n, "20 branches cost {$n} queries for their schedules" );

		wp_cache_flush();
		ScheduleService::flush();
		$n = $this->queries(
			static function () use ( $ids ): void {
				BranchRepository::published_ids();
				foreach ( $ids as $id ) {
					ScheduleService::open_status( $id );
					ScheduleService::schedule_json( $id );
					ScheduleService::periods_on( $id, '2026-12-01' );
				}
			}
		);
		// published_ids (1) + its post/meta prime (2) + closures window (1) + weekly rows (1) + the autoloaded
		// options reload that follows wp_cache_flush() (1) — independent of the branch count.
		$this->assertLessThanOrEqual( 6, $n, "Directory (published_ids + 20 branch schedules) cost {$n} queries" );

		// Explicit prime of an arbitrary range: one closure + one weekly query, then no more.
		ScheduleService::flush();
		$n = $this->queries( static fn() => ScheduleService::prime( $ids, '2026-12-10', '2026-12-20' ) );
		$this->assertSame( 2, $n );
		$n = $this->queries(
			static function () use ( $ids ): void {
				foreach ( $ids as $id ) {
					for ( $d = 10; $d <= 20; $d++ ) {
						ScheduleService::periods_on( $id, '2026-12-' . $d );
					}
				}
			}
		);
		$this->assertSame( 0, $n, 'Primed per-day lookups are served from memory' );

		// A write flushes the cache within the request.
		ClosureRepository::replace_dates( $ids[19], [ '2026-12-15' ], 'closed_all_day', [], 'maintenance', '', AccessContext::system() );
		$this->assertSame( [], ScheduleService::periods_on( $ids[19], '2026-12-15' ) );
		$this->assertNotSame( [], ScheduleService::periods_on( $ids[18], '2026-12-15' ) );

		// Offers and notices: constant query count for 20 branch-scoped items (no per-item meta/term reads):
		// options reload + ID query + posts + postmeta + term relationships + terms + termmeta + scoped branch
		// posts + their meta = 9–10, whatever the number of items.
		wp_cache_flush();
		$active = [];
		$n      = $this->queries(
			static function () use ( &$active ): void {
				$active = OfferQuery::all_active( 'ja' );
			}
		);
		sort( $active );
		$this->assertSame( $offers, $active );
		$this->assertLessThanOrEqual( 10, $n, "20 active offers cost {$n} queries" );

		wp_cache_flush();
		$items = [];
		$n     = $this->queries(
			static function () use ( &$items ): void {
				$items = NoticeQuery::active( 'ja' );
			}
		);
		$this->assertCount( 20, $items );
		$this->assertLessThanOrEqual( 10, $n, "20 active notices cost {$n} queries" );
	}
}
