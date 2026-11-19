<?php
declare(strict_types=1);

namespace Momos\Reservation\Admin;

use Momos\Branch\Repository\BranchRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Reservation\Domain\Scope;
use Momos\Reservation\Service\ReportService;

/**
 * SCR-A17 reservation report (F-RES-12, FR-RES-022): `momo_view_reservation_reports` with branch scope
 * (BM: assigned branches; RS: no access). No PII anywhere on the screen; TEST branch excluded.
 */
final class ReportPage {
	public const SLUG = 'momos-reservation-report';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 21 );
	}

	public static function menu(): void {
		$hook = add_submenu_page( InboxPage::SLUG, __( 'Reservation report', 'momos-core' ), __( 'Report', 'momos-core' ), 'momo_view_reservation_reports', self::SLUG, [ self::class, 'render' ] );
		if ( $hook ) {
			add_action( 'load-' . $hook, [ self::class, 'preload' ] );
		}
	}

	/** Before any output: a foreign branch filter → 403 (TC-ACL-003). */
	public static function preload(): void {
		if ( current_user_can( 'momo_view_reservation_reports' ) ) {
			InboxPage::forbid_foreign_branch_filter( 'SCR-A17' );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'momo_view_reservation_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		Locale::set( Locale::admin() );
		$scope    = Scope::for_user( get_current_user_id() );
		$branches = array_values( array_filter( InboxPage::scope_branches( $scope ), static fn( $b ) => ! $b['is_test'] ) );
		$get      = static fn( string $k ): string => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) ) : ''; // phpcs:ignore
		$valid    = static fn( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && false !== \DateTimeImmutable::createFromFormat( '!Y-m-d', $d );
		$to       = $valid( $get( 'date_to' ) ) ? $get( 'date_to' ) : Clock::jst()->format( 'Y-m-d' );
		$from     = $valid( $get( 'date_from' ) ) ? $get( 'date_from' ) : Clock::jst()->modify( '-27 days' )->format( 'Y-m-d' );
		$basis    = 'requested' === $get( 'basis' ) ? 'requested' : 'created';
		$lang     = Lang::is_valid( $get( 'lang' ) ) ? $get( 'lang' ) : '';
		$branch   = $get( 'branch' );
		$ids      = null;
		$error    = null;
		if ( '' !== $branch ) {
			$b = InboxPage::branch_by_token( $branch );
			if ( ! $b || $b['is_test'] || ! $scope->allows( (int) $b['id'] ) ) {
				\Momos\Reservation\Service\ReservationAccess::deny( null, $b ? (int) $b['id'] : null, 'forbidden_branch', 'SCR-A17' );
				$error = momos_t( 'staff.err.forbidden_branch' );
				$ids   = [];
			} else {
				$ids    = [ (int) $b['id'] ];
				$branch = (string) $b['code'];
			}
		}
		$report = ReportService::build( $scope, [ 'date_from' => min( $from, $to ), 'date_to' => max( $from, $to ), 'basis' => $basis, 'branch_ids' => $ids, 'lang' => '' !== $lang ? $lang : null ] );
		include MOMOS_CORE_DIR . '/templates/admin/reservation-report.php';
	}
}
