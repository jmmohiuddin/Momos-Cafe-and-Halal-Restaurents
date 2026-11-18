<?php
declare(strict_types=1);

namespace Momos\Notification\Admin;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Service\ResendService;
use Momos\Notification\Template\TemplateRegistry;

/**
 * SCR-A09 notification panel (TK-10-07, spec §7.1) and the `notifications` array of API-11 (§8.1).
 * Rendered by the Reservation detail screen through `do_action( 'momos/admin/reservation_detail',
 * int $reservation_id )`. Masked recipients only; raw error text never shown to BM/RS.
 */
final class NotificationPanel {
	public static function register(): void {
		add_action( 'momos/admin/reservation_detail', [ self::class, 'render' ], 20, 1 );
		add_action( 'admin_post_momos_notification_resend_detail', [ self::class, 'handle_resend' ] );
	}

	private static function can_view( int $reservation_id, int $branch_id ): bool {
		return current_user_can( 'momo_view_reservations' ) && momos_user_can_branch( $branch_id );
	}

	/**
	 * API-11 `notifications` (no recipient_email; `last_error` text only for SA/HO).
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function api_rows( int $reservation_id, int $user_id ): array {
		$full = user_can( $user_id, 'momo_view_notification_log' );
		$out  = [];
		foreach ( NotificationRepository::for_reservation( $reservation_id ) as $r ) {
			$item = [
				'id'                  => (int) $r['id'],
				'template_key'        => (string) $r['template_key'],
				'template_variant'    => $r['template_variant'],
				'lang'                => (string) $r['lang'],
				'status'              => (string) $r['status'],
				'attempts'            => (int) $r['attempts'],
				'next_attempt_at'     => $r['next_attempt_at'],
				'sent_at'             => $r['sent_at'],
				'failed_at'           => $r['failed_at'],
				'recipient_type'      => (string) $r['recipient_type'],
				'recipient_masked'    => (string) $r['recipient_masked'],
				'provider_message_id' => $r['provider_message_id'],
				'last_error_code'     => $r['last_error_code'],
				'can_resend'          => ResendService::is_latest_resendable( $r, $user_id ),
			];
			if ( $full ) {
				$item['last_error'] = $r['last_error'];
			}
			$out[] = $item;
		}
		return $out;
	}

	public static function render( int $reservation_id ): void {
		$rows   = NotificationRepository::for_reservation( $reservation_id );
		$branch = (int) ( $rows[0]['branch_id'] ?? 0 );
		if ( ! $branch ) {
			$res    = \Momos\Notification\Support\ReservationReader::find( $reservation_id );
			$branch = (int) ( $res['branch_id'] ?? 0 );
		}
		if ( ! self::can_view( $reservation_id, $branch ) ) {
			return;
		}
		$uid = get_current_user_id();
		echo '<section class="momos-panel momos-notifications" aria-labelledby="momos-notif-h"><h2 id="momos-notif-h">' . esc_html__( 'Emails', 'momos-core' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No emails for this request yet.', 'momos-core' ) . '</p></section>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Email', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Language', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Recipient', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Status', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Attempts', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Next attempt / sent (JST)', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Message ID', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Error', 'momos-core' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$when = $r['sent_at'] ?: ( $r['next_attempt_at'] ?: $r['failed_at'] );
			$jst  = $when ? Clock::from_mysql_utc( (string) $when )?->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) : '—';
			echo '<tr><td>' . esc_html( TemplateRegistry::label( (string) $r['template_key'], $r['template_variant'] ? (string) $r['template_variant'] : null ) ) . '</td>';
			echo '<td>' . esc_html( Lang::native( (string) $r['lang'] ) ) . '</td>';
			echo '<td><code>' . esc_html( (string) $r['recipient_masked'] ) . '</code></td>';
			echo '<td><span class="momos-chip momos-chip--' . esc_attr( (string) $r['status'] ) . '">' . ( 'failed' === $r['status'] ? '<span aria-hidden="true">⚠ </span>' : '' ) . esc_html( NotificationLogScreen::status_label( (string) $r['status'] ) ) . '</span></td>';
			echo '<td>' . esc_html( (int) $r['attempts'] . '/5' ) . '</td>';
			echo '<td>' . esc_html( (string) $jst ) . '</td>';
			echo '<td><code title="' . esc_attr( (string) $r['provider_message_id'] ) . '">' . esc_html( mb_strimwidth( (string) $r['provider_message_id'], 0, 18, '…' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) $r['last_error_code'] ) . '</td><td>';
			if ( ResendService::is_latest_resendable( $r, $uid ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="momos_notification_resend_detail"><input type="hidden" name="notification_id" value="' . esc_attr( (string) $r['id'] ) . '"><input type="hidden" name="reservation_id" value="' . esc_attr( (string) $reservation_id ) . '">';
				wp_nonce_field( 'momos_notification_resend_' . $r['id'], '_momos_nonce' );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Resend', 'momos-core' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/** admin-post handler for the A09 Resend button (BM/RS within branch scope). */
	public static function handle_resend(): void {
		$id  = absint( $_POST['notification_id'] ?? 0 );
		$rid = absint( $_POST['reservation_id'] ?? 0 );
		if ( ! current_user_can( 'momo_process_reservations' ) && ! current_user_can( 'momo_view_notification_log' ) ) {
			momos_audit( 'access.denied', 'notification', $id ?: null, null, [ 'action' => 'notification.resend' ] );
			wp_die( esc_html__( 'You do not have permission to do this.', 'momos-core' ), 403 );
		}
		check_admin_referer( 'momos_notification_resend_' . $id, '_momos_nonce' );
		$out = ResendService::resend( $id, get_current_user_id(), $rid );
		$url = \Momos\Notification\Template\Renderer::admin_reservation_url( $rid );
		wp_safe_redirect( add_query_arg( 'momos_msg', $out['ok'] ? 'resent' : (string) $out['code'], $url ) );
		exit;
	}
}
