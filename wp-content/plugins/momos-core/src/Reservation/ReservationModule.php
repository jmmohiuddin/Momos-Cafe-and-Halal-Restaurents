<?php
declare(strict_types=1);

namespace Momos\Reservation;

use Momos\Kernel\ModuleInterface;

/**
 * Reservation requests (docs/specs/reservation.md, F-RES-01..12).
 *
 * Public services: Repository\ReservationRepository (find, find_by_ref), Service\TokenService
 * (status_url), Service\SlotGenerator, Service\SubmissionService, Service\TransitionService,
 * Service\ModificationService, Service\NoteService, Service\ExpiryService.
 * Domain hooks fired after commit (docs/CONTRACTS.md): momos/reservation/created,
 * momos/reservation/status_changed, momos/reservation/modified, momos/reservation/sla_reminder,
 * momos/reservation/sla_escalated, momos/reservation/closure_conflict (+ momos/reservation/token_reissued).
 * Consumes: momos/branch/schedule_changed, momos/branch/accepting_changed, momos/notification/failed.
 */
final class ReservationModule implements ModuleInterface {
	public function register(): void {
		Job\ExpiryJob::register();
		Job\SlaJob::register();
		Service\FlagService::register();
		add_filter( 'map_meta_cap', [ self::class, 'map_meta_cap' ], 10, 4 );
		add_action( 'momos/branch/schedule_changed', [ self::class, 'purge_rules_cache' ], 20, 1 );
		add_action( 'momos/branch/settings_changed', [ self::class, 'purge_rules_cache' ], 20, 1 );
		add_action( 'momos/cli', [ Cli\TokenCommand::class, 'register' ] );
		if ( is_admin() ) {
			Admin\InboxPage::register();
			Admin\ReportPage::register();
			Admin\ExportHandler::register();
		}
		add_filter( 'heartbeat_received', [ Admin\InboxPage::class, 'heartbeat' ], 10, 2 );
	}

	/**
	 * @param list<string>     $caps
	 * @param array<int,mixed> $args
	 * @return list<string>
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		return Service\ReservationAccess::map_meta_cap( $caps, $cap, $user_id, $args );
	}

	/** API-01 is cached for 5 min at the edge: purge on schedule/settings changes. */
	public static function purge_rules_cache( int $branch_id ): void {
		$b = \Momos\Branch\Repository\BranchRepository::get( $branch_id );
		if ( $b && '' !== $b['code'] ) {
			do_action( 'momos/cache/purge', [ 'reservation-rules:' . $b['code'], rest_url( 'momos/v1/branches/' . $b['code'] . '/reservation-rules' ) ] );
		}
	}
}
