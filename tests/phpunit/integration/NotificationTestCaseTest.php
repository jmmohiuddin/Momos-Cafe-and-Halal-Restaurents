<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Support\Crockford;
use Momos\Notification\Transport\Message;
use Momos\Notification\Transport\SendResult;
use Momos\Notification\Transport\TransportInterface;
use Momos\Tests\TestCase;

/**
 * Shared fixtures for the Notification module tests (fictional data: branches QAA/QAB, guest
 * "Aisha Rahman", example.test domains). A provider stub transport records messages and can be
 * told to fail.
 */
abstract class NotificationTestCaseTest extends TestCase {
	/** @var list<Message> */
	protected array $sent = [];

	/** @var list<array{0:string,1:string}> queue of failures to return: [code, error] */
	protected array $fail_queue = [];

	/** @var list<array<int,mixed>> */
	protected array $failed_events = [];

	/** @var list<array<int,mixed>> */
	protected array $audits = [];

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( [ Tables::notifications(), Tables::events(), Tables::reservations(), Tables::user_branches(), Tables::audit() ] as $t ) {
			$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore
		}
		self::ensure_roles();
		$this->sent          = [];
		$this->fail_queue    = [];
		$this->failed_events = [];
		$this->audits        = [];
		add_filter( 'momos/notification/transport', fn() => $this->stub() );
		add_action( 'momos/notification/failed', function ( ...$a ): void {
			$this->failed_events[] = $a;
		}, 10, 2 );
		add_action( 'momos/audit/log', function ( ...$a ): void {
			$this->audits[] = $a;
		}, 10, 6 );
		update_option( 'momos_languages_enabled', [ 'ja', 'en', 'ar', 'ur', 'hi', 'bn' ] );
		\Momos\Kernel\Settings::flush();
		$this->freeze( '2026-12-01 10:00:00' );
	}

	public function tear_down(): void {
		\Momos\Kernel\Settings::flush();
		parent::tear_down();
	}

	protected function stub(): TransportInterface {
		$test = $this;
		return new class( $test ) implements TransportInterface {
			public function __construct( private NotificationTestCaseTest $t ) {}

			public function name(): string {
				return 'stub';
			}

			public function send( Message $m ): SendResult {
				return $this->t->record( $m );
			}
		};
	}

	public function record( Message $m ): SendResult {
		if ( $this->fail_queue ) {
			[ $code, $err ] = array_shift( $this->fail_queue );
			return SendResult::fail( $code, $err );
		}
		$this->sent[] = $m;
		return SendResult::ok( $m->message_id );
	}

	protected static function ensure_roles(): void {
		if ( get_role( 'momo_branch_manager' ) && get_role( 'momo_head_office' ) && get_role( 'momo_head_office' )->has_cap( 'momo_view_notification_log' ) ) {
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
	 * Published branch with recipients and an approved English translation.
	 *
	 * @param list<array{email:string,language:string,active:string}> $recipients
	 */
	protected function branch( string $code, array $recipients = [], array $meta = [], array $tr = [ 'en' ] ): int {
		global $wpdb;
		$id = self::factory()->post->create( [ 'post_type' => 'momo_branch', 'post_status' => 'draft', 'post_title' => '心斎橋店' . $code, 'post_name' => strtolower( $code ) ] );
		$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $id ] );
		clean_post_cache( $id );
		$defaults = [
			'momo_branch_code'                 => $code,
			'momo_phone_e164'                  => '+81600000000',
			'momo_phone_display'               => '06-0000-0000',
			'momo_branch_email'                => strtolower( $code ) . '@momos.example',
			'momo_address_ja'                  => '〒542-0085 大阪府大阪市中央区心斎橋筋1-1-1 2F',
			'momo_postal_code'                 => '542-0085',
			'momo_lat'                         => '34.6745',
			'momo_lng'                         => '135.5011',
			'momo_res_notification_recipients' => $recipients,
		];
		foreach ( array_merge( $defaults, $meta ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		foreach ( $tr as $lang ) {
			update_post_meta( $id, '_momos_i18n_' . $lang, wp_slash( (string) wp_json_encode( [ 'title' => 'Shinsaibashi ' . $code, 'momo_address_line1' => '1-1-1 Shinsaibashisuji', 'momo_city' => 'Chuo-ku, Osaka', 'momo_prefecture' => 'Osaka' ], JSON_UNESCAPED_UNICODE ) ) );
			update_post_meta( $id, '_momos_i18n_status_' . $lang, 'approved' );
		}
		return $id;
	}

	/** @param array<string,mixed> $over */
	protected function reservation( int $branch_id, array $over = [] ): int {
		global $wpdb;
		$now   = Clock::mysql_utc();
		$date  = $over['requested_date'] ?? '2026-12-24';
		$time  = $over['requested_time'] ?? '19:00:00';
		$start = Clock::mysql_utc( Clock::jst_at( $date, substr( $time, 0, 5 ) ) );
		$row   = array_merge(
			[
				'public_ref'          => Crockford::random(),
				'idempotency_key'     => wp_generate_uuid4(),
				'branch_id'           => $branch_id,
				'status'              => 'pending',
				'requested_date'      => $date,
				'requested_time'      => $time,
				'requested_start_utc' => $start,
				'party_size'          => 4,
				'guest_name'          => 'Aisha Rahman',
				'email'               => 'aisha.guest@example.test',
				'phone_e164'          => '+60123456789',
				'special_request'     => 'Window seat, peanut allergy',
				'preferred_lang'      => 'en',
				'source_lang'         => 'en',
				'consent_version'     => 'v1',
				'consent_at'          => $now,
				'token_hash'          => str_repeat( 'a', 64 ),
				'token_key_version'   => 1,
				'token_expires_utc'   => Clock::mysql_utc( Clock::utc()->modify( '+30 days' ) ),
				'created_at'          => $now,
				'updated_at'          => $now,
				'version'             => 1,
			],
			$over
		);
		$wpdb->insert( Tables::reservations(), $row );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string,mixed> $set */
	protected function update_reservation( int $id, array $set ): void {
		global $wpdb;
		$wpdb->update( Tables::reservations(), $set, [ 'id' => $id ] );
	}

	/** @return list<array<string,mixed>> */
	protected function rows( ?string $template = null ): array {
		global $wpdb;
		$t    = Tables::notifications();
		$rows = $template
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE template_key = %s ORDER BY id", $template ), ARRAY_A ) // phpcs:ignore
			: $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id", ARRAY_A ); // phpcs:ignore
		return (array) $rows;
	}

	protected function advance( string $modifier ): void {
		$clock = Clock::get();
		if ( $clock instanceof \Momos\Kernel\Clock\FrozenClock ) {
			$clock->advance( $modifier );
		}
	}

	protected static function recipients( string ...$emails ): array {
		return array_map( static fn( $e ) => [ 'email' => $e, 'language' => 'ja', 'active' => '1' ], $emails );
	}
}
