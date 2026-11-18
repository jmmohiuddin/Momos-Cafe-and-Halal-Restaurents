<?php
declare(strict_types=1);

namespace Momos\Branch;

use Momos\Kernel\ModuleInterface;

/**
 * Branch module (docs/specs/branch.md): branches, hours & closures, notices, offers.
 *
 * Public services: Repository\BranchRepository, Repository\BranchSettingsRepository,
 * Service\ScheduleService, Query\OfferQuery, Query\NoticeQuery, Service\ClosureImpactService.
 * Domain hooks (after commit): momos/branch/schedule_changed, momos/branch/settings_changed,
 * momos/branch/identity_changed, momos/branch/accepting_changed, momos/offer/visibility_changed.
 * Cache purges are requested with `do_action( 'momos/cache/purge', list<string> $tags )`.
 */
final class BranchModule implements ModuleInterface {
	public function register(): void {
		PostType\PostTypes::register();
		PostType\BranchScopeTaxonomy::register();
		Fields\BranchFields::register();
		Fields\NoticeFields::register();
		Fields\OfferFields::register();
		Service\BranchAccess::register();
		Service\BranchPublishValidator::register();
		Service\BranchGuard::register();
		Service\BranchVisibility::register();
		Service\ValidityMeta::register();
		Service\OfferScopeGuard::register();
		Service\NoticeService::register();
		Service\OfferService::register();
		Job\OfferExpiryJob::register();
		Job\ScheduledPurgeJob::register();
		Job\IntegrityJob::register();
		if ( is_admin() ) {
			Admin\HoursEditorPage::register();
			Admin\HoursFormHandler::register();
			Admin\ListScreens::register();
		}
	}
}
