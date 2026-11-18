<?php
/**
 * Global helper functions for templates (theme) and modules. Thin wrappers over kernel services.
 */

declare(strict_types=1);

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Strings;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'momos_t' ) ) {
	/**
	 * Customer-facing / email string by key in the current (or given) language.
	 *
	 * @param array<string, string|int|float> $args
	 */
	function momos_t( string $key, array $args = [], ?string $lang = null ): string {
		return Strings::t( $key, $args, $lang );
	}

	/** Echo an escaped catalog string. @param array<string, string|int|float> $args */
	function momos_e( string $key, array $args = [], ?string $lang = null ): void {
		echo esc_html( Strings::t( $key, $args, $lang ) );
	}

	function momos_lang(): string {
		return Locale::current();
	}

	function momos_is_rtl(): bool {
		return Lang::is_rtl( Locale::current() );
	}

	/** Language-aware URL for a site path. */
	function momos_url( string $path = '/', ?string $lang = null ): string {
		return Url::to( $path, $lang );
	}

	/** Translated field of a post (empty string when not approved in that language). */
	function momos_tr( int|\WP_Post $post, string $field, ?string $lang = null ): string {
		return Translations::post( $post, $field, $lang );
	}

	/** Translated field of a term. */
	function momos_tr_term( int|\WP_Term $term, string $field, ?string $lang = null ): string {
		return Translations::term( $term, $field, $lang );
	}

	/** Wrap Latin/number fragments for bidi isolation inside RTL text (FR-I18N-005). */
	function momos_bdi( string $text ): string {
		return '<bdi>' . esc_html( $text ) . '</bdi>';
	}
}

if ( ! function_exists( 'momos_audit' ) ) {
	/**
	 * Record a domain audit entry (FR-ADM-003). Implemented by the Audit module listening to
	 * `momos/audit/log`. Never pass personal data (email, phone, names, tokens) in $changes.
	 *
	 * @param array<string,mixed> $changes before/after values or context
	 */
	function momos_audit( string $action, string $object_type, ?int $object_id = null, ?int $branch_id = null, array $changes = [], string $actor_type = 'staff' ): void {
		do_action( 'momos/audit/log', $action, $object_type, $object_id, $branch_id, $changes, $actor_type );
	}

	/**
	 * Branch IDs the user may act on, or null when the user has all-branch scope
	 * (`momo_access_all_branches`). Disabled/expired users get []. (TRD §2.7.3 "assigned").
	 *
	 * @return list<int>|null
	 */
	function momos_user_branch_ids( ?int $user_id = null ): ?array {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}
		/** @var list<int>|null|false $pre Short-circuit used by the Access module (disabled/expired accounts). */
		$pre = apply_filters( 'momos/access/user_branch_ids', false, $user_id );
		if ( false !== $pre ) {
			return $pre;
		}
		if ( user_can( $user_id, 'momo_access_all_branches' ) ) {
			return null;
		}
		global $wpdb;
		$table = \Momos\Kernel\Db\Tables::user_branches();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT branch_id FROM {$table} WHERE user_id = %d", $user_id ) ); // phpcs:ignore
		return array_map( 'intval', $ids );
	}

	/** Is the branch within the user's scope? */
	function momos_user_can_branch( int $branch_id, ?int $user_id = null ): bool {
		$ids = momos_user_branch_ids( $user_id );
		return null === $ids || in_array( $branch_id, $ids, true );
	}
}
