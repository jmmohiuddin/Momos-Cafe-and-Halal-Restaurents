<?php
declare(strict_types=1);

namespace Momos\Dietary\Admin;

use Momos\Dietary\ContentTypes;
use Momos\Dietary\Domain\ClaimStatus;
use Momos\Dietary\Service\CertificateService;
use Momos\Dietary\Service\ClaimRecordValidator;
use Momos\Dietary\Storage\EvidenceStore;

/**
 * SCR-A12 record edit additions: status box with Activate / Revoke (reason) / Re-verify actions and the
 * private evidence + permission document upload/download (CAP-DCA + momo_manage_certificates only,
 * SEC-028, M11). Downloads: `admin-post.php?action=momos_evidence&id=&doc=` (nonce, attachment,
 * nosniff, SHA-256 check, audit `certificate.evidence_downloaded`).
 */
final class CertificateEditor {
	public static function register(): void {
		add_action( 'add_meta_boxes_' . ContentTypes::CERTIFICATE, [ self::class, 'meta_boxes' ] );
		add_action( 'post_edit_form_tag', [ self::class, 'form_enctype' ] );
		add_action( 'save_post_' . ContentTypes::CERTIFICATE, [ self::class, 'handle_upload' ], 20, 2 );
		add_action( 'admin_post_momos_cert_action', [ self::class, 'handle_action' ] );
		add_action( 'admin_post_momos_evidence', [ self::class, 'download' ] );
	}

	public static function form_enctype( \WP_Post $post ): void {
		if ( ContentTypes::CERTIFICATE === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public static function meta_boxes( \WP_Post $post ): void {
		add_meta_box( 'momos-cert-status', __( 'Status', 'momos-core' ), [ self::class, 'box_status' ], ContentTypes::CERTIFICATE, 'side', 'high' );
		add_meta_box( 'momos-cert-evidence', __( 'Evidence (private)', 'momos-core' ), [ self::class, 'box_evidence' ], ContentTypes::CERTIFICATE, 'normal', 'high' );
	}

	public static function box_status( \WP_Post $post ): void {
		$status = CertificateService::status( $post->ID );
		echo '<p><strong>' . esc_html( ClaimStatus::choices()[ $status->value ] ) . '</strong></p>';
		$reason = (string) get_post_meta( $post->ID, 'momo_cert_revocation_reason', true );
		if ( ClaimStatus::Revoked === $status && '' !== $reason ) {
			echo '<p>' . esc_html( $reason ) . '</p>';
		}
		if ( ! CertificateService::can_manage( get_current_user_id() ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ClaimStatus::PendingVerification === $status ) {
			$errors = ClaimRecordValidator::activation_errors( $post->ID );
			if ( $errors ) {
				echo '<ul class="momos-errors">';
				foreach ( $errors as $e ) {
					echo '<li>' . esc_html( $e ) . '</li>';
				}
				echo '</ul>';
			}
			echo '<p><a class="button button-primary" href="' . esc_url( self::action_url( $post->ID, 'activate' ) ) . '">' . esc_html__( 'Activate', 'momos-core' ) . '</a></p>';
		}
		if ( ClaimStatus::Active === $status ) {
			echo '<p><a class="button" href="' . esc_url( self::action_url( $post->ID, 'reattest' ) ) . '">' . esc_html__( 'Re-verify (quarterly attestation)', 'momos-core' ) . '</a></p>';
		}
		if ( in_array( $status, [ ClaimStatus::Active, ClaimStatus::PendingVerification ], true ) ) {
			echo '<p><a class="button button-link-delete" href="' . esc_url( admin_url( 'admin.php?page=' . CertificateRegister::SLUG . '&revoke=' . $post->ID ) ) . '">' . esc_html__( 'Revoke…', 'momos-core' ) . '</a></p>';
		}
	}

	public static function action_url( int $cert_id, string $do ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=momos_cert_action&cert=' . $cert_id . '&do=' . $do ), 'momos_cert_' . $do . '_' . $cert_id );
	}

	public static function box_evidence( \WP_Post $post ): void {
		$can = CertificateService::can_manage( get_current_user_id() );
		foreach ( [ 'evidence' => __( 'Certificate / supplier evidence', 'momos-core' ), 'permission' => __( 'Mark permission document', 'momos-core' ) ] as $kind => $label ) {
			$rec = get_post_meta( $post->ID, 'permission' === $kind ? 'momo_cert_permission_doc' : 'momo_cert_evidence', true );
			echo '<p><strong>' . esc_html( $label ) . '</strong><br>';
			if ( EvidenceStore::is_valid_record( $rec ) ) {
				echo esc_html( sprintf( '%s · %s · SHA-256 %s…', (string) $rec['mime'], size_format( (int) ( $rec['size'] ?? 0 ) ), substr( (string) $rec['sha256'], 0, 12 ) ) );
				if ( $can ) {
					echo ' — <a href="' . esc_url( self::download_url( $post->ID, $kind ) ) . '">' . esc_html__( 'Download', 'momos-core' ) . '</a>';
				}
			} else {
				esc_html_e( 'No file', 'momos-core' );
			}
			echo '</p>';
			if ( $can ) {
				wp_nonce_field( 'momos_cert_upload_' . $post->ID, '_momos_cert_upload' );
				echo '<label class="screen-reader-text" for="momos-upload-' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</label>';
				echo '<input id="momos-upload-' . esc_attr( $kind ) . '" type="file" name="momos_cert_' . esc_attr( $kind ) . '" accept="application/pdf,image/jpeg,image/png">';
			}
		}
		echo '<p class="description">' . esc_html__( 'PDF, JPEG or PNG, max. 10 MB. Stored outside the public web space; replacing the evidence of an active record sets it back to “Pending verification”.', 'momos-core' ) . '</p>';
	}

	public static function download_url( int $cert_id, string $kind ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=momos_evidence&id=' . $cert_id . '&doc=' . $kind ), 'momos_evidence_' . $cert_id . '_' . $kind );
	}

	public static function handle_upload( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || empty( $_FILES ) || ! isset( $_POST['_momos_cert_upload'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_momos_cert_upload'] ) ), 'momos_cert_upload_' . $post_id ) ) {
			return;
		}
		foreach ( [ 'evidence', 'permission' ] as $kind ) {
			$f = $_FILES[ 'momos_cert_' . $kind ] ?? null; // phpcs:ignore -- validated by EvidenceStore.
			if ( ! is_array( $f ) || UPLOAD_ERR_NO_FILE === (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				continue;
			}
			try {
				if ( ! CertificateService::can_manage( get_current_user_id() ) ) {
					throw new \DomainException( __( 'Only Dietary Claims Approvers can change these fields', 'momos-core' ) );
				}
				$record = EvidenceStore::store_upload( $f );
				CertificateService::attach_document( $post_id, $kind, $record, get_current_user_id() );
			} catch ( \Throwable $e ) {
				\Momos\Kernel\Fields\FieldRegistry::add_errors( [ $e->getMessage() ] );
			}
		}
	}

	public static function handle_action(): void {
		$cert = isset( $_REQUEST['cert'] ) ? (int) $_REQUEST['cert'] : 0;
		$do   = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		check_admin_referer( 'momos_cert_' . $do . '_' . $cert );
		$uid = get_current_user_id();
		try {
			match ( $do ) {
				'activate' => CertificateService::activate( $cert, $uid ),
				'reattest' => CertificateService::reattest( $cert, $uid ),
				'revoke'   => CertificateService::revoke( $cert, isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '', $uid ),
				default    => throw new \DomainException( __( 'Unknown action.', 'momos-core' ) ),
			};
			set_transient( 'momos_cert_notice_' . $uid, [ 'success', __( 'Status updated.', 'momos-core' ) ], 120 );
		} catch ( \DomainException $e ) {
			\Momos\Kernel\Fields\FieldRegistry::add_errors( explode( "\n", $e->getMessage() ) );
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . $cert . '&action=edit' ) );
		exit;
	}

	/** Authenticated evidence download (SEC-028, AC-DIET-01-06). */
	public static function download(): void {
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$kind = isset( $_GET['doc'] ) && 'permission' === $_GET['doc'] ? 'permission' : 'evidence';
		$uid  = get_current_user_id();
		if ( ! $uid || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'momos_evidence_' . $id . '_' . $kind ) || ! CertificateService::can_manage( $uid ) || ContentTypes::CERTIFICATE !== get_post_type( $id ) ) {
			momos_audit( 'access.denied', 'certificate', $id ?: null, null, [ 'reason' => 'evidence_download_denied' ] );
			wp_die( esc_html__( 'You do not have permission to download this file.', 'momos-core' ), 403 );
		}
		$rec = get_post_meta( $id, 'permission' === $kind ? 'momo_cert_permission_doc' : 'momo_cert_evidence', true );
		if ( ! is_array( $rec ) || ! EvidenceStore::verify( $rec ) ) {
			\Momos\Kernel\Log\Logger::critical( 'dietary.evidence_integrity_failed', [ 'certificate_id' => $id, 'doc' => $kind ] );
			wp_die( esc_html__( 'The file is missing or damaged.', 'momos-core' ), 404 );
		}
		momos_audit( 'certificate.evidence_downloaded', 'certificate', $id, null, [ 'doc' => $kind, 'sha256' => (string) $rec['sha256'] ] );
		$path = (string) EvidenceStore::path( $rec );
		nocache_headers();
		header( 'Content-Type: ' . (string) $rec['mime'] );
		header( 'Content-Disposition: attachment; filename="certificate-' . $id . '-' . $kind . '.' . pathinfo( $path, PATHINFO_EXTENSION ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore
		exit;
	}
}
