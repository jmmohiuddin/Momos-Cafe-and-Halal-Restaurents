<?php
declare(strict_types=1);

namespace Momos\Notification\Admin;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\Support\Crockford;
use Momos\Notification\Recipient\RecipientResolver;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Service\ResendService;
use Momos\Notification\Support\Brand;
use Momos\Notification\Support\ReservationReader;
use Momos\Notification\Template\TemplateRegistry;
use Momos\Notification\Transport\MailIdentity;
use Momos\Notification\Transport\Message;
use Momos\Notification\Transport\TransportFactory;

/**
 * Notification log view `admin.php?page=momo-notifications` (F-NOT-03 "global failed list", spec
 * §7.3; cap `momo_view_notification_log` — SA/HO). Default filter: failed, last 7 days. Masked
 * recipients only, no bodies, no CSV. Read-only except Resend (nonce + object check, audited) and
 * the "Send test email" tool of SCR-A15 §7.4 (cap `momo_manage_brand`, audited).
 */
final class NotificationLogScreen {
	public const SLUG     = 'momo-notifications';
	public const CAP      = 'momo_view_notification_log';
	public const PER_PAGE = 50;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 25 );
		add_action( 'admin_post_momos_notification_resend', [ self::class, 'handle_resend' ] );
		add_action( 'admin_post_momos_notification_test_send', [ self::class, 'handle_test_send' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'momos', __( 'Notification log', 'momos-core' ), __( 'Notification log', 'momos-core' ), self::CAP, self::SLUG, [ self::class, 'render' ] );
	}

	/**
	 * Validated filters from the query string (whitelists; date range ≤ 180 days).
	 *
	 * @param array<string,mixed> $q
	 * @return array{status:string,template:string,branch_id:int,lang:string,from:string,to:string}
	 */
	public static function filters( array $q ): array {
		$status   = isset( $q['status'] ) ? sanitize_key( (string) $q['status'] ) : 'failed';
		$status   = 'all' === $status ? '' : ( in_array( $status, NotificationRepository::STATUSES, true ) ? $status : 'failed' );
		$template = isset( $q['template'] ) ? strtoupper( sanitize_key( (string) $q['template'] ) ) : '';
		$template = in_array( $template, TemplateRegistry::keys(), true ) ? $template : '';
		$lang     = isset( $q['lang'] ) ? sanitize_key( (string) $q['lang'] ) : '';
		$lang     = Lang::is_valid( $lang ) ? $lang : '';
		$today    = Clock::jst()->format( 'Y-m-d' );
		$from     = isset( $q['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $q['from'] ) ? (string) $q['from'] : Clock::jst()->modify( '-6 days' )->format( 'Y-m-d' );
		$to       = isset( $q['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $q['to'] ) ? (string) $q['to'] : $today;
		if ( $to < $from ) {
			[ $from, $to ] = [ $to, $from ];
		}
		$min = Clock::jst_at( $to, '00:00' )->modify( '-180 days' )->format( 'Y-m-d' );
		if ( $from < $min ) {
			$from = $min;
		}
		return [ 'status' => $status, 'template' => $template, 'branch_id' => isset( $q['branch'] ) ? absint( $q['branch'] ) : 0, 'lang' => $lang, 'from' => $from, 'to' => $to ];
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'notification', null, null, [ 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$f     = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters
		$page  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$scope = momos_user_branch_ids();
		$res   = NotificationRepository::search(
			[
				'status'     => $f['status'],
				'template'   => $f['template'],
				'branch_id'  => $f['branch_id'],
				'lang'       => $f['lang'],
				'from'       => Clock::mysql_utc( Clock::jst_at( $f['from'], '00:00' ) ),
				'to'         => Clock::mysql_utc( Clock::jst_at( $f['to'], '00:00' )->modify( '+1 day' ) ),
				'branch_ids' => $scope,
			],
			$page,
			self::PER_PAGE
		);
		echo '<div class="wrap momos-admin"><h1>' . esc_html__( 'Notification log', 'momos-core' ) . '</h1>';
		self::notices();
		self::filter_form( $f );
		self::table( $res['rows'] );
		self::pagination( $res['total'], $page, $f );
		if ( current_user_can( 'momo_manage_brand' ) ) {
			self::test_form();
		}
		echo '</div>';
	}

	private static function notices(): void {
		$msg = isset( $_GET['momos_msg'] ) ? sanitize_key( (string) $_GET['momos_msg'] ) : ''; // phpcs:ignore
		$map = [
			'resent'             => [ 'success', __( 'Email queued again.', 'momos-core' ) ],
			'invalid_transition' => [ 'error', __( 'This email can no longer be resent (status changed, already resent 3 times, or the address was removed).', 'momos-core' ) ],
			'forbidden_branch'   => [ 'error', __( 'You do not have access to this branch.', 'momos-core' ) ],
			'not_found'          => [ 'error', __( 'Notification not found.', 'momos-core' ) ],
			'rate_limited'       => [ 'error', __( 'Too many resends. Please try again later.', 'momos-core' ) ],
		];
		if ( isset( $map[ $msg ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $map[ $msg ][0] ) . '" role="status"><p>' . esc_html( $map[ $msg ][1] ) . '</p></div>';
		}
		$results = get_transient( 'momos_test_send_' . get_current_user_id() );
		if ( is_array( $results ) && 'test' === $msg ) {
			delete_transient( 'momos_test_send_' . get_current_user_id() );
			echo '<div class="notice notice-info" role="status"><p>' . esc_html__( 'Test email results:', 'momos-core' ) . '</p><ul>';
			foreach ( $results as $r ) {
				echo '<li><code>' . esc_html( (string) $r['masked'] ) . '</code> — ' . esc_html( (string) $r['result'] ) . '</li>';
			}
			if ( ! $results ) {
				echo '<li>' . esc_html__( 'This list has no active address.', 'momos-core' ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	/** @param array{status:string,template:string,branch_id:int,lang:string,from:string,to:string} $f */
	private static function filter_form( array $f ): void {
		echo '<form method="get" class="momos-filters" style="margin:12px 0"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label>' . esc_html__( 'Status', 'momos-core' ) . ' <select name="status"><option value="all"' . selected( $f['status'], '', false ) . '>' . esc_html__( 'All', 'momos-core' ) . '</option>';
		foreach ( NotificationRepository::STATUSES as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $f['status'], $s, false ) . '>' . esc_html( self::status_label( $s ) ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Template', 'momos-core' ) . ' <select name="template"><option value="">' . esc_html__( 'All', 'momos-core' ) . '</option>';
		foreach ( TemplateRegistry::keys() as $k ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['template'], $k, false ) . '>' . esc_html( TemplateRegistry::label( $k ) ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Branch', 'momos-core' ) . ' <select name="branch"><option value="0">' . esc_html__( 'All', 'momos-core' ) . '</option>';
		foreach ( self::branches() as $id => $label ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $f['branch_id'], $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Language', 'momos-core' ) . ' <select name="lang"><option value="">' . esc_html__( 'All', 'momos-core' ) . '</option>';
		foreach ( Lang::codes() as $l ) {
			echo '<option value="' . esc_attr( $l ) . '"' . selected( $f['lang'], $l, false ) . '>' . esc_html( Lang::native( $l ) ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'From', 'momos-core' ) . ' <input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'momos-core' ) . ' <input type="date" name="to" value="' . esc_attr( $f['to'] ) . '"></label> ';
		submit_button( __( 'Filter', 'momos-core' ), 'secondary', '', false );
		echo '<p class="description">' . esc_html__( 'Dates in JST; at most 180 days are kept (DEC-034).', 'momos-core' ) . '</p></form>';
	}

	/** @param list<array<string,mixed>> $rows */
	private static function table( array $rows ): void {
		$cols = [ __( 'Created (JST)', 'momos-core' ), __( 'Template', 'momos-core' ), __( 'Branch', 'momos-core' ), __( 'Reference', 'momos-core' ), __( 'Recipient type', 'momos-core' ), __( 'Recipient', 'momos-core' ), __( 'Language', 'momos-core' ), __( 'Status', 'momos-core' ), __( 'Attempts', 'momos-core' ), __( 'Sent / failed at (JST)', 'momos-core' ), __( 'Last error', 'momos-core' ), __( 'Action', 'momos-core' ) ];
		echo '<table class="widefat striped momos-notification-log"><thead><tr>';
		foreach ( $cols as $c ) {
			echo '<th scope="col">' . esc_html( $c ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . count( $cols ) . '">' . esc_html__( 'No notifications match these filters.', 'momos-core' ) . '</td></tr>';
		}
		$uid = get_current_user_id();
		foreach ( $rows as $row ) {
			$res  = ! empty( $row['reservation_id'] ) ? ReservationReader::find( (int) $row['reservation_id'] ) : null;
			$ref  = $res ? Crockford::display( (string) $res['public_ref'] ) : '';
			$code = ! empty( $row['branch_id'] ) ? (string) get_post_meta( (int) $row['branch_id'], 'momo_branch_code', true ) : '';
			$when = $row['sent_at'] ?: $row['failed_at'];
			echo '<tr>';
			echo '<td>' . esc_html( self::jst( (string) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( TemplateRegistry::label( (string) $row['template_key'], $row['template_variant'] ? (string) $row['template_variant'] : null ) ) . '</td>';
			echo '<td>' . esc_html( $code ) . '</td>';
			echo '<td>' . ( '' !== $ref ? '<a href="' . esc_url( \Momos\Notification\Template\Renderer::admin_reservation_url( (int) $row['reservation_id'] ) ) . '"><code>' . esc_html( $ref ) . '</code></a>' : '' ) . '</td>';
			echo '<td>' . esc_html( (string) $row['recipient_type'] ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['recipient_masked'] ) . '</code></td>';
			echo '<td>' . esc_html( Lang::native( (string) $row['lang'] ) ) . '</td>';
			echo '<td><span class="momos-chip momos-chip--' . esc_attr( (string) $row['status'] ) . '">' . esc_html( self::status_label( (string) $row['status'] ) ) . '</span></td>';
			echo '<td>' . esc_html( (int) $row['attempts'] . '/5' ) . '</td>';
			echo '<td>' . esc_html( $when ? self::jst( (string) $when ) : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['last_error_code'] ?? '' ) ) . '</td>';
			echo '<td>';
			if ( null === ResendService::check( $row, $uid ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="momos_notification_resend"><input type="hidden" name="notification_id" value="' . esc_attr( (string) $row['id'] ) . '">';
				wp_nonce_field( 'momos_notification_resend_' . $row['id'], '_momos_nonce' );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Resend', 'momos-core' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array{status:string,template:string,branch_id:int,lang:string,from:string,to:string} $f */
	private static function pagination( int $total, int $page, array $f ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		$base = add_query_arg( [ 'page' => self::SLUG, 'status' => '' === $f['status'] ? 'all' : $f['status'], 'template' => $f['template'], 'branch' => $f['branch_id'], 'lang' => $f['lang'], 'from' => $f['from'], 'to' => $f['to'] ], admin_url( 'admin.php' ) );
		echo '<nav class="tablenav" aria-label="' . esc_attr__( 'Pagination', 'momos-core' ) . '"><div class="tablenav-pages">';
		echo wp_kses_post( (string) paginate_links( [ 'base' => $base . '%_%', 'format' => '&paged=%#%', 'current' => $page, 'total' => $pages ] ) );
		echo '</div></nav>';
	}

	private static function test_form(): void {
		echo '<h2>' . esc_html__( 'Send test email', 'momos-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="momos_notification_test_send">';
		wp_nonce_field( 'momos_notification_test_send', '_momos_nonce' );
		echo '<label>' . esc_html__( 'Recipient list', 'momos-core' ) . ' <select name="list">';
		echo '<option value="ho">' . esc_html__( 'Head Office notification list', 'momos-core' ) . '</option>';
		echo '<option value="digest">' . esc_html__( 'Additional daily summary recipients', 'momos-core' ) . '</option>';
		foreach ( self::branches() as $id => $label ) {
			/* translators: %s: branch code and name */
			echo '<option value="branch:' . esc_attr( (string) $id ) . '">' . esc_html( sprintf( __( 'Branch recipients: %s', 'momos-core' ), $label ) ) . '</option>';
		}
		echo '</select></label> ';
		submit_button( __( 'Send test email', 'momos-core' ), 'secondary', '', false );
		echo '<p class="description">' . esc_html__( 'Sends a fixed message without reservation data to each active address of the list.', 'momos-core' ) . '</p></form>';
	}

	public static function handle_resend(): void {
		$id = absint( $_POST['notification_id'] ?? 0 );
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'notification', $id ?: null, null, [ 'action' => 'notification.resend' ] );
			wp_die( esc_html__( 'You do not have permission to do this.', 'momos-core' ), 403 );
		}
		check_admin_referer( 'momos_notification_resend_' . $id, '_momos_nonce' );
		$out = ResendService::resend( $id, get_current_user_id() );
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'momos_msg' => $out['ok'] ? 'resent' : (string) $out['code'] ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_test_send(): void {
		if ( ! current_user_can( 'momo_manage_brand' ) ) {
			momos_audit( 'access.denied', 'notification', null, null, [ 'action' => 'notification.test_send' ] );
			wp_die( esc_html__( 'You do not have permission to do this.', 'momos-core' ), 403 );
		}
		check_admin_referer( 'momos_notification_test_send', '_momos_nonce' );
		$list    = sanitize_text_field( wp_unslash( (string) ( $_POST['list'] ?? 'ho' ) ) );
		$results = self::test_send( $list );
		set_transient( 'momos_test_send_' . get_current_user_id(), $results, 300 );
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'momos_msg' => 'test' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Synchronous fixed test message to each active address of a list (spec §7.4). No reservation data.
	 *
	 * @return list<array{masked:string,result:string}>
	 */
	public static function test_send( string $list ): array {
		$branch_id = 0;
		if ( str_starts_with( $list, 'branch:' ) ) {
			$branch_id  = absint( substr( $list, 7 ) );
			$recipients = $branch_id && momos_user_can_branch( $branch_id ) ? RecipientResolver::branch( $branch_id ) : [];
			$list       = 'branch';
		} elseif ( 'digest' === $list ) {
			$recipients = array_map( static fn( $r ) => RecipientResolver::staff( 'head_office', $r['email'], $r['language'] ), RecipientResolver::active_rows( RecipientResolver::setting( 'momo_digest_recipients' ) ) );
		} else {
			$list       = 'ho';
			$recipients = RecipientResolver::head_office();
		}
		$transport = TransportFactory::make();
		$out       = [];
		$sent      = 0;
		foreach ( $recipients as $r ) {
			$lang    = $r['lang'];
			$brand   = Brand::trading_name( $lang );
			$subject = strtr( momos_t( 'staff.email.test.subject', [], $lang ), [ '{brand}' => $brand ] );
			$body    = momos_t( 'staff.email.test.body', [], $lang );
			$html    = '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="utf-8"><title>' . esc_html( $subject ) . '</title></head><body><p>' . esc_html( $body ) . '</p></body></html>';
			$msg     = MailIdentity::finalize( new Message( $r['email'], $subject, $html, $body, MailIdentity::from_address(), Brand::sender_name( $lang ), null, MailIdentity::message_id( wp_generate_uuid4() ), $lang ) );
			$res     = $transport->send( $msg );
			$sent   += $res->ok ? 1 : 0;
			$out[]   = [ 'masked' => RecipientResolver::mask( $r['email'] ), 'result' => $res->ok ? __( 'sent', 'momos-core' ) : $res->error_code ];
		}
		momos_audit( 'notification.test_sent', 'notification', null, $branch_id ?: null, [ 'list' => $list, 'recipients' => count( $out ), 'sent' => $sent ] );
		return $out;
	}

	/** @return array<int,string> branch id => "CODE name" within the user's scope */
	private static function branches(): array {
		$ids   = \Momos\Notification\Job\DigestJob::branch_ids( true );
		$scope = momos_user_branch_ids();
		$out   = [];
		foreach ( $ids as $id ) {
			if ( null === $scope || in_array( $id, $scope, true ) ) {
				$out[ $id ] = trim( get_post_meta( $id, 'momo_branch_code', true ) . ' ' . get_the_title( $id ) );
			}
		}
		return $out;
	}

	public static function status_label( string $s ): string {
		return [
			'queued'    => __( 'Queued', 'momos-core' ),
			'sending'   => __( 'Sending', 'momos-core' ),
			'sent'      => __( 'Sent', 'momos-core' ),
			'failed'    => __( 'Failed', 'momos-core' ),
			'cancelled' => __( 'Cancelled', 'momos-core' ),
		][ $s ] ?? $s;
	}

	private static function jst( string $utc ): string {
		$d = Clock::from_mysql_utc( $utc );
		return $d ? $d->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) : '';
	}
}
