<?php
declare(strict_types=1);

namespace Momos\Branch\Admin;

use Momos\Access\AccountStatus;
use Momos\Branch\Domain\OfferScope;
use Momos\Branch\Domain\OfferState;
use Momos\Branch\Fields\BranchFields;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Query\NoticeQuery;
use Momos\Branch\Query\OfferQuery;
use Momos\Branch\Service\BranchAccess;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Admin list/edit screen additions (SCR-A03/A13/A20, spec §7): list columns, scoped list tables
 * for branch-scoped staff, offer list filters by state / scope / branch (FR-CORP-010(c)), GATE-01
 * block in the publish box, assigned-staff panel (active users only, FR-ADM-002(e)), offer row
 * action "Duplicate as draft" and the HO dashboard widget "Offers awaiting approval: N"
 * (FR-ADM-001(b), AC-ADM-01-02).
 */
final class ListScreens {
	private const SCOPED_TYPES = [ 'momo_branch', 'momo_notice', 'momo_offer' ];

	/** Offer list filter query args (FR-CORP-010(c)). */
	public const F_STATE  = 'momo_offer_state';
	public const F_SCOPE  = 'momo_offer_scope';
	public const F_BRANCH = 'momo_offer_branch';

	public static function register(): void {
		add_filter( 'manage_momo_branch_posts_columns', [ self::class, 'branch_columns' ] );
		add_action( 'manage_momo_branch_posts_custom_column', [ self::class, 'branch_cell' ], 10, 2 );
		add_filter( 'manage_momo_notice_posts_columns', [ self::class, 'notice_columns' ] );
		add_action( 'manage_momo_notice_posts_custom_column', [ self::class, 'notice_cell' ], 10, 2 );
		add_filter( 'manage_momo_offer_posts_columns', [ self::class, 'offer_columns' ] );
		add_action( 'manage_momo_offer_posts_custom_column', [ self::class, 'offer_cell' ], 10, 2 );
		add_action( 'pre_get_posts', [ self::class, 'scope_lists' ] );
		add_action( 'pre_get_posts', [ self::class, 'offer_filters' ], 11 );
		add_action( 'restrict_manage_posts', [ self::class, 'offer_filter_controls' ], 10, 2 );
		add_filter( 'wp_count_posts', [ self::class, 'scope_counts' ], 10, 2 );
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

	// ------------------------------------------------------------------ offer list filters (FR-CORP-010(c))

	/**
	 * Requested offer filters, validated: state ∈ OfferState, scope ∈ OfferScope, branch = a branch the
	 * user may see (branch-scoped staff: assigned branches only; anything else is ignored).
	 *
	 * @param array<string,mixed> $req
	 * @return array{state:string,scope:string,branch:int}
	 */
	public static function offer_filter_values( array $req ): array {
		$state  = isset( $req[ self::F_STATE ] ) ? sanitize_key( (string) $req[ self::F_STATE ] ) : '';
		$scope  = isset( $req[ self::F_SCOPE ] ) ? sanitize_key( (string) $req[ self::F_SCOPE ] ) : '';
		$branch = isset( $req[ self::F_BRANCH ] ) ? absint( $req[ self::F_BRANCH ] ) : 0;
		return [
			'state'  => OfferState::is_valid( $state ) ? $state : '',
			'scope'  => OfferScope::is_valid( $scope ) ? $scope : '',
			'branch' => $branch > 0 && in_array( $branch, self::filter_branch_ids(), true ) ? $branch : 0,
		];
	}

	/**
	 * Branches offered in the branch filter: every branch for unscoped staff, assigned ones otherwise.
	 *
	 * @return list<int>
	 */
	public static function filter_branch_ids(): array {
		$ids   = array_map( 'intval', get_posts( [ 'post_type' => 'momo_branch', 'post_status' => [ 'publish', 'draft', 'pending', 'future', 'private' ], 'fields' => 'ids', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true, 'suppress_filters' => true ] ) );
		$scope = momos_user_branch_ids();
		return null === $scope ? $ids : array_values( array_intersect( $ids, array_map( 'intval', $scope ) ) );
	}

	/** Filter controls above the offers list (state, scope, branch). */
	public static function offer_filter_controls( string $post_type, string $which = 'top' ): void {
		if ( 'momo_offer' !== $post_type || 'top' !== $which ) {
			return;
		}
		$f      = self::offer_filter_values( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters, validated above.
		$select = static function ( string $name, string $label, string $all, array $choices, string $current ): void {
			echo '<label class="screen-reader-text" for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>';
			echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '"><option value="">' . esc_html( $all ) . '</option>';
			foreach ( $choices as $value => $text ) {
				echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $text ) . '</option>';
			}
			echo '</select>';
		};
		$select( self::F_STATE, self::t( 'admin.offers.filter.state' ), self::t( 'admin.offers.filter.all_states' ), self::offer_state_labels(), $f['state'] );
		$select(
			self::F_SCOPE,
			self::t( 'admin.offers.filter.scope' ),
			self::t( 'admin.offers.filter.all_scopes' ),
			[
				OfferScope::AllBranches->value => self::t( 'admin.offers.scope.all_branches' ),
				OfferScope::Branches->value    => self::t( 'admin.offers.scope.branches' ),
			],
			$f['scope']
		);
		$branches = [];
		foreach ( self::filter_branch_ids() as $b ) {
			$code           = (string) get_post_meta( $b, 'momo_branch_code', true );
			$branches[ $b ] = get_the_title( $b ) . ( '' !== $code ? ' (' . $code . ')' : '' );
		}
		$select( self::F_BRANCH, self::t( 'admin.offers.filter.branch' ), self::t( 'admin.offers.filter.all_branches' ), $branches, $f['branch'] ? (string) $f['branch'] : '' );
	}

	/**
	 * Apply the offer filters to the main admin list query. State is the computed state of
	 * OfferQuery::state() (status + stored UTC validity); branch = offers that apply to that branch
	 * (scoped to it, or all branches). Runs after scope_lists(), so the scoped post__in is kept.
	 */
	public static function offer_filters( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || 'momo_offer' !== (string) $q->get( 'post_type' ) ) {
			return;
		}
		self::apply_offer_filters( $q, self::offer_filter_values( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filters.
	}

	/** @param array{state:string,scope:string,branch:int} $f */
	public static function apply_offer_filters( \WP_Query $q, array $f ): void {
		$meta = [];
		$now  = Clock::mysql_utc();
		switch ( $f['state'] ) {
			case OfferState::Draft->value:
				$q->set( 'post_status', [ 'draft', 'future', 'private' ] );
				break;
			case OfferState::Pending->value:
				$q->set( 'post_status', 'pending' );
				break;
			case OfferState::Scheduled->value:
				$q->set( 'post_status', 'publish' );
				$meta[] = [ 'key' => '_momo_offer_start_utc', 'value' => $now, 'compare' => '>', 'type' => 'DATETIME' ];
				break;
			case OfferState::Active->value:
				$q->set( 'post_status', 'publish' );
				$meta[] = [
					'relation' => 'OR',
					[ 'key' => '_momo_offer_start_utc', 'compare' => 'NOT EXISTS' ],
					[ 'key' => '_momo_offer_start_utc', 'value' => $now, 'compare' => '<=', 'type' => 'DATETIME' ],
				];
				$meta[] = [
					'relation' => 'OR',
					[ 'key' => '_momo_offer_end_utc', 'compare' => 'NOT EXISTS' ],
					[ 'key' => '_momo_offer_end_utc', 'value' => $now, 'compare' => '>', 'type' => 'DATETIME' ],
				];
				break;
			case OfferState::Expired->value:
				$q->set( 'post_status', 'publish' );
				$meta[] = [ 'key' => '_momo_offer_end_utc', 'value' => $now, 'compare' => '<=', 'type' => 'DATETIME' ];
				break;
		}
		if ( OfferScope::AllBranches->value === $f['scope'] ) {
			$meta[] = [ 'key' => 'momo_offer_scope', 'value' => OfferScope::AllBranches->value ];
		} elseif ( OfferScope::Branches->value === $f['scope'] ) {
			$meta[] = [
				'relation' => 'OR',
				[ 'key' => 'momo_offer_scope', 'compare' => 'NOT EXISTS' ],
				[ 'key' => 'momo_offer_scope', 'value' => OfferScope::AllBranches->value, 'compare' => '!=' ],
			];
		}
		if ( $meta ) {
			$existing = (array) $q->get( 'meta_query' );
			$q->set( 'meta_query', array_merge( array_filter( $existing ), [ 'relation' => 'AND' ], $meta ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin list filter.
		}
		if ( $f['branch'] > 0 ) {
			$term = BranchScopeTaxonomy::term_for_branch( $f['branch'] );
			$ids  = $term ? array_map( 'intval', (array) get_objects_in_term( $term->term_id, BranchScopeTaxonomy::TAX ) ) : [];
			$all  = get_posts( [ 'post_type' => 'momo_offer', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1000, 'no_found_rows' => true, 'suppress_filters' => true, 'meta_key' => 'momo_offer_scope', 'meta_value' => OfferScope::AllBranches->value ] ); // phpcs:ignore WordPress.DB.SlowDBQuery -- admin list filter.
			$ids  = array_values( array_unique( array_merge( $ids, array_map( 'intval', $all ) ) ) );
			$in   = array_map( 'intval', (array) $q->get( 'post__in' ) );
			$ids  = $in ? array_values( array_intersect( $in, $ids ) ) : $ids;
			$q->set( 'post__in', $ids ?: [ 0 ] );
		}
	}

	/** Admin catalog string in the viewer's admin language (ja/en, DEC-037). @param array<string,string|int> $args */
	private static function t( string $key, array $args = [] ): string {
		return momos_t( $key, $args, str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en' );
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
		if ( ! in_array( $pt, self::SCOPED_TYPES, true ) ) {
			return;
		}
		$ids = self::scoped_ids( $pt );
		if ( null === $ids ) {
			return;
		}
		$q->set( 'post__in', $ids ?: [ 0 ] );
	}

	/**
	 * Post IDs of $pt the current scoped user may list; null = unscoped (all branches).
	 *
	 * @return list<int>|null
	 */
	public static function scoped_ids( string $pt ): ?array {
		$scope = momos_user_branch_ids();
		if ( null === $scope ) {
			return null;
		}
		if ( 'momo_branch' === $pt ) {
			return array_values( array_map( 'intval', $scope ) );
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
		return array_values( array_filter( $ids, static fn( $id ) => ! array_diff( BranchScopeTaxonomy::branch_ids_of_post( $id ), $scope ) ) );
	}

	/**
	 * List-screen view counts ("All (n) | Published (n)") for scoped staff count only the rows
	 * they can list (access spec §4.3 E3, TC-ACL-003, QA-010).
	 *
	 * @param object $counts
	 * @return object
	 */
	public static function scope_counts( $counts, string $type ) {
		if ( ! is_admin() || ! in_array( $type, self::SCOPED_TYPES, true ) ) {
			return $counts;
		}
		$ids = self::scoped_ids( $type );
		if ( null === $ids ) {
			return $counts;
		}
		$out = new \stdClass();
		foreach ( array_keys( (array) $counts ) as $status ) {
			$out->{$status} = 0;
		}
		if ( $ids ) {
			global $wpdb;
			$in   = implode( ',', array_map( 'intval', $ids ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_status, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s AND ID IN ({$in}) GROUP BY post_status", $type ), ARRAY_A ); // phpcs:ignore -- $in is a list of ints.
			foreach ( $rows as $row ) {
				$out->{$row['post_status']} = (int) $row['n'];
			}
		}
		return $out;
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
				$users = self::assigned_staff( $p->ID );
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

	/**
	 * Active users assigned to a branch (FR-ADM-002(e), F-ADM-02): disabled accounts and accounts past
	 * their access window are left out (AccountStatus::is_inactive); assignment rows are kept for
	 * history (AccountService::disable), so they are filtered here.
	 *
	 * @return list<int>
	 */
	public static function assigned_staff( int $branch_id ): array {
		global $wpdb;
		$t     = Tables::user_branches();
		$users = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$t} WHERE branch_id = %d ORDER BY user_id", $branch_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return array_values( array_filter( $users, static fn( int $uid ): bool => false !== get_userdata( $uid ) && ! AccountStatus::is_inactive( $uid ) ) );
	}

	public static function dashboard(): void {
		if ( ! current_user_can( 'publish_momo_offers' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'momos_offers_pending', self::t( 'admin.dash.offers_pending.title' ), [ self::class, 'offers_pending_widget' ] );
	}

	/** HO widget (AC-ADM-01-02): "Offers awaiting approval: N" → offers list filtered by state = pending. */
	public static function offers_pending_widget(): void {
		$n   = (int) ( wp_count_posts( 'momo_offer' )->pending ?? 0 );
		$url = add_query_arg( [ 'post_type' => 'momo_offer', self::F_STATE => OfferState::Pending->value ], admin_url( 'edit.php' ) );
		echo '<p class="momos-dash-count"><a href="' . esc_url( $url ) . '">' . esc_html( self::t( 'admin.dash.offers_pending.count', [ 'n' => number_format_i18n( $n ) ] ) ) . '</a></p>';
	}
}
