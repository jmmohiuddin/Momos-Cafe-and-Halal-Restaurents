<?php
declare(strict_types=1);

namespace Momos\Branch\Service;

use Momos\Branch\Domain\OperatingStatus;
use Momos\Branch\Fields\BranchFields;
use Momos\Branch\Repository\BranchRepository;
use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Events\AfterCommit;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Support\Hash;

/**
 * Write guard and lifecycle of `momo_branch` (spec §4.1–§4.3, BranchFieldGuard, SEC-012):
 * input normalisation, core-field protection, GATE-01 rejection on published branches,
 * first-publish stamp, status transitions and their effects, audits, domain hooks and the
 * delete/trash guard.
 */
final class BranchGuard {
	private static ?string $requested_slug = null;

	/** @var array<int, list<string>> blocking publish errors per post for this request */
	private static array $blocking = [];

	/** @var array<int, string> post_title before the update */
	private static array $old_titles = [];

	public static function register(): void {
		add_filter( 'wp_insert_post_data', [ self::class, 'before_save' ], 5, 2 );
		add_filter( 'wp_insert_post_data', [ self::class, 'after_validation' ], 100, 2 );
		add_filter( 'momos/fields/publish_errors/momo_branch', [ self::class, 'remember_blocking' ], 50, 4 );
		add_filter( 'momos/fields/validate_group/' . BranchFields::G4, [ self::class, 'group_blocking' ], 10, 4 );
		add_filter( 'momos/fields/validate_group/' . BranchFields::G6, [ self::class, 'validate_settings' ], 10, 4 );
		add_action( 'momos/fields/saved', [ self::class, 'fields_saved' ], 10, 5 );
		add_action( 'momos/fields/denied', [ self::class, 'fields_denied' ], 10, 5 );
		add_action( 'transition_post_status', [ self::class, 'transition' ], 10, 3 );
		add_action( 'post_updated', [ self::class, 'post_updated' ], 10, 3 );
		add_filter( 'pre_trash_post', [ self::class, 'pre_trash' ], 10, 2 );
		add_filter( 'pre_delete_post', [ self::class, 'pre_delete' ], 10, 3 );
	}

	public static function requested_slug(): ?string {
		return self::$requested_slug;
	}

	// ------------------------------------------------------------------ before save

	/**
	 * Normalise submitted values (digits, phone, code) and protect HO-only core fields.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function before_save( array $data, array $postarr ): array {
		if ( 'momo_branch' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		$post_id              = (int) ( $postarr['ID'] ?? 0 );
		self::$requested_slug = isset( $postarr['post_name'] ) && '' !== (string) $postarr['post_name'] ? sanitize_title( (string) $postarr['post_name'] ) : null;
		if ( isset( $_POST['momos_fields'] ) && is_array( $_POST['momos_fields'] ) ) { // phpcs:ignore -- nonce verified by FieldRegistry per group.
			$_POST['momos_fields'] = self::normalize_fields( $_POST['momos_fields'] ); // phpcs:ignore
		}
		$old = $post_id ? get_post( $post_id ) : null;
		if ( $old && 'auto-draft' !== $old->post_status ) {
			self::$old_titles[ $post_id ] = (string) $old->post_title;
			if ( is_user_logged_in() && ! current_user_can( 'momo_edit_branch_identity' ) ) {
				$denied = [];
				foreach ( [ 'post_title', 'post_name', 'post_status', 'menu_order' ] as $k ) {
					if ( isset( $data[ $k ] ) && (string) $data[ $k ] !== (string) $old->$k ) {
						$denied[]   = $k;
						$data[ $k ] = $old->$k;
					}
				}
				if ( $denied ) {
					self::deny_fields( $post_id, $denied );
				}
			}
		}
		return $data;
	}

	/**
	 * @param array<string,mixed> $fields raw `momos_fields` (slashed)
	 * @return array<string,mixed>
	 */
	public static function normalize_fields( array $fields ): array {
		if ( isset( $fields['momo_branch_code'] ) && is_string( $fields['momo_branch_code'] ) ) {
			$fields['momo_branch_code'] = strtoupper( trim( HoursValidator::ascii_digits( mb_convert_kana( $fields['momo_branch_code'], 'a', 'UTF-8' ) ) ) );
		}
		if ( isset( $fields['momo_postal_code'] ) && is_string( $fields['momo_postal_code'] ) ) {
			$pc = preg_replace( '/[^0-9]/', '', HoursValidator::ascii_digits( mb_convert_kana( $fields['momo_postal_code'], 'a', 'UTF-8' ) ) );
			$fields['momo_postal_code'] = 7 === strlen( (string) $pc ) ? substr( $pc, 0, 3 ) . '-' . substr( $pc, 3 ) : $fields['momo_postal_code'];
		}
		foreach ( [ 'momo_lat', 'momo_lng' ] as $k ) {
			if ( isset( $fields[ $k ] ) && is_string( $fields[ $k ] ) ) {
				$fields[ $k ] = trim( HoursValidator::ascii_digits( mb_convert_kana( $fields[ $k ], 'a', 'UTF-8' ) ) );
			}
		}
		if ( isset( $fields['momo_phone_e164'] ) && is_string( $fields['momo_phone_e164'] ) ) {
			$e164                      = self::normalize_phone( $fields['momo_phone_e164'] );
			$fields['momo_phone_e164'] = $e164 ?? $fields['momo_phone_e164'];
			if ( null !== $e164 && isset( $fields['momo_phone_display'] ) && '' === trim( (string) $fields['momo_phone_display'] ) ) {
				$fields['momo_phone_display'] = self::display_phone( $e164 );
			}
		}
		if ( isset( $fields['momo_phone_display'] ) && is_string( $fields['momo_phone_display'] ) ) {
			$fields['momo_phone_display'] = str_replace( [ 'ー', '−', '‐', '－' ], '-', HoursValidator::ascii_digits( mb_convert_kana( $fields['momo_phone_display'], 'a', 'UTF-8' ) ) );
		}
		return $fields;
	}

	/** Japanese number → E.164 (+81…); null when not a plausible Japanese number. */
	public static function normalize_phone( string $raw ): ?string {
		$s = HoursValidator::ascii_digits( mb_convert_kana( $raw, 'a', 'UTF-8' ) );
		$s = preg_replace( '/[^0-9+]/', '', $s ) ?? '';
		if ( str_starts_with( $s, '+81' ) ) {
			$n = substr( $s, 3 );
		} elseif ( str_starts_with( $s, '81' ) && strlen( $s ) >= 11 ) {
			$n = substr( $s, 2 );
		} elseif ( str_starts_with( $s, '0' ) ) {
			$n = substr( $s, 1 );
		} else {
			return null;
		}
		$n = ltrim( $n, '0' );
		return preg_match( '/^\d{9,10}$/', $n ) ? '+81' . $n : null;
	}

	/** National display format derived from E.164 (heuristic; HO may overwrite). */
	public static function display_phone( string $e164 ): string {
		$national = '0' . substr( $e164, 3 );
		if ( 11 === strlen( $national ) ) {
			return substr( $national, 0, 3 ) . '-' . substr( $national, 3, 4 ) . '-' . substr( $national, 7 );
		}
		if ( preg_match( '/^0[36]/', $national ) ) {
			return substr( $national, 0, 2 ) . '-' . substr( $national, 2, 4 ) . '-' . substr( $national, 6 );
		}
		return substr( $national, 0, 3 ) . '-' . substr( $national, 3, 3 ) . '-' . substr( $national, 6 );
	}

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $merged
	 * @param array<string,mixed> $data
	 * @return list<string>
	 */
	public static function remember_blocking( array $errors, array $merged, array $data, int $post_id ): array {
		if ( $errors && $post_id && 'publish' === get_post_status( $post_id ) ) {
			self::$blocking[ $post_id ] = $errors;
		}
		return $errors;
	}

	/**
	 * Published branch with failed publish rules: keep title/slug unchanged too.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function after_validation( array $data, array $postarr ): array {
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( 'momo_branch' === ( $data['post_type'] ?? '' ) && isset( self::$blocking[ $post_id ] ) ) {
			$old = get_post( $post_id );
			if ( $old ) {
				$data['post_title'] = $old->post_title;
				$data['post_name']  = $old->post_name;
			}
		}
		return $data;
	}

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $new_all
	 * @param array<string,mixed> $changes
	 * @return list<string>
	 */
	public static function group_blocking( array $errors, array $new_all, array $changes, int|string $object_id ): array {
		return array_merge( $errors, self::$blocking[ (int) $object_id ] ?? [] );
	}

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $new_all
	 * @param array<string,mixed> $changes
	 * @return list<string>
	 */
	public static function validate_settings( array $errors, array $new_all, array $changes, int|string $object_id ): array {
		return array_merge( $errors, SettingsValidator::validate( $new_all, (int) $object_id ) );
	}

	// ------------------------------------------------------------------ after save

	public static function transition( string $new, string $old, \WP_Post $post ): void {
		if ( 'momo_branch' !== $post->post_type || $new === $old ) {
			return;
		}
		if ( 'publish' === $new && '' === (string) get_post_meta( $post->ID, 'momo_first_published_at', true ) ) {
			update_post_meta( $post->ID, 'momo_first_published_at', Clock::mysql_utc() );
		}
		if ( 'publish' === $new || 'publish' === $old ) {
			momos_audit( 'branch.identity_updated', 'branch', $post->ID, $post->ID, [ 'post_status' => [ $old, $new ] ] );
			AfterCommit::dispatch( 'momos/branch/identity_changed', $post->ID );
			do_action( 'momos/cache/purge', [ 'momo-global', 'momo-branch-' . $post->ID, 'momo-branches', 'momo-home' ] );
		}
	}

	public static function post_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( 'momo_branch' !== $after->post_type || 'auto-draft' === $before->post_status ) {
			return;
		}
		$changes = [];
		foreach ( [ 'post_title', 'post_name', 'menu_order' ] as $k ) {
			if ( (string) $before->$k !== (string) $after->$k ) {
				$changes[ $k ] = [ $before->$k, $after->$k ];
			}
		}
		if ( $changes ) {
			momos_audit( 'branch.identity_updated', 'branch', $post_id, $post_id, $changes );
			AfterCommit::dispatch( 'momos/branch/identity_changed', $post_id );
		}
		if ( $before->post_content !== $after->post_content || $before->post_excerpt !== $after->post_excerpt ) {
			momos_audit( 'branch.content_updated', 'branch', $post_id, $post_id, [ 'fields' => [ 'post_content' ], 'role' => self::role() ] );
		}
		if ( $changes || $before->post_content !== $after->post_content ) {
			do_action( 'momos/cache/purge', [ 'momo-branch-' . $post_id, 'momo-branches', 'momo-home', 'momo-global' ] );
		}
	}

	/**
	 * Audits, domain hooks and status side effects after FieldRegistry saved a branch group.
	 *
	 * @param array<string, array{0:mixed,1:mixed}> $changes
	 */
	public static function fields_saved( string $type, int|string $object_id, string $group_key, array $changes, int $user_id ): void {
		$branch_id = (int) $object_id;
		if ( 'post' !== $type || 'momo_branch' !== get_post_type( $branch_id ) || ! $changes ) {
			return;
		}
		$audit = self::audit_changes( $changes );
		if ( BranchFields::G6 === $group_key ) {
			momos_audit( 'branch.settings_updated', 'branch', $branch_id, $branch_id, $audit );
			AfterCommit::dispatch( 'momos/branch/settings_changed', $branch_id, array_keys( $changes ) );
			if ( isset( $changes['momo_res_enabled'] ) ) {
				$on = '1' === (string) $changes['momo_res_enabled'][1];
				ClosureImpactService::accepting_changed( $branch_id, $on, $on ? 'reservations_enabled' : 'reservations_disabled' );
				if ( ! $on ) {
					self::notice_affected( $branch_id, count( ClosureImpactService::preview_all( $branch_id ) ) );
				}
			}
			do_action( 'momos/cache/purge', [ 'momo-branch-' . $branch_id, 'momo-branches', 'momo-api-branch-' . $branch_id ] );
			return;
		}
		if ( isset( $changes['momo_operating_status'] ) ) {
			[ $from, $to ] = $changes['momo_operating_status'];
			momos_audit( 'branch.status_changed', 'branch', $branch_id, $branch_id, [ 'momo_operating_status' => [ $from, $to ] ] );
			self::status_effects( $branch_id, (string) $from, (string) $to );
		}
		$identity = array_intersect_key( $changes, array_flip( BranchFields::IDENTITY_KEYS ) );
		$other    = array_diff_key( $changes, [ 'momo_operating_status' => 1 ] );
		if ( $other ) {
			$action = ( [] === array_diff( array_keys( $other ), [ 'momo_gallery' ] ) ) ? 'branch.content_updated' : 'branch.identity_updated';
			momos_audit( $action, 'branch', $branch_id, $branch_id, self::audit_changes( $other ) + [ 'role' => self::role() ] );
		}
		if ( $identity ) {
			AfterCommit::dispatch( 'momos/branch/identity_changed', $branch_id );
		}
		do_action( 'momos/cache/purge', [ 'momo-global', 'momo-branch-' . $branch_id, 'momo-branches', 'momo-home', 'momo-api-branch-' . $branch_id ] );
	}

	/** Effects of an operating-status transition (spec §4.3). */
	public static function status_effects( int $branch_id, string $from, string $to ): void {
		global $wpdb;
		if ( OperatingStatus::TemporarilyClosed->value === $to ) {
			ClosureImpactService::accepting_changed( $branch_id, false, 'temporarily_closed' );
			self::notice_affected( $branch_id, count( ClosureImpactService::preview_all( $branch_id ) ) );
		} elseif ( OperatingStatus::Open->value === $to ) {
			ClosureImpactService::accepting_changed( $branch_id, true, 'open' );
		} elseif ( OperatingStatus::ClosedPermanently->value === $to ) {
			ClosureImpactService::accepting_changed( $branch_id, false, 'closed_permanently' );
			// Remove staff assignments (audited) and stop offering branch menu rows [Rec].
			$ub    = Tables::user_branches();
			$users = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$ub} WHERE branch_id = %d", $branch_id ) ) ); // phpcs:ignore
			foreach ( $users as $uid ) {
				$wpdb->delete( $ub, [ 'user_id' => $uid, 'branch_id' => $branch_id ] );
				momos_audit( 'user.branch_unassigned', 'user', $uid, $branch_id, [ 'reason' => 'branch_closed_permanently' ] );
				AfterCommit::dispatch( 'momos/user/assignment_changed', $uid, [], [ $branch_id ] );
			}
			$mi = Tables::menu_items();
			$wpdb->query( $wpdb->prepare( "UPDATE {$mi} SET is_offered = 0, updated_at = %s WHERE branch_id = %d AND is_offered = 1", Clock::mysql_utc(), $branch_id ) ); // phpcs:ignore
			\Momos\Branch\PostType\BranchScopeTaxonomy::sync( $branch_id );
		}
		AfterCommit::dispatch( 'momos/branch/identity_changed', $branch_id );
		// Offers are only shown on open branches: re-evaluate the offer lists / header item.
		\Momos\Branch\Query\OfferQuery::sync_visibility( 0 );
	}

	/** @param list<string> $fields */
	public static function fields_denied( string $type, int|string $object_id, string $group_key, array $fields, int $user_id ): void {
		if ( 'post' === $type && 'momo_branch' === get_post_type( (int) $object_id ) ) {
			self::deny_fields( (int) $object_id, $fields );
		}
	}

	/** @param list<string> $fields */
	private static function deny_fields( int $branch_id, array $fields ): void {
		foreach ( $fields as $f ) {
			$cap = in_array( $f, array_keys( \Momos\Branch\Repository\BranchSettingsRepository::DEFAULTS ), true ) ? 'momo_edit_branch_settings' : 'momo_edit_branch_identity';
			BranchAccess::deny( $cap, $branch_id, 'branch', $branch_id, [ 'field' => $f ] );
		}
		$labels = array_map( static fn( $f ) => self::field_label( $f ), $fields );
		/* translators: %s: field names */
		FieldRegistry::add_errors( [ sprintf( __( 'You are not allowed to change: %s.', 'momos-core' ), implode( ', ', $labels ) ) ] );
	}

	private static function field_label( string $name ): string {
		$core = [ 'post_title' => __( 'Branch name', 'momos-core' ), 'post_name' => __( 'Slug', 'momos-core' ), 'post_status' => __( 'Status', 'momos-core' ), 'menu_order' => __( 'Order', 'momos-core' ) ];
		if ( isset( $core[ $name ] ) ) {
			return $core[ $name ];
		}
		$def = FieldRegistry::field_def( 'post:momo_branch', $name );
		return $def ? wp_strip_all_tags( (string) $def['label'] ) : $name;
	}

	private static function notice_affected( int $branch_id, int $count ): void {
		if ( $count > 0 ) {
			/* translators: %d: number of reservations */
			FieldRegistry::add_errors( [ sprintf( __( 'This affects %d pending or confirmed requests. They are marked "Needs action" in the inbox.', 'momos-core' ), $count ) ] );
		}
	}

	/**
	 * Before/after values for audit; e-mail addresses are masked (no PII in audit payloads).
	 *
	 * @param array<string, array{0:mixed,1:mixed}> $changes
	 * @return array<string, array{0:mixed,1:mixed}>
	 */
	private static function audit_changes( array $changes ): array {
		$mask = static function ( $v ) use ( &$mask ) {
			if ( is_array( $v ) ) {
				return array_map( $mask, $v );
			}
			return is_string( $v ) && is_email( $v ) ? Hash::mask_email( $v ) : $v;
		};
		$out = [];
		foreach ( $changes as $k => [ $old, $new ] ) {
			$out[ $k ] = [ $mask( $old ), $mask( $new ) ];
		}
		return $out;
	}

	private static function role(): string {
		$u = wp_get_current_user();
		return $u && $u->roles ? implode( ',', $u->roles ) : 'system';
	}

	// ------------------------------------------------------------------ delete / trash guard

	/** @return mixed null to continue, false to block */
	public static function pre_trash( mixed $check, \WP_Post $post ): mixed {
		return self::block_removal( $post ) ? false : $check;
	}

	/** @return mixed null to continue, false to block */
	public static function pre_delete( mixed $check, \WP_Post $post, bool $force ): mixed {
		return self::block_removal( $post ) ? false : $check;
	}

	/** A branch with any reservation or branch-menu row cannot be trashed/deleted (spec §3.1). */
	public static function removal_blocked( int $branch_id ): bool {
		global $wpdb;
		$mi = Tables::menu_items();
		return BranchRepository::has_reservations( $branch_id ) || (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$mi} WHERE branch_id = %d LIMIT 1", $branch_id ) ); // phpcs:ignore
	}

	private static function block_removal( \WP_Post $post ): bool {
		if ( 'momo_branch' !== $post->post_type || ! self::removal_blocked( $post->ID ) ) {
			return false;
		}
		BranchAccess::deny( 'delete_post', $post->ID, 'branch', $post->ID, [ 'reason' => 'branch_has_data' ] );
		FieldRegistry::add_errors( [ __( 'This branch has reservations or menu data and cannot be deleted. Set it to "Closed permanently" instead.', 'momos-core' ) ] );
		return true;
	}
}
