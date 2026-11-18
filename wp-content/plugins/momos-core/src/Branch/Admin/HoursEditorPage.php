<?php
declare(strict_types=1);

namespace Momos\Branch\Admin;

use Momos\Branch\Domain\ClosureReason;
use Momos\Branch\Domain\PeriodLabel;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Repository\HoursRepository;
use Momos\Branch\Service\BranchAccess;
use Momos\Branch\Service\HoursValidator;
use Momos\Branch\Service\ScheduleService;
use Momos\Kernel\Clock\Clock;

/**
 * SCR-A04 Hours & closures editor (`admin.php?page=momos-hours&branch={id}`, spec §7.2).
 * Weekly periods per schedule set (default + dated sets), closures/special hours with public
 * reasons, a 7-day customer preview and the reservation impact panel. Read-only for staff who may
 * view but not edit the branch's hours (MM, CE, RS).
 */
final class HoursEditorPage {
	public const SLUG = 'momos-hours';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 25 );
		add_filter( 'post_row_actions', [ self::class, 'row_action' ], 10, 2 );
	}

	public static function menu(): void {
		if ( ! BranchAccess::is_branch_staff() ) {
			return;
		}
		// Under "Branches" (the CPT menu) so branch staff always have a real submenu there (a CPT
		// menu collapsed to one item makes WordPress deny edit.php for roles without edit_posts).
		$hook = add_submenu_page( 'edit.php?post_type=momo_branch', __( 'Hours & closures', 'momos-core' ), __( 'Hours & closures', 'momos-core' ), 'read', self::SLUG, [ self::class, 'render' ] );
		if ( $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'guard' ] );
		}
	}

	/** Access check before any output so denials return HTTP 403 (SEC-012). */
	public static function guard(): void {
		if ( ! BranchAccess::is_branch_staff() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'momos-core' ), '', [ 'response' => 403 ] );
		}
		$requested = isset( $_GET['branch'] ) ? absint( $_GET['branch'] ) : 0; // phpcs:ignore -- read-only view selection.
		if ( $requested && ! in_array( $requested, BranchAccess::visible_branch_ids(), true ) ) {
			BranchAccess::deny( 'momo_edit_branch_hours', $requested, 'branch', $requested, [ 'channel' => 'admin-screen' ] );
			wp_die( esc_html( momos_t( 'staff.err.forbidden_branch', [], HoursFormHandler::admin_lang() ) ), esc_html__( 'Forbidden', 'momos-core' ), [ 'response' => 403 ] );
		}
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function row_action( array $actions, \WP_Post $post ): array {
		if ( 'momo_branch' === $post->post_type && BranchAccess::can_view( $post->ID ) ) {
			$actions['momos_hours'] = '<a href="' . esc_url( self::url( $post->ID ) ) . '">' . esc_html__( 'Hours & closures', 'momos-core' ) . '</a>';
		}
		return $actions;
	}

	public static function url( int $branch_id, string $tab = 'weekly' ): string {
		return add_query_arg( [ 'post_type' => 'momo_branch', 'page' => self::SLUG, 'branch' => $branch_id, 'tab' => $tab ], admin_url( 'edit.php' ) );
	}

	public static function render(): void {
		self::guard();
		$visible   = BranchAccess::visible_branch_ids();
		$requested = isset( $_GET['branch'] ) ? absint( $_GET['branch'] ) : 0; // phpcs:ignore -- checked in guard().
		$branch_id = $requested ?: ( $visible[0] ?? 0 );
		$view      = self::view_data( $branch_id, $visible );
		include MOMOS_CORE_DIR . '/templates/admin/branch-hours.php';
	}

	/**
	 * @param list<int> $visible
	 * @return array<string,mixed>
	 */
	public static function view_data( int $branch_id, array $visible ): array {
		$tab     = isset( $_GET['tab'] ) && 'closures' === $_GET['tab'] ? 'closures' : 'weekly'; // phpcs:ignore
		$set_key = isset( $_GET['set'] ) ? sanitize_text_field( wp_unslash( $_GET['set'] ) ) : 'default'; // phpcs:ignore
		$state   = null;
		if ( isset( $_GET['momos_state'] ) ) { // phpcs:ignore
			$stored = get_transient( 'momos_hours_state_' . get_current_user_id() );
			if ( is_array( $stored ) && (int) $stored['branch_id'] === $branch_id ) {
				$state = $stored;
				delete_transient( 'momos_hours_state_' . get_current_user_id() );
			}
		}
		$today = Clock::jst()->format( 'Y-m-d' );
		$sets  = $branch_id ? HoursRepository::sets( $branch_id ) : [];
		[ $from, $until ] = HoursFormHandler::parse_set_key( $set_key );
		$rows  = $branch_id && 'new' !== $set_key ? HoursRepository::rows_for_set( $branch_id, $from, $until ) : [];
		$week  = [];
		for ( $w = 1; $w <= 7; $w++ ) {
			$week[ $w ] = array_values( array_filter( $rows, static fn( $r ) => $r['weekday'] === $w ) );
		}
		if ( $state && 'weekly' === $tab && isset( $state['input']['periods'] ) ) {
			$week = [];
			for ( $w = 1; $w <= 7; $w++ ) {
				$week[ $w ] = array_map(
					static fn( $p ) => [ 'label' => (string) ( $p['label'] ?? 'all_day' ), 'opens_at' => (string) ( $p['opens'] ?? '' ), 'closes_at' => (string) ( $p['closes'] ?? '' ), 'last_order_at' => (string) ( $p['last_order'] ?? '' ) ],
					array_values( array_filter( (array) ( $state['input']['periods'][ $w ] ?? [] ), 'is_array' ) )
				);
				if ( isset( $state['input']['closed'][ $w ] ) ) {
					$week[ $w ] = [];
				}
			}
		}
		$closures = $branch_id ? ClosureRepository::group_by_date( ClosureRepository::rows_between( $branch_id, Clock::jst()->modify( '-14 days' )->format( 'Y-m-d' ), Clock::jst()->modify( '+90 days' )->format( 'Y-m-d' ) ) ) : [];
		$preview  = [];
		for ( $i = 0; $i < 7 && $branch_id; $i++ ) {
			$date      = Clock::jst()->modify( '+' . $i . ' day' )->format( 'Y-m-d' );
			$preview[] = [
				'date'    => $date,
				'weekday' => (int) ( new \DateTimeImmutable( $date, Clock::tz() ) )->format( 'N' ),
				'periods' => ScheduleService::periods_on( $branch_id, $date ),
				'closure' => ScheduleService::closure_on( $branch_id, $date ),
			];
		}
		return [
			'branch_id'    => $branch_id,
			'branches'     => array_map( static fn( $id ) => [ 'id' => $id, 'title' => get_the_title( $id ), 'code' => (string) get_post_meta( $id, 'momo_branch_code', true ) ], $visible ),
			'can_edit'     => $branch_id && BranchAccess::can_edit( $branch_id, 'hours' ),
			'tab'          => $tab,
			'set_key'      => $set_key,
			'set'          => [ 'valid_from' => $state['input']['valid_from'] ?? $from, 'valid_until' => $state['input']['valid_until'] ?? $until ],
			'sets'         => $sets,
			'week'         => $week,
			'loaded_at'    => $branch_id ? HoursRepository::max_updated_at( $branch_id ) : '',
			'res_enabled'  => $branch_id && '1' === (string) get_post_meta( $branch_id, 'momo_res_enabled', true ),
			'closures'     => $closures,
			'today'        => $today,
			'preview'      => $preview,
			'state'        => $state,
			'labels'       => self::period_labels(),
			'reasons'      => self::reason_labels(),
			'nonce'        => $branch_id ? wp_create_nonce( 'momos_hours_' . $branch_id ) : '',
			'msg'          => isset( $_GET['momos_msg'] ) ? sanitize_key( wp_unslash( $_GET['momos_msg'] ) ) : '', // phpcs:ignore
			'affected'     => isset( $_GET['affected'] ) ? absint( $_GET['affected'] ) : 0, // phpcs:ignore
			'inbox_url'    => add_query_arg( [ 'page' => 'momos-inbox', 'flag' => 'needs_action', 'branch' => $branch_id ? (string) get_post_meta( $branch_id, 'momo_branch_code', true ) : '' ], admin_url( 'admin.php' ) ),
			'notice_url'   => add_query_arg( [ 'post_type' => 'momo_notice' ], admin_url( 'post-new.php' ) ),
		];
	}

	/** @return array<string,string> */
	public static function period_labels(): array {
		return [
			PeriodLabel::Lunch->value  => __( 'Lunch', 'momos-core' ),
			PeriodLabel::Dinner->value => __( 'Dinner', 'momos-core' ),
			PeriodLabel::AllDay->value => __( 'All day', 'momos-core' ),
			PeriodLabel::Other->value  => __( 'Other', 'momos-core' ),
		];
	}

	/** @return array<string,string> */
	public static function reason_labels(): array {
		return [
			ClosureReason::PublicHoliday->value => __( 'Public holiday', 'momos-core' ),
			ClosureReason::PrivateEvent->value  => __( 'Private event', 'momos-core' ),
			ClosureReason::Maintenance->value   => __( 'Maintenance', 'momos-core' ),
			ClosureReason::StaffShortage->value => __( 'Closed for operational reasons', 'momos-core' ),
			ClosureReason::Other->value         => __( 'Closed (other)', 'momos-core' ),
		];
	}

	/** "Mon 12:00–22:00, last order 21:30" style text for the preview (admin language). */
	public static function periods_text( array $periods ): string {
		if ( ! $periods ) {
			return __( 'Closed', 'momos-core' );
		}
		return implode(
			' · ',
			array_map(
				static fn( $p ) => $p['opens_at'] . '–' . $p['closes_at'] . ( $p['last_order_at'] ? sprintf( /* translators: %s: time */ __( ', last order %s', 'momos-core' ), $p['last_order_at'] ) : '' ),
				$periods
			)
		);
	}

	public static function weekday_short( int $w ): string {
		return HoursValidator::weekday_name( $w );
	}
}
