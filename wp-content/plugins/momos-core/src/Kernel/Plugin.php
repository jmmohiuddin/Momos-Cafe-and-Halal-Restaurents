<?php
declare(strict_types=1);

namespace Momos\Kernel;

use Momos\Kernel\Migration\Runner;

/**
 * Plugin kernel: boots the shared infrastructure and every business module.
 *
 * Modules implement ModuleInterface and are listed in MODULES. A module class that does not
 * exist yet is skipped, so modules can be delivered incrementally.
 */
final class Plugin {

	/** Ordered: infrastructure-like modules first, presentation last. */
	public const MODULES = [
		\Momos\Kernel\I18n\I18nModule::class,
		\Momos\Access\AccessModule::class,
		\Momos\Audit\AuditModule::class,
		\Momos\Branch\BranchModule::class,
		\Momos\Menu\MenuModule::class,
		\Momos\Dietary\DietaryModule::class,
		\Momos\Reservation\ReservationModule::class,
		\Momos\Notification\NotificationModule::class,
		\Momos\Api\ApiModule::class,
		\Momos\Privacy\PrivacyModule::class,
		\Momos\Seo\SeoModule::class,
		\Momos\Presentation\PresentationModule::class,
		\Momos\Admin\AdminModule::class,
		\Momos\Admin\Content\ContentModule::class,
		\Momos\Seed\SeedModule::class,
	];

	private static ?Plugin $instance = null;

	/** @var array<class-string, ModuleInterface> */
	private array $modules = [];

	private bool $booted = false;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		foreach ( self::MODULES as $class ) {
			if ( class_exists( $class ) ) { // Every listed class implements ModuleInterface; a missing one is skipped.
				$this->modules[ $class ] = new $class();
			}
		}
		/**
		 * Allows tests/extensions to add modules (Phase 2 seams, DEC-056).
		 *
		 * @param array<class-string, ModuleInterface> $modules
		 */
		$this->modules = apply_filters( 'momos/modules', $this->modules );

		foreach ( $this->modules as $module ) {
			$module->register();
		}

		// Media-library upload allow-list and size limit for every role (SEC-028, FR-ADM-005).
		Media\UploadPolicy::register();

		// Run pending migrations once per version (cheap option check on every request).
		add_action( 'init', [ Runner::class, 'maybe_run' ], 1 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Commands::register();
		}
	}

	/** @return array<class-string, ModuleInterface> */
	public function modules(): array {
		return $this->modules;
	}

	public function module( string $class ): ?ModuleInterface {
		return $this->modules[ $class ] ?? null;
	}
}
