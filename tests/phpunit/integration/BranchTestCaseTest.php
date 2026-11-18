<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Branch\Repository\HoursRepository;
use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Tests\TestCase;

/**
 * Shared fixtures for the Branch module tests (fictional data, Doc 12 §1.5 style: QAA, QAB…).
 *
 * Kernel Transaction::run() issues COMMIT, which ends the WP test transaction; every test therefore
 * starts from clean branch data.
 */
abstract class BranchTestCaseTest extends TestCase {
	/** @var list<array{0:string,1:string,2:?int,3:?int,4:array<string,mixed>,5:string}> */
	protected array $audits = [];

	/** @var list<array<int,mixed>> */
	protected array $events = [];

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		_delete_all_data();
		foreach ( [ Tables::hours(), Tables::closures(), Tables::reservations(), Tables::events(), Tables::user_branches(), Tables::menu_items(), Tables::audit() ] as $t ) {
			$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
		}
		delete_option( \Momos\Branch\Query\OfferQuery::STATE_OPTION );
		wp_cache_flush();
		ScheduleService::flush();
		self::ensure_roles();
		wp_set_current_user( 0 );
		$this->audits = [];
		$this->events = [];
		add_action( 'momos/audit/log', function ( ...$args ): void {
			$this->audits[] = $args;
		}, 10, 6 );
		foreach ( [ 'momos/branch/schedule_changed', 'momos/branch/settings_changed', 'momos/branch/identity_changed', 'momos/branch/accepting_changed', 'momos/offer/visibility_changed' ] as $hook ) {
			add_action( $hook, function ( ...$args ) use ( $hook ): void {
				$this->events[] = array_merge( [ $hook ], $args );
			}, 10, 3 );
		}
	}

	public function tear_down(): void {
		$_POST = [];
		ScheduleService::clear_overlay();
		ScheduleService::flush();
		parent::tear_down();
	}

	/** Install the role table when the Access module is not present (tests only). */
	protected static function ensure_roles(): void {
		if ( get_role( 'momo_branch_manager' ) && get_role( 'momo_head_office' )->has_cap( 'momo_edit_branch_hours' ) ) {
			return;
		}
		$config = require MOMOS_CORE_DIR . '/config/capabilities.php';
		foreach ( $config['roles'] as $role => [ $en ] ) {
			remove_role( $role );
			add_role( $role, $en, [] );
		}
		foreach ( $config['caps'] as $cap => $roles ) {
			foreach ( $roles as $role ) {
				$r = get_role( $role );
				if ( $r ) {
					$r->add_cap( $cap );
				}
			}
		}
	}

	/** @param array<string,mixed> $meta */
	protected function make_branch( string $code, array $meta = [], string $status = 'publish' ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'momo_branch', 'post_status' => 'draft', 'post_title' => '支店' . $code, 'post_name' => strtolower( $code ) ] );
		$defaults = [
			'momo_branch_code'        => $code,
			'momo_operating_status'   => 'open',
			'momo_postal_code'        => '530-0001',
			'momo_prefecture'         => '大阪府',
			'momo_city'               => '大阪市北区',
			'momo_address_line1'      => '梅田1-1-1',
			'momo_address_ja'         => '〒530-0001 大阪府大阪市北区梅田1-1-1',
			'momo_lat'                => '34.702485',
			'momo_lng'                => '135.495951',
			'momo_phone_e164'         => '+81600000000',
			'momo_phone_display'      => '06-0000-0000',
			'momo_branch_email'       => strtolower( $code ) . '@momos.example',
			'momo_facts_confirmed_by' => 'Owner',
			'momo_facts_confirmed_at' => '2026-11-01',
		];
		foreach ( array_merge( $defaults, $meta ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		if ( 'publish' === $status ) {
			wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		}
		return $id;
	}

	/**
	 * @param array<int, list<array{0:string,1:string,2?:?string,3?:string}>> $week weekday => [[open, close, lo, label]]
	 */
	protected function set_week( int $branch_id, array $week, ?string $from = null, ?string $until = null ): void {
		$periods = [];
		foreach ( $week as $w => $list ) {
			foreach ( $list as $i => $p ) {
				$periods[] = [ 'weekday' => $w, 'period_no' => $i + 1, 'label' => $p[3] ?? 'all_day', 'opens_at' => $p[0], 'closes_at' => $p[1], 'last_order_at' => $p[2] ?? null ];
			}
		}
		HoursRepository::replace_week( $branch_id, $from, $until, $periods, AccessContext::system() );
	}

	/** Insert a reservation row (fictional, no real PII). */
	protected function make_reservation( int $branch_id, string $date, string $time, string $status = 'confirmed', int $party = 2 ): int {
		global $wpdb;
		$start = Clock::mysql_utc( Clock::jst_at( $date, $time ) );
		$wpdb->insert(
			Tables::reservations(),
			[
				'public_ref' => strtoupper( substr( wp_generate_password( 8, false ), 0, 8 ) ), 'idempotency_key' => wp_generate_uuid4(), 'branch_id' => $branch_id, 'status' => $status,
				'requested_date' => $date, 'requested_time' => $time . ':00', 'requested_start_utc' => $start, 'party_size' => $party, 'guest_name' => 'Test Guest',
				'email' => 'guest@example.org', 'preferred_lang' => 'en', 'source_lang' => 'en', 'consent_version' => 'v1', 'consent_at' => '2026-11-01 00:00:00',
				'token_hash' => str_repeat( 'a', 64 ), 'token_expires_utc' => $start, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
			]
		);
		return (int) $wpdb->insert_id;
	}

	/** @return list<string> audit actions recorded during the test */
	protected function audit_actions(): array {
		return array_map( static fn( $a ) => (string) $a[0], $this->audits );
	}

	/** @return list<array<int,mixed>> */
	protected function events_of( string $hook ): array {
		return array_values( array_filter( $this->events, static fn( $e ) => $e[0] === $hook ) );
	}
}
