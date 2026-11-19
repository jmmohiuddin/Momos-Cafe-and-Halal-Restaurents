<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Audit\Catalogue;
use Momos\Branch\Admin\ListScreens;
use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Fields\BranchFields;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Repository\HoursRepository;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Cache\CacheInvalidator;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\Renderer;
use Momos\Kernel\I18n\Strings;
use Momos\Kernel\I18n\Translations;
use Momos\Notification\Job\DigestJob;
use Momos\Seo\Schema\JsonLd;

/**
 * Branch / notification / admin fixes:
 *  - F-MENU-04 + FR-BR-008(c): every BM change action of the HO digest is a catalogue action written by its
 *    producer, and each one appears in the digest's branch_changes section.
 *  - AC-BR-04-06: the HO digest line of a BM hours change names branch, changed weekday(s), before → after and user.
 *  - FR-CORP-010(c): offers admin list filters by state, scope and branch (branch-scoped users: own branches).
 *  - FR-ADM-002(e) / F-ADM-02: the assigned-staff list shows active users only.
 *  - FR-ADM-001(b)(c)(d), AC-ADM-01-02: dashboard widgets (offers awaiting approval: N, pages awaiting review,
 *    menu / page translation gaps with LCCS %).
 *  - FR-BR-005 + AC-BR-03-07, AC-BR-03-06, AC-SEO-02-04.
 */
final class BranchNotificationAdminFixesTest extends BranchTestCaseTest {
	/** @var list<list<string>> */
	private array $purges = [];

	public function set_up(): void {
		parent::set_up();
		$this->purges = [];
		add_action(
			'momos/cache/purge',
			function ( $tags ): void {
				$this->purges[] = array_map( 'strval', (array) $tags );
			}
		);
		update_option( 'momos_languages_enabled', [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ] );
		\Momos\Kernel\Settings::flush();
		Strings::flush();
	}

	public function tear_down(): void {
		$_GET = [];
		delete_option( 'momos_languages_enabled' );
		\Momos\Kernel\Settings::flush();
		parent::tear_down();
	}

	/** @return list<string> branch_changes lines of the HO digest for the 24 h ending at $run */
	private function digest_lines( \DateTimeImmutable $run, ?callable $t = null ): array {
		foreach ( DigestJob::sections( null, $run, $t ) as $s ) {
			if ( 'branch_changes' === $s['key'] ) {
				return $s['items'];
			}
		}
		$this->fail( 'branch_changes section missing' );
	}

	private function bm( int $branch, string $name = 'Kenji BM' ): int {
		$id = $this->user_with_role( 'momo_branch_manager', [ $branch ] );
		wp_update_user( [ 'ID' => $id, 'display_name' => $name ] );
		return $id;
	}

	private function published_post( string $type, string $title, array $meta = [] ): int {
		global $wpdb;
		$id = self::factory()->post->create( [ 'post_type' => $type, 'post_status' => 'draft', 'post_title' => $title ] );
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $id ] );
		clean_post_cache( $id );
		foreach ( $meta as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		return $id;
	}

	// ------------------------------------------------------------------ F-MENU-04 / FR-BR-008(c)

	public function test_f_menu_04_every_bm_change_action_appears_in_the_ho_digest(): void {
		$known = Catalogue::all();
		foreach ( DigestJob::BRANCH_CHANGE_ACTIONS as $action ) {
			$this->assertContains( $action, $known, "{$action} is not an Audit\\Catalogue action (nothing writes it)" );
		}
		foreach ( [ 'menu_item.availability_changed', 'notice.published', 'notice.updated', 'branch.hours_updated', 'branch.closure_created', 'branch.content_updated', 'offer.submitted' ] as $required ) {
			$this->assertContains( $required, DigestJob::BRANCH_CHANGE_ACTIONS );
		}

		$this->freeze( '2026-12-01 10:00:00' );
		$b  = $this->make_branch( 'QAA' );
		$bm = $this->bm( $b );
		wp_set_current_user( $bm );

		// Real producer: BM marks a dish sold out at the branch (BranchMenuService → menu_item.availability_changed).
		$dish = $this->published_post( 'momo_dish', 'チキンビリヤニ', [ 'momo_reference_price_jpy' => '1500' ] );
		\Momos\Menu\Repository\BranchMenuItemRepository::insert( $b, $dish, [ 'offered' => true ], 1 );
		\Momos\Menu\Service\BranchMenuService::update( $b, $dish, [ 'version' => 1, 'availability' => 'sold_out' ], $bm );

		// Every other listed action, written as the BM with its producer's payload shape.
		$notice = $this->published_post( 'momo_notice', '年末年始のお知らせ' );
		$offer  = $this->published_post( 'momo_offer', 'ランチセット割引' );
		foreach ( DigestJob::BRANCH_CHANGE_ACTIONS as $action ) {
			if ( 'menu_item.availability_changed' === $action ) {
				continue;
			}
			[ $type, $object ] = match ( strtok( $action, '.' ) ) {
				'notice' => [ 'notice', $notice ],
				'offer' => [ 'offer', $offer ],
				'menu_item' => [ 'branch_menu_item', 1 ],
				default => [ 'branch', $b ],
			};
			$changes = match ( $action ) {
				'menu_item.offered_changed' => [ 'dish_id' => $dish, 'before' => false, 'after' => true, 'created' => false ],
				'menu_item.price_changed' => [ 'dish_id' => $dish, 'before' => null, 'after' => 1700, 'created' => false ],
				'notice.updated', 'offer.withdrawn' => [ 'post_status' => [ 'publish', 'draft' ], 'scope' => 'branches', 'branches' => [ $b ], 'role' => 'momo_branch_manager' ],
				default => [ 'fields' => [ 'post_content' ], 'role' => 'momo_branch_manager' ],
			};
			momos_audit( $action, $type, $object, $b, $changes );
		}
		// Changes by other roles are not "branch changes by ROLE-06".
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		momos_audit( 'branch.hours_updated', 'branch', $b, $b, [ 'set' => [], 'before' => [], 'after' => [] ] );

		$lines = $this->digest_lines( Clock::utc()->modify( '+1 hour' ) );
		$this->assertCount( count( DigestJob::BRANCH_CHANGE_ACTIONS ), $lines, implode( "\n", $lines ) );
		$text = implode( "\n", $lines );
		foreach ( DigestJob::BRANCH_CHANGE_ACTIONS as $action ) {
			$this->assertStringContainsString( 'QAA · ' . $action . ' · ', $text, "{$action} missing from the HO digest" );
		}
		$this->assertStringContainsString( 'QAA · menu_item.availability_changed · «チキンビリヤニ» · availability: available → sold_out', $text );
		$this->assertStringContainsString( 'QAA · notice.updated · «年末年始のお知らせ» · post_status: publish → draft · Kenji BM', $text );
		$this->assertStringContainsString( 'QAA · menu_item.price_changed · «チキンビリヤニ» · — → 1700 · Kenji BM', $text );
		$this->assertStringNotContainsString( 'momo_branch_manager', $text, 'context keys are not listed as fields' );
	}

	// ------------------------------------------------------------------ AC-BR-04-06

	public function test_ac_br_04_06_hours_change_line_names_branch_weekday_before_after_and_user(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$b = $this->make_branch( 'QAH' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00', '14:30', 'lunch' ] ], 2 => [ [ '11:00', '22:00' ] ], 3 => [ [ '11:00', '22:00' ] ] ] );
		$this->assertSame( [], $this->digest_lines( Clock::utc()->modify( '+1 hour' ) ), 'system writes are not BM changes' );

		wp_set_current_user( $this->bm( $b, 'Aiko Manager' ) );
		$periods = [
			[ 'weekday' => 1, 'period_no' => 1, 'label' => 'lunch', 'opens_at' => '11:00', 'closes_at' => '16:00', 'last_order_at' => '15:30' ],
			[ 'weekday' => 2, 'period_no' => 1, 'label' => 'all_day', 'opens_at' => '11:00', 'closes_at' => '22:00', 'last_order_at' => null ],
			[ 'weekday' => 6, 'period_no' => 1, 'label' => 'dinner', 'opens_at' => '17:00', 'closes_at' => '02:00', 'last_order_at' => null ],
		];
		HoursRepository::replace_week( $b, null, null, $periods, AccessContext::current() );

		$lines = $this->digest_lines( Clock::utc()->modify( '+1 hour' ) );
		$this->assertSame( [ 'QAH · branch.hours_updated · Mon: 11:00–15:00 (L.O. 14:30) → 11:00–16:00 (L.O. 15:30); Wed: 11:00–22:00 → —; Sat: — → 17:00–02:00 · Aiko Manager' ], $lines );
		$this->assertStringNotContainsString( 'before', $lines[0], 'no raw keys/arrays' );
		$this->assertStringNotContainsString( '{', $lines[0] );

		// In the recipient's language (E10 template passes the renderer's translator).
		$ja = $this->digest_lines( Clock::utc()->modify( '+1 hour' ), static fn( string $k ): string => (string) Strings::raw( $k, 'ja' ) );
		$this->assertStringContainsString( '月: 11:00–15:00 (L.O. 14:30) → 11:00–16:00 (L.O. 15:30); 水: 11:00–22:00 → —; 土: — → 17:00–02:00', $ja[0] );

		// Dated sets carry their validity; closures show date and before → after.
		ClosureRepository::replace_dates( $b, [ '2026-12-31' ], 'closed_all_day', [], 'public_holiday', 'note', AccessContext::current() );
		$lines = $this->digest_lines( Clock::utc()->modify( '+1 hour' ) );
		$this->assertSame( 'QAH · branch.closure_created · 2026-12-31: — → closed_all_day (public_holiday) · Aiko Manager', $lines[1] );
		$this->assertStringNotContainsString( 'note', $lines[1], 'internal note never listed' );
	}

	public function test_e10_head_office_email_renders_readable_hours_line(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$b = $this->make_branch( 'QAE' );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00' ] ] ] );
		wp_set_current_user( $this->bm( $b, 'Aiko Manager' ) );
		HoursRepository::replace_week( $b, null, null, [ [ 'weekday' => 1, 'period_no' => 1, 'label' => 'lunch', 'opens_at' => '12:00', 'closes_at' => '15:00', 'last_order_at' => null ] ], AccessContext::current() );
		$row      = [
			'id'               => 0,
			'uuid'             => wp_generate_uuid4(),
			'template_key'     => 'E10',
			'template_variant' => 'head_office',
			'lang'             => 'en',
			'reservation_id'   => null,
			'branch_id'        => null,
			'object_type'      => 'digest',
			'object_id'        => 0,
			'created_at'       => Clock::mysql_utc( Clock::utc()->modify( '+1 hour' ) ),
			'recipient_type'   => 'head_office',
		];
		$rendered = \Momos\Notification\Template\Renderer::render( $row );
		$this->assertSame( 'en', $rendered->lang );
		$this->assertStringContainsString( 'QAE · branch.hours_updated · Mon: 11:00–15:00 → 12:00–15:00 · Aiko Manager', $rendered->text );
		$row['lang'] = 'ja';
		$this->assertStringContainsString( 'QAE · branch.hours_updated · 月: 11:00–15:00 → 12:00–15:00 · Aiko Manager', \Momos\Notification\Template\Renderer::render( $row )->text );
	}

	// ------------------------------------------------------------------ FR-CORP-010(c)

	/** @return list<int> IDs the main admin offers list returns for $get as the current user */
	private function offer_list( array $get ): array {
		// ListScreens::register() runs only in wp-admin requests: hook the list-query filters as there.
		if ( false === has_action( 'pre_get_posts', [ ListScreens::class, 'offer_filters' ] ) ) {
			add_action( 'pre_get_posts', [ ListScreens::class, 'scope_lists' ] );
			add_action( 'pre_get_posts', [ ListScreens::class, 'offer_filters' ], 11 );
		}
		$_GET = $get;
		set_current_screen( 'edit-momo_offer' );
		$q = new \WP_Query();
		$GLOBALS['wp_the_query'] = $q; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- main-query simulation.
		$ids = $q->query( [ 'post_type' => 'momo_offer', 'post_status' => [ 'draft', 'pending', 'publish', 'future' ], 'fields' => 'ids', 'posts_per_page' => 50 ] );
		set_current_screen( 'front' );
		return array_map( 'intval', $ids );
	}

	private function offer( string $title, string $status, string $scope, array $branches, string $start, string $end ): int {
		global $wpdb;
		$id = self::factory()->post->create( [ 'post_type' => 'momo_offer', 'post_status' => 'draft', 'post_title' => $title ] );
		if ( 'draft' !== $status ) {
			$wpdb->update( $wpdb->posts, [ 'post_status' => $status ], [ 'ID' => $id ] );
			clean_post_cache( $id );
		}
		update_post_meta( $id, 'momo_offer_scope', $scope );
		update_post_meta( $id, '_momo_offer_start_utc', $start );
		update_post_meta( $id, '_momo_offer_end_utc', $end );
		$terms = array_map( static fn( $b ) => \Momos\Branch\PostType\BranchScopeTaxonomy::term_for_branch( $b )->term_id, $branches );
		wp_set_object_terms( $id, $terms, \Momos\Branch\PostType\BranchScopeTaxonomy::TAX );
		return $id;
	}

	public function test_fr_corp_010_c_offer_list_filters_by_state_scope_and_branch(): void {
		$this->freeze( '2026-12-10 12:00:00' ); // 03:00 UTC.
		$a = $this->make_branch( 'QOA' );
		$b = $this->make_branch( 'QOB' );
		\Momos\Branch\PostType\BranchScopeTaxonomy::sync( $a );
		\Momos\Branch\PostType\BranchScopeTaxonomy::sync( $b );
		$draft     = $this->offer( 'Draft A', 'draft', 'branches', [ $a ], '2026-12-01 00:00:00', '2026-12-31 00:00:00' );
		$pending   = $this->offer( 'Pending B', 'pending', 'branches', [ $b ], '2026-12-01 00:00:00', '2026-12-31 00:00:00' );
		$scheduled = $this->offer( 'Scheduled all', 'publish', 'all_branches', [], '2026-12-20 00:00:00', '2026-12-31 00:00:00' );
		$active    = $this->offer( 'Active A', 'publish', 'branches', [ $a ], '2026-12-01 00:00:00', '2026-12-31 00:00:00' );
		$expired   = $this->offer( 'Expired B', 'publish', 'branches', [ $b ], '2026-11-01 00:00:00', '2026-12-01 00:00:00' );

		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		$this->assertEqualsCanonicalizing( [ $draft, $pending, $scheduled, $active, $expired ], $this->offer_list( [] ) );
		$expect = [ 'draft' => [ $draft ], 'pending' => [ $pending ], 'scheduled' => [ $scheduled ], 'active' => [ $active ], 'expired' => [ $expired ] ];
		foreach ( $expect as $state => $ids ) {
			$this->assertSame( $ids, $this->offer_list( [ ListScreens::F_STATE => $state ] ), "state {$state}" );
			foreach ( $ids as $id ) {
				$this->assertSame( $state, \Momos\Branch\Query\OfferQuery::state( $id ), 'filter = computed state column' );
			}
		}
		$this->assertSame( [ $scheduled ], $this->offer_list( [ ListScreens::F_SCOPE => 'all_branches' ] ) );
		$this->assertEqualsCanonicalizing( [ $draft, $pending, $active, $expired ], $this->offer_list( [ ListScreens::F_SCOPE => 'branches' ] ) );
		$this->assertEqualsCanonicalizing( [ $draft, $scheduled, $active ], $this->offer_list( [ ListScreens::F_BRANCH => (string) $a ] ), 'branch = scoped to it or all branches' );
		$this->assertSame( [ $active ], $this->offer_list( [ ListScreens::F_BRANCH => (string) $a, ListScreens::F_STATE => 'active', ListScreens::F_SCOPE => 'branches' ] ) );
		$this->assertCount( 5, $this->offer_list( [ ListScreens::F_STATE => 'bogus', ListScreens::F_SCOPE => '<x>' ] ), 'invalid values ignored' );

		// Controls: three labelled selects; the branch select lists every branch for HO.
		set_current_screen( 'edit-momo_offer' );
		$_GET = [ ListScreens::F_STATE => 'pending' ];
		ob_start();
		ListScreens::offer_filter_controls( 'momo_offer', 'top' );
		$html = (string) ob_get_clean();
		foreach ( [ ListScreens::F_STATE, ListScreens::F_SCOPE, ListScreens::F_BRANCH ] as $name ) {
			$this->assertStringContainsString( '<label class="screen-reader-text" for="' . $name . '">', $html );
			$this->assertStringContainsString( '<select name="' . $name . '" id="' . $name . '">', $html );
		}
		$this->assertMatchesRegularExpression( '/<option value="pending"\s+selected/', $html );
		$this->assertStringContainsString( '(QOA)', $html );
		$this->assertStringContainsString( '(QOB)', $html );
		ob_start();
		ListScreens::offer_filter_controls( 'momo_notice', 'top' );
		$this->assertSame( '', ob_get_clean(), 'offers list only' );

		// Branch-scoped user: only own branches selectable; another branch id is ignored; list stays scoped.
		$bm = $this->bm( $a );
		wp_set_current_user( $bm );
		ob_start();
		ListScreens::offer_filter_controls( 'momo_offer', 'top' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '(QOA)', $html );
		$this->assertStringNotContainsString( '(QOB)', $html );
		$this->assertSame( 0, ListScreens::offer_filter_values( [ ListScreens::F_BRANCH => (string) $b ] )['branch'] );
		$this->assertEqualsCanonicalizing( [ $draft, $active ], $this->offer_list( [ ListScreens::F_BRANCH => (string) $b ] ), 'foreign branch filter ignored; scope_lists still applies' );
		$this->assertSame( [ $active ], $this->offer_list( [ ListScreens::F_STATE => 'active' ] ) );
		$this->assertSame( [], $this->offer_list( [ ListScreens::F_STATE => 'pending' ] ), 'B pending offer is not visible to the BM of A' );

		// Branch scope column.
		ob_start();
		ListScreens::offer_cell( 'momo_scope', $scheduled );
		ListScreens::offer_cell( 'momo_scope', $active );
		$cells = (string) ob_get_clean();
		$this->assertStringContainsString( 'All branches', $cells );
		$this->assertStringContainsString( '支店QOA', $cells );
		$this->assertArrayHasKey( 'momo_scope', ListScreens::offer_columns( [ 'title' => 'Title' ] ) );
	}

	// ------------------------------------------------------------------ FR-ADM-002(e) / F-ADM-02

	public function test_fr_adm_002_e_assigned_staff_lists_active_users_only(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$b        = $this->make_branch( 'QSA' );
		$active   = $this->user_with_role( 'momo_reservation_staff', [ $b ] );
		$disabled = $this->user_with_role( 'momo_reservation_staff', [ $b ] );
		$expired  = $this->user_with_role( 'momo_reservation_staff', [ $b ] );
		$future   = $this->user_with_role( 'momo_reservation_staff', [ $b ] );
		$bm       = $this->bm( $b, 'Branch Boss' );
		wp_update_user( [ 'ID' => $active, 'display_name' => 'Active Staff' ] );
		wp_update_user( [ 'ID' => $disabled, 'display_name' => 'Disabled Staff' ] );
		wp_update_user( [ 'ID' => $expired, 'display_name' => 'Expired Vendor' ] );
		update_user_meta( $disabled, \Momos\Access\AccountStatus::META_DISABLED, '1' );
		update_user_meta( $expired, \Momos\Access\AccountStatus::META_EXPIRES, '2026-11-30 00:00:00' );
		update_user_meta( $future, \Momos\Access\AccountStatus::META_EXPIRES, '2027-01-31 00:00:00' );

		$this->assertSame( [ $active, $future, $bm ], ListScreens::assigned_staff( $b ) );

		// The BM's read-only panel on the branch editor.
		wp_set_current_user( $bm );
		global $wp_meta_boxes;
		$wp_meta_boxes = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- test isolation.
		ListScreens::staff_panel( get_post( $b ) );
		$box = $wp_meta_boxes['momo_branch']['side']['low']['momos-branch-staff'] ?? null;
		$this->assertNotNull( $box, 'BM of the branch sees the Assigned staff panel' );
		ob_start();
		call_user_func( $box['callback'], get_post( $b ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Active Staff', $html );
		$this->assertStringContainsString( 'Branch Boss', $html );
		$this->assertStringNotContainsString( 'Disabled Staff', $html );
		$this->assertStringNotContainsString( 'Expired Vendor', $html );
		$this->assertStringNotContainsString( '<input', $html, 'read-only list' );
		$this->assertStringNotContainsString( '<a ', $html, 'no edit links' );

		// BM of another branch: no panel.
		$other         = $this->make_branch( 'QSB' );
		$wp_meta_boxes = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		ListScreens::staff_panel( get_post( $other ) );
		$this->assertArrayNotHasKey( 'momo_branch', (array) $wp_meta_boxes );
	}

	// ------------------------------------------------------------------ FR-ADM-001 / AC-ADM-01-02

	/** @return list<string> */
	private function widget_ids( int $user ): array {
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		global $wp_meta_boxes;
		$wp_meta_boxes = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $user );
		set_current_screen( 'dashboard' );
		\Momos\Admin\Dashboard\Widgets::setup();
		ListScreens::dashboard();
		$out = [];
		foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? [] ) as $ctx ) {
			foreach ( $ctx as $prio ) {
				foreach ( (array) $prio as $id => $box ) {
					if ( is_array( $box ) ) {
						$out[ $id ] = $box['title'];
					}
				}
			}
		}
		set_current_screen( 'front' );
		return $out;
	}

	public function test_ac_adm_01_02_ho_widget_offers_awaiting_approval_links_to_filtered_list(): void {
		$this->freeze( '2026-12-10 12:00:00' );
		$a = $this->make_branch( 'QWA' );
		\Momos\Branch\PostType\BranchScopeTaxonomy::sync( $a );
		$p1 = $this->offer( 'P1', 'pending', 'branches', [ $a ], '2026-12-20 00:00:00', '2026-12-31 00:00:00' );
		$p2 = $this->offer( 'P2', 'pending', 'all_branches', [], '2026-12-20 00:00:00', '2026-12-31 00:00:00' );
		$this->offer( 'Live', 'publish', 'all_branches', [], '2026-12-01 00:00:00', '2026-12-31 00:00:00' );
		$ho = $this->user_with_role( 'momo_head_office' );
		update_user_meta( $ho, 'locale', 'en_US' );
		$widgets = $this->widget_ids( $ho );
		$this->assertArrayHasKey( 'momos_offers_pending', $widgets );
		$this->assertSame( 'Offers awaiting approval', $widgets['momos_offers_pending'] );

		wp_set_current_user( $ho );
		ob_start();
		ListScreens::offers_pending_widget();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '#<a href="[^"]*edit\.php\?post_type=momo_offer&(?:amp;|\#038;)momo_offer_state=pending">Offers awaiting approval: 2</a>#', $html );
		// The link target lists exactly the two pending offers (filter of item FR-CORP-010(c)).
		$this->assertEqualsCanonicalizing( [ $p1, $p2 ], $this->offer_list( [ 'post_type' => 'momo_offer', ListScreens::F_STATE => 'pending' ] ) );

		update_user_meta( $ho, 'locale', 'ja' );
		ob_start();
		ListScreens::offers_pending_widget();
		$this->assertStringContainsString( '承認待ちの特典：2件', (string) ob_get_clean() );
		$this->assertArrayNotHasKey( 'momos_offers_pending', $this->widget_ids( $this->user_with_role( 'momo_content_editor' ) ), 'HO/SA only' );
	}

	public function test_fr_adm_001_d_pages_awaiting_review_and_typed_translation_gaps(): void {
		$this->freeze( '2026-12-10 12:00:00' );
		global $wpdb;
		foreach ( [ 'pending', 'pending', 'draft' ] as $i => $st ) {
			$id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Page ' . $i ] );
			$wpdb->update( $wpdb->posts, [ 'post_status' => $st ], [ 'ID' => $id ] );
		}
		$page = $this->published_post( 'page', 'About', [ 'momo_lccs' => '1' ] );
		$this->published_post( 'momo_dish', 'Curry' );
		wp_cache_flush();
		\Momos\Kernel\I18n\Admin\TranslationGaps::flush();

		$ce = $this->user_with_role( 'momo_content_editor' );
		update_user_meta( $ce, 'locale', 'en_US' );
		$w = $this->widget_ids( $ce );
		$this->assertSame( 'Pages awaiting review', $w['momos_pages_pending'] ?? null, 'ROLE-04 pages awaiting review' );
		$this->assertSame( 'Page translation gaps', $w['momos_translation_gaps'] ?? null );
		wp_set_current_user( $ce );
		ob_start();
		\Momos\Admin\Dashboard\Widgets::pages_pending();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '#<a href="[^"]*edit\.php\?post_type=page&(?:amp;|\#038;)post_status=pending">Pages awaiting review: 2</a>#', $html );

		$this->assertSame( 'pages', \Momos\Admin\Dashboard\Widgets::gaps_mode() );
		ob_start();
		\Momos\Admin\Dashboard\Widgets::translation_gaps();
		$pages = (string) ob_get_clean();
		$en    = \Momos\Kernel\I18n\Admin\TranslationGaps::lccs_percent( 'en' );
		$this->assertStringContainsString( '<th scope="col">LCCS complete</th>', $pages );
		$this->assertMatchesRegularExpression( '#page=momos-translation-gaps&(?:amp;|\#038;)lang=en&(?:amp;|\#038;)type=page">1</a></td><td>' . $en . ' %</td>#', $pages, 'one untranslated page in en + the LCCS %' );

		Translations::save( 'post', $page, 'en', [ 'title' => 'About' ], 'approved' );
		\Momos\Kernel\I18n\Admin\TranslationGaps::flush();
		ob_start();
		\Momos\Admin\Dashboard\Widgets::translation_gaps();
		$this->assertStringNotContainsString( 'lang=en&amp;type=page"', (string) ob_get_clean(), 'approved page is no longer a gap in en' );

		$mm = $this->user_with_role( 'momo_menu_manager' );
		update_user_meta( $mm, 'locale', 'en_US' );
		$w = $this->widget_ids( $mm );
		$this->assertSame( 'Menu translation gaps', $w['momos_translation_gaps'] ?? null );
		$this->assertArrayNotHasKey( 'momos_pages_pending', $w );
		wp_set_current_user( $mm );
		$this->assertSame( 'menu', \Momos\Admin\Dashboard\Widgets::gaps_mode() );
		ob_start();
		\Momos\Admin\Dashboard\Widgets::translation_gaps();
		$menu = (string) ob_get_clean();
		$this->assertStringContainsString( 'type=dish">1</a>', str_replace( '&amp;', '&', $menu ), 'the untranslated dish' );
		$this->assertStringNotContainsString( 'type=page', $menu );

		$ho = $this->user_with_role( 'momo_head_office' );
		wp_set_current_user( $ho );
		$this->assertSame( 'all', \Momos\Admin\Dashboard\Widgets::gaps_mode() );
		ob_start();
		\Momos\Admin\Dashboard\Widgets::translation_gaps();
		$this->assertStringContainsString( ' %</td>', (string) ob_get_clean(), 'HO sees LCCS % per language' );
	}

	// ------------------------------------------------------------------ FR-BR-005 + AC-BR-03-07

	public function test_ac_br_03_07_bm_sees_reservation_settings_read_only_and_post_is_rejected(): void {
		$b = $this->make_branch( 'QRS', [ 'momo_res_party_max' => '12', 'momo_res_enabled' => '1' ] );
		$group = FieldRegistry::get_group( BranchFields::G6 );
		$this->assertNotNull( $group );
		$names = array_values( array_filter( array_column( $group['fields'], 'name' ), static fn( $n ) => str_starts_with( (string) $n, 'momo_res_' ) ) );
		$this->assertGreaterThan( 10, count( $names ) );

		foreach ( [ 'momo_branch_manager', 'momo_reservation_staff' ] as $role ) {
			wp_set_current_user( $this->user_with_role( $role, [ $b ] ) );
			ob_start();
			Renderer::group( $group, FieldRegistry::post_values( $b, $group ), $b, 'post', get_post( $b ) );
			$html = (string) ob_get_clean();
			$this->assertStringNotContainsString( 'name="momos_fields[momo_res_', $html, "{$role}: no editable reservation input" );
			$this->assertSame( count( $names ), substr_count( $html, 'class="momos-readonly"' ), "{$role}: every field read-only" );
		}
		wp_set_current_user( $this->user_with_role( 'momo_head_office' ) );
		ob_start();
		Renderer::group( $group, FieldRegistry::post_values( $b, $group ), $b, 'post', get_post( $b ) );
		$this->assertStringContainsString( 'name="momos_fields[momo_res_party_max]"', (string) ob_get_clean(), 'HO edits' );

		// Crafted POST as the BM through the real save path (valid nonce, branch assigned).
		$bm = $this->user_with_role( 'momo_branch_manager', [ $b ] );
		wp_set_current_user( $bm );
		$this->audits = [];
		$_POST        = [
			'momos_fields'                       => [ 'momo_res_party_max' => '40', 'momo_res_enabled' => '0' ],
			'_momos_rendered'                    => [ 'momo_res_party_max', 'momo_res_enabled' ],
			'_momos_nonce_' . BranchFields::G6   => wp_create_nonce( 'momos_fields_' . BranchFields::G6 ),
		];
		FieldRegistry::save_post( $b, get_post( $b ) );
		$_POST = [];
		$this->assertSame( '12', get_post_meta( $b, 'momo_res_party_max', true ) );
		$this->assertSame( '1', get_post_meta( $b, 'momo_res_enabled', true ) );
		$denied = array_values( array_filter( $this->audits, static fn( $a ) => 'access.denied' === $a[0] ) );
		$this->assertCount( 2, $denied );
		$this->assertEqualsCanonicalizing( [ 'momo_res_party_max', 'momo_res_enabled' ], array_map( static fn( $a ) => $a[4]['field'] ?? '', $denied ) );
		$this->assertNotContains( 'branch.settings_updated', array_column( $this->audits, 0 ) );
		global $wpdb;
		$this->assertSame( '2', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \Momos\Kernel\Db\Tables::audit() . ' WHERE action = %s AND actor_id = %d AND branch_id = %d', 'access.denied', $bm, $b ) ), 'denials stored in the audit log' ); // phpcs:ignore
	}

	// ------------------------------------------------------------------ AC-BR-03-06

	public function test_ac_br_03_06_bm_description_and_gallery_with_alt_purged_and_in_next_digest(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$b   = $this->make_branch( 'QDG' );
		$att = self::factory()->attachment->create_object( 'room.jpg', 0, [ 'post_mime_type' => 'image/jpeg', 'post_type' => 'attachment' ] );
		update_post_meta( $att, '_wp_attachment_image_alt', '店内の客席' );
		Translations::save( 'post', $att, 'en', [ 'alt' => 'Dining room with window seats' ], 'approved' );
		Translations::save( 'post', $att, 'ar', [ 'alt' => 'صالة الطعام' ], 'approved' );

		wp_set_current_user( $this->bm( $b, 'Sara BM' ) );
		$this->purges = [];
		wp_update_post( [ 'ID' => $b, 'post_content' => '新しい店舗紹介文。' ] );
		$this->assertSame( '新しい店舗紹介文。', get_post( $b )->post_content );
		$this->assertContains( 'momo-branch-' . $b, array_merge( ...$this->purges ), 'description change purges the branch tag' );

		$this->purges = [];
		FieldRegistry::save_group_values( 'post', $b, FieldRegistry::get_group( BranchFields::G4 ), [ 'momo_gallery' => [ (string) $att ] ], get_post( $b ) );
		$this->assertSame( [ $att ], array_map( 'intval', (array) get_post_meta( $b, 'momo_gallery', true ) ) );
		$this->assertContains( 'momo-branch-' . $b, array_merge( ...$this->purges ), 'gallery change purges the branch tag' );
		$this->assertSame( 'Dining room with window seats', \Momos\Presentation\View\MenuView::alt( $att, 'en' ) );
		$this->assertSame( 'صالة الطعام', \Momos\Presentation\View\MenuView::alt( $att, 'ar' ) );
		$this->assertSame( '店内の客席', \Momos\Presentation\View\MenuView::alt( $att, 'ja' ) );

		$lines = $this->digest_lines( Clock::utc()->modify( '+1 hour' ) );
		$this->assertSame(
			[
				'QDG · branch.content_updated · post_content · Sara BM',
				'QDG · branch.content_updated · momo_gallery: — → [' . $att . '] · Sara BM',
			],
			$lines
		);
		$this->assertSame( [], $this->digest_lines( Clock::utc()->modify( '+25 hours' ) ), 'only the next digest (24 h window)' );
	}

	// ------------------------------------------------------------------ AC-SEO-02-04

	public function test_ac_seo_02_04_hour_change_purges_branch_tag_and_json_ld_shows_new_hours(): void {
		$this->freeze( '2026-12-07 10:00:00' );
		global $wpdb;
		$b = $this->make_branch( 'QJS', [ 'momo_res_enabled' => '1' ], 'draft' );
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $b ] ); // Fixture: skip the GATE-01 publish checks.
		clean_post_cache( $b );
		$this->set_week( $b, [ 1 => [ [ '11:00', '15:00' ] ] ] );
		$this->assertTrue( \Momos\Branch\Repository\BranchRepository::is_public( $b, 'ja' ) );
		$before = JsonLd::restaurant( $b, 'ja' );
		$this->assertNotNull( $before );
		$this->assertSame( [ [ 'https://schema.org/Monday', '11:00', '15:00' ] ], array_map( static fn( $h ) => [ $h['dayOfWeek'], $h['opens'], $h['closes'] ], $before['openingHoursSpecification'] ) );

		CacheInvalidator::flush();
		$purged = [];
		$spy    = static function ( $tags ) use ( &$purged ): void {
			$purged = array_merge( $purged, (array) $tags );
		};
		add_action( 'momos/cache/purged', $spy );
		wp_set_current_user( $this->bm( $b ) );
		HoursRepository::replace_week(
			$b,
			null,
			null,
			[
				[ 'weekday' => 1, 'period_no' => 1, 'label' => 'lunch', 'opens_at' => '11:30', 'closes_at' => '16:00', 'last_order_at' => null ],
				[ 'weekday' => 2, 'period_no' => 1, 'label' => 'dinner', 'opens_at' => '17:00', 'closes_at' => '23:00', 'last_order_at' => null ],
			],
			AccessContext::current()
		);
		CacheInvalidator::flush();
		remove_action( 'momos/cache/purged', $spy );
		$this->assertContains( 'momos/branch/schedule_changed', array_column( $this->events, 0 ) );
		$this->assertContains( 'momo-branch-' . $b, $purged, 'schedule_changed purges the branch page tag' );
		$this->assertContains( 'momo-home', $purged );

		ScheduleService::flush();
		$after = JsonLd::restaurant( $b, 'ja' );
		$this->assertSame(
			[ [ 'https://schema.org/Monday', '11:30', '16:00' ], [ 'https://schema.org/Tuesday', '17:00', '23:00' ] ],
			array_map( static fn( $h ) => [ $h['dayOfWeek'], $h['opens'], $h['closes'] ], $after['openingHoursSpecification'] ),
			'regenerated JSON-LD shows the new hours'
		);
	}
}
