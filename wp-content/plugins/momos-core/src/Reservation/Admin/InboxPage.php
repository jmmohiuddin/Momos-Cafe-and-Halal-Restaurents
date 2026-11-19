<?php
declare(strict_types=1);

namespace Momos\Reservation\Admin;

use Momos\Api\Controller\StaffReservationController;
use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Locale;
use Momos\Reservation\Domain\Actor;
use Momos\Reservation\Domain\ReservationError;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Repository\ReservationRepository;
use Momos\Reservation\Service\ExpiryService;
use Momos\Reservation\Service\ModificationService;
use Momos\Reservation\Service\NoteService;
use Momos\Reservation\Service\Presenter;
use Momos\Reservation\Service\ReservationAccess;
use Momos\Reservation\Service\SlaClock;
use Momos\Reservation\Service\SlotGenerator;
use Momos\Reservation\Service\TransitionService;

/**
 * SCR-A08 inbox and SCR-A09 detail (FR-RES-008/009/013/014, F-RES-04/05/09/10).
 * Server-rendered so they work without JavaScript; every action is an admin-post form with a nonce,
 * capability and branch-scope check, and goes through the same services as API-12/13/16.
 * assets/js/staff-inbox.js adds 60 s polling ("n new — Show"), an offline banner and the takeover
 * confirmation (FR-RES-028).
 */
final class InboxPage {
	public const SLUG     = 'momos-reservations';
	public const ACTION   = 'momos_reservation_action';
	public const PER_PAGE = 20;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 20 );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_page_access_denied', [ self::class, 'legacy_link' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
	}

	public static function menu(): void {
		$badge = '';
		if ( current_user_can( 'momo_view_reservations' ) ) {
			$n = ReservationRepository::counts_by_status( Scope::for_user( get_current_user_id() ) )['pending'] ?? 0;
			if ( $n > 0 ) {
				$badge = ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count" id="momos-res-badge">' . number_format_i18n( $n ) . '</span></span>';
			}
		}
		$hook = add_menu_page( __( 'Reservation requests', 'momos-core' ), __( 'Reservations', 'momos-core' ) . $badge, 'momo_view_reservations', self::SLUG, [ self::class, 'render' ], 'dashicons-calendar-alt', 4 );
		add_action( 'load-' . $hook, [ self::class, 'preload' ] );
		add_submenu_page( self::SLUG, __( 'Reservation requests', 'momos-core' ), __( 'Inbox', 'momos-core' ), 'momo_view_reservations', self::SLUG, [ self::class, 'render' ] );
	}

	/** Deep links of the notification spec (`page=momo-reservations&reservation={id}`) → this screen. */
	public static function legacy_link(): void {
		if ( 'momo-reservations' === ( $_GET['page'] ?? '' ) ) { // phpcs:ignore
			$id = isset( $_GET['reservation'] ) ? (int) $_GET['reservation'] : ( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 ); // phpcs:ignore
			wp_safe_redirect( self::url( $id ? [ 'id' => $id ] : [] ) );
			exit;
		}
	}

	public static function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) && ! str_contains( $hook, ReportPage::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'momos-admin', MOMOS_CORE_URL . '/assets/css/admin.css', [], MOMOS_CORE_VERSION );
		wp_enqueue_script( 'momos-staff-inbox', MOMOS_CORE_URL . '/assets/js/staff-inbox.js', [], MOMOS_CORE_VERSION, true );
		wp_localize_script(
			'momos-staff-inbox',
			'momosInbox',
			[
				'api'      => esc_url_raw( rest_url( 'momos/v1/staff/reservations' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'since'    => Clock::utc()->format( 'c' ),
				'interval' => 60000,
				'i18n'     => [
					/* translators: %d: number of new requests */
					'new'      => __( '%d new — Show', 'momos-core' ),
					'offline'  => __( 'You are offline. The list will update when the connection returns.', 'momos-core' ),
					/* translators: %s: staff name */
					'takeover' => __( 'Claimed by %s. Take over?', 'momos-core' ),
				],
			]
		);
	}

	/** @param array<string,scalar> $args */
	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Before any output: lazy expiry and object-level access for the detail view, so a denied or
	 * unknown request is answered with a real 403/404 status and no data.
	 */
	public static function preload(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore
		if ( ! $id ) {
			return;
		}
		Locale::set( Locale::admin() );
		ExpiryService::expire_if_due( $id );
		$row = ReservationRepository::find( $id );
		if ( null === $row ) {
			wp_die( esc_html( momos_t( 'staff.err.not_found' ) ), '', [ 'response' => 404, 'back_link' => true ] );
		}
		if ( ! ReservationAccess::can_view( $row ) ) {
			ReservationAccess::deny( $id, (int) $row['branch_id'], 'forbidden_branch', 'SCR-A09' );
			wp_die( esc_html( momos_t( 'staff.err.forbidden_branch' ) ), '', [ 'response' => 403, 'back_link' => true ] );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'momo_view_reservations' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		Locale::set( Locale::admin() );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore
		if ( $id ) {
			self::render_detail( $id );
			return;
		}
		self::render_inbox();
	}

	/** Saved views of the filter chips (spec §7.1). @return array<string,array<string,string>> */
	public static function views(): array {
		$today = Clock::jst()->format( 'Y-m-d' );
		return [
			'pending'    => [ 'label' => __( 'Pending', 'momos-core' ), 'status' => 'pending' ],
			'today'      => [ 'label' => __( 'Today', 'momos-core' ), 'status' => 'pending,confirmed', 'date_from' => $today, 'date_to' => $today ],
			'upcoming'   => [ 'label' => __( 'Upcoming confirmed', 'momos-core' ), 'status' => 'confirmed', 'date_from' => $today ],
			'attendance' => [ 'label' => __( 'Attendance due', 'momos-core' ), 'status' => 'confirmed', 'date_from' => Clock::jst()->modify( '-3 days' )->format( 'Y-m-d' ), 'date_to' => $today ],
			'needs'      => [ 'label' => __( 'Needs action', 'momos-core' ), 'status' => 'pending,confirmed', 'flags' => 'needs_action' ],
			'all'        => [ 'label' => __( 'All', 'momos-core' ), 'status' => 'all' ],
		];
	}

	private static function render_inbox(): void {
		$user_id = get_current_user_id();
		$scope   = Scope::for_user( $user_id );
		$views   = self::views();
		$view    = isset( $_GET['view'] ) && isset( $views[ $_GET['view'] ] ) ? (string) $_GET['view'] : 'pending'; // phpcs:ignore
		$q       = array_merge( $views[ $view ], self::query_from_get() );
		unset( $q['label'] );
		$error   = null;
		$result  = [ 'rows' => [], 'total' => 0 ];
		$filters = [ 'per_page' => self::PER_PAGE, 'branch_ids' => null ];
		try {
			$filters = StaffReservationController::filters( $q + [ 'per_page' => (string) self::PER_PAGE ], $scope );
			$result  = ReservationRepository::search( $scope, $filters );
		} catch ( ReservationError $e ) {
			$error = 'forbidden_branch' === $e->error_code ? momos_t( 'staff.err.forbidden_branch' ) : __( 'Check the filters.', 'momos-core' );
		}
		if ( 'attendance' === $view ) {
			$result['rows'] = array_values( array_filter( $result['rows'], static fn( $r ) => TransitionService::attendance_open( $r, $user_id ) ) );
		}
		$rows    = array_map( [ Presenter::class, 'list_row' ], $result['rows'] );
		$counts  = ReservationRepository::counts_by_status( $scope, $filters['branch_ids'] ?? null );
		$overdue = 0;
		foreach ( ReservationRepository::pending_light( $scope ) as $r ) {
			$overdue += 'overdue' === SlaClock::state( $r + [ 'status' => 'pending' ] ) ? 1 : 0;
		}
		$branches   = self::scope_branches( $scope );
		$page       = (int) ( $filters['page'] ?? 1 );
		$pages      = (int) ceil( $result['total'] / self::PER_PAGE );
		$total      = (int) $result['total'];
		$base_q     = array_filter( array_merge( [ 'view' => $view ], self::query_from_get() ), static fn( $v ) => '' !== $v && null !== $v );
		$can_export = current_user_can( 'momo_export_reservations' );
		include MOMOS_CORE_DIR . '/templates/admin/reservation-inbox.php';
	}

	/** @return array<string,string> raw filter values from the request */
	private static function query_from_get(): array {
		$out = [];
		foreach ( [ 'branch', 'status', 'date_from', 'date_to', 'q', 'flags', 'has_special_request', 'handled_by', 'sort', 'order', 'paged' ] as $k ) {
			if ( isset( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { // phpcs:ignore
				$v = is_array( $_GET[ $k ] ) ? implode( ',', array_map( 'sanitize_text_field', wp_unslash( $_GET[ $k ] ) ) ) : sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) ); // phpcs:ignore
				$out[ 'paged' === $k ? 'page' : $k ] = $v;
			}
		}
		return $out;
	}

	/** @return list<array{id:int,code:string,name:string,is_test:bool}> */
	public static function scope_branches( Scope $scope ): array {
		$out = [];
		foreach ( BranchRepository::published_ids( true ) as $id ) {
			if ( $scope->allows( $id ) ) {
				$b     = BranchRepository::get( $id );
				$out[] = [ 'id' => $id, 'code' => (string) $b['code'], 'name' => (string) $b['name'], 'is_test' => (bool) $b['is_test'] ];
			}
		}
		return $out;
	}

	private static function render_detail( int $id ): void {
		$user_id = get_current_user_id();
		ExpiryService::expire_if_due( $id );
		$row = ReservationRepository::find( $id );
		if ( null === $row ) {
			wp_die( esc_html( momos_t( 'staff.err.not_found' ) ), 404 );
		}
		if ( ! ReservationAccess::can_view( $row, $user_id ) ) {
			ReservationAccess::deny( $id, (int) $row['branch_id'], 'forbidden_branch', 'SCR-A09' );
			wp_die( esc_html( momos_t( 'staff.err.forbidden_branch' ) ), 403 );
		}
		StaffReservationController::audit_view( $row );
		$d        = Presenter::detail( $row, $user_id );
		$branch   = BranchRepository::get( (int) $row['branch_id'] );
		$settings = BranchSettingsRepository::get( (int) $row['branch_id'] );
		$slots    = array_keys( SlotGenerator::slots_on( (int) $row['branch_id'], (string) $row['requested_date'], (int) $settings['slot_interval_minutes'] ) );
		$notice   = self::notice();
		$back     = wp_get_referer() && str_contains( (string) wp_get_referer(), 'page=' . self::SLUG ) && ! str_contains( (string) wp_get_referer(), '&id=' ) ? (string) wp_get_referer() : self::url();
		$is_ho    = ReservationAccess::all_branches( $user_id );
		include MOMOS_CORE_DIR . '/templates/admin/reservation-detail.php';
	}

	/** @return array{type:string,text:string}|null notice from the redirect after an action */
	private static function notice(): ?array {
		$ok  = isset( $_GET['momos_ok'] ) ? sanitize_key( (string) $_GET['momos_ok'] ) : ''; // phpcs:ignore
		$err = isset( $_GET['momos_err'] ) ? sanitize_key( (string) $_GET['momos_err'] ) : ''; // phpcs:ignore
		if ( '' !== $ok ) {
			$map = [
				'accept'             => __( 'Accepted — email queued to guest.', 'momos-core' ),
				'decline'            => __( 'Declined — email queued to guest.', 'momos-core' ),
				'cancel'             => __( 'Cancelled.', 'momos-core' ),
				'cancel_silent'      => __( 'Cancelled. No email was sent to the guest.', 'momos-core' ),
				'mark_completed'     => __( 'Marked as attended.', 'momos-core' ),
				'mark_no_show'       => __( 'Marked as no-show.', 'momos-core' ),
				'claim'              => __( 'You are now handling this request.', 'momos-core' ),
				'release'            => __( 'Released.', 'momos-core' ),
				'note'               => __( 'Internal note added.', 'momos-core' ),
				'customer_contacted' => __( 'Marked as contacted.', 'momos-core' ),
				'flag_resolved'      => __( 'Flag resolved.', 'momos-core' ),
				'modify'             => __( 'Details changed — email queued to guest.', 'momos-core' ),
			];
			return [ 'type' => 'success', 'text' => $map[ $ok ] ?? __( 'Saved.', 'momos-core' ) ];
		}
		if ( '' !== $err ) {
			$key = match ( $err ) {
				'version_conflict'   => 'staff.err.version_conflict',
				'invalid_transition' => 'staff.err.invalid_transition',
				'expired'            => 'staff.err.expired',
				'reason_required'    => 'staff.err.reason_required',
				'attendance_window'  => 'staff.err.attendance_window',
				'forbidden_field'    => 'staff.err.forbidden_field',
				'forbidden_branch'   => 'staff.err.forbidden_branch',
				'slot_unavailable'   => 'res.err.time.unavailable',
				'branch_not_accepting' => 'staff.err.branch_not_accepting',
				'party_size_out_of_range' => 'staff.err.party_size',
				'lead_time_not_met'  => 'staff.err.lead_time',
				'outside_booking_window' => 'staff.err.booking_window',
				default              => 'staff.err.invalid_param',
			};
			$status = isset( $_GET['momos_status'] ) ? sanitize_key( (string) $_GET['momos_status'] ) : ''; // phpcs:ignore
			$label  = \Momos\Reservation\Domain\ReservationStatus::tryFrom( $status );
			$text   = momos_t( $key, [ 'status' => $label ? $label->staffLabel() : $status, 'field' => __( 'Override rules', 'momos-core' ) ] );
			if ( isset( $_GET['momos_fields'] ) ) { // phpcs:ignore
				$text .= ' (' . sanitize_text_field( wp_unslash( (string) $_GET['momos_fields'] ) ) . ')'; // phpcs:ignore
			}
			return [ 'type' => 'error', 'text' => $text ];
		}
		return null;
	}

	/** admin-post handler for every SCR-A09 action (nonce + capability + scope). */
	public static function handle(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		check_admin_referer( 'momos_res_action_' . $id );
		$op      = isset( $_POST['op'] ) ? sanitize_key( (string) $_POST['op'] ) : '';
		$user_id = get_current_user_id();
		$row     = ReservationRepository::find( $id );
		if ( null === $row ) {
			wp_die( esc_html( momos_t( 'staff.err.not_found' ) ), 404 );
		}
		if ( ! ReservationAccess::can_process( $row, $user_id ) ) {
			ReservationAccess::deny( $id, (int) $row['branch_id'], 'forbidden_branch', 'SCR-A09' );
			wp_die( esc_html( momos_t( 'staff.err.forbidden_branch' ) ), 403 );
		}
		$p       = wp_unslash( $_POST );
		$version = isset( $p['version'] ) ? (int) $p['version'] : 0;
		$actor   = Actor::staff( $user_id );
		$ok      = $op;
		try {
			switch ( $op ) {
				case 'accept':
				case 'decline':
				case 'cancel':
				case 'mark_completed':
				case 'mark_no_show':
					$suggested = [];
					for ( $i = 1; $i <= 2; $i++ ) {
						$sd = trim( (string) ( $p[ 'suggest_date_' . $i ] ?? '' ) );
						$st = trim( (string) ( $p[ 'suggest_time_' . $i ] ?? '' ) );
						if ( '' !== $sd || '' !== $st ) {
							$suggested[] = [ 'date' => $sd, 'time' => \Momos\Reservation\Validation\Digits::normalize( $st ) ];
						}
					}
					$input = [
						'version'             => $version,
						'reason_code'         => isset( $p['reason_code'] ) ? sanitize_key( (string) $p['reason_code'] ) : null,
						'message_to_customer' => isset( $p['message_to_customer'] ) ? (string) $p['message_to_customer'] : null,
						'note'                => isset( $p['note'] ) ? (string) $p['note'] : null,
						'suggested_times'     => 'decline' === $op ? $suggested : [],
					];
					$res   = TransitionService::staff( $id, $op, $input, $actor );
					if ( 'cancel' === $op && ! $res['notifications_queued'] ) {
						$ok = 'cancel_silent';
					}
					break;
				case 'claim':
				case 'release':
				case 'note':
				case 'customer_contacted':
				case 'flag_resolved':
					NoteService::apply( $id, [ 'type' => $op, 'text' => isset( $p['text'] ) ? (string) $p['text'] : null, 'version' => $version ], $actor );
					break;
				case 'modify':
					$input = [
						'version'        => $version,
						'requested_date' => (string) ( $p['requested_date'] ?? '' ),
						'requested_time' => (string) ( $p['requested_time'] ?? '' ),
						'party_size'     => (string) ( $p['party_size'] ?? '' ),
						'reason'         => 'agreed_with_guest',
						'note'           => isset( $p['note'] ) && '' !== trim( (string) $p['note'] ) ? (string) $p['note'] : null,
					];
					if ( ! empty( $p['override_rules'] ) ) {
						$input['override_rules']  = true;
						$input['override_reason'] = (string) ( $p['override_reason'] ?? '' );
					}
					ModificationService::modify( $id, $input, $actor );
					break;
				default:
					wp_die( esc_html__( 'Unknown action.', 'momos-core' ), 400 );
			}
			wp_safe_redirect( self::url( [ 'id' => $id, 'momos_ok' => $ok ] ) );
		} catch ( ReservationError $e ) {
			$args = [ 'id' => $id, 'momos_err' => $e->error_code ];
			if ( 'invalid_transition' === $e->error_code ) {
				$args['momos_status'] = (string) ( $e->data['current']['status'] ?? '' );
				if ( 'staff.err.expired' === ( $e->data['message_key'] ?? '' ) ) {
					$args['momos_err'] = 'expired';
				}
			}
			if ( $e->fields ) {
				$args['momos_fields'] = implode( ', ', array_keys( $e->fields ) );
			}
			wp_safe_redirect( self::url( $args ) );
		}
		exit;
	}

	/**
	 * Heartbeat badge counts {pending, overdue} in scope (spec §7.1).
	 *
	 * @param array<string,mixed> $response
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function heartbeat( array $response, array $data ): array {
		if ( empty( $data['momos_res_badge'] ) || ! current_user_can( 'momo_view_reservations' ) ) {
			return $response;
		}
		$scope   = Scope::for_user( get_current_user_id() );
		$overdue = 0;
		foreach ( ReservationRepository::pending_light( $scope ) as $r ) {
			$overdue += 'overdue' === SlaClock::state( $r + [ 'status' => 'pending' ] ) ? 1 : 0;
		}
		$response['momos_res_badge'] = [ 'pending' => ReservationRepository::counts_by_status( $scope )['pending'] ?? 0, 'overdue' => $overdue ];
		return $response;
	}
}
