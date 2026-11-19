<?php
declare(strict_types=1);

namespace Momos\Privacy\Admin;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Support\Json;
use Momos\Privacy\Consent\ConsentRegistry;
use Momos\Privacy\Dsr\DsrService;
use Momos\Privacy\Retention\RetentionJob;

/**
 * SCR-A19 Data-subject requests (`admin.php?page=momos-dsr`, cap `momo_manage_dsr`; F-PRV-03,
 * FR-PRV-004, US-PRV-003, AC-PRV-03-01…03; Access spec §10.5, §13.1, §14).
 *
 * Handlers: admin-post `momos_dsr_{search,export,correct,anonymize}` — capability + nonce on each;
 * denied → 403 + `access.denied` (BM/RS/vendor, AC-PRV-03-03). The searched value never appears in a
 * URL, log or audit row: a search stores only {by, reservation ids} in a per-user transient (15 min)
 * whose random token is the only thing in the redirect URL. Export is streamed JSON with no personal
 * data in the file name and never written to disk. Also shows the retention status and the consent
 * version history (read-only).
 */
final class DsrScreen {
	public const SLUG    = 'momos-dsr';
	public const CAP     = 'momo_manage_dsr';
	public const NONCE   = 'momos_dsr';
	public const TTL     = 15 * MINUTE_IN_SECONDS;
	public const ACTIONS = [ 'search', 'export', 'correct', 'anonymize' ];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 45 );
		foreach ( self::ACTIONS as $a ) {
			add_action( 'admin_post_momos_dsr_' . $a, [ self::class, 'handle_' . $a ] );
		}
	}

	public static function menu(): void {
		add_submenu_page( \Momos\Admin\AdminModule::MENU, __( 'Data requests', 'momos-core' ), __( 'Data requests', 'momos-core' ), self::CAP, self::SLUG, [ self::class, 'render' ] );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	public static function admin_lang(): string {
		return str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en';
	}

	// ------------------------------------------------------------------ guards

	/** Capability check for screens and handlers; denial is audited and answered with 403. */
	public static function guard( string $route ): void {
		if ( current_user_can( self::CAP ) ) {
			return;
		}
		momos_audit( 'access.denied', 'dsr', null, null, [ 'cap' => self::CAP, 'channel' => 'admin' === $route ? 'admin' : 'admin-post', 'route' => $route ] );
		wp_die( esc_html__( 'You do not have permission to manage data-subject requests.', 'momos-core' ), '', [ 'response' => 403 ] );
	}

	private static function verify_post( string $route ): void {
		self::guard( $route );
		check_admin_referer( self::NONCE, '_momos_dsr_nonce' );
	}

	// ------------------------------------------------------------------ result store (no PII)

	/** @param array{by:?string,ids:list<int>} $result */
	public static function store( array $result ): string {
		$token = strtolower( wp_generate_password( 20, false, false ) );
		set_transient( self::key( $token ), [ 'by' => (string) $result['by'], 'ids' => array_values( array_map( 'intval', $result['ids'] ) ) ], self::TTL );
		return $token;
	}

	/** @return array{by:string,ids:list<int>}|null */
	public static function load( string $token ): ?array {
		if ( ! preg_match( '/^[a-z0-9]{20}$/', $token ) ) {
			return null;
		}
		$v = get_transient( self::key( $token ) );
		return is_array( $v ) && isset( $v['ids'] ) ? [ 'by' => (string) ( $v['by'] ?? '' ), 'ids' => array_map( 'intval', (array) $v['ids'] ) ] : null;
	}

	private static function key( string $token ): string {
		return 'momos_dsr_' . get_current_user_id() . '_' . $token;
	}

	private static function post( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- verified in verify_post().
	}

	/** @return list<int> selected ids that belong to the stored result set */
	private static function selected( ?array $result ): array {
		$raw = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', wp_unslash( $_POST['ids'] ) ) : []; // phpcs:ignore WordPress.Security.NonceVerification
		return null === $result ? [] : array_values( array_intersect( $raw, $result['ids'] ) );
	}

	private static function back( string $token, array $args ): void {
		wp_safe_redirect( self::url( array_merge( '' !== $token ? [ 'r' => $token ] : [], $args ) ) );
		exit;
	}

	// ------------------------------------------------------------------ handlers

	public static function handle_search(): void {
		self::verify_post( 'momos_dsr_search' );
		$result = DsrService::search( isset( $_POST['q'] ) && is_string( $_POST['q'] ) ? wp_unslash( $_POST['q'] ) : '' ); // phpcs:ignore -- validated by DsrService::search (exact email/reference only).
		if ( null !== $result['error'] ) {
			self::back( '', [ 'm' => 'search_' . $result['error'] ] );
		}
		self::back( self::store( $result ), [ 'm' => 'searched' ] );
	}

	public static function handle_export(): void {
		self::verify_post( 'momos_dsr_export' );
		$token  = sanitize_key( self::post( 'r' ) );
		$result = self::load( $token );
		$ids    = self::selected( $result );
		if ( ! $ids ) {
			self::back( null === $result ? '' : $token, [ 'm' => null === $result ? 'expired' : 'select' ] );
		}
		$bundle = DsrService::bundle( $ids, (string) $result['by'] );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . DsrService::export_filename() . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		echo (string) wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput -- JSON download.
		exit;
	}

	public static function handle_correct(): void {
		self::verify_post( 'momos_dsr_correct' );
		$token  = sanitize_key( self::post( 'r' ) );
		$result = self::load( $token );
		if ( null === $result ) {
			self::back( '', [ 'm' => 'expired' ] );
		}
		$ids = self::selected( $result );
		if ( ! $ids ) {
			self::back( $token, [ 'm' => 'select' ] );
		}
		$out = DsrService::correct(
			$ids,
			[ 'name' => self::post( 'name' ), 'email' => self::post( 'email' ), 'phone' => self::post( 'phone' ), 'preferred_lang' => sanitize_key( self::post( 'preferred_lang' ) ) ],
			sanitize_key( self::post( 'reason' ) ),
			self::post( 'request_ref' )
		);
		if ( $out['errors'] ) {
			self::back( $token, [ 'm' => 'correct_invalid', 'f' => implode( ',', array_keys( $out['errors'] ) ) ] );
		}
		self::back( $token, [ 'm' => 'corrected', 'n' => $out['updated'] ] );
	}

	public static function handle_anonymize(): void {
		self::verify_post( 'momos_dsr_anonymize' );
		$token  = sanitize_key( self::post( 'r' ) );
		$result = self::load( $token );
		$id     = (int) self::post( 'id' );
		if ( null === $result ) {
			self::back( '', [ 'm' => 'expired' ] );
		}
		if ( ! in_array( $id, $result['ids'], true ) ) {
			self::back( $token, [ 'm' => 'select' ] );
		}
		$code = DsrService::anonymize( $id, self::post( 'confirm_ref' ), sanitize_key( self::post( 'reason' ) ), self::post( 'request_ref' ) );
		if ( 'anonymized' === $code ) {
			$result['ids'] = array_values( array_diff( $result['ids'], [ $id ] ) );
			set_transient( self::key( $token ), $result, self::TTL );
		}
		self::back( $token, [ 'm' => 'anon_' . $code, 'id' => $id ] );
	}

	// ------------------------------------------------------------------ render

	public static function render(): void {
		self::guard( 'admin' );
		$token  = isset( $_GET['r'] ) ? sanitize_key( wp_unslash( (string) $_GET['r'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only.
		$result = '' !== $token ? self::load( $token ) : null;
		$rows   = null !== $result ? DsrService::rows( $result['ids'] ) : [];
		$notice = self::notice();
		$lang   = self::admin_lang();
		$now    = Clock::utc();
		$view   = [];
		foreach ( $rows as $r ) {
			$branch = DsrService::branch( (int) $r['branch_id'] );
			$status = \Momos\Reservation\Domain\ReservationStatus::tryFrom( (string) $r['status'] );
			$view[] = [
				'id'         => (int) $r['id'],
				'ref'        => \Momos\Kernel\Support\Crockford::display( (string) $r['public_ref'] ),
				'branch'     => $branch['code'],
				'when'       => $r['requested_date'] . ' ' . substr( (string) $r['requested_time'], 0, 5 ),
				'status'     => $status ? $status->staffLabel() : (string) $r['status'],
				'lang'       => (string) $r['preferred_lang'],
				'created'    => wp_date( 'Y-m-d H:i', (int) Clock::from_mysql_utc( (string) $r['created_at'] )?->getTimestamp(), Clock::tz() ),
				'blocked'    => DsrService::is_active_future( $r, $now ),
				'detail_url' => class_exists( \Momos\Reservation\Admin\InboxPage::class ) ? \Momos\Reservation\Admin\InboxPage::url( [ 'id' => (int) $r['id'] ] ) : '',
			];
		}
		$retention = RetentionJob::last();
		$consent   = ConsentRegistry::history();
		$current   = ConsentRegistry::stored_key();
		$in_force  = ConsentRegistry::current();
		$langs     = \Momos\Kernel\I18n\Lang::codes();
		include MOMOS_CORE_DIR . '/templates/admin/privacy-dsr.php';
	}

	/** @return array{type:string,text:string,link?:string}|null message from the redirect code (no PII) */
	public static function notice(): ?array {
		$m    = isset( $_GET['m'] ) ? sanitize_key( wp_unslash( (string) $_GET['m'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$lang = self::admin_lang();
		$n    = isset( $_GET['n'] ) ? (int) $_GET['n'] : 0; // phpcs:ignore
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore
		$f    = isset( $_GET['f'] ) ? array_filter( array_map( 'sanitize_key', explode( ',', wp_unslash( (string) $_GET['f'] ) ) ) ) : []; // phpcs:ignore
		$msg  = match ( $m ) {
			'searched'              => null,
			'search_empty'          => [ 'error', __( 'Enter an email address or a reservation reference.', 'momos-core' ) ],
			'search_invalid'        => [ 'error', __( 'Enter a complete email address or an 8-character reservation reference.', 'momos-core' ) ],
			'expired'               => [ 'warning', __( 'The search result has expired. Search again.', 'momos-core' ) ],
			'select'                => [ 'error', __( 'Select at least one reservation from the search result.', 'momos-core' ) ],
			/* translators: %d: number of reservations */
			'corrected'             => [ 'success', sprintf( _n( '%d reservation corrected.', '%d reservations corrected.', $n, 'momos-core' ), $n ) ],
			/* translators: %s: comma-separated field names */
			'correct_invalid'       => [ 'error', sprintf( __( 'Nothing was changed. Check these fields: %s', 'momos-core' ), implode( ', ', array_map( [ self::class, 'field_label' ], $f ) ) ) ],
			'anon_anonymized'       => [ 'success', __( 'The reservation was anonymised. Its personal data cannot be restored.', 'momos-core' ) ],
			'anon_confirm_mismatch' => [ 'error', __( 'The reference you typed does not match. Nothing was changed.', 'momos-core' ) ],
			'anon_blocked_active'   => [ 'warning', momos_t( 'admin.dsr.blocked_active', [], $lang ) ],
			'anon_not_found'        => [ 'error', momos_t( 'admin.dsr.empty', [], $lang ) ],
			'anon_invalid_reason'   => [ 'error', __( 'Choose a reason.', 'momos-core' ) ],
			'anon_invalid_ref'      => [ 'error', __( 'The register reference may contain letters, digits and . _ / # - only (no spaces, at most 40 characters).', 'momos-core' ) ],
			default                 => null,
		};
		if ( null === $msg ) {
			return null;
		}
		$out = [ 'type' => $msg[0], 'text' => $msg[1] ];
		if ( 'anon_blocked_active' === $m && $id > 0 && class_exists( \Momos\Reservation\Admin\InboxPage::class ) ) {
			$out['link'] = \Momos\Reservation\Admin\InboxPage::url( [ 'id' => $id ] );
		}
		return $out;
	}

	public static function field_label( string $key ): string {
		return match ( $key ) {
			'name'           => __( 'Name', 'momos-core' ),
			'email'          => __( 'Email', 'momos-core' ),
			'phone'          => __( 'Phone', 'momos-core' ),
			'preferred_lang' => __( 'Email language', 'momos-core' ),
			'reason'         => __( 'Reason', 'momos-core' ),
			'request_ref'    => __( 'Register reference', 'momos-core' ),
			'fields'         => __( 'at least one new value', 'momos-core' ),
			'conflict'       => __( 'another user changed the reservation — search again', 'momos-core' ),
			default          => $key,
		};
	}

	/** @return array<string,string> */
	public static function correct_reasons(): array {
		return [
			'subject_request' => __( 'Request from the data subject', 'momos-core' ),
			'staff_error'     => __( 'Data entry error found by staff', 'momos-core' ),
			'other'           => __( 'Other (see DSR register)', 'momos-core' ),
		];
	}

	/** @return array<string,string> */
	public static function erase_reasons(): array {
		return [
			'erasure_request' => __( 'Deletion request from the data subject', 'momos-core' ),
			'objection'       => __( 'Objection to processing', 'momos-core' ),
			'other'           => __( 'Other (see DSR register)', 'momos-core' ),
		];
	}

	/** Retention summary values for display (counts only). @param array<string,mixed> $last */
	public static function retention_lines( array $last ): array {
		$out = [];
		foreach ( (array) ( $last['counts'] ?? [] ) as $k => $v ) {
			$out[] = sanitize_key( (string) $k ) . ': ' . ( is_scalar( $v ) ? (string) $v : Json::encode( $v ) );
		}
		return $out;
	}
}
