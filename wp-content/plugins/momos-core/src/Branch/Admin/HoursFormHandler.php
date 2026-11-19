<?php
declare(strict_types=1);

namespace Momos\Branch\Admin;

use Momos\Branch\Domain\AccessContext;
use Momos\Branch\Domain\ClosureReason;
use Momos\Branch\Domain\ClosureType;
use Momos\Branch\Repository\ClosureRepository;
use Momos\Branch\Repository\HoursRepository;
use Momos\Branch\Service\BranchAccess;
use Momos\Branch\Service\ClosureImpactService;
use Momos\Branch\Service\HoursValidator;
use Momos\Kernel\Clock\Clock;

/**
 * admin-post handlers of SCR-A04 (spec §7.2, §9): momos_save_hours, momos_delete_hours_set,
 * momos_save_closure, momos_delete_closure. Nonce `momos_hours_{branch}` + `momo_edit_branch(id,
 * 'hours')`; denial → 403 + `access.denied`. Validation errors re-render the form with the input
 * kept; a save that affects pending/confirmed reservations first shows the impact panel and is
 * applied only after "Save anyway" (confirm=1).
 */
final class HoursFormHandler {
	public const MAX_RANGE_DAYS = 31;

	public static function register(): void {
		add_action( 'admin_post_momos_save_hours', [ self::class, 'save_hours' ] );
		add_action( 'admin_post_momos_delete_hours_set', [ self::class, 'delete_set' ] );
		add_action( 'admin_post_momos_save_closure', [ self::class, 'save_closure' ] );
		add_action( 'admin_post_momos_delete_closure', [ self::class, 'delete_closure' ] );
	}

	// ------------------------------------------------------------------ weekly hours

	public static function save_hours(): void {
		$branch_id = self::authorize();
		$input     = self::input();
		$result    = self::process_hours( $branch_id, $input, get_current_user_id() );
		self::finish( $branch_id, 'weekly', $result, $input );
	}

	/**
	 * Validate → preview → save. Shared by the HTTP handler and tests.
	 *
	 * @param array<string,mixed> $input
	 * @return array{status:string,errors?:array<string,string>,affected?:list<array<string,mixed>>,set?:array<string,?string>}
	 */
	public static function process_hours( int $branch_id, array $input, int $user_id ): array {
		$from                       = self::date_or_null( $input['valid_from'] ?? '' );
		$until                      = self::date_or_null( $input['valid_until'] ?? '' );
		$set_key                    = (string) ( $input['set'] ?? 'default' );
		[ $orig_from, $orig_until ] = self::parse_set_key( $set_key );
		if ( 'default' === $set_key ) {
			$from  = null;
			$until = null;
		}
		$closed = array_map( 'intval', array_keys( (array) ( $input['closed'] ?? [] ) ) );
		$raw    = (array) ( $input['periods'] ?? [] );
		foreach ( $closed as $w ) {
			$raw[ $w ] = [];
		}
		$lo_required          = '1' === (string) get_post_meta( $branch_id, 'momo_res_enabled', true );
		[ $errors, $periods ] = HoursValidator::validate_week( $raw, $lo_required );
		if ( 'default' !== $set_key && null === $from ) {
			$errors['validity'] = __( 'Enter the date from which this schedule applies.', 'momos-core' );
		}
		$errors += HoursValidator::validate_set_dates( $branch_id, $from, $until, $orig_from, $orig_until );
		$loaded  = (string) ( $input['loaded_max_updated_at'] ?? '' );
		if ( '' !== $loaded && HoursRepository::max_updated_at( $branch_id ) > $loaded ) {
			$errors['concurrency'] = __( 'This schedule was changed by someone else. Reload the page and apply your changes again.', 'momos-core' );
		}
		if ( $errors ) {
			return [ 'status' => 'errors', 'errors' => $errors ];
		}
		$confirmed = '1' === (string) ( $input['confirm'] ?? '0' );
		$affected  = ClosureImpactService::preview_week( $branch_id, $from, $until, $periods );
		if ( $affected && ! $confirmed ) {
			return [ 'status' => 'impact', 'affected' => $affected ];
		}
		$ctx = AccessContext::user( $user_id );
		\Momos\Kernel\Db\Transaction::run(
			static function () use ( $branch_id, $set_key, $orig_from, $orig_until, $from, $until, $periods, $ctx ): void {
				// A dated set whose dates changed: remove the old set in the same transaction.
				if ( 'default' !== $set_key && null !== $orig_from && ( $orig_from !== $from || $orig_until !== $until ) ) {
					HoursRepository::delete_set( $branch_id, $orig_from, $orig_until, $ctx );
				}
				HoursRepository::replace_week( $branch_id, $from, $until, $periods, $ctx );
			}
		);
		return [ 'status' => 'saved', 'affected' => $affected, 'set' => [ 'valid_from' => $from, 'valid_until' => $until ] ];
	}

	public static function delete_set(): void {
		$branch_id        = self::authorize();
		[ $from, $until ] = self::parse_set_key( (string) ( $_POST['set'] ?? '' ) ); // phpcs:ignore -- nonce checked in authorize().
		if ( null !== $from ) {
			HoursRepository::delete_set( $branch_id, $from, $until, AccessContext::current() );
		}
		self::redirect( $branch_id, 'weekly', [ 'momos_msg' => 'set_deleted' ] );
	}

	// ------------------------------------------------------------------ closures

	public static function save_closure(): void {
		$branch_id = self::authorize();
		$input     = self::input();
		$result    = self::process_closure( $branch_id, $input, get_current_user_id() );
		self::finish( $branch_id, 'closures', $result, $input );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array{status:string,errors?:array<string,string>,affected?:list<array<string,mixed>>,dates?:list<string>}
	 */
	public static function process_closure( int $branch_id, array $input, int $user_id ): array {
		$errors = [];
		$from   = self::date_or_null( $input['date_from'] ?? '' );
		$to     = self::date_or_null( $input['date_to'] ?? '' ) ?? $from;
		$type   = (string) ( $input['type'] ?? '' );
		$reason = (string) ( $input['public_reason'] ?? ClosureReason::Other->value );
		$note   = sanitize_text_field( (string) ( $input['internal_note'] ?? '' ) );
		$today  = Clock::jst()->format( 'Y-m-d' );
		$dates  = [];
		if ( null === $from || null === $to || $to < $from ) {
			$errors['date'] = __( 'End must be after start.', 'momos-core' );
		} else {
			for ( $d = new \DateTimeImmutable( $from, Clock::tz() ), $n = 0; $d->format( 'Y-m-d' ) <= $to && $n <= self::MAX_RANGE_DAYS; $d = $d->modify( '+1 day' ), $n++ ) {
				$dates[] = $d->format( 'Y-m-d' );
			}
			if ( count( $dates ) > self::MAX_RANGE_DAYS ) {
				$errors['date'] = __( 'A closure range can cover at most 31 days. Use temporary closure for longer periods.', 'momos-core' );
			} elseif ( $from < $today ) {
				$errors['date'] = __( 'You cannot change past dates.', 'momos-core' );
			}
		}
		if ( ! ClosureType::is_valid( $type ) ) {
			$errors['type'] = __( 'Choose "Closed all day" or "Special hours".', 'momos-core' );
		}
		if ( ! ClosureReason::is_valid( $reason ) ) {
			$errors['public_reason'] = __( 'Choose a reason.', 'momos-core' );
		}
		if ( mb_strlen( $note ) > 255 ) {
			$errors['internal_note'] = __( 'The internal note can have at most 255 characters.', 'momos-core' );
		}
		$periods = [];
		if ( ClosureType::SpecialHours->value === $type ) {
			[ $p_errors, $periods ] = HoursValidator::validate_special( (array) ( $input['periods'] ?? [] ), '1' === (string) get_post_meta( $branch_id, 'momo_res_enabled', true ) );
			$errors                += $p_errors;
		}
		if ( ! $errors && $dates ) {
			$existing = array_keys( ClosureRepository::group_by_date( ClosureRepository::rows_between( $branch_id, $dates[0], end( $dates ) ) ) );
			$editing  = (string) ( $input['editing'] ?? '' );
			$conflict = array_values( array_diff( $existing, [ $editing ] ) );
			if ( $conflict && '1' !== (string) ( $input['replace_existing'] ?? '0' ) ) {
				/* translators: %s: list of dates */
				$errors['replace_existing'] = sprintf( __( 'These dates already have an entry: %s. Tick "Replace existing entries" to overwrite them.', 'momos-core' ), implode( ', ', $conflict ) );
			}
		}
		if ( $errors ) {
			return [ 'status' => 'errors', 'errors' => $errors ];
		}
		$affected = ClosureImpactService::preview_closure( $branch_id, $dates, $type, $periods );
		if ( $affected && '1' !== (string) ( $input['confirm'] ?? '0' ) ) {
			return [ 'status' => 'impact', 'affected' => $affected ];
		}
		ClosureRepository::replace_dates( $branch_id, $dates, $type, $periods, $reason, $note, AccessContext::user( $user_id ) );
		return [ 'status' => 'saved', 'affected' => $affected, 'dates' => $dates ];
	}

	public static function delete_closure(): void {
		$branch_id = self::authorize();
		$date      = self::date_or_null( $_POST['date'] ?? '' ); // phpcs:ignore -- nonce checked in authorize().
		if ( null === $date || $date < Clock::jst()->format( 'Y-m-d' ) ) {
			self::store_state( $branch_id, [ 'status' => 'errors', 'errors' => [ 'date' => __( 'You cannot change past dates.', 'momos-core' ) ] ], [] );
			self::redirect( $branch_id, 'closures', [ 'momos_state' => 1 ] );
		}
		ClosureRepository::delete_date( $branch_id, (string) $date, AccessContext::current() );
		self::redirect( $branch_id, 'closures', [ 'momos_msg' => 'closure_deleted' ] );
	}

	// ------------------------------------------------------------------ plumbing

	/** Nonce + capability + branch scope; 403 with audit otherwise (SEC-012). */
	private static function authorize(): int {
		$branch_id = isset( $_POST['branch_id'] ) ? absint( $_POST['branch_id'] ) : 0; // phpcs:ignore -- verified below.
		$nonce     = isset( $_POST['_momos_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_momos_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'momos_hours_' . $branch_id ) ) {
			wp_die( esc_html__( 'The link has expired. Reload the page and try again.', 'momos-core' ), '', [ 'response' => 403 ] );
		}
		if ( ! BranchAccess::can_edit( $branch_id, 'hours' ) ) {
			BranchAccess::deny( 'momo_edit_branch_hours', $branch_id ?: null, 'branch', $branch_id ?: null, [ 'channel' => 'admin-post' ] );
			wp_die( esc_html( momos_t( 'staff.err.forbidden_branch', [], self::admin_lang() ) ), esc_html__( 'Forbidden', 'momos-core' ), [ 'response' => 403 ] );
		}
		return $branch_id;
	}

	/** @return array<string,mixed> */
	private static function input(): array {
		$token = isset( $_POST['momos_token'] ) ? sanitize_key( wp_unslash( $_POST['momos_token'] ) ) : ''; // phpcs:ignore -- nonce verified in authorize().
		if ( '' !== $token && isset( $_POST['confirm'] ) ) { // phpcs:ignore
			$stored = get_transient( 'momos_hours_pending_' . get_current_user_id() . '_' . $token );
			delete_transient( 'momos_hours_pending_' . get_current_user_id() . '_' . $token );
			if ( is_array( $stored ) ) {
				$stored['confirm'] = '1';
				return $stored;
			}
		}
		$raw = wp_unslash( $_POST ); // phpcs:ignore -- every value is validated by HoursValidator / process_*().
		unset( $raw['_momos_nonce'], $raw['action'], $raw['_wp_http_referer'] );
		return is_array( $raw ) ? $raw : [];
	}

	/**
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $input
	 */
	private static function finish( int $branch_id, string $tab, array $result, array $input ): void {
		if ( 'saved' === $result['status'] ) {
			self::redirect( $branch_id, $tab, [ 'momos_msg' => 'saved', 'affected' => count( $result['affected'] ?? [] ) ] + ( isset( $result['set'] ) ? [ 'set' => self::set_key( $result['set']['valid_from'], $result['set']['valid_until'] ) ] : [] ) );
		}
		$token = self::store_state( $branch_id, $result, $input );
		self::redirect( $branch_id, $tab, [ 'momos_state' => $token ] + ( isset( $input['set'] ) ? [ 'set' => (string) $input['set'] ] : [] ) );
	}

	/**
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $input
	 */
	private static function store_state( int $branch_id, array $result, array $input ): string {
		$token = strtolower( wp_generate_password( 12, false ) );
		unset( $input['confirm'], $input['momos_token'] );
		set_transient( 'momos_hours_state_' . get_current_user_id(), [ 'branch_id' => $branch_id, 'result' => $result, 'input' => $input, 'token' => $token ], 600 );
		if ( 'impact' === $result['status'] ) {
			set_transient( 'momos_hours_pending_' . get_current_user_id() . '_' . $token, $input, 600 );
		}
		return $token;
	}

	/** @param array<string,scalar> $args */
	private static function redirect( int $branch_id, string $tab, array $args ): void {
		wp_safe_redirect( add_query_arg( $args, HoursEditorPage::url( $branch_id, $tab ) ) );
		exit;
	}

	public static function set_key( ?string $from, ?string $until ): string {
		return null === $from ? 'default' : $from . '_' . ( $until ?? 'open' );
	}

	/** @return array{0:?string,1:?string} */
	public static function parse_set_key( string $key ): array {
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2}|open)$/', $key, $m ) ) {
			return [ $m[1], 'open' === $m[2] ? null : $m[2] ];
		}
		return [ null, null ];
	}

	private static function date_or_null( mixed $v ): ?string {
		$v = is_scalar( $v ) ? trim( HoursValidator::ascii_digits( (string) $v ) ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return $v;
	}

	public static function admin_lang(): string {
		return str_starts_with( get_user_locale(), 'en' ) ? 'en' : 'ja';
	}
}
