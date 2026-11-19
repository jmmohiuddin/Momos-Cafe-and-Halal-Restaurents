<?php
declare(strict_types=1);

namespace Momos\Audit\Admin;

use Momos\Audit\AuditRepository;
use Momos\Audit\Catalogue;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Support\Json;

/**
 * SCR-A16 Audit log viewer (FR-ADM-004, AC-ADM-03-01…04): read-only, SA/HO (`momo_view_audit_log`),
 * filters (date range JST, actor, action, object type/ID, branch), 50 rows per page, ORDER BY id DESC.
 * No edit or delete controls exist. Other roles get 403 + `access.denied` (TC-ACL-018).
 */
final class AuditLogScreen {
	public const SLUG = 'momos-audit';
	public const CAP  = 'momo_view_audit_log';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 40 );
	}

	public static function menu(): void {
		add_submenu_page( \Momos\Admin\AdminModule::MENU, __( 'Audit log', 'momos-core' ), __( 'Audit log', 'momos-core' ), self::CAP, self::SLUG, [ self::class, 'render' ] );
	}

	/** @return array<string,mixed> validated filters from the query string */
	public static function filters(): array {
		$get    = static fn( string $k ): string => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only GET filters.
		$date   = static fn( string $v ): ?string => ( '' !== $v && \DateTimeImmutable::createFromFormat( '!Y-m-d', $v ) && \DateTimeImmutable::createFromFormat( '!Y-m-d', $v )->format( 'Y-m-d' ) === $v ) ? $v : null;
		$f      = [
			'from'        => $date( $get( 'from' ) ) ?? Clock::jst()->modify( '-6 days' )->format( 'Y-m-d' ),
			'to'          => $date( $get( 'to' ) ) ?? Clock::jst()->format( 'Y-m-d' ),
			'actor'       => 'system' === $get( 'actor' ) ? 'system' : ( ctype_digit( $get( 'actor' ) ) ? (int) $get( 'actor' ) : '' ),
			'action'      => '',
			'object_type' => sanitize_key( $get( 'object_type' ) ),
			'object_id'   => ctype_digit( $get( 'object_id' ) ) ? (int) $get( 'object_id' ) : 0,
			'branch_id'   => ctype_digit( $get( 'branch' ) ) ? (int) $get( 'branch' ) : 0,
			'page'        => max( 1, (int) $get( 'paged' ) ),
			'error'       => '',
		];
		$action = $get( 'audit_action' );
		if ( '' !== $action && ( in_array( $action, Catalogue::all(), true ) || ( str_ends_with( $action, '.*' ) && isset( Catalogue::grouped()[ substr( $action, 0, -2 ) ] ) ) ) ) {
			$f['action'] = $action;
		}
		if ( $f['to'] < $f['from'] ) {
			$f['error'] = __( 'The end date must be on or after the start date.', 'momos-core' );
			$f['to']    = $f['from'];
		}
		return $f;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'audit', null, null, [ 'cap' => self::CAP, 'channel' => 'admin', 'route' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$filters  = self::filters();
		$result   = AuditRepository::search( $filters );
		$rows     = $result['rows'];
		$total    = $result['total'];
		$pages    = (int) ceil( $total / AuditRepository::PER_PAGE );
		$actors   = self::actor_choices();
		$branches = self::branch_choices();
		$types    = AuditRepository::object_types();
		$groups   = Catalogue::grouped();
		include MOMOS_CORE_DIR . '/templates/admin/audit-log.php';
	}

	/** @return array<int,string> user id => display name ("(disabled)" suffix) */
	public static function actor_choices(): array {
		$out = [];
		foreach ( AuditRepository::actor_ids() as $id ) {
			$out[ $id ] = self::actor_label( $id );
		}
		asort( $out );
		return $out;
	}

	/** Viewer's admin language for catalog strings (ja/en, DEC-037). */
	public static function admin_lang(): string {
		return str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en';
	}

	/** SCR-A16 Role column: display name of the stored role slug (e.g. 本部管理者), slug when unknown. */
	public static function role_label( string $slug ): string {
		if ( '' === $slug ) {
			return '—';
		}
		return \Momos\Access\Roles\RoleInstaller::role_label( $slug );
	}

	/** SCR-A16 Action column label (catalog `admin.audit.action.<key>`); '' when the action has no label. */
	public static function action_label( string $action ): string {
		$key   = 'admin.audit.action.' . $action;
		$label = momos_t( $key, [], self::admin_lang() );
		return $label === $key ? '' : $label;
	}

	public static function actor_label( ?int $id ): string {
		if ( ! $id ) {
			return __( 'System', 'momos-core' );
		}
		$u = get_userdata( $id );
		if ( ! $u ) {
			/* translators: %d: user id */
			return sprintf( __( 'User #%d', 'momos-core' ), $id );
		}
		$name = $u->display_name ?: $u->user_login;
		return '1' === (string) get_user_meta( $id, 'momo_account_disabled', true ) ? $name . ' ' . __( '(disabled)', 'momos-core' ) : $name;
	}

	/** @return array<int,string> branch id => "CODE name" (all statuses) */
	public static function branch_choices(): array {
		$out = [];
		foreach ( get_posts( [ 'post_type' => 'momo_branch', 'post_status' => 'any', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ] ) as $p ) {
			$out[ (int) $p->ID ] = trim( (string) get_post_meta( $p->ID, 'momo_branch_code', true ) . ' ' . $p->post_title );
		}
		return $out;
	}

	public static function branch_code( ?int $branch_id ): string {
		if ( ! $branch_id ) {
			return '';
		}
		$code = (string) get_post_meta( $branch_id, 'momo_branch_code', true );
		return '' !== $code ? $code : '#' . $branch_id;
	}

	/** Object link only when the viewer may open it. */
	public static function object_link( string $type, ?int $id ): ?string {
		if ( ! $id ) {
			return null;
		}
		if ( 'user' === $type && current_user_can( 'edit_user', $id ) ) {
			return get_edit_user_link( $id );
		}
		if ( 'reservation' === $type && current_user_can( 'momo_view_reservation', $id ) ) {
			return admin_url( 'admin.php?page=momos-reservations&id=' . $id );
		}
		$post = get_post( $id );
		if ( $post && $post->post_type === $type && current_user_can( 'edit_post', $id ) ) {
			return get_edit_post_link( $id, 'raw' );
		}
		return null;
	}

	/**
	 * Human-readable change lines "field: before → after" (values already masked at write time).
	 *
	 * @return list<string>
	 */
	public static function change_lines( ?string $json ): array {
		$data = Json::decode_array( $json );
		if ( ! $data ) {
			return [];
		}
		$lines = [];
		$diff  = isset( $data['changes'] ) && is_array( $data['changes'] ) ? $data['changes'] : null;
		foreach ( $data as $k => $v ) {
			if ( 'changes' === $k && null !== $diff ) {
				continue;
			}
			$lines[] = self::line( (string) $k, $v );
		}
		foreach ( (array) $diff as $k => $v ) {
			$lines[] = self::line( (string) $k, $v );
		}
		return array_slice( $lines, 0, 30 );
	}

	private static function line( string $key, mixed $v ): string {
		if ( is_array( $v ) && ( array_key_exists( 'from', $v ) || array_key_exists( 'to', $v ) ) ) {
			return $key . ': ' . self::scalar( $v['from'] ?? null ) . ' → ' . self::scalar( $v['to'] ?? null );
		}
		return $key . ': ' . self::scalar( $v );
	}

	private static function scalar( mixed $v ): string {
		if ( null === $v || '' === $v ) {
			return '—';
		}
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		$s = is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return mb_strlen( $s ) > 200 ? mb_substr( $s, 0, 200 ) . '…' : $s;
	}

	public static function time_jst( string $utc ): string {
		$d = Clock::from_mysql_utc( $utc );
		return $d ? $d->setTimezone( Clock::tz() )->format( 'Y-m-d H:i:s' ) : '';
	}
}
