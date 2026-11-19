<?php
declare(strict_types=1);

namespace Momos\Menu;

use Momos\Kernel\Jobs\Schedule;
use Momos\Kernel\ModuleInterface;

/**
 * Menu module (F-MENU-01..05; spec docs/specs/menu-dietary.md): dish CPT + taxonomies, G8/G9/G11/G13
 * field groups, publish validation and lifecycle, menu URLs, branch menu items (SCR-A07, API-14/15),
 * sold-out reset job, price resolution and public menu queries. Hooks only.
 */
final class MenuModule implements ModuleInterface {
	public function register(): void {
		add_action( 'init', [ ContentTypes::class, 'register' ], 5 );
		add_action( 'init', [ Fields\MenuFields::class, 'register' ], 6 );
		add_filter( 'use_block_editor_for_post_type', [ ContentTypes::class, 'disable_block_editor' ], 10, 2 );
		add_filter( 'rest_' . ContentTypes::DISH . '_query', [ ContentTypes::class, 'exclude_samples' ] );
		add_filter( 'wp_sitemaps_posts_query_args', [ ContentTypes::class, 'exclude_samples' ], 10, 2 );

		// FR-MENU-005(a): category reorder audit action (CTO change control, Audit\Catalogue).
		add_filter( 'momos/audit/catalogue', static fn( array $list ): array => array_merge( $list, [ Admin\CategoryOrderPage::AUDIT ] ) );

		Rewrite\MenuRewrite::register();
		Service\DishPublishValidator::register();
		Service\DescriptionLimit::register();
		Service\DishLifecycle::register();
		Service\CategoryGuard::register();

		Schedule::recurring( Job\SoldOutResetJob::HOOK, Job\SoldOutResetJob::INTERVAL );
		add_action( Job\SoldOutResetJob::HOOK, [ Job\SoldOutResetJob::class, 'run' ] );

		add_action( 'rest_api_init', [ Rest\BranchMenuController::class, 'routes' ] );

		if ( is_admin() ) {
			Admin\BranchMenuGrid::register();
			Admin\DishAdmin::register();
			Admin\CategoryOrderPage::register();
		}
	}
}
