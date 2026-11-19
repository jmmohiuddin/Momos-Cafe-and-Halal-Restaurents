<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/AccessTestCaseTest.php';

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Strings;

/**
 * String catalog kernel: precedence + duplicate detection, placeholders, per-key review status
 * (content-strings.md §2.1/§3.2, i18n-seo-analytics.md §6.2; TC-I18N-011, A22).
 */
final class I18nCatalogTest extends AccessTestCaseTest {
	public function set_up(): void {
		parent::set_up();
		Strings::flush();
	}

	public function tear_down(): void {
		Strings::flush();
		parent::tear_down();
	}

	/** A key must be defined by exactly one module file (fails when two catalog files define it). */
	public function test_no_key_is_defined_by_two_module_files(): void {
		$dups = Strings::duplicates();
		$msg  = '';
		foreach ( $dups as $lang => $keys ) {
			foreach ( $keys as $key => $modules ) {
				$msg .= "{$lang}: {$key} in " . implode( ', ', $modules ) . ' (owner: ' . ( Strings::owner( $key ) ?? 'none' ) . ")\n";
			}
		}
		$this->assertSame( [], $dups, "Duplicate catalog keys — keep the owning module's entry only:\n" . $msg );
	}

	public function test_owner_precedence_map(): void {
		$this->assertSame( 'reservation', Strings::owner( 'res.title' ) );
		$this->assertSame( 'reservation', Strings::owner( 'status.pending' ) );
		$this->assertSame( 'access', Strings::owner( 'staff.err.forbidden_branch' ) );
		$this->assertSame( 'notification', Strings::owner( 'staff.warn.customer_not_notified' ) );
		$this->assertSame( 'branch', Strings::owner( 'cta.directions' ), 'longest prefix wins' );
		$this->assertNull( Strings::owner( 'cta.view_menu' ) );
		$this->assertSame( 'reservation', Strings::group_of( 'res.title' ) );
		$this->assertSame( 'admin-content', Strings::group_of( 'admin.legal.publish_denied' ) );
	}

	public function test_six_catalogs_share_keys_and_drafts_are_marked(): void {
		foreach ( glob( MOMOS_CORE_DIR . '/languages/strings/en/*.php' ) as $file ) {
			$module = basename( $file );
			foreach ( Lang::codes() as $lang ) {
				$this->assertFileExists( MOMOS_CORE_DIR . "/languages/strings/{$lang}/{$module}", "{$lang}/{$module}" );
			}
			foreach ( [ 'ar', 'ur', 'hi', 'bn' ] as $lang ) {
				$this->assertStringContainsString( 'DRAFT', (string) file_get_contents( MOMOS_CORE_DIR . "/languages/strings/{$lang}/{$module}" ), "{$lang}/{$module} header" );
			}
		}
		foreach ( [ 'admin.legal.publish_denied', 'admin.translation.gaps_none' ] as $key ) {
			foreach ( Lang::codes() as $lang ) {
				$this->assertNotNull( Strings::file_value( $key, $lang ), "{$lang}: {$key}" );
			}
		}
	}

	public function test_placeholders_must_match_the_english_source(): void {
		$this->assertSame( [ 'n', 'name' ], Strings::placeholders( 'Hi {name}, {n} items for {name}' ) );
		$this->assertSame( [ [], [] ], Strings::placeholder_diff( 'admin.translation.gaps_none', 'لا شيء لـ {language}', 'ar' ) );
		$this->assertSame( [ [ 'language' ], [] ], Strings::placeholder_diff( 'admin.translation.gaps_none', 'لا شيء', 'ar' ) );
		$this->assertSame( [ [ 'language' ], [ 'lang' ] ], Strings::placeholder_diff( 'admin.translation.gaps_none', 'لا شيء {lang}', 'ar' ) );
		// Every shipped translation matches its EN placeholders. Allow-list: singular forms that spell the
		// number out (Arabic "one hour"); these become CLDR plural arrays later (content-strings §2.1).
		$allow = [ 'ar:email.duration.hour' ];
		$bad   = [];
		foreach ( Lang::codes() as $lang ) {
			foreach ( Strings::catalog( $lang ) as $key => $value ) {
				if ( null === Strings::file_value( $key, 'en' ) ) {
					continue;
				}
				[ $missing, $extra ] = Strings::placeholder_diff( $key, $value, $lang );
				if ( ( $missing || $extra ) && ! in_array( "{$lang}:{$key}", $allow, true ) ) {
					$bad[] = "{$lang}:{$key} missing[" . implode( ',', $missing ) . '] extra[' . implode( ',', $extra ) . ']';
				}
			}
		}
		$this->assertSame( [], $bad, implode( "\n", $bad ) );
	}

	public function test_review_status_lifecycle_and_filter(): void {
		$key = 'admin.translation.gaps_none';
		$rev = $this->user_with_role( 'momo_translator' );
		$this->assertSame( 'draft', Strings::status( $key, 'ar' ), 'seeds start as draft' );
		$this->assertSame( 'missing', Strings::status( 'no.such.key', 'ar' ) );
		$this->assertFalse( Strings::is_approved( $key, 'ar' ) );

		Strings::set_status( 'ar', [ $key ], 'approved', $rev );
		$this->assertTrue( Strings::is_approved( $key, 'ar' ) );
		$this->assertSame( $rev, (int) Strings::record( $key, 'ar' )['by'] );
		$this->assertFalse( Strings::is_outdated( $key, 'ar' ) );

		// The English reference changes → approval outdated (still approved).
		Strings::save_override( 'en', $key, 'Nothing left to translate for {language}' );
		$this->assertTrue( Strings::is_approved( $key, 'ar' ) );
		$this->assertTrue( Strings::is_outdated( $key, 'ar' ) );
		$this->assertSame( [], Strings::unapproved_keys( 'ar', 'admin.' ), 'admin keys are not customer-facing' );

		// The reviewed value itself changes → needs review again.
		Strings::save_override( 'ar', $key, 'لا توجد فجوات لـ {language}' );
		$this->assertSame( 'needs_review', Strings::status( $key, 'ar' ) );

		// Customer-facing keys feed unapproved_keys().
		$this->assertContains( 'nav.menu', Strings::unapproved_keys( 'ar', 'nav.' ) );
		Strings::set_status( 'ar', [ 'nav.menu' ], 'approved', $rev );
		$this->assertNotContains( 'nav.menu', Strings::unapproved_keys( 'ar', 'nav.' ) );
		$this->assertSame( [], array_filter( Strings::unapproved_keys( 'ar' ), static fn( $k ) => str_starts_with( $k, 'staff.' ) || str_starts_with( $k, 'admin.' ) ) );

		// Email checks can require approval; the filter lets the platform override.
		$f = static fn( $status, $k, $lang ) => 'email.x' === $k ? 'approved' : $status;
		add_filter( 'momos/strings/status', $f, 10, 3 );
		Strings::save_override( 'en', 'email.x', 'X' );
		$this->assertTrue( Strings::is_approved( 'email.x', 'en' ) );
		remove_filter( 'momos/strings/status', $f, 10 );
		$this->assertFalse( Strings::is_approved( 'email.x', 'en' ) );

		$this->expectException( \InvalidArgumentException::class );
		Strings::set_status( 'xx', [ $key ], 'approved' );
	}

	/**
	 * QA-049 / DEC-062 / FR-I18N-006: digits are always Western on output — in every catalog key,
	 * including the admin.* and staff.* keys (bn access.php once had "৬-অঙ্কের", "১২ থেকে ১২৮").
	 */
	public function test_every_catalog_value_uses_western_digits(): void {
		$hits = [];
		foreach ( Lang::codes() as $lang ) {
			foreach ( (array) glob( MOMOS_CORE_DIR . '/languages/strings/' . $lang . '/*.php' ) as $file ) {
				$part = include $file;
				foreach ( is_array( $part ) ? $part : [] as $key => $value ) {
					if ( is_string( $value ) && preg_match( '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0966}-\x{096F}\x{09E6}-\x{09EF}\x{FF10}-\x{FF19}]/u', $value, $m ) ) {
						$hits[] = $lang . '/' . basename( (string) $file ) . " {$key}: \"{$m[0]}\"";
					}
				}
			}
		}
		$this->assertSame( [], $hits );
	}

	public function test_lccs_and_review_class_prefixes(): void {
		$this->assertTrue( Strings::is_lccs_key( 'nav.menu' ) );
		$this->assertTrue( Strings::is_lccs_key( 'res.title' ) );
		$this->assertFalse( Strings::is_lccs_key( 'faq.empty' ) );
		$this->assertFalse( Strings::is_lccs_key( 'staff.err.forbidden_branch' ) );
		$this->assertFalse( Strings::is_customer_key( 'admin.legal.publish_denied' ) );
		$this->assertTrue( Strings::is_review_class( 'email.e01.subject' ) );
		$this->assertFalse( Strings::is_review_class( 'nav.menu' ) );
	}
}
