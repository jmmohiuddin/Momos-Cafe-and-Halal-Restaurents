<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/ReservationTestCaseTest.php';

use Momos\Access\Roles\RoleInstaller;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Kernel\Db\Tables;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\TokenService;

/** Thrown from the `wp_redirect` filter so admin-post handlers that redirect + exit can be observed. */
final class QaIsolationRedirect extends \RuntimeException {}

/**
 * QA (isolation team) — branch isolation and object-level authorisation matrix (Doc 12 TC-ACL-001…012,
 * TC-ACL-021/022/024; access spec §4.2 M1–M12 and §4.3 E1–E14; reservation spec §3.6/§5.3).
 *
 * Fixtures (fictional): branches QIA (A), QIB (B) and the hidden TEST branch QIT; Branch Manager and
 * Reservation Staff of A and of B, Head Office, Menu Manager, Content Editor, Translator and a named
 * vendor ROLE-01 account (`momo_vendor = 1`, access window open, per-user PII denies applied).
 *
 * For every A-scoped actor and every foreign object (B and TEST) the tests call the real channel —
 * capability API, momos/v1 REST routes, admin-post handlers with a *valid nonce of the actor's own*
 * (TC-ACL-006: "a valid nonce never substitutes for capability + branch check"), repositories behind
 * list screens, dashboard widgets and heartbeat badges, export, notification fan-out and the customer
 * token API — and assert: denied (403 / not found / empty), no record fields in the response, no
 * database change, and an `access.denied` audit row where the spec requires one.
 *
 * Run: MOMOS_TEST_DB_NAME=<db>_test vendor/bin/phpunit --filter QaIsolationMatrixTest
 */
final class QaIsolationMatrixTest extends ReservationTestCaseTest {
	private int $a;
	private int $b;
	private int $t;
	/** @var array<string,int> actor key => user id */
	private array $u = [];
	/** @var array<string,int> reservation per branch key */
	private array $r = [];

	public function set_up(): void {
		parent::set_up();
		$this->freeze( '2026-11-20 10:00' );
		$this->a = $this->make_branch( 'QIA' );
		$this->b = $this->make_branch( 'QIB' );
		$this->t = $this->make_branch( 'QIT', [ 'momo_is_test_branch' => '1' ] );
		update_post_meta( $this->t, 'momo_res_notification_recipients', wp_json_encode( [ [ 'email' => 'qit-inbox@momos.example', 'language' => 'en', 'active' => true ] ] ) );

		$this->u = [
			'BM_A' => $this->staff( 'momo_branch_manager', [ $this->a ] ),
			'RS_A' => $this->staff( 'momo_reservation_staff', [ $this->a ] ),
			'BM_B' => $this->staff( 'momo_branch_manager', [ $this->b ] ),
			'RS_B' => $this->staff( 'momo_reservation_staff', [ $this->b ] ),
			'HO'   => $this->staff( 'momo_head_office' ),
			'MM'   => $this->staff( 'momo_menu_manager' ),
			'CE'   => $this->staff( 'momo_content_editor' ),
			'TR'   => $this->staff( 'momo_translator' ),
			'VEN'  => $this->staff( 'administrator' ),
		];
		update_user_meta( $this->u['VEN'], 'momo_vendor', '1' );
		update_user_meta( $this->u['VEN'], 'momo_access_expires_at', \Momos\Kernel\Clock\Clock::utc()->modify( '+4 hours' )->format( 'Y-m-d H:i:s' ) );
		RoleInstaller::apply_user_denies( $this->u['VEN'] );
		clean_user_cache( $this->u['VEN'] );

		$this->r = [
			'A' => $this->make_reservation( $this->a, '2026-11-25', '19:00', [ 'guest_name' => 'Guest Alpha', 'email' => 'alpha@example.org', 'phone_e164' => '+819011110001', 'special_request' => 'QA-SECRET-A' ] ),
			'B' => $this->make_reservation( $this->b, '2026-11-25', '19:00', [ 'guest_name' => 'Guest Bravo', 'email' => 'bravo@example.org', 'phone_e164' => '+819011110002', 'special_request' => 'QA-SECRET-B' ] ),
			'T' => $this->make_reservation( $this->t, '2026-11-25', '19:00', [ 'guest_name' => 'Guest Tango', 'email' => 'tango@example.org', 'phone_e164' => '+819011110003', 'special_request' => 'QA-SECRET-T' ] ),
		];
		$this->audits = [];
	}

	public function tear_down(): void {
		$_POST    = [];
		$_GET     = [];
		$_REQUEST = [];
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// ------------------------------------------------------------------ helpers

	/** Snapshot of a reservation row plus its events (to prove "0 DB changes"). */
	private function snapshot( int $id ): string {
		global $wpdb;
		$row    = $this->row( $id );
		$events = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::events() . ' WHERE reservation_id = %d', $id ) ); // phpcs:ignore
		$notifs = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::notifications() . ' WHERE reservation_id = %d', $id ) ); // phpcs:ignore
		return md5( (string) wp_json_encode( [ $row, $events, $notifs ] ) );
	}

	private function denied_rows(): int {
		return count( array_filter( $this->audits, static fn( $a ) => 'access.denied' === $a[0] ) );
	}

	/** Body of a REST response as JSON text (to look for leaked record fields). */
	private static function text( \WP_REST_Response $res ): string {
		return (string) wp_json_encode( $res->get_data() );
	}

	/**
	 * Call an admin-post handler like admin-post.php does and capture the outcome.
	 *
	 * @param array<string,mixed> $post
	 * @param array<string,mixed> $get
	 * @return array{status:int,body:string}
	 */
	private function admin_post( callable $handler, array $post, array $get = [] ): array {
		$_POST    = $post;
		$_GET     = $get;
		$_REQUEST = array_merge( $get, $post );
		$throw    = static function ( $location ) {
			throw new QaIsolationRedirect( (string) $location );
		};
		add_filter( 'wp_redirect', $throw, 0 );
		ob_start();
		try {
			$handler();
			$out = [ 'status' => 200, 'body' => (string) ob_get_contents() ];
		} catch ( \WPDieException $e ) {
			$out = [ 'status' => (int) ( $e->getCode() ?: 500 ), 'body' => $e->getMessage() ];
		} catch ( QaIsolationRedirect $e ) {
			$out = [ 'status' => 302, 'body' => $e->getMessage() ];
		} finally {
			ob_end_clean();
			remove_filter( 'wp_redirect', $throw, 0 );
			$_POST    = [];
			$_GET     = [];
			$_REQUEST = [];
		}
		return $out;
	}

	private function as( string $actor ): int {
		$id = $this->u[ $actor ];
		wp_set_current_user( $id );
		return $id;
	}

	private function offer_scoped_to( int $branch_id, string $status = 'publish', ?int $author = null ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'momo_offer', 'post_status' => $status, 'post_title' => 'QA offer ' . $branch_id, 'post_author' => $author ?? $this->u['HO'] ] );
		update_post_meta( $id, 'momo_offer_scope', 'branches' );
		$term = BranchScopeTaxonomy::term_for_branch( $branch_id );
		$this->assertNotNull( $term, 'branch scope term exists for every branch (BranchScopeSync)' );
		wp_set_object_terms( $id, [ (int) $term->term_id ], BranchScopeTaxonomy::TAX );
		return $id;
	}

	private function notice_scoped_to( int $branch_id, ?int $author = null ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'momo_notice', 'post_status' => 'publish', 'post_title' => 'QA notice ' . $branch_id, 'post_author' => $author ?? $this->u['HO'] ] );
		update_post_meta( $id, 'momo_notice_scope', 'branches' );
		$term = BranchScopeTaxonomy::term_for_branch( $branch_id );
		wp_set_object_terms( $id, [ (int) $term->term_id ], BranchScopeTaxonomy::TAX );
		return $id;
	}

	// ------------------------------------------------------------------ TC-ACL-001/002 capability level

	/** M1/M2/M8/M10/M12 for A-scoped actors against A (allowed) and B / TEST (denied). */
	public function test_meta_caps_branch_scoped_actors_cannot_touch_foreign_or_test_objects(): void {
		$dish = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_status' => 'publish', 'post_title' => 'QA dish' ] );
		$cert = [];
		foreach ( [ 'A' => $this->a, 'B' => $this->b, 'T' => $this->t ] as $k => $bid ) {
			$cert[ $k ] = self::factory()->post->create( [ 'post_type' => 'momo_certificate', 'post_status' => 'publish', 'post_title' => 'QA cert ' . $k ] );
			update_post_meta( $cert[ $k ], 'momo_cert_branches', [ $bid ] );
		}
		$offer  = [ 'A' => $this->offer_scoped_to( $this->a, 'draft' ), 'B' => $this->offer_scoped_to( $this->b, 'draft' ), 'T' => $this->offer_scoped_to( $this->t, 'draft' ) ];
		$notice = [ 'A' => $this->notice_scoped_to( $this->a ), 'B' => $this->notice_scoped_to( $this->b ), 'T' => $this->notice_scoped_to( $this->t ) ];
		$branch = [ 'A' => $this->a, 'B' => $this->b, 'T' => $this->t ];

		$problems = [];
		$check    = function ( string $actor, string $label, bool $expected, string $cap, ...$args ) use ( &$problems ): void {
			$got = user_can( $this->u[ $actor ], $cap, ...$args );
			if ( $got !== $expected ) {
				$problems[] = sprintf( '%s %s: expected %s, got %s', $actor, $label, $expected ? 'allow' : 'deny', $got ? 'allow' : 'deny' );
			}
		};
		foreach ( [ 'BM_A', 'RS_A' ] as $actor ) {
			$bm = 'BM_A' === $actor;
			foreach ( [ 'A', 'B', 'T' ] as $k ) {
				$own = 'A' === $k;
				$check( $actor, "momo_view_reservation($k)", $own, 'momo_view_reservation', $this->r[ $k ] );
				$check( $actor, "momo_process_reservation($k)", $own, 'momo_process_reservation', $this->r[ $k ] );
				$check( $actor, "edit_post(branch $k)", $own && $bm, 'edit_post', $branch[ $k ] );
				$check( $actor, "delete_post(branch $k)", false, 'delete_post', $branch[ $k ] );
				$check( $actor, "momo_edit_branch($k,hours)", $own && $bm, 'momo_edit_branch', $branch[ $k ], 'hours' );
				$check( $actor, "momo_edit_branch($k,settings)", false, 'momo_edit_branch', $branch[ $k ], 'settings' );
				$check( $actor, "momo_edit_branch_menu_item($k,availability)", $own, 'momo_edit_branch_menu_item', $branch[ $k ], $dish, [ 'availability' ] );
				$check( $actor, "momo_edit_branch_menu_item($k,price)", false, 'momo_edit_branch_menu_item', $branch[ $k ], $dish, [ 'price' ] );
				$check( $actor, "edit_post(notice $k)", $own && $bm, 'edit_post', $notice[ $k ] );
				$check( $actor, "delete_post(notice $k)", $own && $bm, 'delete_post', $notice[ $k ] );
				$check( $actor, "edit_post(draft offer $k)", $own && $bm, 'edit_post', $offer[ $k ] );
				$check( $actor, "read_post(certificate $k)", $own && $bm, 'read_post', $cert[ $k ] );
				$check( $actor, "momo_download_evidence(certificate $k)", false, 'momo_download_evidence', $cert[ $k ] );
			}
		}
		// Users of B are the mirror image (ordered pair B→A).
		$check( 'BM_B', 'momo_view_reservation(A)', false, 'momo_view_reservation', $this->r['A'] );
		$check( 'RS_B', 'momo_process_reservation(A)', false, 'momo_process_reservation', $this->r['A'] );
		$check( 'BM_B', 'edit_post(branch A)', false, 'edit_post', $this->a );
		// Non-reservation roles never reach reservations, even with all-branch scope (TRD §2.7.2).
		foreach ( [ 'MM', 'CE', 'TR' ] as $actor ) {
			$check( $actor, 'momo_view_reservation(A)', false, 'momo_view_reservation', $this->r['A'] );
			$check( $actor, 'momo_process_reservation(A)', false, 'momo_process_reservation', $this->r['A'] );
		}
		// Unknown reservation ID → do_not_allow (M8).
		$check( 'HO', 'momo_view_reservation(unknown)', false, 'momo_view_reservation', 999999 );
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	/** TC-ACL-022 step 1 / PRV-028 / SEC-008: a vendor account holds no reservation capability at all. */
	public function test_vendor_account_has_no_reservation_or_pii_capability(): void {
		$problems = [];
		foreach ( [ 'momo_view_reservations', 'momo_view_customer_pii', 'momo_export_reservations', 'momo_manage_dsr' ] as $cap ) {
			if ( user_can( $this->u['VEN'], $cap ) ) {
				$problems[] = "vendor holds $cap";
			}
		}
		// A vendor that may not *view* a reservation must not be able to *act* on it either: every action
		// response carries reservation fields (API-12/16 return the record; A09 redirects to it).
		if ( user_can( $this->u['VEN'], 'momo_process_reservation', $this->r['A'] ) ) {
			$problems[] = 'vendor passes momo_process_reservation(A) although momo_view_reservations is denied';
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	// ------------------------------------------------------------------ TC-ACL-005 REST API-10…16

	public function test_rest_detail_and_actions_on_foreign_and_test_reservations_are_403_without_data(): void {
		$problems = [];
		foreach ( [ 'BM_A', 'RS_A' ] as $actor ) {
			$this->as( $actor );
			foreach ( [ 'B', 'T' ] as $k ) {
				$id     = $this->r[ $k ];
				$before = $this->snapshot( $id );
				$denied = $this->denied_rows();
				$calls  = [
					'API-11 GET'         => $this->rest( 'GET', "/momos/v1/staff/reservations/$id" ),
					'API-12 accept'      => $this->rest( 'POST', "/momos/v1/staff/reservations/$id/transitions", [ 'action' => 'accept', 'version' => 1 ] ),
					'API-12 decline'     => $this->rest( 'POST', "/momos/v1/staff/reservations/$id/transitions", [ 'action' => 'decline', 'version' => 1, 'reason_code' => 'fully_booked' ] ),
					'API-13 note'        => $this->rest( 'POST', "/momos/v1/staff/reservations/$id/notes", [ 'type' => 'note', 'text' => 'x', 'version' => 1 ] ),
					'API-13 claim'       => $this->rest( 'POST', "/momos/v1/staff/reservations/$id/notes", [ 'type' => 'claim', 'version' => 1 ] ),
					'API-16 PATCH'       => $this->rest( 'PATCH', "/momos/v1/staff/reservations/$id", [ 'party_size' => 3, 'version' => 1, 'reason' => 'agreed_with_guest' ] ),
					'resend (proposal)'  => $this->rest( 'POST', "/momos/v1/staff/reservations/$id/notifications/1/resend", '{}' ),
				];
				foreach ( $calls as $name => $res ) {
					$body = self::text( $res );
					if ( 403 !== $res->get_status() && ! ( 'resend (proposal)' === $name && 404 === $res->get_status() ) ) {
						$problems[] = "$actor $name on $k: HTTP {$res->get_status()} $body";
					}
					foreach ( [ 'Guest ', '@example.org', '+8190', 'QA-SECRET' ] as $needle ) {
						if ( str_contains( $body, $needle ) ) {
							$problems[] = "$actor $name on $k leaked '$needle'";
						}
					}
				}
				if ( $this->snapshot( $id ) !== $before ) {
					$problems[] = "$actor changed reservation $k through the REST API";
				}
				if ( $this->denied_rows() < $denied + 6 ) {
					$problems[] = "$actor on $k: expected an access.denied audit row per refused call, got " . ( $this->denied_rows() - $denied );
				}
			}
			// Own branch still works (control).
			$own = $this->rest( 'GET', '/momos/v1/staff/reservations/' . $this->r['A'] );
			if ( 200 !== $own->get_status() ) {
				$problems[] = "$actor cannot read own reservation (control): {$own->get_status()}";
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	public function test_rest_list_filters_counts_and_search_are_scoped(): void {
		$problems = [];
		$ref_b    = (string) $this->row( $this->r['B'] )['public_ref'];
		$ref_t    = (string) $this->row( $this->r['T'] )['public_ref'];
		foreach ( [ 'BM_A', 'RS_A' ] as $actor ) {
			$this->as( $actor );
			$all = $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'status' => 'pending,confirmed,rejected,cancelled,expired,completed,no_show', 'per_page' => 50 ] );
			$ids = array_map( static fn( $r ) => (int) $r['id'], (array) $all->get_data() );
			if ( [ $this->r['A'] ] !== $ids ) {
				$problems[] = "$actor API-10 rows: " . wp_json_encode( $ids );
			}
			if ( '1' !== (string) ( $all->get_headers()['X-WP-Total'] ?? '' ) ) {
				$problems[] = "$actor X-WP-Total = " . ( $all->get_headers()['X-WP-Total'] ?? 'missing' );
			}
			$counts = json_decode( (string) ( $all->get_headers()['X-Momo-Status-Counts'] ?? '{}' ), true );
			if ( 1 !== array_sum( array_map( 'intval', (array) $counts ) ) ) {
				$problems[] = "$actor X-Momo-Status-Counts includes foreign rows: " . wp_json_encode( $counts );
			}
			foreach ( [ 'QIB', 'QIT', 'QIA,QIB', 'qib' ] as $filter ) {
				$res = $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'branch' => $filter ] );
				if ( 403 !== $res->get_status() || 'forbidden_branch' !== ( $res->get_data()['code'] ?? '' ) ) {
					$problems[] = "$actor API-10 branch=$filter → {$res->get_status()} " . self::text( $res );
				}
			}
			foreach ( [ $ref_b, $ref_t, substr( $ref_b, 0, 4 ) . '-' . substr( $ref_b, 4 ), 'Guest Bravo', 'Bravo' ] as $q ) {
				$res = $this->rest( 'GET', '/momos/v1/staff/reservations', null, [], [ 'q' => $q, 'status' => 'pending' ] );
				if ( [] !== (array) $res->get_data() ) {
					$problems[] = "$actor search '$q' found foreign rows: " . self::text( $res );
				}
			}
			// Scoped repository behind SCR-A08 views, the heartbeat badge and the dashboard widget.
			$scope = Scope::for_user( $this->u[ $actor ] );
			$c     = ReservationRepository::counts_by_status( $scope );
			if ( 1 !== (int) ( $c['pending'] ?? 0 ) ) {
				$problems[] = "$actor counts_by_status pending = " . (int) ( $c['pending'] ?? 0 );
			}
			$badge = \Momos\Reservation\Admin\InboxPage::heartbeat( [], [ 'momos_res_badge' => 1 ] );
			if ( 1 !== (int) ( $badge['momos_res_badge']['pending'] ?? -1 ) ) {
				$problems[] = "$actor heartbeat badge = " . wp_json_encode( $badge );
			}
			ob_start();
			\Momos\Admin\Dashboard\Widgets::pending();
			$html = (string) ob_get_clean();
			if ( ! preg_match( '/>\s*1\s*<\/strong>/', $html ) ) {
				$problems[] = "$actor dashboard pending widget: " . trim( wp_strip_all_tags( $html ) );
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	/** TC-ACL-022 step 1: every reservation REST call of a vendor is refused with no reservation fields. */
	public function test_vendor_rest_calls_are_refused_and_change_nothing(): void {
		$this->as( 'VEN' );
		$id       = $this->r['A'];
		$before   = $this->snapshot( $id );
		$problems = [];
		$calls    = [
			'API-10'       => $this->rest( 'GET', '/momos/v1/staff/reservations' ),
			'API-11'       => $this->rest( 'GET', "/momos/v1/staff/reservations/$id" ),
			'API-13 note'  => $this->rest( 'POST', "/momos/v1/staff/reservations/$id/notes", [ 'type' => 'note', 'text' => 'vendor', 'version' => 1 ] ),
			'API-16 PATCH' => $this->rest( 'PATCH', "/momos/v1/staff/reservations/$id", [ 'party_size' => 3, 'version' => 1, 'reason' => 'agreed_with_guest' ] ),
			'API-12'       => $this->rest( 'POST', "/momos/v1/staff/reservations/$id/transitions", [ 'action' => 'accept', 'version' => 1 ] ),
		];
		foreach ( $calls as $name => $res ) {
			$body = self::text( $res );
			if ( 403 !== $res->get_status() ) {
				$problems[] = "vendor $name: HTTP {$res->get_status()} " . substr( $body, 0, 300 );
			}
			foreach ( [ 'Guest Alpha', 'alpha@example.org', 'QA-SECRET' ] as $needle ) {
				if ( str_contains( $body, $needle ) ) {
					$problems[] = "vendor $name leaked '$needle'";
				}
			}
		}
		if ( $this->snapshot( $id ) !== $before ) {
			$problems[] = 'vendor changed reservation A (row/events/notifications differ)';
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	// ------------------------------------------------------------------ TC-ACL-006 admin-post with valid nonces

	public function test_reservation_admin_post_actions_with_valid_nonce_are_refused_for_foreign_branch_and_vendor(): void {
		$problems = [];
		$ops      = [
			'accept'  => [],
			'decline' => [ 'reason_code' => 'fully_booked' ],
			'cancel'  => [ 'reason_code' => 'guest_request' ],
			'claim'   => [],
			'note'    => [ 'text' => 'x' ],
			'modify'  => [ 'requested_date' => '2026-11-25', 'requested_time' => '19:00', 'party_size' => '3' ],
		];
		$cases    = [ [ 'BM_A', 'B' ], [ 'RS_A', 'B' ], [ 'RS_A', 'T' ], [ 'BM_B', 'A' ], [ 'VEN', 'A' ] ];
		foreach ( $cases as [ $actor, $k ] ) {
			$this->as( $actor );
			$id     = $this->r[ $k ];
			$before = $this->snapshot( $id );
			foreach ( $ops as $op => $extra ) {
				$out = $this->admin_post(
					[ \Momos\Reservation\Admin\InboxPage::class, 'handle' ],
					array_merge( [ 'action' => 'momos_reservation_action', 'id' => (string) $id, 'op' => $op, 'version' => '1', '_wpnonce' => wp_create_nonce( 'momos_res_action_' . $id ) ], $extra )
				);
				if ( 403 !== $out['status'] ) {
					$problems[] = "$actor A09 op=$op on $k: {$out['status']} {$out['body']}";
				}
			}
			if ( $this->snapshot( $id ) !== $before ) {
				$problems[] = "$actor changed reservation $k through admin-post";
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	public function test_hours_and_closure_handlers_with_valid_nonce_are_refused_for_foreign_branch(): void {
		global $wpdb;
		$problems = [];
		$state    = static fn() => md5( (string) wp_json_encode( [ $wpdb->get_results( 'SELECT * FROM ' . Tables::hours() . ' ORDER BY id' ), $wpdb->get_results( 'SELECT * FROM ' . Tables::closures() . ' ORDER BY id' ) ] ) ); // phpcs:ignore
		$week     = [];
		for ( $w = 1; $w <= 7; $w++ ) {
			$week[ $w ] = [ [ 'opens_at' => '09:00', 'closes_at' => '10:00', 'last_order_at' => '09:30', 'label' => 'all_day' ] ];
		}
		$posts = [
			'save_hours'     => [ 'set' => 'default', 'periods' => $week, 'confirm' => '1' ],
			'save_closure'   => [ 'date_from' => '2026-12-01', 'date_to' => '2026-12-01', 'type' => 'closed_all_day', 'public_reason' => 'other', 'confirm' => '1', 'replace_existing' => '1' ],
			'delete_closure' => [ 'date' => '2026-12-01' ],
			'delete_set'     => [ 'set' => '2026-12-01_open' ],
		];
		foreach ( [ [ 'BM_A', $this->b ], [ 'BM_A', $this->t ], [ 'RS_A', $this->b ], [ 'RS_A', $this->a ], [ 'BM_B', $this->a ] ] as [ $actor, $target ] ) {
			$this->as( $actor );
			$before = $state();
			$denied = $this->denied_rows();
			foreach ( $posts as $method => $post ) {
				$out = $this->admin_post(
					[ \Momos\Branch\Admin\HoursFormHandler::class, $method ],
					array_merge( [ 'action' => 'momos_' . $method, 'branch_id' => (string) $target, '_momos_nonce' => wp_create_nonce( 'momos_hours_' . $target ) ], $post )
				);
				if ( 403 !== $out['status'] ) {
					$problems[] = "$actor $method on branch $target: {$out['status']} {$out['body']}";
				}
			}
			if ( $state() !== $before ) {
				$problems[] = "$actor changed hours/closures of branch $target";
			}
			if ( $this->denied_rows() < $denied + count( $posts ) ) {
				$problems[] = "$actor on branch $target: missing access.denied rows";
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	/** SCR-A07 admin-post (E5: nonce + meta cap → 403) and API-14/15 for the foreign branch. */
	public function test_branch_menu_item_updates_for_foreign_branch_are_403_and_change_nothing(): void {
		global $wpdb;
		$dish = self::factory()->post->create( [ 'post_type' => 'momo_dish', 'post_status' => 'publish', 'post_title' => 'QA dish' ] );
		update_post_meta( $dish, 'momo_dish_sku', 'D9901' );
		update_post_meta( $dish, 'momo_reference_price', 1000 );
		foreach ( [ $this->a, $this->b, $this->t ] as $bid ) {
			$wpdb->insert( Tables::menu_items(), [ 'branch_id' => $bid, 'dish_id' => $dish, 'is_offered' => 1, 'availability' => 'available', 'version' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] ); // phpcs:ignore
		}
		$state    = static fn() => md5( (string) wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Tables::menu_items() . ' ORDER BY branch_id' ) ) ); // phpcs:ignore
		$problems = [];
		foreach ( [ [ 'BM_A', $this->b, 'QIB' ], [ 'RS_A', $this->b, 'QIB' ], [ 'RS_A', $this->t, 'QIT' ], [ 'BM_B', $this->a, 'QIA' ] ] as [ $actor, $target, $code ] ) {
			$this->as( $actor );
			$before = $state();
			$out    = $this->admin_post(
				[ \Momos\Menu\Admin\BranchMenuGrid::class, 'save' ],
				[ 'action' => 'momos_branch_menu_save', 'branch_id' => (string) $target, 'dish_id' => (string) $dish, 'quick' => 'sold_out_today', 'version' => '1', '_wpnonce' => wp_create_nonce( 'momos_bmg_' . $target . '_' . $dish ) ]
			);
			if ( 403 !== $out['status'] ) {
				$problems[] = "$actor SCR-A07 admin-post on $code: expected 403 (access spec §4.3 E5), got {$out['status']} {$out['body']}";
			}
			$get   = $this->rest( 'GET', "/momos/v1/staff/branches/$code/menu-items" );
			$patch = $this->rest( 'PATCH', "/momos/v1/staff/branches/$code/menu-items/$dish", [ 'availability' => 'sold_out', 'availability_reason' => 'sold_out_today', 'version' => 1 ] );
			foreach ( [ 'API-14 GET' => $get, 'API-15 PATCH' => $patch ] as $n => $res ) {
				if ( 403 !== $res->get_status() ) {
					$problems[] = "$actor $n $code: {$res->get_status()} " . substr( self::text( $res ), 0, 200 );
				}
			}
			if ( $state() !== $before ) {
				$problems[] = "$actor changed the menu item of $code";
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	/** Resend (A09 panel admin-post + REST proposal) of a foreign branch's failed customer email. */
	public function test_resend_of_foreign_notification_is_refused(): void {
		global $wpdb;
		$wpdb->insert(
			Tables::notifications(),
			[ 'uuid' => wp_generate_uuid4(), 'channel' => 'email', 'template_key' => 'E01', 'recipient_type' => 'customer', 'recipient_email' => 'bravo@example.org', 'recipient_hash' => str_repeat( 'b', 64 ), 'recipient_masked' => 'b***@e***.org', 'lang' => 'en', 'status' => 'failed', 'attempts' => 3, 'reservation_id' => $this->r['B'], 'branch_id' => $this->b, 'dedupe_key' => 'qa-iso-e01-b', 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ]
		); // phpcs:ignore
		$nid = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $nid, 'fixture notification inserted: ' . $wpdb->last_error );
		$count    = static fn() => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::notifications() ); // phpcs:ignore
		$problems = [];
		foreach ( [ 'BM_A', 'RS_A', 'VEN' ] as $actor ) {
			$this->as( $actor );
			$before = $count();
			$rest   = $this->rest( 'POST', '/momos/v1/staff/reservations/' . $this->r['B'] . "/notifications/$nid/resend", '{}' );
			if ( 403 !== $rest->get_status() ) {
				$problems[] = "$actor REST resend: {$rest->get_status()} " . self::text( $rest );
			}
			$cross = $this->rest( 'POST', '/momos/v1/staff/reservations/' . $this->r['A'] . "/notifications/$nid/resend", '{}' );
			if ( ! in_array( $cross->get_status(), [ 403, 404 ], true ) ) {
				$problems[] = "$actor REST resend via own reservation id: {$cross->get_status()}";
			}
			$out = $this->admin_post(
				[ \Momos\Notification\Admin\NotificationPanel::class, 'handle_resend' ],
				[ 'action' => 'momos_notification_resend_detail', 'notification_id' => (string) $nid, 'reservation_id' => (string) $this->r['B'], '_momos_nonce' => wp_create_nonce( 'momos_notification_resend_' . $nid ) ]
			);
			if ( $count() !== $before ) {
				$problems[] = "$actor queued a resend of branch B's email (admin-post outcome {$out['status']})";
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	/** TC-ACL-021: offers scoped to another branch cannot be duplicated, edited or re-scoped by BM A. */
	public function test_offer_duplicate_and_scope_change_for_foreign_branch_refused(): void {
		$offer_b  = $this->offer_scoped_to( $this->b, 'publish' );
		$problems = [];
		$this->as( 'BM_A' );
		$before = (int) wp_count_posts( 'momo_offer' )->draft;
		$out    = $this->admin_post( [ \Momos\Branch\Service\OfferService::class, 'handle_duplicate' ], [], [ 'action' => 'momos_duplicate_offer', 'offer' => (string) $offer_b, '_wpnonce' => wp_create_nonce( 'momos_duplicate_offer_' . $offer_b ) ] );
		wp_cache_flush();
		if ( 403 !== $out['status'] ) {
			$problems[] = "BM_A duplicate B offer: {$out['status']} {$out['body']}";
		}
		if ( (int) wp_count_posts( 'momo_offer' )->draft !== $before ) {
			$problems[] = 'BM_A created a copy of the B offer';
		}
		// Re-scope an own draft to B through the guarded field layer (momos_fields) → 403.
		$own   = $this->offer_scoped_to( $this->a, 'draft', $this->u['BM_A'] );
		$err   = \Momos\Branch\Service\OfferScopeGuard::error_for_submission( 'momo_offer', $own, [ 'momo_offer_scope' => 'branches', 'momo_offer_branches' => [ BranchScopeTaxonomy::term_for_branch( $this->b )->term_id ] ], $this->u['BM_A'] );
		$err_g = \Momos\Branch\Service\OfferScopeGuard::error_for_submission( 'momo_offer', $own, [ 'momo_offer_scope' => 'all_branches' ], $this->u['BM_A'] );
		if ( null === $err || null === $err_g ) {
			$problems[] = 'OfferScopeGuard allows BM_A to scope an offer to B / all branches';
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	/**
	 * M3 through the *core* save path: BM A edits its own notice and sends `tax_input[momo_branch_scope]`
	 * with B's scope term (no `momos_fields` in the request). Core edit_post() assigns the terms because
	 * BM holds `assign_terms` (= edit_momo_notices); the notice must still not end up scoped to B.
	 */
	public function test_core_tax_input_cannot_rescope_own_notice_or_offer_to_foreign_branch(): void {
		$this->as( 'BM_A' );
		$problems = [];
		$term_b   = BranchScopeTaxonomy::term_for_branch( $this->b );
		foreach ( [ 'momo_notice' => $this->notice_scoped_to( $this->a, $this->u['BM_A'] ), 'momo_offer' => $this->offer_scoped_to( $this->a, 'draft', $this->u['BM_A'] ) ] as $type => $id ) {
			$_POST = [
				'post_ID'     => $id,
				'post_type'   => $type,
				'post_title'  => 'QA rescope',
				'post_status' => get_post_status( $id ),
				'tax_input'   => [ BranchScopeTaxonomy::TAX => $term_b->name ],
			];
			try {
				edit_post( $_POST );
			} catch ( \WPDieException $e ) {
				// Refusal is the expected outcome.
				unset( $e );
			}
			$_POST = [];
			clean_post_cache( $id );
			$branches = BranchScopeTaxonomy::branch_ids_of_post( $id );
			if ( in_array( $this->b, $branches, true ) ) {
				$problems[] = "$type $id of BM_A is now scoped to branch B via core tax_input: " . wp_json_encode( $branches );
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	// ------------------------------------------------------------------ TC-ACL-007 export

	public function test_export_is_head_office_only_and_filters_stay_in_scope(): void {
		$problems = [];
		$in       = [ 'reason' => 'QA audit', 'date_from' => '2026-11-01', 'date_to' => '2026-12-31' ];
		foreach ( [ 'BM_A', 'RS_A', 'VEN' ] as $actor ) {
			$this->as( $actor );
			$out = $this->admin_post( [ \Momos\Reservation\Admin\ExportHandler::class, 'handle' ], array_merge( $in, [ 'action' => 'momos_export', '_wpnonce' => wp_create_nonce( 'momos_export' ), 'branch' => [ 'QIA' ] ] ) );
			if ( 403 !== $out['status'] ) {
				$problems[] = "$actor export: {$out['status']}";
			}
		}
		$this->as( 'HO' );
		$parsed = \Momos\Reservation\Admin\ExportHandler::parse( array_merge( $in, [ 'branch' => [ 'QIB' ] ] ), Scope::for_user( $this->u['HO'] ) );
		$this->assertIsArray( $parsed );
		global $wpdb;
		$where = ReservationRepository::scoped_where( Scope::for_user( $this->u['HO'] ), $parsed['filters'] );
		$ids   = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . Tables::reservations() . " WHERE {$where}" ) ); // phpcs:ignore
		if ( [ $this->r['B'] ] !== $ids ) {
			$problems[] = 'HO export filtered to QIB returned ' . wp_json_encode( $ids );
		}
		$parsed = \Momos\Reservation\Admin\ExportHandler::parse( $in, Scope::for_user( $this->u['HO'] ) );
		$where  = ReservationRepository::scoped_where( Scope::for_user( $this->u['HO'] ), $parsed['filters'] );
		$ids    = array_map( 'intval', (array) $wpdb->get_col( 'SELECT id FROM ' . Tables::reservations() . " WHERE {$where}" ) ); // phpcs:ignore
		if ( in_array( $this->r['T'], $ids, true ) ) {
			$problems[] = 'default HO export contains the TEST branch row (FR-RES-030)';
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	// ------------------------------------------------------------------ TC-ACL-008 notifications

	public function test_staff_notifications_go_only_to_the_reservation_branch_recipients(): void {
		$res = $this->submit( $this->body( 'QIB', '2026-11-27', '19:00', [ 'email' => 'iso-guest@example.org' ] ) );
		$this->assertContains( $res->get_status(), [ 200, 201 ], self::text( $res ) );
		$id = (int) ReservationRepository::find_by_ref( (string) $res->get_data()['reference'] )['id'];
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT recipient_type, recipient_email, branch_id FROM ' . Tables::notifications() . ' WHERE reservation_id = %d', $id ), ARRAY_A ); // phpcs:ignore
		$staff = array_values( array_filter( $rows, static fn( $r ) => 'customer' !== $r['recipient_type'] ) );
		$this->assertNotEmpty( $staff, 'E07 rows created for the branch' );
		foreach ( $staff as $r ) {
			$this->assertSame( 'qib-inbox@momos.example', $r['recipient_email'], 'only branch B recipients get branch B mail' );
			$this->assertSame( $this->b, (int) $r['branch_id'] );
		}
	}

	/** TC-ACL-008 step 2: each branch digest (E10) lists only its own branch's items. */
	public function test_branch_digest_sections_contain_only_own_branch_items(): void {
		global $wpdb;
		foreach ( [ 'A', 'B', 'T' ] as $k ) {
			$wpdb->update( Tables::reservations(), [ 'needs_action_flag' => 1, 'customer_notified_flag' => 0 ], [ 'id' => $this->r[ $k ] ] ); // phpcs:ignore
		}
		$refs = [];
		foreach ( [ 'A', 'B', 'T' ] as $k ) {
			$ref        = (string) $this->row( $this->r[ $k ] )['public_ref'];
			$refs[ $k ] = [ $ref, \Momos\Kernel\Support\Crockford::display( $ref ) ];
		}
		$run      = \Momos\Kernel\Clock\Clock::jst();
		$problems = [];
		foreach ( [ 'A' => $this->a, 'B' => $this->b ] as $k => $bid ) {
			$text = (string) wp_json_encode( \Momos\Notification\Job\DigestJob::sections( $bid, $run ) );
			if ( ! str_contains( $text, $refs[ $k ][1] ) && ! str_contains( $text, $refs[ $k ][0] ) ) {
				$problems[] = "digest of $k does not list its own needs-action request (fixture check)";
			}
			foreach ( $refs as $other => $pair ) {
				if ( $other !== $k && ( str_contains( $text, $pair[0] ) || str_contains( $text, $pair[1] ) ) ) {
					$problems[] = "digest of branch $k lists reservation of $other";
				}
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	// ------------------------------------------------------------------ customer token (API-03/04)

	public function test_status_token_only_opens_its_own_reservation_and_is_header_only(): void {
		$row_a   = $this->row( $this->r['A'] );
		$row_b   = $this->row( $this->r['B'] );
		$token_a = TokenService::token_for( $row_a );
		global $wpdb;
		$wpdb->update( Tables::reservations(), [ 'token_hash' => TokenService::hash( $token_a ), 'token_key_version' => 1 ], [ 'id' => $this->r['A'] ] ); // phpcs:ignore
		$ok = $this->rest( 'GET', '/momos/v1/reservations/' . $row_a['public_ref'], null, [ 'X-Momo-Token' => $token_a ] );
		$this->assertSame( 200, $ok->get_status(), 'control: token A opens A' );

		$problems = [];
		$cross    = $this->rest( 'GET', '/momos/v1/reservations/' . $row_b['public_ref'], null, [ 'X-Momo-Token' => $token_a ] );
		$unknown  = $this->rest( 'GET', '/momos/v1/reservations/ZZZZZZZZ', null, [ 'X-Momo-Token' => $token_a ] );
		if ( 404 !== $cross->get_status() || str_contains( self::text( $cross ), 'Bravo' ) ) {
			$problems[] = 'token A on ref B: ' . $cross->get_status() . ' ' . self::text( $cross );
		}
		$strip = static fn( \WP_REST_Response $r ) => preg_replace( '/"request_id":"[^"]+"/', '', self::text( $r ) );
		if ( $strip( $cross ) !== $strip( $unknown ) ) {
			$problems[] = 'wrong token on a real ref differs from an unknown ref: ' . $strip( $cross ) . ' vs ' . $strip( $unknown );
		}
		$before = $this->snapshot( $this->r['B'] );
		$cancel = $this->rest( 'POST', '/momos/v1/reservations/' . $row_b['public_ref'] . '/cancel', [ 'lang' => 'en' ], [ 'X-Momo-Token' => $token_a ] );
		if ( 404 !== $cancel->get_status() || $this->snapshot( $this->r['B'] ) !== $before ) {
			$problems[] = 'token A cancels B: ' . $cancel->get_status();
		}
		// The token is never accepted outside the header (reservation spec §3.6 step 1).
		$q = $this->rest( 'GET', '/momos/v1/reservations/' . $row_a['public_ref'], null, [], [ 't' => $token_a ] );
		if ( 400 !== $q->get_status() ) {
			$problems[] = 'token in query string: ' . $q->get_status();
		}
		$qh = $this->rest( 'GET', '/momos/v1/reservations/' . $row_a['public_ref'], null, [ 'X-Momo-Token' => $token_a ], [ 't' => $token_a ] );
		if ( 400 !== $qh->get_status() ) {
			$problems[] = 'token in query string + header: ' . $qh->get_status();
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}

	/**
	 * Enumeration oracle: once the status_ip bucket is exhausted every further request is answered with
	 * 429. That answer must not depend on whether the reference exists (nor reveal its branch).
	 */
	public function test_rate_limited_status_answer_does_not_reveal_whether_a_reference_exists(): void {
		$ref_b = (string) $this->row( $this->r['B'] )['public_ref'];
		update_post_meta( $this->b, 'momo_phone_display', '06-9999-0002' );
		\Momos\Reservation\Service\Presenter::flush();
		$token = str_repeat( 'A', 43 );
		for ( $i = 0; $i < 40; $i++ ) {
			$last = $this->rest( 'GET', '/momos/v1/reservations/ZZZZZZZ' . ( $i % 10 ), null, [ 'X-Momo-Token' => $token ] );
			if ( 429 === $last->get_status() ) {
				break;
			}
		}
		$this->assertSame( 429, $last->get_status(), 'status_ip limit reached within 40 calls' );
		$real    = $this->rest( 'GET', "/momos/v1/reservations/$ref_b", null, [ 'X-Momo-Token' => $token ], [ 'lang' => 'en' ] );
		$unknown = $this->rest( 'GET', '/momos/v1/reservations/YYYYYYYY', null, [ 'X-Momo-Token' => $token ], [ 'lang' => 'en' ] );
		$strip   = static fn( \WP_REST_Response $r ) => preg_replace( '/"(request_id|retry_after)":"?[^",}]+"?/', '', self::text( $r ) );
		$this->assertStringNotContainsString( '06-9999-0002', self::text( $real ), '429 for an existing reference reveals its branch phone' );
		$this->assertSame( $strip( $unknown ), $strip( $real ), 'API-03 answer differs between an existing and an unknown reference' );
	}

	// ------------------------------------------------------------------ TEST branch (FR-RES-026)

	/**
	 * API-01/02 and the core momo_branch route for the hidden TEST branch. The core search and oEmbed
	 * channels only leak under a real REST request (REST_REQUEST), so they are covered over HTTP in
	 * tests/e2e/specs/qa-isolation-channels.spec.ts.
	 */
	public function test_test_branch_is_not_reachable_by_anonymous_or_unassigned_users(): void {
		$problems = [];
		foreach ( [ 0, $this->u['BM_A'], $this->u['RS_A'] ] as $uid ) {
			wp_set_current_user( $uid );
			$rules = $this->rest( 'GET', '/momos/v1/branches/QIT/reservation-rules' );
			if ( 404 !== $rules->get_status() ) {
				$problems[] = "user $uid API-01 QIT: {$rules->get_status()}";
			}
			$sub = $this->submit( $this->body( 'QIT', '2026-11-27', '19:00', [ 'email' => 'iso-test-' . $uid . '@example.org' ] ) );
			if ( 404 !== $sub->get_status() ) {
				$problems[] = "user $uid API-02 QIT: {$sub->get_status()} " . self::text( $sub );
			}
			$core = $this->rest( 'GET', '/wp/v2/momo_branch/' . $this->t );
			if ( 200 === $core->get_status() ) {
				$problems[] = "user $uid wp/v2/momo_branch/{test}: 200";
			}
		}
		$this->assertSame( [], $problems, implode( "\n", $problems ) );
	}
}
