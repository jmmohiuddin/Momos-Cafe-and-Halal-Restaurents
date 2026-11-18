<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Db\Tables;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;
use Momos\Kernel\Support\Crockford;
use Momos\Kernel\Support\Hash;
use Momos\Tests\TestCase;

final class KernelTest extends TestCase {
	public function test_all_custom_tables_exist(): void {
		global $wpdb;
		foreach ( Tables::ALL as $short ) {
			$this->assertSame( Tables::name( $short ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Tables::name( $short ) ) ) );
		}
	}

	public function test_check_constraint_rejects_invalid_status(): void {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( Tables::reservations(), [
			'public_ref' => 'ABCD2345', 'idempotency_key' => wp_generate_uuid4(), 'branch_id' => 1, 'status' => 'booked',
			'requested_date' => '2026-12-01', 'requested_time' => '18:00:00', 'requested_start_utc' => '2026-12-01 09:00:00',
			'party_size' => 2, 'guest_name' => 'A', 'email' => 'a@example.org', 'preferred_lang' => 'en', 'source_lang' => 'en',
			'consent_version' => 'v1', 'consent_at' => '2026-11-01 00:00:00', 'token_hash' => str_repeat( 'a', 64 ),
			'token_expires_utc' => '2026-12-02 00:00:00', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
		] );
		$wpdb->suppress_errors( false );
		$this->assertFalse( $ok );
	}

	public function test_url_prefixes(): void {
		$home = untrailingslashit( get_option( 'home' ) );
		$this->assertSame( $home . '/menu/', Url::to( '/menu/', 'ja' ) );
		$this->assertSame( $home . '/ar/menu/', Url::to( '/menu/', 'ar' ) );
		$this->assertSame( $home . '/bn/branches/x/', Url::localize( $home . '/en/branches/x/', 'bn' ) );
		$this->assertSame( 'ur', Url::lang_of_path( '/ur/faq/' ) );
		$this->assertSame( '/faq/', Url::strip_prefix( '/hi/faq/' ) );
	}

	public function test_translation_visibility_requires_approval(): void {
		$id = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => '会社概要', 'post_status' => 'publish' ] );
		$this->assertTrue( Translations::post_visible( $id, 'ja' ) );
		$this->assertFalse( Translations::post_visible( $id, 'en' ) );
		Translations::save( 'post', $id, 'en', [ 'title' => 'About us' ], 'needs_review' );
		$this->assertSame( '', Translations::post( $id, 'title', 'en' ) );
		Translations::save( 'post', $id, 'en', [ 'title' => 'About us' ], 'approved' );
		$this->assertSame( 'About us', Translations::post( $id, 'title', 'en' ) );
		$this->assertFalse( Translations::is_outdated( 'post', $id, 'en' ) );
		wp_update_post( [ 'ID' => $id, 'post_title' => '私たちについて' ] );
		$this->assertTrue( Translations::is_outdated( 'post', $id, 'en' ) );
	}

	public function test_crockford_and_hash_helpers(): void {
		$ref = Crockford::random();
		$this->assertMatchesRegularExpression( '/^[0-9A-HJKMNP-TV-Z]{8}$/', $ref );
		$this->assertSame( 'ABCD-2345', Crockford::display( 'ABCD2345' ) );
		$this->assertSame( 'ABCD2345', Crockford::normalize( 'abcd-2345' ) );
		$this->assertSame( '203.0.113.0/24', Hash::truncate_ip( '203.0.113.77' ) );
		$this->assertSame( '2001:db8:1234::/48', Hash::truncate_ip( '2001:db8:1234:5678::1' ) );
	}

	public function test_field_registry_validates_and_saves(): void {
		register_post_type( 'momo_test', [ 'public' => false ] );
		FieldRegistry::group( [
			'key'       => 'group_momo_test',
			'post_type' => 'momo_test',
			'fields'    => [
				[ 'name' => 'momo_code', 'type' => 'text', 'pattern' => '^[A-Z]{3}$' ],
				[ 'name' => 'momo_n', 'type' => 'number', 'min' => 1, 'max' => 5 ],
				[ 'name' => 'momo_rows', 'type' => 'repeater', 'sub_fields' => [ [ 'name' => 'email', 'type' => 'email', 'required' => true ] ] ],
			],
		] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$id = self::factory()->post->create( [ 'post_type' => 'momo_test' ] );
		FieldRegistry::save_group_values( 'post', $id, FieldRegistry::get_group( 'group_momo_test' ), [
			'momo_code' => 'abc', 'momo_n' => '9', 'momo_rows' => [ [ 'email' => 'Staff@Example.org' ] ],
		] );
		$this->assertSame( '', get_post_meta( $id, 'momo_code', true ) );
		$this->assertSame( '', get_post_meta( $id, 'momo_n', true ) );
		$this->assertSame( [ [ 'email' => 'staff@example.org' ] ], get_post_meta( $id, 'momo_rows', true ) );
		$this->assertNotEmpty( FieldRegistry::last_errors() );
	}
}
