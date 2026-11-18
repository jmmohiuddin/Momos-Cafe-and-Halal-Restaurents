<?php
declare(strict_types=1);

namespace Momos\Notification\Job;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\Log\Logger;
use Momos\Kernel\Support\Crockford;
use Momos\Notification\Admin\SettingsFields;
use Momos\Notification\Queue\NotificationQueue;
use Momos\Notification\Queue\NotificationRequest;
use Momos\Notification\Support\ReservationReader;

/**
 * E10 daily digest (FR-NOT-002(e), US-NOT-007, WF-ADM-26, SDD §5.10; spec §4.8). Runs daily at
 * `momo_digest_time` JST (Action Scheduler cron action in UTC, re-registered when the setting
 * changes); skipped when `momo_digest_enabled = 0` (E11 unaffected). Window W = [run − 24 h, run).
 * A branch digest is queued only when that branch has ≥ 1 item (AC-NOT-02-04) and goes to that
 * branch's recipients only (TC-ACL-008); the HO digest covers every non-TEST branch plus HO
 * sections. Content = counts + references + date/time/party — no guest names or contact data.
 */
final class DigestJob {
	public const HOOK = 'momos_job_daily_digest';

	/** Branch-manager audit actions listed in the HO digest (FR-BR-008(c)). */
	public const BRANCH_CHANGE_ACTIONS = [ 'branch.hours_updated', 'branch.content_updated', 'branch.closure_created', 'branch.closure_updated', 'branch.closure_deleted', 'branch_menu_item.availability_changed', 'branch_menu_item.updated' ];

	public static function register(): void {
		add_action( self::HOOK, [ self::class, 'run' ] );
		add_action( 'init', [ self::class, 'ensure_schedule' ], 31 );
		add_action( 'momos/options_page/saved', static function ( string $slug ): void {
			if ( SettingsFields::PAGE === $slug ) {
				delete_option( 'momos_digest_schedule_sig' );
				self::ensure_schedule();
			}
		} );
	}

	/** Configured JST time HH:MM (default ⟦09:00⟧, C1). */
	public static function time(): string {
		$v = (string) SettingsFields::get( 'momo_digest_time', '09:00' );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v ) ? $v : '09:00';
	}

	public static function enabled(): bool {
		return '1' === (string) SettingsFields::get( 'momo_digest_enabled', '1' );
	}

	/** (Re)register the cron action in UTC when the configured time changes. */
	public static function ensure_schedule(): void {
		if ( ! function_exists( 'as_schedule_cron_action' ) || wp_installing() ) {
			return;
		}
		$sig = self::time();
		if ( get_option( 'momos_digest_schedule_sig' ) === $sig && as_has_scheduled_action( self::HOOK, [], Schedule::GROUP ) ) {
			return;
		}
		[ $h, $m ] = array_map( 'intval', explode( ':', $sig ) );
		$utc_h     = ( $h - 9 + 24 ) % 24;
		as_unschedule_all_actions( self::HOOK, [], Schedule::GROUP );
		as_schedule_cron_action( time(), sprintf( '%d %d * * *', $m, $utc_h ), self::HOOK, [], Schedule::GROUP, true );
		update_option( 'momos_digest_schedule_sig', $sig, false );
	}

	/**
	 * Queue the day's digests. Idempotent per JST date (dedupe keys). Returns new notification ids.
	 *
	 * @return list<int>
	 */
	public static function run(): array {
		if ( ! self::enabled() || ! NamedLock::acquire( self::HOOK, 0 ) ) {
			return [];
		}
		$ids = [];
		try {
			$run  = Clock::utc();
			$date = $run->setTimezone( Clock::tz() )->format( 'Y-m-d' );
			foreach ( self::branch_ids( true ) as $branch_id ) {
				if ( ! self::has_items( self::sections( $branch_id, $run ) ) ) {
					continue;
				}
				$ids = array_merge(
					$ids,
					NotificationQueue::enqueue( new NotificationRequest( 'E10', 'branch_recipients', 'digest:' . $date . ':branch:' . $branch_id, 'branch', null, $branch_id, 'digest', $branch_id ) )
				);
			}
			if ( self::has_items( self::sections( null, $run ) ) ) {
				$ids = array_merge(
					$ids,
					NotificationQueue::enqueue( new NotificationRequest( 'E10', 'head_office_digest', 'digest:' . $date . ':head_office', 'head_office', null, null, 'digest', 0 ) )
				);
			}
			Schedule::beat( self::HOOK );
		} catch ( \Throwable $e ) {
			Logger::critical( 'notification.digest_failed', [ 'error' => $e->getMessage() ] );
		} finally {
			NamedLock::release( self::HOOK );
		}
		return $ids;
	}

	/** @param list<array<string,mixed>> $sections */
	public static function has_items( array $sections ): bool {
		foreach ( $sections as $s ) {
			if ( (int) $s['count'] > 0 ) {
				return true;
			}
		}
		return false;
	}

	/** @return list<int> */
	public static function branch_ids( bool $include_test ): array {
		if ( class_exists( \Momos\Branch\Repository\BranchRepository::class ) ) {
			return \Momos\Branch\Repository\BranchRepository::published_ids( $include_test );
		}
		$ids = get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true ] );
		$ids = array_map( 'intval', $ids );
		return $include_test ? $ids : array_values( array_filter( $ids, static fn( $id ) => '1' !== (string) get_post_meta( $id, 'momo_is_test_branch', true ) ) );
	}

	/**
	 * Digest sections. $branch_id = one branch (variant `branch`), null = HO digest (every non-TEST
	 * branch grouped by code + HO sections). Each section: key, branch code ('' for HO-wide), count,
	 * items (language-neutral reference lines), link (admin filtered view).
	 *
	 * @return list<array{key:string,code:string,count:int,items:list<string>,link:?string}>
	 */
	public static function sections( ?int $branch_id, \DateTimeImmutable $run ): array {
		$from = Clock::mysql_utc( $run->modify( '-24 hours' ) );
		$to   = Clock::mysql_utc( $run );
		$out  = [];
		$ids  = null === $branch_id ? self::branch_ids( false ) : [ $branch_id ];
		foreach ( $ids as $id ) {
			$code = (string) get_post_meta( $id, 'momo_branch_code', true );
			foreach ( self::branch_sections( $id, $from, $to, $run ) as $s ) {
				$s['code'] = null === $branch_id ? $code : '';
				$out[]     = $s;
			}
		}
		if ( null === $branch_id ) {
			$out = array_merge( $out, self::ho_sections( $from, $to, $run ) );
		}
		return $out;
	}

	/** @return list<array{key:string,code:string,count:int,items:list<string>,link:?string}> */
	private static function branch_sections( int $branch_id, string $from, string $to, \DateTimeImmutable $run ): array {
		$list = static fn( string $status ): string => admin_url( 'admin.php?page=momos-reservations&status=' . $status . '&branch=' . $branch_id );
		$line = static fn( array $r ): string => Crockford::display( (string) $r['public_ref'] ) . ' · ' . substr( (string) $r['requested_date'], 0, 10 ) . ' ' . substr( (string) $r['requested_time'], 0, 5 ) . ' · ' . (int) $r['party_size'];

		$expired = ReservationReader::light( 'branch_id = %d AND status = %s AND expired_at >= %s AND expired_at < %s', [ $branch_id, 'expired', $from, $to ] );

		$failed_items = [];
		foreach ( \Momos\Notification\Repository\NotificationRepository::failed_between( $branch_id, $from, $to ) as $n ) {
			$ref            = '';
			$res            = $n['reservation_id'] ? ReservationReader::find( (int) $n['reservation_id'] ) : null;
			$ref            = $res ? Crockford::display( (string) $res['public_ref'] ) : '';
			$failed_items[] = trim( $ref . ' · ' . $n['template_key'] . ( $n['template_variant'] ? ' (' . $n['template_variant'] . ')' : '' ), ' ·' );
		}
		$not_notified = ReservationReader::light( "branch_id = %d AND customer_notified_flag = 0 AND status IN ('pending','confirmed')", [ $branch_id ] );
		foreach ( $not_notified as $r ) {
			$failed_items[] = $line( $r );
		}
		$failed_items = array_values( array_unique( $failed_items ) );

		$attendance = ReservationReader::light( 'branch_id = %d AND status = %s AND requested_start_utc <= %s AND requested_start_utc > %s', [ $branch_id, 'confirmed', Clock::mysql_utc( $run->modify( '-24 hours' ) ), Clock::mysql_utc( $run->modify( '-72 hours' ) ) ] );
		$needs      = ReservationReader::light( "branch_id = %d AND needs_action_flag = 1 AND status IN ('pending','confirmed') AND requested_start_utc >= %s", [ $branch_id, Clock::mysql_utc( $run ) ] );

		return [
			[ 'key' => 'expired', 'code' => '', 'count' => count( $expired ), 'items' => array_map( $line, $expired ), 'link' => $list( 'expired' ) ],
			[ 'key' => 'failed', 'code' => '', 'count' => count( $failed_items ), 'items' => $failed_items, 'link' => admin_url( 'admin.php?page=momos-reservations&customer_not_notified=1&branch=' . $branch_id ) ],
			[ 'key' => 'attendance', 'code' => '', 'count' => count( $attendance ), 'items' => array_map( $line, $attendance ), 'link' => $list( 'confirmed' ) ],
			[ 'key' => 'needs_action', 'code' => '', 'count' => count( $needs ), 'items' => array_map( $line, $needs ), 'link' => admin_url( 'admin.php?page=momos-reservations&flags=needs_action&branch=' . $branch_id ) ],
		];
	}

	/** @return list<array{key:string,code:string,count:int,items:list<string>,link:?string}> */
	private static function ho_sections( string $from, string $to, \DateTimeImmutable $run ): array {
		global $wpdb;
		$out = [];

		// Branch changes by Branch Managers in W (US-NOT-007): branch, action, field before → after, user.
		$audit   = Tables::audit();
		$actions = self::BRANCH_CHANGE_ACTIONS;
		$ph      = implode( ',', array_fill( 0, count( $actions ), '%s' ) );
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT action, branch_id, actor_id, changes FROM {$audit} WHERE occurred_at >= %s AND occurred_at < %s AND actor_role = %s AND (action IN ({$ph}) OR action LIKE %s) ORDER BY occurred_at ASC LIMIT 100", array_merge( [ $from, $to, 'momo_branch_manager' ], $actions, [ 'notice.%' ] ) ), ARRAY_A ); // phpcs:ignore
		$items   = [];
		foreach ( $rows as $a ) {
			$code    = $a['branch_id'] ? (string) get_post_meta( (int) $a['branch_id'], 'momo_branch_code', true ) : '';
			$user    = $a['actor_id'] ? get_userdata( (int) $a['actor_id'] ) : null;
			$changes = \Momos\Kernel\Support\Json::decode_array( (string) $a['changes'] );
			$fields  = [];
			foreach ( array_slice( $changes, 0, 3, true ) as $field => $pair ) {
				if ( is_array( $pair ) && 2 === count( $pair ) && array_is_list( $pair ) ) {
					$fields[] = $field . ': ' . self::short( $pair[0] ) . ' → ' . self::short( $pair[1] );
				} else {
					$fields[] = (string) $field;
				}
			}
			$items[] = trim( $code . ' · ' . $a['action'] . ( $fields ? ' · ' . implode( '; ', $fields ) : '' ) . ( $user ? ' · ' . $user->display_name : '' ), ' ·' );
		}
		$out[] = [ 'key' => 'branch_changes', 'code' => '', 'count' => count( $items ), 'items' => $items, 'link' => admin_url( 'admin.php?page=momos-audit' ) ];

		$offers = get_posts( [ 'post_type' => 'momo_offer', 'post_status' => 'pending', 'posts_per_page' => 50, 'no_found_rows' => true, 'suppress_filters' => true ] );
		$out[]  = [ 'key' => 'offers_pending', 'code' => '', 'count' => count( $offers ), 'items' => array_map( static fn( $p ) => (string) $p->post_title, $offers ), 'link' => admin_url( 'edit.php?post_type=momo_offer&post_status=pending' ) ];

		$dishes = get_posts( [ 'post_type' => 'momo_dish', 'post_status' => 'pending', 'posts_per_page' => 50, 'no_found_rows' => true, 'suppress_filters' => true ] );
		$out[]  = [ 'key' => 'dishes_pending', 'code' => '', 'count' => count( $dishes ), 'items' => array_map( static fn( $p ) => (string) $p->post_title, $dishes ), 'link' => admin_url( 'edit.php?post_type=momo_dish&post_status=pending' ) ];

		$today = $run->setTimezone( Clock::tz() )->format( 'Y-m-d' );
		$d30   = $run->setTimezone( Clock::tz() )->modify( '+30 days' )->format( 'Y-m-d' );
		$certs = get_posts(
			[
				'post_type'      => 'momo_certificate',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore
					[ 'key' => 'momo_cert_status', 'value' => 'active' ],
					[ 'key' => 'momo_cert_valid_until', 'value' => [ $today, $d30 ], 'compare' => 'BETWEEN', 'type' => 'DATE' ],
				],
			]
		);
		$out[] = [ 'key' => 'certificates', 'code' => '', 'count' => count( $certs ), 'items' => array_map( static fn( $p ) => trim( get_post_meta( $p->ID, 'momo_cert_number', true ) . ' · ' . get_post_meta( $p->ID, 'momo_cert_valid_until', true ), ' ·' ), $certs ), 'link' => admin_url( 'edit.php?post_type=momo_certificate' ) ];

		$captcha = ReservationReader::count( 'captcha_status = %s AND created_at >= %s AND created_at < %s', [ 'unverified_outage', $from, $to ] );
		$out[]   = [ 'key' => 'captcha', 'code' => '', 'count' => $captcha, 'items' => [], 'link' => null ];

		$events = Tables::events();
		$res    = Tables::reservations();
		$esc    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT r.public_ref, r.branch_id, r.requested_date, r.requested_time, r.party_size FROM {$events} e JOIN {$res} r ON r.id = e.reservation_id WHERE e.event_type = 'sla_escalated' AND e.created_at >= %s AND e.created_at < %s AND r.status = 'pending' ORDER BY e.created_at ASC LIMIT 100", $from, $to ), ARRAY_A ); // phpcs:ignore
		$out[]  = [
			'key'   => 'escalations',
			'code'  => '',
			'count' => count( $esc ),
			'items' => array_map( static fn( $r ) => (string) get_post_meta( (int) $r['branch_id'], 'momo_branch_code', true ) . ' · ' . Crockford::display( (string) $r['public_ref'] ) . ' · ' . $r['requested_date'] . ' ' . substr( (string) $r['requested_time'], 0, 5 ) . ' · ' . (int) $r['party_size'], $esc ),
			'link'  => admin_url( 'admin.php?page=momos-reservations&status=pending' ),
		];
		return $out;
	}

	private static function short( mixed $v ): string {
		$s = is_scalar( $v ) || null === $v ? (string) $v : (string) wp_json_encode( $v );
		$s = \Momos\Kernel\Log\Redactor::scrub( $s );
		return mb_strlen( $s ) > 60 ? mb_substr( $s, 0, 57 ) . '…' : $s;
	}
}
