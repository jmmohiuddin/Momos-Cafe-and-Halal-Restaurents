<?php
declare(strict_types=1);

namespace Momos\Admin\Dashboard;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;

/**
 * SCR-A02 role-aware dashboard (F-ADM-01 / FR-ADM-001, Should; spec §13.1 widget table, TC-ADM-001,
 * TC-ACL-024). A widget is registered
 * only when the user holds its capability; every count is scoped to the user's branches through
 * the owning module's scoped repository (E13) and links to the pre-filtered list. Modules that
 * are not installed simply contribute no widget (class_exists guards). Core widgets (Welcome,
 * Events & News, Quick Draft, Site Health, Activity, At a Glance) are removed except for ROLE-01.
 */
final class Widgets {
	public static function register(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'setup' ], 20 );
	}

	public static function setup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
			foreach ( [ 'dashboard_primary' => 'side', 'dashboard_quick_press' => 'side', 'dashboard_site_health' => 'normal', 'dashboard_activity' => 'normal', 'dashboard_right_now' => 'normal' ] as $id => $ctx ) {
				remove_meta_box( $id, 'dashboard', $ctx );
			}
		}
		$add = static fn( string $id, string $title, callable $cb ) => wp_add_dashboard_widget( 'momos_' . $id, $title, $cb );
		$res = class_exists( \Momos\Reservation\Repository\ReservationRepository::class ) && class_exists( \Momos\Reservation\Domain\Scope::class );

		// Reservations (RS/BM (b), HO/SA): pending + needs action; attendance only for branch-scoped staff.
		if ( $res && current_user_can( 'momo_view_reservations' ) ) {
			$add( 'pending', __( 'My pending requests', 'momos-core' ), [ self::class, 'pending' ] );
			$add( 'needs_action', self::t( 'admin.dash.needs_action.title' ), [ self::class, 'needs_action' ] );
			if ( current_user_can( 'momo_process_reservations' ) && null !== momos_user_branch_ids() && class_exists( \Momos\Reservation\Service\TransitionService::class ) ) {
				$add( 'attendance', self::t( 'admin.dash.attendance.title' ), [ self::class, 'attendance' ] );
			}
		}
		if ( current_user_can( 'momo_translate' ) ) {
			$add( 'translation_gaps', __( 'Translation gaps', 'momos-core' ), [ self::class, 'translation_gaps' ] );
		}
		if ( self::is_translator() && class_exists( \Momos\Kernel\I18n\Admin\TranslationGaps::class ) ) {
			$add( 'translation_jobs', self::t( 'admin.dash.tr_jobs.title' ), [ self::class, 'translation_jobs' ] );
		}
		if ( ( current_user_can( 'momo_edit_dietary_claims' ) || current_user_can( 'momo_manage_certificates' ) ) && post_type_exists( 'momo_certificate' ) ) {
			$add( 'certificates', __( 'Expiring certificates', 'momos-core' ), [ self::class, 'certificates' ] );
		}
		if ( current_user_can( 'momo_view_notification_log' ) && class_exists( \Momos\Notification\Repository\NotificationRepository::class ) ) {
			$add( 'notifications', __( 'Failed notifications', 'momos-core' ), [ self::class, 'notifications' ] );
		}
		// HO: all pending dishes (publishers); MM: own submissions.
		if ( current_user_can( 'edit_momo_dishes' ) && post_type_exists( 'momo_dish' ) ) {
			$add( 'dishes', __( 'Dishes awaiting review', 'momos-core' ), [ self::class, 'dishes' ] );
		}
		if ( ( current_user_can( 'momo_edit_dietary_claims' ) || current_user_can( 'edit_momo_dishes' ) ) && post_type_exists( 'momo_dish' ) && class_exists( \Momos\Dietary\Service\AllergenPresenter::class ) ) {
			$add( 'allergens', self::t( 'admin.dash.allergen.title' ), [ self::class, 'allergens' ] );
		}
		if ( current_user_can( 'momo_view_audit_log' ) && class_exists( \Momos\Audit\AuditRepository::class ) ) {
			$add( 'recent_changes', __( 'Recent changes', 'momos-core' ), [ self::class, 'recent_changes' ] );
		}
		// Offers: HO sees all pending offers through the Branch module widget (`momos_offers_pending`);
		// CE/BM see their own submissions here; ending soon for HO, CE and BM (b).
		if ( post_type_exists( 'momo_offer' ) && current_user_can( 'edit_momo_offers' ) ) {
			if ( ! current_user_can( 'publish_momo_offers' ) ) {
				$add( 'offers_own', self::t( 'admin.dash.offers_own.title' ), [ self::class, 'offers_own' ] );
			}
			$add( 'offers_ending', self::t( 'admin.dash.offers_ending.title' ), [ self::class, 'offers_ending' ] );
		}
		if ( current_user_can( 'momo_access_all_branches' ) && ( current_user_can( 'edit_momo_dishes' ) || current_user_can( 'edit_pages' ) ) ) {
			$add( 'demo_content', self::t( 'admin.dash.demo.title' ), [ self::class, 'demo_content' ] );
		}
	}

	/** Admin catalog string in the viewer's admin language (ja/en, DEC-037). @param array<string,string|int> $args */
	private static function t( string $key, array $args = [] ): string {
		return momos_t( $key, $args, str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en' );
	}

	private static function is_translator(): bool {
		$u = wp_get_current_user();
		return in_array( 'momo_translator', (array) $u->roles, true );
	}

	/** One linked count line in a catalog phrasing ("{n} …"); positive empty text when 0. */
	private static function catalog_line( int $n, string $key, string $zero_key, string $url ): void {
		if ( 0 === $n ) {
			echo '<p>✓ ' . esc_html( self::t( $zero_key ) ) . '</p>';
			return;
		}
		echo '<p class="momos-dash-count"><a href="' . esc_url( $url ) . '">' . esc_html( self::t( $key, [ 'n' => number_format_i18n( $n ) ] ) ) . '</a></p>';
	}

	private static function count_line( int $n, string $zero, string $label, string $url ): void {
		if ( 0 === $n ) {
			echo '<p>✓ ' . esc_html( $zero ) . '</p>';
			return;
		}
		echo '<p class="momos-dash-count"><a href="' . esc_url( $url ) . '"><strong style="font-size:1.6em">' . esc_html( number_format_i18n( $n ) ) . '</strong> ' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Pending count, the 3 soonest pending requests (each links to A09) and the SLA-overdue count
	 * (spec §13.1); all through the scoped repository (E13).
	 */
	public static function pending(): void {
		$scope  = \Momos\Reservation\Domain\Scope::for_user( get_current_user_id() );
		$counts = \Momos\Reservation\Repository\ReservationRepository::counts_by_status( $scope );
		$url    = admin_url( 'admin.php?page=momos-reservations&status=pending' );
		$n      = (int) ( $counts['pending'] ?? 0 );
		self::count_line( $n, __( 'No pending requests.', 'momos-core' ), __( 'pending requests', 'momos-core' ), $url );
		if ( null !== $scope->branch_ids && ! $scope->branch_ids ) {
			echo '<p>' . esc_html( momos_t( 'admin.user.no_branch', [], str_starts_with( get_user_locale(), 'ja' ) ? 'ja' : 'en' ) ) . '</p>';
			return;
		}
		if ( 0 === $n ) {
			return;
		}
		$overdue = 0;
		if ( class_exists( \Momos\Reservation\Service\SlaClock::class ) ) {
			foreach ( \Momos\Reservation\Repository\ReservationRepository::pending_light( $scope ) as $r ) {
				$overdue += 'overdue' === \Momos\Reservation\Service\SlaClock::state( $r + [ 'status' => 'pending' ] ) ? 1 : 0;
			}
		}
		if ( $overdue > 0 ) {
			echo '<p class="momos-dash-overdue" style="color:#b32d2e"><strong>' . esc_html( self::t( 'admin.dash.pending.overdue', [ 'n' => number_format_i18n( $overdue ) ] ) ) . '</strong></p>';
		}
		$soonest = \Momos\Reservation\Repository\ReservationRepository::search( $scope, [ 'statuses' => [ 'pending' ], 'sort' => 'requested_start', 'order' => 'asc', 'per_page' => 3 ] );
		if ( $soonest['rows'] ) {
			echo '<ul class="momos-dash-list">';
			foreach ( $soonest['rows'] as $r ) {
				$line = self::t(
					'admin.dash.pending.item',
					[
						'ref'    => (string) $r['public_ref'],
						'date'   => (string) $r['requested_date'],
						'time'   => substr( (string) $r['requested_time'], 0, 5 ),
						'party'  => (int) $r['party_size'],
						'branch' => (string) get_post_meta( (int) $r['branch_id'], 'momo_branch_code', true ),
					]
				);
				echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=momos-reservations&id=' . (int) $r['id'] ) ) . '">' . esc_html( $line ) . '</a></li>';
			}
			echo '</ul>';
		}
	}

	/** Needs action (spec §13.1): `needs_action_flag` or customer not notified, open requests in scope. */
	public static function needs_action(): void {
		$scope = \Momos\Reservation\Domain\Scope::for_user( get_current_user_id() );
		$open  = [ 'pending', 'confirmed' ];
		$flag  = \Momos\Reservation\Repository\ReservationRepository::search( $scope, [ 'statuses' => $open, 'flags' => [ 'needs_action' ], 'per_page' => 1 ] );
		$noti  = \Momos\Reservation\Repository\ReservationRepository::search( $scope, [ 'statuses' => $open, 'flags' => [ 'not_notified' ], 'per_page' => 1 ] );
		if ( 0 === (int) $flag['total'] && 0 === (int) $noti['total'] ) {
			echo '<p>✓ ' . esc_html( self::t( 'admin.dash.needs_action.none' ) ) . '</p>';
			return;
		}
		if ( (int) $flag['total'] > 0 ) {
			self::catalog_line( (int) $flag['total'], 'admin.dash.needs_action.flagged', 'admin.dash.needs_action.none', admin_url( 'admin.php?page=momos-reservations&view=needs' ) );
		}
		if ( (int) $noti['total'] > 0 ) {
			self::catalog_line( (int) $noti['total'], 'admin.dash.needs_action.not_notified', 'admin.dash.needs_action.none', admin_url( 'admin.php?page=momos-reservations&view=all&flags=not_notified' ) );
		}
	}

	/** Attendance to record (RS/BM): confirmed, start in the past, within the 72 h window, no attendance. */
	public static function attendance(): void {
		$uid   = get_current_user_id();
		$scope = \Momos\Reservation\Domain\Scope::for_user( $uid );
		$res   = \Momos\Reservation\Repository\ReservationRepository::search(
			$scope,
			[
				'statuses'  => [ 'confirmed' ],
				'date_from' => Clock::jst()->modify( '-3 days' )->format( 'Y-m-d' ),
				'date_to'   => Clock::jst()->format( 'Y-m-d' ),
				'per_page'  => 50,
			]
		);
		$n = count( array_filter( $res['rows'], static fn( $r ) => \Momos\Reservation\Service\TransitionService::attendance_open( $r, $uid ) ) );
		self::catalog_line( $n, 'admin.dash.attendance.count', 'admin.dash.attendance.none', admin_url( 'admin.php?page=momos-reservations&view=attendance' ) );
	}

	/** Items not approved per enabled target language (pages, branches, dishes, categories). */
	public static function translation_gaps(): void {
		global $wpdb;
		$types = array_values( array_filter( [ 'page', 'momo_branch', 'momo_dish', 'momo_notice', 'momo_offer' ], 'post_type_exists' ) );
		if ( ! $types ) {
			return;
		}
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in})", $types ) ); // phpcs:ignore
		$rows  = [];
		foreach ( Lang::enabled() as $lang ) {
			if ( Lang::SOURCE === $lang ) {
				continue;
			}
			$ok = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'approved' WHERE p.post_status = 'publish' AND p.post_type IN ({$in})", array_merge( [ '_momos_i18n_status_' . $lang ], $types ) ) ); // phpcs:ignore
			$rows[ $lang ] = max( 0, $total - $ok );
		}
		if ( ! array_filter( $rows ) ) {
			echo '<p>✓ ' . esc_html__( 'All published items are translated in every public language.', 'momos-core' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Language', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Items not yet approved', 'momos-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $lang => $n ) {
			echo '<tr><td>' . esc_html( Lang::native( $lang ) ) . '</td><td>' . esc_html( (string) $n ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	public static function certificates(): void {
		$today = Clock::jst()->format( 'Y-m-d' );
		$ids   = get_posts(
			[
				'post_type'      => 'momo_certificate',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore
					[ 'key' => 'momo_cert_status', 'value' => [ 'active', 'expired' ], 'compare' => 'IN' ],
					[ 'key' => 'momo_cert_valid_until', 'value' => [ Clock::jst()->modify( '-7 days' )->format( 'Y-m-d' ), Clock::jst()->modify( '+30 days' )->format( 'Y-m-d' ) ], 'compare' => 'BETWEEN', 'type' => 'DATE' ],
				],
			]
		);
		if ( ! $ids ) {
			echo '<p>✓ ' . esc_html__( 'No certificate expires in the next 30 days.', 'momos-core' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $ids as $id ) {
			$until = (string) get_post_meta( (int) $id, 'momo_cert_valid_until', true );
			$label = $until < $today ? __( 'expired', 'momos-core' ) : __( 'valid until', 'momos-core' );
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=momo-halal-register' ) ) . '">' . esc_html( get_the_title( (int) $id ) ) . '</a> — ' . esc_html( $label . ' ' . $until ) . '</li>';
		}
		echo '</ul>';
	}

	public static function notifications(): void {
		$c = \Momos\Notification\Repository\NotificationRepository::counts();
		self::count_line( (int) $c['failed_24h'], __( 'No failed notifications in the last 24 hours.', 'momos-core' ), __( 'failed in the last 24 hours', 'momos-core' ), admin_url( 'admin.php?page=momo-notifications&status=failed' ) );
	}

	public static function dishes(): void {
		if ( current_user_can( 'publish_momo_dishes' ) ) {
			$n = (int) ( wp_count_posts( 'momo_dish' )->pending ?? 0 );
			self::count_line( $n, __( 'No dishes are waiting for review.', 'momos-core' ), __( 'dishes awaiting review', 'momos-core' ), admin_url( 'edit.php?post_type=momo_dish&post_status=pending' ) );
			return;
		}
		$n = count( get_posts( [ 'post_type' => 'momo_dish', 'post_status' => 'pending', 'author' => get_current_user_id(), 'fields' => 'ids', 'posts_per_page' => 200, 'no_found_rows' => true ] ) );
		self::count_line( $n, __( 'No dishes are waiting for review.', 'momos-core' ), __( 'dishes awaiting review', 'momos-core' ), admin_url( 'edit.php?post_type=momo_dish&post_status=pending&author=' . get_current_user_id() ) );
	}

	/** Dishes with `not_verified` allergen states or the review flag (spec §13.1). */
	public static function allergens(): void {
		$ids = get_posts( [ 'post_type' => 'momo_dish', 'post_status' => [ 'publish', 'pending', 'draft', 'future' ], 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true ] );
		$n   = 0;
		foreach ( $ids as $id ) {
			if ( '1' === (string) get_post_meta( (int) $id, 'momo_allergen_review_required', true ) || \Momos\Dietary\Service\AllergenPresenter::not_verified_codes( (int) $id ) ) {
				++$n;
			}
		}
		self::catalog_line( $n, 'admin.dash.allergen.count', 'admin.dash.allergen.none', admin_url( 'edit.php?post_type=momo_dish' ) );
	}

	/** CE/BM: own offers waiting for approval. */
	public static function offers_own(): void {
		$n = count( get_posts( [ 'post_type' => 'momo_offer', 'post_status' => 'pending', 'author' => get_current_user_id(), 'fields' => 'ids', 'posts_per_page' => 200, 'no_found_rows' => true ] ) );
		self::catalog_line( $n, 'admin.dash.offers_own.count', 'admin.dash.offers_own.none', admin_url( 'edit.php?post_type=momo_offer&post_status=pending&author=' . get_current_user_id() ) );
	}

	/** Published offers ending within 7 days; branch-scoped users see offers that apply to their branches. */
	public static function offers_ending(): void {
		$now   = Clock::mysql_utc();
		$until = Clock::utc()->modify( '+7 days' )->format( 'Y-m-d H:i:s' );
		$ids   = get_posts(
			[
				'post_type'      => 'momo_offer',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'orderby'        => 'meta_value',
				'meta_key'       => '_momo_offer_end_utc', // phpcs:ignore
				'order'          => 'ASC',
				'meta_query'     => [ [ 'key' => '_momo_offer_end_utc', 'value' => [ $now, $until ], 'compare' => 'BETWEEN', 'type' => 'DATETIME' ] ], // phpcs:ignore
			]
		);
		$scope = momos_user_branch_ids();
		if ( null !== $scope ) {
			$ids = array_values( array_filter( $ids, static fn( $id ): bool => self::offer_applies( (int) $id, $scope ) ) );
		}
		if ( ! $ids ) {
			echo '<p>✓ ' . esc_html( self::t( 'admin.dash.offers_ending.none' ) ) . '</p>';
			return;
		}
		echo '<ul class="momos-dash-list">';
		foreach ( array_slice( $ids, 0, 10 ) as $id ) {
			$end  = Clock::from_mysql_utc( (string) get_post_meta( (int) $id, '_momo_offer_end_utc', true ) );
			$line = self::t( 'admin.dash.offers_ending.item', [ 'title' => get_the_title( (int) $id ), 'date' => $end ? $end->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) : '' ] );
			echo '<li><a href="' . esc_url( admin_url( 'edit.php?post_type=momo_offer&post_status=publish' ) ) . '">' . esc_html( $line ) . '</a></li>';
		}
		echo '</ul>';
	}

	/** @param list<int> $scope */
	private static function offer_applies( int $offer_id, array $scope ): bool {
		if ( in_array( (string) get_post_meta( $offer_id, 'momo_offer_scope', true ), [ 'site_wide', 'all_branches' ], true ) ) {
			return true;
		}
		$branches = class_exists( \Momos\Branch\PostType\BranchScopeTaxonomy::class ) ? \Momos\Branch\PostType\BranchScopeTaxonomy::branch_ids_of_post( $offer_id ) : [];
		return (bool) array_intersect( $branches, $scope );
	}

	/** Demo content remaining (HO, MM, CE): `momo_is_sample` items and illustrative images in use. */
	public static function demo_content(): void {
		global $wpdb;
		$samples = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = '1' AND p.post_status NOT IN ('trash','auto-draft','inherit')", 'momo_is_sample' ) ); // phpcs:ignore
		$illus   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m WHERE m.meta_key = %s AND m.meta_value = '1'", 'momo_image_illustrative' ) ); // phpcs:ignore
		if ( 0 === $samples && 0 === $illus ) {
			echo '<p>✓ ' . esc_html( self::t( 'admin.dash.demo.none' ) ) . '</p>';
			return;
		}
		if ( $samples > 0 ) {
			self::catalog_line( $samples, 'admin.dash.demo.count', 'admin.dash.demo.none', admin_url( 'edit.php?post_type=momo_dish' ) );
		}
		if ( $illus > 0 ) {
			self::catalog_line( $illus, 'admin.dash.demo.illustrative', 'admin.dash.demo.none', admin_url( 'upload.php' ) );
		}
	}

	/** TR: open translation items (missing, draft, needs review, outdated) in the translator's languages. */
	public static function translation_jobs(): void {
		$langs = array_values( array_intersect( Lang::targets(), array_map( 'strval', (array) get_user_meta( get_current_user_id(), 'momo_translator_langs', true ) ) ) );
		$langs = $langs ?: array_values( array_diff( Lang::enabled(), [ Lang::SOURCE ] ) );
		$rows  = [];
		foreach ( \Momos\Kernel\I18n\Admin\TranslationGaps::counts( $langs ) as $lang => $types ) {
			$n = 0;
			foreach ( $types as $states ) {
				$n += array_sum( array_map( 'intval', (array) $states ) );
			}
			if ( $n > 0 ) {
				$rows[ $lang ] = $n;
			}
		}
		if ( ! $rows ) {
			echo '<p>✓ ' . esc_html( self::t( 'admin.dash.tr_jobs.none' ) ) . '</p>';
			return;
		}
		echo '<ul class="momos-dash-list">';
		foreach ( $rows as $lang => $n ) {
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=momos-translation-gaps&lang=' . rawurlencode( (string) $lang ) ) ) . '">' . esc_html( self::t( 'admin.dash.tr_jobs.count', [ 'n' => number_format_i18n( $n ), 'language' => Lang::native( (string) $lang ) ] ) ) . '</a></li>';
		}
		echo '</ul>';
	}

	public static function recent_changes(): void {
		$res  = \Momos\Audit\AuditRepository::search( [ 'from' => Clock::jst()->modify( '-6 days' )->format( 'Y-m-d' ), 'to' => Clock::jst()->format( 'Y-m-d' ), 'page' => 1 ] );
		$rows = array_slice( $res['rows'], 0, 20 );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No changes in the last 7 days.', 'momos-core' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $rows as $r ) {
			echo '<li><code>' . esc_html( (string) $r['action'] ) . '</code> — ' . esc_html( \Momos\Audit\Admin\AuditLogScreen::actor_label( null !== $r['actor_id'] ? (int) $r['actor_id'] : null ) ) . ' · ' . esc_html( \Momos\Audit\Admin\AuditLogScreen::time_jst( (string) $r['occurred_at'] ) ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=momos-audit' ) ) . '">' . esc_html__( 'Open the audit log', 'momos-core' ) . '</a></p>';
	}
}
