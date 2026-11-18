<?php
declare(strict_types=1);

namespace Momos\Dietary\Admin;

use Momos\Dietary\Guard\ClaimFieldGuard;
use Momos\Dietary\Service\StatementRepository;

/**
 * SCR-A15 "Dietary statements" (G2, options page `momo-dietary`): statement template rows (key, type,
 * JA text, approval) with per-language translation status, and the cross-contact confirmation toggle.
 * CAP-DCA edits/approves rows; SA/HO read; translators (and approvers) translate rows. Server-side
 * checks in StatementRepository; nonce on every action.
 */
final class StatementsPage {
	public const SLUG = 'momo-dietary';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 22 );
		add_action( 'admin_post_momos_statement_save', [ self::class, 'save' ] );
	}

	public static function can_view(): bool {
		return current_user_can( ClaimFieldGuard::CAP ) || current_user_can( 'momo_manage_brand' ) || current_user_can( 'momo_approve_translations' );
	}

	public static function menu(): void {
		if ( self::can_view() ) {
			add_submenu_page( 'momos', __( 'Dietary statements', 'momos-core' ), __( 'Dietary statements', 'momos-core' ), 'read', self::SLUG, [ self::class, 'render' ] );
		}
	}

	public static function render(): void {
		if ( ! self::can_view() ) {
			momos_audit( 'access.denied', 'dietary_statement', null, null, [ 'reason' => 'forbidden', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$rows      = StatementRepository::all();
		$can_edit  = current_user_can( ClaimFieldGuard::CAP );
		$can_trans = current_user_can( 'momo_translate' ) || $can_edit;
		$cross     = StatementRepository::cross_contact();
		$uid       = get_current_user_id();
		$errors    = get_transient( 'momos_stmt_errors_' . $uid );
		delete_transient( 'momos_stmt_errors_' . $uid );
		include MOMOS_CORE_DIR . '/templates/admin/dietary-statements.php';
	}

	public static function save(): void {
		check_admin_referer( 'momos_statement_save' );
		$uid  = get_current_user_id();
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$errs = [];
		if ( ! self::can_view() ) {
			momos_audit( 'access.denied', 'dietary_statement', null, null, [ 'reason' => 'forbidden', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to do this.', 'momos-core' ), 403 );
		}
		if ( 'row' === $mode ) {
			$errs = StatementRepository::save_row(
				[
					'key'        => isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '',
					'claim_type' => isset( $_POST['claim_type'] ) ? sanitize_key( wp_unslash( $_POST['claim_type'] ) ) : '',
					'text_ja'    => isset( $_POST['text_ja'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text_ja'] ) ) : '',
					'approved'   => ! empty( $_POST['approved'] ),
				],
				$uid,
				isset( $_POST['original_key'] ) && '' !== $_POST['original_key'] ? sanitize_text_field( wp_unslash( $_POST['original_key'] ) ) : null
			);
		} elseif ( 'translation' === $mode ) {
			$errs = StatementRepository::save_translation(
				isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '',
				isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '',
				isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '',
				isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
				$uid
			);
		} elseif ( 'cross_contact' === $mode ) {
			if ( ! StatementRepository::set_cross_contact( ! empty( $_POST['confirmed'] ), $uid ) ) {
				$errs = [ __( 'Only Dietary Claims Approvers can change these fields', 'momos-core' ) ];
			}
		}
		if ( $errs ) {
			set_transient( 'momos_stmt_errors_' . $uid, $errs, 120 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . ( $errs ? '' : '&updated=1' ) ) );
		exit;
	}
}
