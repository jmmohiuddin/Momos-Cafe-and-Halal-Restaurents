<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Repository\HoursRepository;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Reservation\Service\Presenter;
use Momos\Tests\TestCase;

/**
 * Shared fixtures for the Reservation/API tests (fictional data: branches QRA/QRB/QRT, guests at
 * example.org). Transaction::run() COMMITs, which ends the WP test transaction, so every test starts
 * by clearing the module tables, rate-limit transients and hooks it observes.
 */
abstract class ReservationTestCaseTest extends TestCase {
	/** @var list<array<int,mixed>> */
	protected array $audits = [];

	/** @var list<array<int,mixed>> */
	protected array $hooks = [];

	protected static int $ip = 1;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		_delete_all_data();
		foreach ( [ Tables::notifications(), Tables::events(), Tables::reservations(), Tables::hours(), Tables::closures(), Tables::user_branches(), Tables::audit() ] as $t ) {
			$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
		}
		foreach ( [ 'momo_rate_email_per_hour', 'momo_rate_ip_per_hour', 'momo_res_global_enabled', 'momos_heartbeat_expiry' ] as $o ) {
			delete_option( $o );
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%momos%' OR option_name LIKE '\_transient\_timeout\_%momos%'" ); // phpcs:ignore
		wp_cache_flush();
		ScheduleService::flush();
		Presenter::flush();
		self::ensure_roles();
		wp_set_current_user( 0 );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( ++self::$ip % 250 + 1 );
		$this->audits = [];
		$this->hooks  = [];
		add_action( 'momos/audit/log', function ( ...$args ): void {
			$this->audits[] = $args;
		}, 10, 6 );
		foreach ( [ 'created', 'status_changed', 'modified', 'sla_reminder', 'sla_escalated', 'closure_conflict' ] as $h ) {
			add_action( 'momos/reservation/' . $h, function ( ...$args ) use ( $h ): void {
				$this->hooks[] = array_merge( [ $h ], $args );
			}, 10, 4 );
		}
	}

	public function tear_down(): void {
		global $wpdb;
		// Leave no committed rows behind for other suites (FK notifications → reservations).
		foreach ( [ Tables::notifications(), Tables::events(), Tables::reservations() ] as $t ) {
			$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
		}
		ScheduleService::clear_overlay();
		ScheduleService::flush();
		\Momos\Api\RateLimit\RateLimiter::$last = null;
		parent::tear_down();
	}

	protected static function ensure_roles(): void {
		if ( get_role( 'momo_reservation_staff' ) && get_role( 'momo_head_office' ) && get_role( 'momo_head_office' )->has_cap( 'momo_export_reservations' ) ) {
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

	/**
	 * Published, open branch accepting requests with daily hours 11:00–22:00 (L.O. 21:30) unless
	 * $week is given.
	 *
	 * @param array<string,mixed> $meta
	 * @param array<int, list<array{0:string,1:string,2?:?string,3?:string}>>|null $week
	 */
	protected function make_branch( string $code, array $meta = [], ?array $week = null ): int {
		$id       = self::factory()->post->create( [ 'post_type' => 'momo_branch', 'post_status' => 'draft', 'post_title' => '支店' . $code, 'post_name' => strtolower( $code ) ] );
		$defaults = [
			'momo_branch_code'                 => $code,
			'momo_operating_status'            => 'open',
			'momo_postal_code'                 => '530-0001',
			'momo_prefecture'                  => '大阪府',
			'momo_city'                        => '大阪市北区',
			'momo_address_line1'               => '梅田1-1-1',
			'momo_address_ja'                  => '〒530-0001 大阪府大阪市北区梅田1-1-1',
			'momo_lat'                         => '34.702485',
			'momo_lng'                         => '135.495951',
			'momo_phone_e164'                  => '+81600000000',
			'momo_phone_display'               => '06-0000-0000',
			'momo_branch_email'                => strtolower( $code ) . '@momos.example',
			'momo_facts_confirmed_by'          => 'Owner',
			'momo_facts_confirmed_at'          => '2026-11-01',
			'momo_res_notification_recipients' => wp_json_encode( [ [ 'email' => strtolower( $code ) . '-inbox@momos.example', 'language' => 'ja', 'active' => true ] ] ),
		];
		foreach ( array_merge( $defaults, $meta ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		$this->set_week( $id, $week ?? array_fill_keys( range( 1, 7 ), [ [ '11:00', '22:00', '21:30', 'all_day' ] ] ) );
		update_post_meta( $id, 'momo_res_enabled', $meta['momo_res_enabled'] ?? '1' );
		Presenter::flush();
		return $id;
	}

	/** @param array<int, list<array{0:string,1:string,2?:?string,3?:string}>> $week */
	protected function set_week( int $branch_id, array $week, ?string $from = null, ?string $until = null ): void {
		$periods = [];
		foreach ( $week as $w => $list ) {
			foreach ( $list as $i => $p ) {
				$periods[] = [ 'weekday' => $w, 'period_no' => $i + 1, 'label' => $p[3] ?? 'all_day', 'opens_at' => $p[0], 'closes_at' => $p[1], 'last_order_at' => $p[2] ?? null ];
			}
		}
		HoursRepository::replace_week( $branch_id, $from, $until, $periods, AccessContext::system() );
		ScheduleService::flush();
	}

	/** @param list<int> $branches */
	protected function staff( string $role, array $branches = [] ): int {
		return $this->user_with_role( $role, $branches );
	}

	/** @param array<string,mixed> $over */
	protected function body( string $code, string $date, string $time, array $over = [] ): array {
		return array_merge(
			[
				'branch_code'     => $code,
				'date'            => $date,
				'time'            => $time,
				'party_size'      => 2,
				'name'            => 'Aisha Rahman',
				'email'           => 'aisha@example.org',
				'phone'           => '+60 12-345 6789',
				'lang'            => 'en',
				'preferred_lang'  => 'en',
				'consent'         => true,
				'idempotency_key' => wp_generate_uuid4(),
				'captcha_token'   => 'test-pass',
			],
			$over
		);
	}

	/**
	 * Dispatch a REST request the way serve_request() does (pre + post dispatch filters).
	 *
	 * @param array<string,mixed>|string|null $body
	 * @param array<string,string>            $headers
	 */
	protected function rest( string $method, string $route, $body = null, array $headers = [], array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		if ( null !== $body ) {
			$request->set_body( is_string( $body ) ? $body : (string) wp_json_encode( $body ) );
			$request->set_header( 'content-type', 'application/json' );
		}
		if ( in_array( $method, [ 'POST', 'PATCH' ], true ) && ! str_contains( $route, '/staff/' ) ) {
			$request->set_header( 'origin', 'http://example.org' );
		}
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		if ( $query ) {
			$request->set_query_params( $query );
		}
		$server   = rest_get_server();
		$result   = $server->dispatch( $request );
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $result ), $server, $request );
		return $response;
	}

	protected function submit( array $body ): \WP_REST_Response {
		return $this->rest( 'POST', '/momos/v1/reservations', $body );
	}

	/** @return array<string,mixed> */
	protected function row( int $id ): array {
		return (array) \Momos\Reservation\Repository\ReservationRepository::find( $id );
	}

	/** Insert a pending reservation directly (fictional data). */
	protected function make_reservation( int $branch_id, string $date, string $time, array $over = [] ): int {
		global $wpdb;
		$start = Clock::jst_at( $date, $time );
		$now   = Clock::mysql_utc();
		$wpdb->insert(
			Tables::reservations(),
			array_merge(
				[
					'public_ref'          => \Momos\Kernel\Support\Crockford::random( 8 ),
					'idempotency_key'     => wp_generate_uuid4(),
					'branch_id'           => $branch_id,
					'status'              => 'pending',
					'requested_date'      => $date,
					'requested_time'      => $time . ':00',
					'requested_start_utc' => Clock::mysql_utc( $start ),
					'party_size'          => 2,
					'guest_name'          => 'Test Guest',
					'email'               => 'guest@example.org',
					'preferred_lang'      => 'en',
					'source_lang'         => 'en',
					'consent_version'     => 'v1',
					'consent_at'          => $now,
					'token_hash'          => str_repeat( 'a', 64 ),
					'token_expires_utc'   => Clock::mysql_utc( $start->modify( '+24 hours' ) ),
					'created_at'          => $now,
					'updated_at'          => $now,
				],
				$over
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return list<string> */
	protected function audit_actions(): array {
		return array_map( static fn( $a ) => (string) $a[0], $this->audits );
	}

	/** @return list<array<int,mixed>> */
	protected function hooks_of( string $name ): array {
		return array_values( array_filter( $this->hooks, static fn( $h ) => $h[0] === $name ) );
	}

	/** @return list<array<string,mixed>> */
	protected function events( int $id ): array {
		return \Momos\Reservation\Repository\EventRepository::for_reservation( $id );
	}
}
