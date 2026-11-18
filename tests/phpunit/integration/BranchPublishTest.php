<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/BranchTestCaseTest.php';

use Momos\Branch\Fields\BranchFields;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Service\BranchGuard;
use Momos\Branch\Service\BranchPublishValidator;
use Momos\Kernel\Db\Tables;

/**
 * Branch identity, GATE-01, code immutability, status transitions, TEST branch, delete guard:
 * TC-BR-001, TC-BR-007, TC-BR-014, TC-RSA-023, AC-BR-03-02..05, AC-BR-01-01.
 */
final class BranchPublishTest extends BranchTestCaseTest {
	public function test_publish_requires_facts_confirmation_gate_01(): void {
		$id = $this->make_branch( 'QAD', [ 'momo_facts_confirmed_by' => '', 'momo_facts_confirmed_at' => '' ] );
		$this->assertSame( 'draft', get_post_status( $id ), 'first publication downgraded to draft' );
		$this->assertContains( "Record the owner's written confirmation before publishing (GATE-01).", \Momos\Kernel\Fields\FieldRegistry::last_errors() );
		update_post_meta( $id, 'momo_facts_confirmed_by', 'Owner' );
		update_post_meta( $id, 'momo_facts_confirmed_at', '2026-11-01' );
		wp_update_post( [ 'ID' => $id, 'post_status' => 'publish' ] );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertNotSame( '', get_post_meta( $id, 'momo_first_published_at', true ) );
	}

	public function test_publish_creates_scope_term_and_requires_valid_data(): void {
		$id   = $this->make_branch( 'QAA' );
		$term = BranchScopeTaxonomy::term_for_branch( $id );
		$this->assertNotNull( $term );
		$this->assertSame( 'qaa', $term->slug );
		$errors = BranchPublishValidator::errors( [ 'momo_branch_code' => 'qa1', 'momo_lat' => '34.7', 'momo_lng' => '135.495951', 'momo_phone_e164' => '0600000000', 'momo_facts_confirmed_by' => 'O', 'momo_facts_confirmed_at' => '2026-11-01' ], [ 'post_title' => 'X', 'post_name' => 'Bad Slug' ], 0 );
		$this->assertContains( 'Branch code must be 3 capital letters and unique.', $errors );
		$this->assertContains( 'Enter latitude and longitude with at least 5 decimal places.', $errors );
		$this->assertContains( 'Enter a valid phone number.', $errors );
		$this->assertContains( 'Use lowercase letters, numbers and hyphens; this slug is already used.', $errors );
		$this->assertNotNull( BranchPublishValidator::code_error( 'QAA', 0 ), 'code must be unique' );
	}

	public function test_code_immutable_after_publication_and_reservations_ac_br_03_05(): void {
		$id = $this->make_branch( 'QAA' );
		$this->assertSame( 'The branch code cannot be changed after publication.', BranchFields::validate_code( 'QAB', $id ) );
		$draft = $this->make_branch( 'QAF', [], 'draft' );
		$this->assertNull( BranchFields::validate_code( 'QAG', $draft ) );
		$this->make_reservation( $draft, '2026-12-24', '19:00', 'pending' );
		$this->assertSame( 'The branch code cannot be changed after reservations exist.', BranchFields::validate_code( 'QAG', $draft ) );
	}

	public function test_phone_and_input_normalisation(): void {
		$this->assertSame( '+81600000000', BranchGuard::normalize_phone( '０６－００００－００００' ) );
		$this->assertSame( '+819012345678', BranchGuard::normalize_phone( '090-1234-5678' ) );
		$this->assertNull( BranchGuard::normalize_phone( '12345' ) );
		$this->assertSame( '06-0000-0000', BranchGuard::display_phone( '+81600000000' ) );
		$this->assertSame( '090-1234-5678', BranchGuard::display_phone( '+819012345678' ) );
		$f = BranchGuard::normalize_fields( [ 'momo_branch_code' => 'ｓｈｎ', 'momo_postal_code' => '５３００００１', 'momo_phone_e164' => '06 0000 0000', 'momo_phone_display' => '' ] );
		$this->assertSame( 'SHN', $f['momo_branch_code'] );
		$this->assertSame( '530-0001', $f['momo_postal_code'] );
		$this->assertSame( '+81600000000', $f['momo_phone_e164'] );
		$this->assertSame( '06-0000-0000', $f['momo_phone_display'] );
	}

	public function test_identity_change_on_published_branch_needs_recent_confirmation(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$id   = $this->make_branch( 'QAA', [ 'momo_facts_confirmed_at' => '2026-10-01' ] );
		$base = [ 'momo_branch_code' => 'QAA', 'momo_lat' => '34.702485', 'momo_lng' => '135.495951', 'momo_phone_e164' => '+81600000001', 'momo_facts_confirmed_by' => 'Owner' ];
		$data = [ 'post_title' => get_the_title( $id ), 'post_name' => 'qaa' ];
		$this->assertContains( BranchPublishValidator::gate_message(), BranchPublishValidator::errors( $base + [ 'momo_facts_confirmed_at' => '2026-10-01' ], $data, $id ) );
		$this->assertNotContains( BranchPublishValidator::gate_message(), BranchPublishValidator::errors( $base + [ 'momo_facts_confirmed_at' => '2026-12-01' ], $data, $id ) );
	}

	public function test_test_branch_hidden_from_public_reads_fr_res_026(): void {
		$a   = $this->make_branch( 'QAA' );
		$tst = $this->make_branch( 'TST', [ 'momo_is_test_branch' => '1' ] );
		$c   = $this->make_branch( 'QAZ', [ 'momo_operating_status' => 'closed_permanently' ] );
		$y   = $this->make_branch( 'QAY', [ 'momo_operating_status' => 'temporarily_closed' ] );
		$this->assertSame( [ $a, $y, $c ], BranchRepository::published_ids(), "ordered by menu_order, then title" );
		$this->assertNotContains( $tst, BranchRepository::published_ids() );
		$this->assertContains( $tst, BranchRepository::published_ids( true ) );
		$public = BranchRepository::public_ids( 'ja' );
		$this->assertContains( $a, $public );
		$this->assertContains( $y, $public );
		$this->assertNotContains( $c, $public );
		$this->assertNotContains( $tst, $public );
		$this->assertNull( BranchRepository::find_by_code( 'TST' ) );
		$this->assertSame( $tst, BranchRepository::find_by_code( 'tst', true )['id'] );
		$this->assertSame( [], BranchRepository::public_ids( 'en' ), 'untranslated branches are hidden in en (BR-I18N-02)' );
		\Momos\Kernel\I18n\Translations::save( 'post', $a, 'en', [ 'title' => 'Branch QAA' ], 'approved' );
		$this->assertSame( [ $a ], BranchRepository::public_ids( 'en' ) );
		$this->assertSame( 'Branch QAA', BranchRepository::name( $a, 'en' ) );
		$q = new \WP_Query( [ 'post_type' => 'momo_branch', 'posts_per_page' => 10, 'fields' => 'ids' ] );
		$this->assertNotContains( $tst, $q->posts, 'front-end queries never list TEST' );
		$this->assertNotContains( $c, $q->posts );
	}

	public function test_contract_shape_of_branch_repository_get(): void {
		$id = $this->make_branch( 'QAA' );
		$b  = BranchRepository::get( $id );
		foreach ( [ 'id', 'code', 'name', 'slug', 'status', 'operating_status', 'is_test', 'phone_e164', 'phone_display', 'email', 'address_ja', 'lat', 'lng', 'concept' ] as $k ) {
			$this->assertArrayHasKey( $k, $b );
		}
		$this->assertSame( 'QAA', $b['code'] );
		$this->assertSame( 34.702485, $b['lat'] );
		$this->assertNull( BranchRepository::get( self::factory()->post->create() ) );
	}

	public function test_closed_permanently_refused_with_future_requests_ac_br_03_04(): void {
		$this->freeze( '2026-12-01 10:00:00' );
		$id = $this->make_branch( 'QAA' );
		$this->make_reservation( $id, '2026-12-24', '19:00', 'confirmed' );
		$this->make_reservation( $id, '2026-12-25', '18:30', 'confirmed' );
		$this->make_reservation( $id, '2026-11-20', '18:30', 'confirmed' ); // Past.
		$this->make_reservation( $id, '2026-12-26', '18:30', 'cancelled' );
		$err = BranchFields::validate_status( 'closed_permanently', $id );
		$this->assertStringContainsString( 'Handle the 2 pending or confirmed future requests', (string) $err );
		$this->assertStringContainsString( '2026-12-24 19:00 (confirmed)', (string) $err );
		$this->assertNull( BranchFields::validate_status( 'temporarily_closed', $id ) );
	}

	public function test_closed_permanently_effects(): void {
		$id  = $this->make_branch( 'QAA' );
		$bm  = $this->user_with_role( 'momo_branch_manager', [ $id ] );
		BranchGuard::status_effects( $id, 'open', 'closed_permanently' );
		update_post_meta( $id, 'momo_operating_status', 'closed_permanently' );
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::user_branches() . ' WHERE branch_id = %d', $id ) ) );
		$this->assertContains( 'user.branch_unassigned', $this->audit_actions() );
		$this->assertSame( '1', (string) get_term_meta( BranchScopeTaxonomy::term_for_branch( $id )->term_id, 'momo_scope_hidden', true ) );
		$acc = $this->events_of( 'momos/branch/accepting_changed' );
		$this->assertSame( [ 'momos/branch/accepting_changed', $id, false, 'closed_permanently' ], end( $acc ) );
		$this->assertGreaterThan( 0, $bm );
	}

	public function test_delete_guard_with_reservations(): void {
		$id = $this->make_branch( 'QAA' );
		$this->make_reservation( $id, '2026-12-24', '19:00', 'pending' );
		$this->assertFalse( wp_trash_post( $id ) );
		$this->assertFalse( wp_delete_post( $id, true ) );
		$this->assertSame( 'publish', get_post_status( $id ) );
		$this->assertContains( 'access.denied', $this->audit_actions() );
		$free = $this->make_branch( 'QAF', [], 'draft' );
		$this->assertNotFalse( wp_trash_post( $free ) );
	}

	public function test_branch_manager_cannot_change_identity_core_fields_ac_br_03_03(): void {
		$id = $this->make_branch( 'QAA' );
		wp_set_current_user( $this->user_with_role( 'momo_branch_manager', [ $id ] ) );
		wp_update_post( [ 'ID' => $id, 'post_title' => 'Hijacked', 'post_content' => 'New description' ] );
		$post = get_post( $id );
		$this->assertSame( '支店QAA', $post->post_title );
		$this->assertSame( 'New description', $post->post_content, 'description is BM-editable (US-BR-009)' );
		$this->assertContains( 'access.denied', $this->audit_actions() );
		$this->assertContains( 'branch.content_updated', $this->audit_actions() );
		$this->assertFalse( current_user_can( 'momo_edit_branch_settings' ) );
		$other = $this->make_branch( 'QAE' );
		$this->assertFalse( current_user_can( 'edit_post', $other ), 'M1: BM cannot edit an unassigned branch' );
		$this->assertTrue( current_user_can( 'edit_post', $id ) );
	}

	public function test_illustrative_images_rejected(): void {
		$att = self::factory()->attachment->create();
		update_post_meta( $att, 'momo_image_illustrative', '1' );
		$this->assertSame( 'Demo images cannot be used here. Upload a real photo.', BranchFields::validate_gallery( [ $att ] ) );
		$this->assertNotNull( BranchFields::validate_gallery( range( 1, 13 ) ) );
	}
}
