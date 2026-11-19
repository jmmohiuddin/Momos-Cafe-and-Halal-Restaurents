<?php
declare(strict_types=1);

namespace Momos\Seo;

/**
 * SEO/analytics technical settings and capabilities (i18n-seo-analytics.md §3.3, §13).
 *
 * Option `momos_seo_settings` (SA only): `faq_schema` (FAQPage JSON-LD, C), `log_retention_days`
 * (404 log). GTM container: constant `MOMOS_GTM_ID` (per environment) wins, else the G3 setting
 * `momo_gtm_container_id`; invalid or absent ⇒ no GTM at all.
 *
 * [IMPL] capabilities not yet in config/capabilities.php are mapped here (kernel request filed):
 *  - `momo_manage_redirects` (SA, HO)  → `momo_manage_brand` (granted to exactly SA, HO);
 *  - `momo_edit_seo_meta` (SA, HO, MM, CE) → `momo_access_all_branches` (granted to exactly SA, HO, MM, CE).
 * Once the role table grants either cap as a primitive, that grant is used instead of the mapping.
 */
final class SeoSettings {
	public const OPTION = 'momos_seo_settings';

	public const DEFAULTS = [
		'faq_schema'         => true,
		'log_retention_days' => 90,
	];

	public const GTM_PATTERN = '/^GTM-[A-Z0-9]{4,10}$/';

	public static function register(): void {
		add_filter( 'map_meta_cap', [ self::class, 'map_caps' ], 10, 4 );
	}

	/** @return array{faq_schema:bool,log_retention_days:int} */
	public static function all(): array {
		$raw = get_option( self::OPTION, [] );
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'faq_schema'         => (bool) ( $raw['faq_schema'] ?? self::DEFAULTS['faq_schema'] ),
			'log_retention_days' => max( 7, min( 730, (int) ( $raw['log_retention_days'] ?? self::DEFAULTS['log_retention_days'] ) ) ),
		];
	}

	/** @param array{faq_schema?:mixed,log_retention_days?:mixed} $values */
	public static function save( array $values ): array {
		$before = self::all();
		$after  = [
			'faq_schema'         => ! empty( $values['faq_schema'] ),
			'log_retention_days' => max( 7, min( 730, (int) ( $values['log_retention_days'] ?? $before['log_retention_days'] ) ) ),
		];
		update_option( self::OPTION, $after, true );
		return [ 'before' => $before, 'after' => $after ];
	}

	public static function faq_schema(): bool {
		return self::all()['faq_schema'];
	}

	public static function retention_days(): int {
		return self::all()['log_retention_days'];
	}

	/** Valid GTM container ID for this environment, or '' (no GTM). */
	public static function gtm_id(): string {
		$id = defined( 'MOMOS_GTM_ID' ) ? (string) MOMOS_GTM_ID : '';
		if ( '' === $id && class_exists( \Momos\Kernel\Settings::class ) ) {
			$id = (string) \Momos\Kernel\Settings::get( 'momo_gtm_container_id', '' );
		}
		$id = strtoupper( trim( $id ) );
		return preg_match( self::GTM_PATTERN, $id ) ? $id : '';
	}

	/**
	 * @param list<string> $caps
	 * @param array<int,mixed> $args
	 * @return list<string>
	 */
	public static function map_caps( array $caps, string $cap, int $user_id, array $args ): array {
		if ( 'momo_manage_redirects' === $cap && ! self::has_primitive( $user_id, $cap ) ) {
			return [ 'momo_manage_brand' ];
		}
		if ( 'momo_edit_seo_meta' === $cap && ! self::has_primitive( $user_id, $cap ) ) {
			return [ 'momo_access_all_branches' ];
		}
		return $caps;
	}

	private static function has_primitive( int $user_id, string $cap ): bool {
		$user = get_userdata( $user_id );
		return $user instanceof \WP_User && ! empty( $user->allcaps[ $cap ] );
	}
}
