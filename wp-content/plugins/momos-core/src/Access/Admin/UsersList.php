<?php
declare(strict_types=1);

namespace Momos\Access\Admin;

use Momos\Access\AccountStatus;
use Momos\Access\BranchScope;
use Momos\Access\TwoFactor\TwoFactor;
use Momos\Access\Users\AccountService;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;

/**
 * SCR-A10 list (native Users screen): columns Branches, 2FA, Status, Last login; filters by branch
 * and status; row actions Disable/Enable and Reset two-factor; no Delete anywhere (BR-ADM-01).
 * Row actions post to admin-post.php (`momos_user_status`, `momos_user_2fa_reset`) with nonces.
 */
final class UsersList {
	public static function register(): void {
		add_filter( 'manage_users_columns', [ self::class, 'columns' ] );
		add_filter( 'manage_users_custom_column', [ self::class, 'column' ], 10, 3 );
		add_filter( 'user_row_actions', [ self::class, 'row_actions' ], 10, 2 );
		add_filter( 'bulk_actions-users', [ self::class, 'bulk_actions' ] );
		add_action( 'restrict_manage_users', [ self::class, 'filters' ] );
		add_action( 'pre_get_users', [ self::class, 'apply_filters' ] );
		add_action( 'admin_post_momos_user_status', [ self::class, 'handle_status' ] );
		add_action( 'admin_post_momos_user_2fa_reset', [ self::class, 'handle_2fa_reset' ] );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
	}

	/** @param array<string,string> $cols */
	public static function columns( $cols ): array {
		$cols = (array) $cols;
		unset( $cols['posts'] );
		$cols['momos_branches'] = __( 'Branches', 'momos-core' );
		$cols['momos_2fa']      = __( '2FA', 'momos-core' );
		$cols['momos_status']   = __( 'Status', 'momos-core' );
		$cols['momos_login']    = __( 'Last login', 'momos-core' );
		return $cols;
	}

	public static function column( $output, $column, $user_id ): string {
		$uid = (int) $user_id;
		switch ( $column ) {
			case 'momos_branches':
				$codes = array_map( static fn( int $b ): string => (string) get_post_meta( $b, 'momo_branch_code', true ) ?: '#' . $b, BranchScope::rows( $uid ) );
				return esc_html( $codes ? implode( ', ', $codes ) : '—' );
			case 'momos_2fa':
				return esc_html( TwoFactor::is_enrolled( $uid ) ? __( 'Enrolled', 'momos-core' ) : __( 'Not enrolled', 'momos-core' ) );
			case 'momos_status':
				if ( AccountStatus::is_disabled( $uid ) ) {
					return esc_html__( 'Disabled', 'momos-core' );
				}
				$exp = AccountStatus::expires_at( $uid );
				if ( AccountStatus::is_vendor( $uid ) && $exp ) {
					/* translators: %s: JST date/time */
					return esc_html( AccountStatus::is_expired( $uid ) ? __( 'Expired', 'momos-core' ) : sprintf( __( 'Vendor until %s', 'momos-core' ), $exp->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) ) );
				}
				return esc_html__( 'Active', 'momos-core' );
			case 'momos_login':
				$d = Clock::from_mysql_utc( (string) get_user_meta( $uid, AccountStatus::META_LAST_LOGIN, true ) );
				return esc_html( $d ? $d->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) : '—' );
		}
		return (string) $output;
	}

	/** @param array<string,string> $actions */
	public static function row_actions( $actions, $user ): array {
		$actions = (array) $actions;
		unset( $actions['delete'], $actions['remove'] );
		$uid = (int) $user->ID;
		if ( ! UserAccessFields::can_manage( $uid ) ) {
			return $actions;
		}
		if ( ! AccountStatus::is_vendor( $uid ) ) {
			$do                      = AccountStatus::is_disabled( $uid ) ? 'enable' : 'disable';
			$actions['momos_status'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=momos_user_status&do=' . $do . '&user=' . $uid ), 'momos_user_status_' . $uid ) ),
				esc_html( 'enable' === $do ? __( 'Enable', 'momos-core' ) : __( 'Disable', 'momos-core' ) )
			);
		}
		if ( TwoFactor::is_enrolled( $uid ) ) {
			$actions['momos_2fa'] = sprintf(
				'<a href="%s" onclick="return confirm(this.dataset.confirm)" data-confirm="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=momos_user_2fa_reset&user=' . $uid ), 'momos_user_2fa_reset_' . $uid ) ),
				esc_attr__( 'Reset two-factor for this user? Confirm their identity first.', 'momos-core' ),
				esc_html__( 'Reset two-factor', 'momos-core' )
			);
		}
		return $actions;
	}

	/** @param array<string,string> $actions */
	public static function bulk_actions( $actions ): array {
		$actions = (array) $actions;
		unset( $actions['delete'], $actions['remove'] );
		return $actions;
	}

	public static function filters( $which = 'top' ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$branch = isset( $_GET['momos_branch'] ) ? (int) $_GET['momos_branch'] : 0; // phpcs:ignore
		$status = isset( $_GET['momos_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['momos_status'] ) ) : ''; // phpcs:ignore
		echo '<label class="screen-reader-text" for="momos_branch">' . esc_html__( 'Branch', 'momos-core' ) . '</label><select name="momos_branch" id="momos_branch"><option value="">' . esc_html__( 'All branches', 'momos-core' ) . '</option>';
		foreach ( UserAccessFields::branch_choices() as $id => $label ) {
			echo '<option value="' . (int) $id . '"' . selected( $branch, (int) $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><label class="screen-reader-text" for="momos_status_f">' . esc_html__( 'Status', 'momos-core' ) . '</label><select name="momos_status" id="momos_status_f"><option value="">' . esc_html__( 'Any status', 'momos-core' ) . '</option>';
		foreach ( [ 'active' => __( 'Active', 'momos-core' ), 'disabled' => __( 'Disabled', 'momos-core' ), 'vendor' => __( 'Vendor', 'momos-core' ) ] as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'momos-core' ), '', 'momos_filter', false );
	}

	/** @param \WP_User_Query $q */
	public static function apply_filters( $q ): void {
		global $pagenow, $wpdb;
		if ( ! is_admin() || 'users.php' !== $pagenow ) {
			return;
		}
		$branch = isset( $_GET['momos_branch'] ) ? (int) $_GET['momos_branch'] : 0; // phpcs:ignore
		$status = isset( $_GET['momos_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['momos_status'] ) ) : ''; // phpcs:ignore
		if ( $branch > 0 ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . Tables::user_branches() . ' WHERE branch_id = %d', $branch ) ) ); // phpcs:ignore
			$q->set( 'include', $ids ?: [ 0 ] );
		}
		$mq = (array) $q->get( 'meta_query' );
		if ( 'disabled' === $status ) {
			$mq[] = [ 'key' => AccountStatus::META_DISABLED, 'value' => '1' ];
		} elseif ( 'active' === $status ) {
			$mq[] = [ 'key' => AccountStatus::META_DISABLED, 'compare' => 'NOT EXISTS' ];
		} elseif ( 'vendor' === $status ) {
			$mq[] = [ 'key' => AccountStatus::META_VENDOR, 'value' => '1' ];
		}
		if ( $mq ) {
			$q->set( 'meta_query', $mq );
		}
	}

	private static function target(): int {
		return isset( $_GET['user'] ) ? (int) $_GET['user'] : 0; // phpcs:ignore
	}

	private static function done( string $msg, bool $error = false ): never {
		$key = 'momos_users_msg_' . get_current_user_id();
		set_transient( $key, [ $msg, $error ], 120 );
		wp_safe_redirect( admin_url( 'users.php' ) );
		exit;
	}

	public static function handle_status(): void {
		$uid = self::target();
		check_admin_referer( 'momos_user_status_' . $uid );
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( (string) $_GET['do'] ) ) : ''; // phpcs:ignore
		try {
			if ( 'disable' === $do ) {
				$warnings = AccountService::disable( $uid, get_current_user_id() );
				self::done( trim( __( 'User disabled. All sessions have ended.', 'momos-core' ) . ' ' . implode( ' ', $warnings ) ) );
			}
			if ( 'enable' === $do ) {
				AccountService::enable( $uid, get_current_user_id() );
				self::done( __( 'User enabled. Review the kept branch assignments.', 'momos-core' ) );
			}
		} catch ( \InvalidArgumentException $e ) {
			momos_audit( 'access.denied', 'user', $uid ?: null, null, [ 'channel' => 'admin-post', 'route' => 'momos_user_status' ] );
			self::done( $e->getMessage(), true );
		}
		self::done( __( 'Unknown action.', 'momos-core' ), true );
	}

	public static function handle_2fa_reset(): void {
		$uid = self::target();
		check_admin_referer( 'momos_user_2fa_reset_' . $uid );
		try {
			AccountService::reset_2fa( $uid, get_current_user_id() );
			self::done( __( 'Two-factor was reset. The user enrols again at the next login.', 'momos-core' ) );
		} catch ( \InvalidArgumentException $e ) {
			momos_audit( 'access.denied', 'user', $uid ?: null, null, [ 'channel' => 'admin-post', 'route' => 'momos_user_2fa_reset' ] );
			self::done( $e->getMessage(), true );
		}
	}

	public static function notice(): void {
		$key = 'momos_users_msg_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! is_array( $msg ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice %s is-dismissible" role="%s"><p>%s</p></div>', $msg[1] ? 'notice-error' : 'notice-success', $msg[1] ? 'alert' : 'status', esc_html( (string) $msg[0] ) );
	}
}
