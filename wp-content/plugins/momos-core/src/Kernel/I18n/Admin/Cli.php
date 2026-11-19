<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n\Admin;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Strings;

/**
 * WP-CLI (i18n-seo-analytics.md §16): `wp momos i18n gaps [--lang=<code>] [--lccs]`,
 * `wp momos i18n strings-missing [--lang=<code>]`, `wp momos i18n strings-duplicates`.
 * Non-zero exit when gaps / missing keys / duplicates exist (CI gate).
 */
final class Cli {
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( \WP_CLI::class ) ) {
			return;
		}
		\WP_CLI::add_command( 'momos i18n gaps', [ self::class, 'gaps' ] );
		\WP_CLI::add_command( 'momos i18n strings-missing', [ self::class, 'strings_missing' ] );
		\WP_CLI::add_command( 'momos i18n strings-duplicates', [ self::class, 'strings_duplicates' ] );
	}

	/** @return list<string> */
	private static function langs( array $assoc ): array {
		$lang = isset( $assoc['lang'] ) ? sanitize_key( (string) $assoc['lang'] ) : '';
		if ( '' !== $lang && ( ! Lang::is_valid( $lang ) || Lang::SOURCE === $lang ) ) {
			\WP_CLI::error( 'Unknown target language.' );
		}
		return '' !== $lang ? [ $lang ] : Lang::targets();
	}

	/** @param list<string> $args @param array<string,string> $assoc */
	public static function gaps( array $args, array $assoc ): void {
		$total = 0;
		foreach ( self::langs( $assoc ) as $lang ) {
			$rows = TranslationGaps::rows( [ 'lang' => $lang, 'lccs' => isset( $assoc['lccs'] ) ] );
			$gate = TranslationGaps::lccs_gaps( $lang );
			\WP_CLI::log( sprintf( '%s: LCCS %d %% · %d launch-gate gaps · %d gap rows', $lang, TranslationGaps::lccs_percent( $lang ), count( $gate ), count( $rows ) ) );
			foreach ( $gate as $g ) {
				\WP_CLI::log( '  - ' . $g );
			}
			$total += count( $gate );
		}
		if ( $total > 0 ) {
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( 'No launch-gate gaps.' );
	}

	/** @param list<string> $args @param array<string,string> $assoc */
	public static function strings_missing( array $args, array $assoc ): void {
		$n = 0;
		foreach ( self::langs( $assoc ) as $lang ) {
			$missing = array_values( array_filter( Strings::missing_keys( $lang ), [ Strings::class, 'is_customer_key' ] ) );
			foreach ( $missing as $k ) {
				\WP_CLI::log( "{$lang}\t{$k}" );
			}
			$n += count( $missing );
		}
		if ( $n > 0 ) {
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( 'No customer-facing keys missing.' );
	}

	public static function strings_duplicates(): void {
		$n = 0;
		foreach ( Strings::duplicates() as $lang => $keys ) {
			foreach ( $keys as $key => $modules ) {
				\WP_CLI::log( "{$lang}\t{$key}\t" . implode( ',', $modules ) . "\towner=" . ( Strings::owner( $key ) ?? '-' ) );
				++$n;
			}
		}
		if ( $n > 0 ) {
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( 'No duplicate catalog keys.' );
	}
}
