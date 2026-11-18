<?php
declare(strict_types=1);

namespace Momos\Admin\Dashboard;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;

/**
 * SCR-A02 role-aware dashboard (F-ADM-01 / FR-ADM-001, Should; spec §13.1). A widget is registered
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

		if ( current_user_can( 'momo_view_reservations' ) && class_exists( \Momos\Reservation\Repository\ReservationRepository::class ) && class_exists( \Momos\Reservation\Domain\Scope::class ) ) {
			$add( 'pending', __( 'My pending requests', 'momos-core' ), [ self::class, 'pending' ] );
		}
		if ( current_user_can( 'momo_translate' ) ) {
			$add( 'translation_gaps', __( 'Translation gaps', 'momos-core' ), [ self::class, 'translation_gaps' ] );
		}
		if ( ( current_user_can( 'momo_edit_dietary_claims' ) || current_user_can( 'momo_manage_certificates' ) ) && post_type_exists( 'momo_certificate' ) ) {
			$add( 'certificates', __( 'Expiring certificates', 'momos-core' ), [ self::class, 'certificates' ] );
		}
		if ( current_user_can( 'momo_view_notification_log' ) && class_exists( \Momos\Notification\Repository\NotificationRepository::class ) ) {
			$add( 'notifications', __( 'Failed notifications', 'momos-core' ), [ self::class, 'notifications' ] );
		}
		// "Offers awaiting approval" / "Offers ending soon" are provided by the Branch module (ListScreens).
		if ( current_user_can( 'publish_momo_dishes' ) && post_type_exists( 'momo_dish' ) ) {
			$add( 'dishes', __( 'Dishes awaiting review', 'momos-core' ), [ self::class, 'dishes' ] );
		}
		if ( current_user_can( 'momo_view_audit_log' ) && class_exists( \Momos\Audit\AuditRepository::class ) ) {
			$add( 'recent_changes', __( 'Recent changes', 'momos-core' ), [ self::class, 'recent_changes' ] );
		}
	}

	private static function count_line( int $n, string $zero, string $label, string $url ): void {
		if ( 0 === $n ) {
			echo '<p>✓ ' . esc_html( $zero ) . '</p>';
			return;
		}
		echo '<p class="momos-dash-count"><a href="' . esc_url( $url ) . '"><strong style="font-size:1.6em">' . esc_html( number_format_i18n( $n ) ) . '</strong> ' . esc_html( $label ) . '</a></p>';
	}

	public static function pending(): void {
		$scope  = \Momos\Reservation\Domain\Scope::for_user( get_current_user_id() );
		$counts = \Momos\Reservation\Repository\ReservationRepository::counts_by_status( $scope );
		$url    = admin_url( 'admin.php?page=momos-reservations&status=pending' );
		self::count_line( (int) ( $counts['pending'] ?? 0 ), __( 'No pending requests.', 'momos-core' ), __( 'pending requests', 'momos-core' ), $url );
		if ( null !== $scope->branch_ids && ! $scope->branch_ids ) {
			echo '<p>' . esc_html( momos_t( 'admin.user.no_branch', [], str_starts_with( get_user_locale(), 'ja' ) ? 'ja' : 'en' ) ) . '</p>';
			return;
		}
		$search = \Momos\Reservation\Repository\ReservationRepository::search( $scope, [ 'flags' => [ 'needs_action' ], 'per_page' => 1 ] );
		if ( (int) $search['total'] > 0 ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=momos-reservations&flags=needs_action' ) ) . '">' . esc_html( sprintf( /* translators: %d: count */ __( '%d need action', 'momos-core' ), (int) $search['total'] ) ) . '</a></p>';
		}
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
		$n = (int) ( wp_count_posts( 'momo_dish' )->pending ?? 0 );
		self::count_line( $n, __( 'No dishes are waiting for review.', 'momos-core' ), __( 'dishes awaiting review', 'momos-core' ), admin_url( 'edit.php?post_type=momo_dish&post_status=pending' ) );
	}

	public static function recent_changes(): void {
		$res = \Momos\Audit\AuditRepository::search( [ 'from' => Clock::jst()->modify( '-6 days' )->format( 'Y-m-d' ), 'to' => Clock::jst()->format( 'Y-m-d' ), 'page' => 1 ] );
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
