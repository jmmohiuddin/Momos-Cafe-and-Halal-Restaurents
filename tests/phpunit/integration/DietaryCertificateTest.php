<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/MenuTest.php';

use Momos\Dietary\Admin\CertificateRegister;
use Momos\Dietary\Admin\ClaimTermScanPage;
use Momos\Dietary\Fields\DietaryFields;
use Momos\Dietary\Job\CertificateExpiryJob;
use Momos\Dietary\Service\CertificateService;
use Momos\Dietary\Service\ClaimRecordValidator;
use Momos\Dietary\Service\ClaimResolver;
use Momos\Dietary\Service\StatementRepository;
use Momos\Dietary\Storage\EvidenceStore;
use Momos\Kernel\I18n\Translations;
use Momos\Menu\ContentTypes;
use Momos\Tests\TestCase;

/**
 * Halal register: activation rules, evidence store, display matrix, expiry job + E11, revoke fallback,
 * access (TC-DIET-002/003/004/005/006/024/025/026, AC-DIET-01-02..08, AC-CORP-03-01..04, US-DIET-007).
 */
final class DietaryCertificateTest extends TestCase {
	use MenuDietaryFixtures;

	private string $dir;
	private int $dca;

	public function set_up(): void {
		parent::set_up();
		$this->fixtures_up();
		$this->dir = sys_get_temp_dir() . '/momos-evidence-' . wp_generate_password( 8, false );
		add_filter( 'momos/evidence/dir', fn() => $this->dir );
		$this->dca = $this->user( 'momo_head_office', [], true );
		foreach ( [ 'certified.whole_kitchen', 'certified.specific_menu', 'halal_ingredients', 'no_claim', 'branch_default', 'alcohol.served.no' ] as $key ) {
			$row = StatementRepository::get( $key );
			StatementRepository::save_row( [ 'key' => $key, 'claim_type' => $row['claim_type'], 'text_ja' => $row['text_ja'], 'approved' => true ], $this->dca, $key );
			StatementRepository::save_translation( $key, 'en', $row['i18n']['en']['text'], 'approved', $this->dca );
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'momos/evidence/dir' );
		remove_all_actions( 'momos/certificate/expiry_alert' );
		if ( is_dir( $this->dir ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
		}
		$this->fixtures_down();
		parent::tear_down();
	}

	private function pdf(): string {
		$f = tempnam( sys_get_temp_dir(), 'ev' );
		file_put_contents( $f, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" );
		return $f;
	}

	private function certificate( array $meta, int $branch ): int {
		wp_set_current_user( $this->dca );
		$id = self::factory()->post->create( [ 'post_type' => 'momo_certificate', 'post_title' => 'Cert', 'post_status' => 'publish' ] );
		$defaults = [
			'momo_cert_claim_type'      => 'certified',
			'momo_cert_statement_key'   => 'certified.whole_kitchen',
			'momo_cert_certifier_name'  => '⟦CERTIFIER⟧',
			'momo_cert_scope'           => 'whole_kitchen',
			'momo_cert_number'          => 'H-001',
			'momo_cert_valid_from'      => '2026-01-01',
			'momo_cert_valid_until'     => '2026-12-31',
			'momo_cert_source_document' => 'Certificate copy v1 / Halal officer',
			'momo_cert_branches'        => [ $branch ],
		];
		foreach ( array_merge( $defaults, $meta ) as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}
		return $id;
	}

	private function activate_with_evidence( int $cert ): void {
		CertificateService::attach_document( $cert, 'evidence', EvidenceStore::store_file( $this->pdf() ), $this->dca );
		CertificateService::activate( $cert, $this->dca );
	}

	private function link_branch( int $branch, int $cert, string $type = 'certified' ): void {
		wp_set_current_user( $this->dca );
		update_post_meta( $branch, 'momo_halal_claim_type', $type );
		update_post_meta( $branch, 'momo_halal_claim_record', $cert );
	}

	/** TC-DIET-002/024: activation blocked until evidence/source/branches; stamps on activation. */
	public function test_activation_rules_and_stamps(): void {
		$this->freeze( '2026-06-01 10:00:00' );
		$b    = $this->branch( 'HAA' );
		$cert = $this->certificate( [ 'momo_cert_source_document' => '' ], $b );
		$this->assertSame( 'pending_verification', CertificateService::status( $cert )->value );
		$this->assertNotEmpty( $this->audits_of( 'certificate.created' ) );
		$errors = ClaimRecordValidator::activation_errors( $cert );
		$this->assertContains( 'Certified records need certifier, certificate number, validity dates and an evidence file.', $errors );
		$this->assertContains( 'Record the source document (document name/version and person 1) before activating.', $errors );
		try {
			CertificateService::activate( $cert, $this->dca );
			$this->fail( 'activation must be refused' );
		} catch ( \DomainException $e ) {
			$this->assertStringContainsString( 'evidence file', $e->getMessage() );
		}
		update_post_meta( $cert, 'momo_cert_source_document', 'Certificate copy v1 / Halal officer' );
		$this->activate_with_evidence( $cert );
		$this->assertSame( 'active', CertificateService::status( $cert )->value );
		$this->assertSame( (string) $this->dca, (string) get_post_meta( $cert, 'momo_cert_verified_by', true ) );
		$this->assertTrue( CertificateService::is_displayable( $cert ) );
		$this->assertNotEmpty( $this->audits_of( 'certificate.status_changed' ) );

		$expired = $this->certificate( [ 'momo_cert_valid_until' => '2026-05-31' ], $b );
		$this->assertContains( 'This certificate has expired and cannot be activated.', ClaimRecordValidator::activation_errors( $expired ) );
		$hi = $this->certificate( [ 'momo_cert_claim_type' => 'halal_ingredients', 'momo_cert_statement_key' => 'halal_ingredients', 'momo_cert_scope' => 'specific_ingredients' ], $b );
		$this->assertContains( 'Select the covered ingredients.', ClaimRecordValidator::activation_errors( $hi ) );
		$mark = $this->certificate( [ 'momo_cert_mark_permission' => '1' ], $b );
		$this->assertContains( 'Upload the mark image and the permission document.', ClaimRecordValidator::activation_errors( $mark ) );
	}

	/** SEC-028 / AC-DIET-01-06: private store, random name, SHA-256, MIME allow-list, denied download. */
	public function test_evidence_store_and_download_access(): void {
		$rec = EvidenceStore::store_file( $this->pdf() );
		$this->assertMatchesRegularExpression( '#^\d{4}/[a-f0-9]{32}\.pdf$#', $rec['path'] );
		$this->assertSame( 'application/pdf', $rec['mime'] );
		$this->assertTrue( EvidenceStore::verify( $rec ) );
		$this->assertFileExists( $this->dir . '/.htaccess' );
		$this->assertStringStartsNotWith( ABSPATH, (string) EvidenceStore::path( $rec ) );
		$this->assertNull( EvidenceStore::path( [ 'path' => '../../wp-config.php' ] ), 'no traversal' );
		file_put_contents( $this->dir . '/' . $rec['path'], 'tampered' );
		$this->assertFalse( EvidenceStore::verify( $rec ) );
		$txt = tempnam( sys_get_temp_dir(), 'ev' );
		file_put_contents( $txt, '<?php echo 1;' );
		try {
			EvidenceStore::store_file( $txt );
			$this->fail( 'non-PDF/JPEG/PNG must be refused' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'PDF, JPEG or PNG', $e->getMessage() );
		}
		$b    = $this->branch( 'HAB' );
		$cert = $this->certificate( [], $b );
		CertificateService::attach_document( $cert, 'evidence', EvidenceStore::store_file( $this->pdf() ), $this->dca );
		wp_set_current_user( $this->user( 'momo_content_editor' ) );
		$_GET = [ 'id' => (string) $cert, 'doc' => 'evidence', '_wpnonce' => wp_create_nonce( 'momos_evidence_' . $cert . '_evidence' ) ];
		try {
			\Momos\Dietary\Admin\CertificateEditor::download();
			$this->fail( 'download must be denied' );
		} catch ( \WPDieException $e ) {
			$this->assertNotEmpty( $this->audits_of( 'access.denied' ) );
		} finally {
			$_GET = [];
		}
	}

	/** M4 / spec §4: certificate caps need CAP-DCA; never deletable. */
	public function test_certificate_capabilities(): void {
		$b    = $this->branch( 'HAC' );
		$cert = $this->certificate( [], $b );
		wp_set_current_user( $this->user( 'momo_head_office' ) );
		$this->assertFalse( current_user_can( 'edit_post', $cert ) );
		$this->assertFalse( current_user_can( 'edit_momo_certificates' ) );
		$this->assertTrue( current_user_can( 'read_private_momo_certificates' ) );
		wp_set_current_user( $this->dca );
		$this->assertTrue( current_user_can( 'edit_post', $cert ) );
		$this->assertFalse( current_user_can( 'delete_post', $cert ) );
		$this->assertFalse( wp_trash_post( $cert ) );
		$this->assertNotNull( get_post( $cert ) );
		$this->expectException( \DomainException::class );
		CertificateService::revoke( $cert, 'x', $this->user( 'momo_menu_manager' ) );
	}

	/** TC-DIET-003 / AC-CORP-03-01: display matrix, mark only with permission, BR-DIET-05 per language. */
	public function test_branch_claim_display_matrix(): void {
		$this->freeze( '2026-06-01 10:00:00' );
		$a    = $this->branch( 'HAD' );
		$b    = $this->branch( 'HAE' );
		$cert = $this->certificate( [], $a );
		$this->activate_with_evidence( $cert );
		$this->link_branch( $a, $cert );
		update_post_meta( $a, 'momo_alcohol_served', 'no' );
		$ja = ClaimResolver::branch_claim( $a, 'ja' );
		$this->assertSame( 'certified', $ja['type'] );
		$this->assertSame( '当店舗は⟦CERTIFIER⟧によるハラール認証を取得しています（認証番号：H-001、範囲：厨房全体、有効期限：2026年12月31日）。', $ja['statement'] );
		$this->assertSame( 'ハラール認証取得', $ja['label'] );
		$this->assertNull( $ja['mark_url'], 'no mark without permission' );
		$en = ClaimResolver::branch_claim( $a, 'en' );
		$this->assertStringContainsString( 'certificate no. H-001', $en['statement'] );
		$this->assertSame( [ 'Alcoholic drinks are not served at this branch.' ], $en['alcohol_lines'] );
		$this->assertNotNull( $en['last_verified'] );
		$ar = ClaimResolver::branch_claim( $a, 'ar' );
		$this->assertSame( 'certified', $ar['type'] );
		$this->assertSame( '', $ar['statement'], 'no approved human translation → not rendered' );
		$this->assertFalse( $ar['statement_available'] );
		$none = ClaimResolver::branch_claim( $b, 'en' );
		$this->assertSame( 'no_claim', $none['type'] );
		$this->assertSame( 'Please ask our staff at this branch about ingredients and preparation.', $none['statement'] );
		$this->assertSame( [], $none['alcohol_lines'], 'unknown alcohol values are not displayed' );
		$this->assertFalse( ClaimResolver::all_branches_certified() );

		// Mark with permission + image + permission document.
		$img = self::factory()->attachment->create_object( 'mark.png', 0, [ 'post_mime_type' => 'image/png', 'post_type' => 'attachment' ] );
		update_post_meta( $cert, 'momo_cert_mark_permission', '1' );
		update_post_meta( $cert, 'momo_cert_mark_image', $img );
		CertificateService::attach_document( $cert, 'permission', EvidenceStore::store_file( $this->pdf() ), $this->dca );
		$this->assertTrue( CertificateService::mark_allowed( $cert ) );
		$this->assertSame( (int) $img, ClaimResolver::branch_claim( $a, 'en' )['mark_id'] );
		$this->assertSame( 'Halal certification mark of ⟦CERTIFIER⟧', ClaimResolver::branch_claim( $a, 'en' )['mark_alt'] );
	}

	/** TC-DIET-004/006 + TC-DIET-005/TC-NOT-017: validity at render time, job expiry, E11 variants, dedupe keys. */
	public function test_expiry_render_time_and_job(): void {
		$a    = $this->branch( 'HAF' );
		$this->freeze( '2026-12-31 09:00:00' );
		$cert = $this->certificate( [], $a );
		$this->activate_with_evidence( $cert );
		$this->link_branch( $a, $cert );
		$this->assertSame( 'certified', ClaimResolver::branch_claim( $a, 'en' )['type'], 'valid_until today = still valid' );

		$soon30 = $this->certificate( [ 'momo_cert_valid_until' => '2027-01-31' ], $a );
		$this->activate_with_evidence( $soon30 );
		$alerts = [];
		add_action( 'momos/certificate/expiry_alert', static function ( $id, $variant, $r, $key ) use ( &$alerts ) { $alerts[] = [ $id, $variant, $r['email'], $key ]; }, 10, 4 );

		$this->freeze( '2027-01-01 00:10:00' );
		$this->assertSame( 'no_claim', ClaimResolver::branch_claim( $a, 'en' )['type'], 'expired yesterday → hidden before the job runs' );
		$result = CertificateExpiryJob::run();
		$this->assertSame( [ $cert ], $result['expired'] );
		$this->assertSame( 'expired', CertificateService::status( $cert )->value );
		$this->assertNotEmpty( $this->audits_of( 'certificate.expired' ) );
		$this->assertSame( 'system', $this->audits_of( 'certificate.expired' )[0][5] );
		$variants = array_column( $alerts, 1 );
		$this->assertContains( 'cert_expired', $variants );
		$this->assertContains( 'cert_30', $variants );
		$recipient = get_userdata( $this->dca )->user_email;
		$this->assertContains( $recipient, array_column( $alerts, 2 ), 'CAP-DCA holders receive E11' );
		$keys = array_column( $alerts, 3 );
		$this->assertMatchesRegularExpression( '/^cert:' . $cert . ':0:[0-9a-f]{16}$/', $keys[ array_search( 'cert_expired', $variants, true ) ] );
		$dca_key = $keys[ array_search( strtolower( $recipient ), array_column( $alerts, 2 ), true ) ];
		$this->assertStringEndsWith( ':' . substr( \Momos\Kernel\Support\Hash::purpose( 'email', strtolower( trim( $recipient ) ) ), 0, 16 ), $dca_key, 'recipient part = purpose-separated email hash (DEC-059, same as RecipientResolver::hash)' );
		$this->assertStringNotContainsString( substr( \Momos\Kernel\Support\Hash::hmac( strtolower( $recipient ) ), 0, 16 ), $dca_key, 'not the plain pepper HMAC' );

		$alerts = [];
		$again  = CertificateExpiryJob::run();
		$this->assertSame( [], $again['expired'], 'second run expires nothing' );
		$this->assertNotContains( 'cert_expired', array_column( $alerts, 1 ) );
		$this->assertSame( $keys[ array_search( 'cert_30', $variants, true ) ], $alerts[0][3] ?? null, 'stable dedupe key for the Notifier' );
		$this->assertNotEmpty( get_option( 'momos_job_heartbeat_certificate_expiry' ) );
	}

	/** TC-DIET-025 / AC-DIET-01-05: revoke → immediate no_claim, no HalalDiet; material change suspends. */
	public function test_revoke_and_material_change(): void {
		$this->freeze( '2026-06-01 10:00:00' );
		$a    = $this->branch( 'HAG' );
		$cat  = $this->category( 'halal' );
		$dish = $this->dish( 'D4000', $cat );
		$this->row( $a, $dish );
		$cert = $this->certificate( [ 'momo_cert_scope' => 'specific_menu', 'momo_cert_statement_key' => 'certified.specific_menu', 'momo_cert_dishes' => [ $dish ] ], $a );
		$this->activate_with_evidence( $cert );
		wp_set_current_user( $this->dca );
		update_post_meta( $dish, 'momo_dish_halal_basis', 'certified_item' );
		update_post_meta( $dish, 'momo_dish_claim_record', $cert );
		$claim = ClaimResolver::dish_claim( $dish, null, 'en' );
		$this->assertSame( 'certified.specific_menu', $claim['template_key'] );
		$this->assertTrue( $claim['suitable_for_halal_diet'] );

		CertificateService::revoke( $cert, 'Certificate withdrawn by certifier', $this->dca );
		$claim = ClaimResolver::dish_claim( $dish, null, 'en' );
		$this->assertSame( 'no_claim', $claim['template_key'] );
		$this->assertFalse( $claim['suitable_for_halal_diet'] );
		$this->assertSame( 'revoked', CertificateService::status( $cert )->value );
		$this->expectException( \DomainException::class );
		CertificateService::activate( $cert, $this->dca ); // terminal
	}

	public function test_material_change_suspends_active_record(): void {
		$this->freeze( '2026-06-01 10:00:00' );
		$a    = $this->branch( 'HAH' );
		$cert = $this->certificate( [], $a );
		$this->activate_with_evidence( $cert );
		CertificateService::after_fields_changed( $cert, [ 'momo_cert_scope' ] );
		$this->assertSame( 'pending_verification', CertificateService::status( $cert )->value );
		CertificateService::activate( $cert, $this->dca );
		CertificateService::after_fields_changed( $cert, [ 'momo_cert_internal_note' ] );
		$this->assertSame( 'active', CertificateService::status( $cert )->value );
	}

	/** FR-DIET-003(e): dish with branch_default lists offering branches; G7 validation. */
	public function test_dish_branch_default_and_branch_group_validation(): void {
		$this->freeze( '2026-06-01 10:00:00' );
		$a    = $this->branch( 'HAI' );
		Translations::save( 'post', $a, 'en', [ 'title' => 'Branch HAI' ], 'approved' );
		$dish = $this->dish( 'D4001', $this->category( 'bd' ) );
		$this->row( $a, $dish );
		$claim = ClaimResolver::dish_claim( $dish, null, 'en' );
		$this->assertSame( 'branch_default', $claim['template_key'] );
		$this->assertSame( 'Halal information is shown for each branch. Branches offering this dish: Branch HAI', $claim['statement'] );
		$this->assertStringContainsString( '<a href=', $claim['statement_html'] );

		$pending = $this->certificate( [], $a );
		$errors  = DietaryFields::validate_branch( [], [ 'momo_halal_claim_type' => 'certified', 'momo_halal_claim_record' => $pending ], [ 'x' => 1 ], $a );
		$this->assertContains( 'Link an active certificate before selecting a certified statement.', $errors );
		$errors = DietaryFields::validate_branch( [], [ 'momo_halal_claim_type' => 'no_claim', 'momo_alcohol_in_cooking' => 'no' ], [ 'x' => 1 ], $a );
		$this->assertContains( '“Not used in cooking” requires specification-sheet evidence for every component.', $errors );
		$no_claim = [ 'momo_halal_claim_type' => 'no_claim', 'momo_halal_statement_key' => 'no_claim' ];
		$this->assertContains( 'The statement template must be approved and translated in every enabled language.', DietaryFields::validate_branch( [], $no_claim, [ 'x' => 1 ], $a ), 'ar/ur/hi/bn enabled on dev but not translated' );
		update_option( 'momos_languages_enabled', [ 'ja', 'en' ] );
		$this->assertSame( [], DietaryFields::validate_branch( [], $no_claim, [ 'x' => 1 ], $a ) );
	}

	/** M12: Branch Manager sees only records linked to assigned branches, never evidence. */
	public function test_register_visibility(): void {
		$a  = $this->branch( 'HAJ' );
		$b  = $this->branch( 'HAK' );
		$ca = $this->certificate( [], $a );
		$cb = $this->certificate( [], $b );
		$bm = $this->user( 'momo_branch_manager', [ $a ] );
		$this->assertSame( [ $ca ], CertificateRegister::visible_ids( $bm ) );
		$this->assertEqualsCanonicalizing( [ $ca, $cb ], CertificateRegister::visible_ids( $this->user( 'momo_menu_manager' ) ) );
		$this->assertSame( [], CertificateRegister::visible_ids( $this->user( 'momo_content_editor' ) ) );
		$this->assertFalse( CertificateService::can_manage( $bm ) );
		$this->assertArrayNotHasKey( 'evidence', CertificateRegister::summary( $ca ) );
	}

	/** US-DIET-007 / TC-DIET-021: scan lists free-text claims per language; BM denied. */
	public function test_claim_term_scan(): void {
		$cat  = $this->category( 'scan' );
		$dish = $this->dish( 'D4002', $cat );
		Translations::save( 'post', $dish, 'en', [ 'title' => 'Chicken', 'content' => 'Our 100% halal chicken.' ], 'approved' );
		$hits = array_filter( ClaimTermScanPage::scan(), static fn( $h ) => $h['id'] === $dish );
		$this->assertNotEmpty( $hits );
		$hit = array_values( $hits )[0];
		$this->assertSame( [ 'en', 'content' ], [ $hit['lang'], $hit['field'] ] );
		$this->assertContains( '100% halal', array_column( $hits, 'term' ) );
		$this->assertFalse( ClaimTermScanPage::can_access( $this->user( 'momo_branch_manager' ) ) );
		$this->assertTrue( ClaimTermScanPage::can_access( $this->dca ) );
	}
}
