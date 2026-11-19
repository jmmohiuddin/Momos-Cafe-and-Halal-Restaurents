<?php
declare(strict_types=1);

namespace Momos\Presentation;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\ModuleInterface;

/**
 * Presentation module (SDD §2.1; ui-design-system.md §0, §8): asset loading, body classes, template
 * routing, the component renderer and view models for the `momos` theme. Contains no business rules —
 * all facts come from the Branch/Menu/Dietary/Reservation services (DEC-008).
 */
final class PresentationModule implements ModuleInterface {
	public function register(): void {
		Assets::register();
		Templates\TemplateRouter::register();
		Contact\ContactForm::register();
		add_action( 'wp_head', [ View\AnalyticsHooks::class, 'page_data' ], 3 );
		add_filter( 'body_class', [ self::class, 'body_class' ] );
		add_action( 'wp', [ self::class, 'prime_options' ], 0 );
		add_filter(
			'momos/migrations',
			static function ( array $list ): array {
				$list[] = new Migration\M0007_SystemPages();
				$list[] = new Migration\M0007_SystemPagesR2();
				$list[] = new Migration\M0008_UnpublishWpDefaults();
				return $list;
			}
		);
		add_action( 'update_option_' . SystemPages::OPTION, [ SystemPages::class, 'flush' ] );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'cli_init', [ self::class, 'cli' ] );
		}
	}

	/**
	 * `<body class="momo-page momo-page--{type} momo-lang-{code} momo-dir-{dir}">` (TK-08-02).
	 *
	 * @param list<string> $classes
	 * @return list<string>
	 */
	public static function body_class( array $classes ): array {
		if ( is_admin() ) {
			return $classes;
		}
		$lang      = Locale::current();
		$type      = PageContext::page_type();
		$classes[] = 'momo-page';
		$classes[] = 'momo-page--' . sanitize_html_class( str_replace( '_', '-', $type ) );
		$classes[] = 'momo-lang-' . $lang;
		$classes[] = 'momo-dir-' . ( Lang::is_rtl( $lang ) ? 'rtl' : 'ltr' );
		if ( ! PageContext::hides_action_bar( $type ) ) {
			$classes[] = 'momo-has-bar';
		}
		return array_values( array_unique( $classes ) );
	}

	/**
	 * Options that the frame and cards read on every customer page but that are not autoloaded (or absent, so
	 * WordPress looks each one up): load them with one query instead of one each (NFR-PERF-02, TRD §5 ≤ 60
	 * queries per uncached page). Pure cache warm-up; owners keep reading them with get_option().
	 */
	public const PRIME_OPTIONS = [
		'momos_strings_overrides',
		'momos_languages_enabled',
		'site_logo',
		'can_compress_scripts',
		'momo_res_global_enabled',
		'momo_min_decision_window_minutes',
		'momo_allowed_recipient_domains',
		'momo_recipient_blocked_domains',
	];

	public static function prime_options(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		/** @var list<string> $names Filter `momos/presentation/prime_options`: option names read on customer pages. */
		$names = (array) apply_filters( 'momos/presentation/prime_options', self::PRIME_OPTIONS );
		wp_prime_option_caches( array_values( array_filter( $names, 'is_string' ) ) );
	}

	public static function cli(): void {
		\WP_CLI::add_command(
			'momos maintenance-page',
			/**
			 * Export the static SCR-C20 maintenance drop-in for all enabled languages.
			 *
			 * ## OPTIONS
			 *
			 * [--path=<file>]
			 * : Target file. Default: wp-content/themes/momos/static/maintenance.php
			 */
			static function ( array $args, array $assoc ): void {
				$path = (string) ( $assoc['path'] ?? get_theme_file_path( 'static/maintenance.php' ) );
				if ( Maintenance\MaintenancePage::export( $path ) ) {
					\WP_CLI::success( "Maintenance page written to {$path}" );
				} else {
					\WP_CLI::error( "Cannot write {$path}" );
				}
			}
		);
	}
}
