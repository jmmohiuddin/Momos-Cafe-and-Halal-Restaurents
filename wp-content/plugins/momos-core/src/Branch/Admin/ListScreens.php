<?php
declare(strict_types=1);

namespace Momos\Branch\Admin;

use Momos\Branch\Domain\OfferState;
use Momos\Branch\Fields\BranchFields;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Query\NoticeQuery;
use Momos\Branch\Query\OfferQuery;
use Momos\Branch\Service\BranchAccess;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Admin list/edit screen additions (SCR-A03/A13/A20, spec §7): list columns, scoped list tables
 * for branch-scoped staff, GATE-01 block in the publish box, assigned-staff panel, offer row
 * action "Duplicate as draft" and the HO dashboard widget "Offers waiting for approval".
 */
final class ListScreens {
	public static function register(): void {
		add_filter( 'manage_momo_branch_posts_columns', [ self::class, 'branch_columns' ] );
		add_action( 'manage_momo_branch_posts_custom_column', [ self::class, 'branch_cell' ], 10, 2 );
		add_filter( 'manage_momo_notice_posts_columns', [ self::class, 'notice_columns' ] );
		add_action( 'manage_momo_notice_posts_custom_column', [ self::class, 'notice_cell' ], 10, 2 );
		add_filter( 'manage_momo_offer_posts_columns', [ self::class, 'offer_columns' ] );
		add_action( 'manage_momo_offer_posts_custom_column', [ self::class, 'offer_cell' ], 10, 2 );
		add_action( 'pre_get_posts', [ self::class, 'scope_lists' ] );
		add_action( 'post_submitbox_misc_actions', [ self::class, 'gate_block' ] );
		add_action( 'add_meta_boxes_momo_branch', [ self::class, 'staff_panel' ] );
		add_filter( 'post_row_actions', [ self::class, 'offer_row_actions' ], 10, 2 );
		add_action( 'edit_form_top', [ self::class, 'offer_readonly_notice' ] );
		add_action( 'wp_dashboard_setup', [ self::class, 'dashboard' ] );
	}

	// ------------------------------------------------------------------ branches

	/**
	 * @param array<string,string> $cols
	 * @return array<string,string>
	 */
	public static function branch_columns( array $cols ): array {
		$out = [];
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['momo_code']      = __( 'Code', 'momos-core' );
				$out['momo_status']    = __( 'Operating status', 'momos-core' );
				$out['momo_res']       = __( 'Online requests', 'momos-core' );
				$out['momo_languages'] = __( 'Languages', 'momos-core' );
			}
		}
		$out['momo_updated'] = __( 'Last updated', 'momos-core' );
		unset( $out['date'] );
		return $out;
	}

	public static function branch_cell( string $col, int $post_id ): void {
		switch ( $col ) {
			case 'momo_code':
				echo '<code>' . esc_html( (string) get_post_meta( $post_id, 'momo_branch_code', true ) ) . '</code>';
				if ( '1' === (string) get_post_meta( $post_id, 'momo_is_test_branch', true ) ) {
					echo ' <span class="momos-badge momos-badge--test">' . esc_html__( 'TEST', 'momos-core' ) . '</span>';
				}
				break;
			case 'momo_status':
				$s = (string) get_post_meta( $post_id, 'momo_operating_status', true ) ?: 'open';
				echo '<span class="momos-badge momos-badge--' . esc_attr( $s ) . '">' . esc_html( BranchFields::status_choices()[ $s ] ?? $s ) . '</span>';
				break;
			case 'momo_res':
				echo '1' === (string) get_post_meta( $post_id, 'momo_res_enabled', true ) ? esc_html__( 'On', 'momos-core' ) : esc_html__( 'Off', 'momos-core' );
				break;
			case 'momo_languages':
				self::language_cell( $post_id );
				break;
			case 'momo_updated':
				echo esc_html( get_the_modified_date( 'Y-m-d H:i', $post_id ) );
				break;
		}
	}

	/** Per-language translation status (language completion). */
	private static function language_cell( int $post_id ): void {
		$parts = [];
		foreach ( Lang::targets() as $lang ) {
			$st      = Translations::status( 'post', $post_id, $lang );
			$parts[] = '<span title="' . esc_attr( $st ) . '" class="momos-lang momos-lang--' . esc_attr( $st ) . '">' . esc_html( strtoupper( $lang ) ) . ( 'approved' === $st ? '✓' : '·' ) . '</span>';
		}
		echo implode( ' ', $parts ); // phpcs:ignore -- escaped above.
	}

	// ------------------------------------------------------------------ notices

	/**
	 * @param array<string,string> $cols
	 * @return array<string,string>
	 */
	public static function notice_columns( array $cols ): array {
		unset( $cols['date'] );
		return $cols + [
			'momo_state'     => __( 'State', 'momos-core' ),
			'momo_scope'     => __( 'Shown on', 'momos-core' ),
			'momo_window'    => __( 'Start – end (JST)', 'momos-core' ),
			'momo_languages' => __( 'Languages', 'momos-core' ),
		];
	}

	public static function notice_cell( string $col, int $post_id ): void {
		switch ( $col ) {
			case 'momo_state':
				$labels = [ 'draft' => __( 'Draft', 'momos-core' ), 'scheduled' => __( 'Scheduled', 'momos-core' ), 'active' => __( 'Active', 'momos-core' ), 'expired' => __( 'Expired', 'momos-core' ) ];
				$s      = NoticeQuery::state( $post_id );
				echo '<span class="momos-badge momos-badge--' . esc_attr( $s ) . '">' . esc_html( $labels[ $s ] ) . '</span>';
				if ( 'warning' === get_post_meta( $post_id, 'momo_notice_severity', true ) ) {
					echo ' <span class="momos-badge momos-badge--warning">' . esc_html__( 'Warning', 'momos-core' ) . '</span>';
				}
				break;
			case 'momo_scope':
				echo esc_html( 'site_wide' === get_post_meta( $post_id, 'momo_notice_scope', true ) ? __( 'Site-wide', 'momos-core' ) : self::branch_names( $post_id ) );
				break;
			case 'momo_window':
				echo esc_html( (string) get_post_meta( $post_id, 'momo_notice_start_at', true ) . ' – ' . (string) get_post_meta( $post_id, 'momo_notice_end_at', true ) );
				break;
			case 'momo_languages':
				self::language_cell( $post_id );
				break;
		}
	}

	// ------------------------------------------------------------------ offers

	/**
	 * @param array<string,string> $cols
	 * @return array<string,string>
	 */
	public static function offer_columns( array $cols ): array {
		unset( $cols['date'] );
		return $cols + [
			'momo_state'     => __( 'State', 'momos-core' ),
			'momo_scope'     => __( 'Branches', 'momos-core' ),
			'momo_window'    => __( 'Start – end (JST)', 'momos-core' ),
			'momo_featured'  => __( 'Featured', 'momos-core' ),
			'momo_order'     => __( 'Order', 'momos-core' ),
			'momo_languages' => __( 'Languages', 'momos-core' ),
		];
	}

	/** @return array<string,string> */
	public static function offer_state_labels(): array {
		return [
			OfferState::Draft->value     => __( 'Draft', 'momos-core' ),
			OfferState::Pending->value   => __( 'Pending review', 'momos-core' ),
			OfferState::Scheduled->value => __( 'Scheduled', 'momos-core' ),
			OfferState::Active->value    => __( 'Active', 'momos-core' ),
			OfferState::Expired->value   => __( 'Expired', 'momos-core' ),
		];
	}

	public static function offer_cell( string $col, int $post_id ): void {
		switch ( $col ) {
			case 'momo_state':
				$s = OfferQuery::state( $post_id );
				echo '<span class="momos-badge momos-badge--' . esc_attr( $s ) . '">' . esc_html( self::offer_state_labels()[ $s ] ) . '</span>';
				break;
			case 'momo_scope':
				echo esc_html( 'all_branches' === OfferQuery::scope( $post_id ) ? __( 'All branches', 'momos-core' ) : self::branch_names( $post_id ) );
				break;
			case 'momo_window':
				echo esc_html( (string) get_post_meta( $post_id, 'momo_offer_start_at', true ) . ' – ' . (string) get_post_meta( $post_id, 'momo_offer_end_at', true ) );
				break;
			case 'momo_featured':
				echo '1' === (string) get_post_meta( $post_id, 'momo_offer_homepage_featured', true ) ? '★' : '';
				break;
			case 'momo_order':
				echo (int) get_post_meta( $post_id, 'momo_offer_order', true );
				break;
			case 'momo_languages':
				self::language_cell( $post_id );
				break;
		}
	}

	/**
	 * "Duplicate as draft" for users who may not edit the published/scheduled offer (spec §4.11).
	 *
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function offer_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'momo_offer' !== $post->post_type || ! current_user_can( 'create_momo_offers' ) || ! current_user_can( 'read_post', $post->ID ) ) {
			return $actions;
		}
		$url                        = wp_nonce_url( add_query_arg( [ 'action' => 'momos_duplicate_offer', 'offer' => $post->ID ], admin_url( 'admin-post.php' ) ), 'momos_duplicate_offer_' . $post->ID );
		$actions['momos_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Duplicate as draft', 'momos-core' ) . '</a>';
		return $actions;
	}

	public static function offer_readonly_notice( \WP_Post $post ): void {
		if ( 'momo_offer' === $post->post_type && 'publish' === $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Published offers can only be changed by Head Office. Use "Duplicate as draft" to propose a change.', 'momos-core' ) . '</p></div>';
		}
	}

	private static function branch_names( int $post_id ): string {
		return implode( ', ', array_map( static fn( $b ) => get_the_title( $b ), BranchScopeTaxonomy::branch_ids_of_post( $post_id ) ) );
	}

	// ------------------------------------------------------------------ scoping (SEC-012)

	/** Scoped staff see only their branches and items scoped to them (+ their own drafts). */
	public static function scope_lists( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$pt = (string) $q->get( 'post_type' );
		if ( ! in_array( $pt, [ 'momo_branch', 'momo_notice', 'momo_offer' ], true ) ) {
			return;
		}
		$scope = momos_user_branch_ids();
		if ( null === $scope ) {
			return;
		}
		if ( 'momo_branch' === $pt ) {
			$q->set( 'post__in', $scope ?: [ 0 ] );
			return;
		}
		$ids = [];
		foreach ( $scope as $b ) {
			$term = BranchScopeTaxonomy::term_for_branch( $b );
			if ( $term ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) get_objects_in_term( $term->term_id, BranchScopeTaxonomy::TAX ) ) );
			}
		}
		$own = get_posts( [ 'post_type' => $pt, 'post_status' => 'any', 'author' => get_current_user_id(), 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true ] );
		$ids = array_values( array_unique( array_merge( $ids, array_map( 'intval', $own ) ) ) );
		// Items that also target unassigned branches are excluded (M3).
		$ids = array_values( array_filter( $ids, static fn( $id ) => ! array_diff( BranchScopeTaxonomy::branch_ids_of_post( $id ), $scope ) ) );
		$q->set( 'post__in', $ids ?: [ 0 ] );
	}

	// ------------------------------------------------------------------ edit screen panels

	/** GATE-01 block in the publish box (SCR-A03). */
	public static function gate_block( \WP_Post $post ): void {
		if ( 'momo_branch' !== $post->post_type ) {
			return;
		}
		$by = (string) get_post_meta( $post->ID, 'momo_facts_confirmed_by', true );
		$at = (string) get_post_meta( $post->ID, 'momo_facts_confirmed_at', true );
		echo '<div class="misc-pub-section momos-gate">';
		if ( '' !== $by && '' !== $at ) {
			/* translators: 1: name 2: date */
			echo '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html( sprintf( __( 'Facts confirmed in writing by %1$s, %2$s', 'momos-core' ), $by, $at ) );
			$note = (string) get_post_meta( $post->ID, 'momo_facts_evidence_note', true );
			if ( '' !== $note ) {
				echo '<br><small>' . esc_html( $note ) . '</small>';
			}
		} else {
			echo '<span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__( "Record the owner's written confirmation before publishing (GATE-01).", 'momos-core' );
		}
		echo '</div>';
	}

	public static function staff_panel( \WP_Post $post ): void {
		if ( ! current_user_can( 'momo_manage_staff' ) && ! ( current_user_can( 'momo_edit_branch_hours' ) && momos_user_can_branch( $post->ID ) ) ) {
			return;
		}
		add_meta_box(
			'momos-branch-staff',
			__( 'Assigned staff', 'momos-core' ),
			static function ( \WP_Post $p ): void {
				global $wpdb;
				$t     = Tables::user_branches();
				$users = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$t} WHERE branch_id = %d", $p->ID ) ) ); // phpcs:ignore
				if ( ! $users ) {
					echo '<p>' . esc_html__( 'No staff assigned.', 'momos-core' ) . '</p>';
					return;
				}
				echo '<ul>';
				foreach ( $users as $uid ) {
					$u = get_userdata( $uid );
					if ( $u ) {
						$roles = array_map( static fn( $r ) => translate_user_role( wp_roles()->role_names[ $r ] ?? $r ), $u->roles );
						echo '<li>' . esc_html( $u->display_name . ' — ' . implode( ', ', $roles ) ) . '</li>';
					}
				}
				echo '</ul>';
			},
			'momo_branch',
			'side',
			'low'
		);
	}

	public static function dashboard(): void {
		if ( ! current_user_can( 'publish_momo_offers' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'momos_offers_pending',
			__( 'Offers waiting for approval', 'momos-core' ),
			static function (): void {
				$n = (int) ( wp_count_posts( 'momo_offer' )->pending ?? 0 );
				/* translators: %d: number of offers */
				echo '<p><a href="' . esc_url( admin_url( 'edit.php?post_type=momo_offer&post_status=pending' ) ) . '">' . esc_html( sprintf( _n( '%d offer is waiting for approval.', '%d offers are waiting for approval.', $n, 'momos-core' ), $n ) ) . '</a></p>';
			}
		);
	}
}
