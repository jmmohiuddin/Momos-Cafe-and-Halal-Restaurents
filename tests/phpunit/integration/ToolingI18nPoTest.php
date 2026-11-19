<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Tests\TestCase;

/**
 * Japanese admin translation of momos-core (ENGINEERING.md §3: admin strings are gettext, text domain
 * `momos-core`; NFR-MNT-01 CI gate). Guards the committed catalogs:
 *  - momos-core-ja.po translates every entry of momos-core.pot (no untranslated / fuzzy admin string);
 *  - printf placeholders and HTML tags of every translation match the source;
 *  - the compiled .mo carries exactly the .po translations (no stale binary);
 *  - the theme header catalog (themes/momos/languages/ja.po) is complete as well.
 * Regenerate with `composer i18n:pot` / `composer i18n:mo` (see composer.json).
 */
final class ToolingI18nPoTest extends TestCase {
	private const DIR = MOMOS_CORE_DIR . '/languages/';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once ABSPATH . WPINC . '/pomo/po.php';
		require_once ABSPATH . WPINC . '/pomo/mo.php';
	}

	private static function po( string $file ): \PO {
		$po = new \PO();
		self::assertTrue( $po->import_from_file( $file ), "Cannot parse {$file}" );
		return $po;
	}

	public function test_every_pot_entry_has_a_japanese_translation(): void {
		$pot     = self::po( self::DIR . 'momos-core.pot' );
		$ja      = self::po( self::DIR . 'momos-core-ja.po' );
		$missing = [];
		foreach ( $pot->entries as $key => $entry ) {
			$t = $ja->entries[ $key ] ?? null;
			if ( null === $t || [] === array_filter( $t->translations, 'strlen' ) || in_array( 'fuzzy', $t->flags, true ) ) {
				$missing[] = $entry->singular;
			}
		}
		$this->assertGreaterThan( 1000, count( $pot->entries ), 'momos-core.pot looks truncated.' );
		$this->assertSame( [], $missing, "Untranslated admin strings in momos-core-ja.po:\n" . implode( "\n", $missing ) );
	}

	public function test_placeholders_and_tags_match_the_source(): void {
		$ja  = self::po( self::DIR . 'momos-core-ja.po' );
		$bad = [];
		foreach ( $ja->entries as $entry ) {
			$sources = array_filter( [ $entry->singular, $entry->plural ] );
			foreach ( $entry->translations as $t ) {
				$ok = false;
				foreach ( $sources as $src ) {
					if ( self::placeholders( $src ) === self::placeholders( $t ) && self::tags( $src ) === self::tags( $t ) ) {
						$ok = true;
					}
				}
				if ( ! $ok ) {
					$bad[] = $entry->singular . ' => ' . $t;
				}
			}
		}
		$this->assertSame( [], $bad, "Placeholder/tag mismatch in momos-core-ja.po:\n" . implode( "\n", $bad ) );
	}

	public function test_compiled_mo_matches_po(): void {
		$po = self::po( self::DIR . 'momos-core-ja.po' );
		$mo = new \MO();
		$this->assertTrue( $mo->import_from_file( self::DIR . 'momos-core-ja.mo' ) );
		$stale = [];
		foreach ( $po->entries as $key => $entry ) {
			if ( ( $mo->entries[ $key ]->translations ?? null ) !== $entry->translations ) {
				$stale[] = $entry->singular;
			}
		}
		$this->assertSame( [], $stale, "momos-core-ja.mo is stale; run composer i18n:mo:\n" . implode( "\n", $stale ) );
		$this->assertSame( count( $po->entries ), count( $mo->entries ) );
	}

	public function test_mo_translates_admin_menu_labels(): void {
		unload_textdomain( 'momos-core' );
		$GLOBALS['momos_tests_load_ja'] = true;
		$this->assertTrue( load_textdomain( 'momos-core', self::DIR . 'momos-core-ja.mo', 'ja' ) );
		try {
			$this->assertSame( '監査ログ', __( 'Audit log', 'momos-core' ) );
			$this->assertSame( '予約リクエスト', __( 'Reservation requests', 'momos-core' ) );
			$this->assertSame( '店舗別メニュー', __( 'Branch menus', 'momos-core' ) );
		} finally {
			unload_textdomain( 'momos-core' );
			unset( $GLOBALS['momos_tests_load_ja'] );
		}
	}

	public function test_theme_header_catalog_is_complete(): void {
		$dir = get_theme_root() . '/momos/languages/';
		$pot = self::po( $dir . 'momos.pot' );
		$ja  = self::po( $dir . 'ja.po' );
		foreach ( $pot->entries as $key => $entry ) {
			$this->assertNotSame( '', (string) ( $ja->entries[ $key ]->translations[0] ?? '' ), $entry->singular );
		}
		$this->assertFileExists( $dir . 'ja.mo' );
	}

	/** @return list<string> printf conversions, positional index stripped, sorted */
	private static function placeholders( string $s ): array {
		preg_match_all( '/%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/', $s, $m );
		$out = array_map( static fn( string $p ): string => (string) preg_replace( '/^%\d+\$/', '%', $p ), $m[0] );
		sort( $out );
		return $out;
	}

	/** @return list<string> opening/closing HTML tag names, sorted */
	private static function tags( string $s ): array {
		preg_match_all( '#</?[a-z][a-z0-9]*#i', $s, $m );
		$out = array_map( 'strtolower', $m[0] );
		sort( $out );
		return $out;
	}
}
