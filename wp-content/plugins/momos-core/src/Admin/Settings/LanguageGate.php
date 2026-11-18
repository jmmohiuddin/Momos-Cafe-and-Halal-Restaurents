<?php
declare(strict_types=1);

namespace Momos\Admin\Settings;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\I18n\Lang;

/**
 * SCR-A15 "Languages" tab — the language launch gate (FR-I18N-009, DEC-002, IMPL-003, GATE-04,
 * TC-I18N-019; i18n-seo-analytics.md §6.5/§14.3). Only path that writes `momos_languages_enabled`.
 *
 * Enable L: refused while launch-critical content is missing (filter
 * `momos/i18n/language_gate_errors` (list<string> $errors, string $lang) — the translation-gaps
 * service adds one entry per missing/unreviewed LCCS item) or when the native-review sign-off
 * (name + date ≤ today) is empty. Disable: always allowed. Both purge `momo-global` and are audited
 * `language.enabled` / `language.disabled`. Cap `momo_manage_languages` (SA/HO); others 403 + audit.
 */
final class LanguageGate {
	public const ACTION = 'momos_language_gate';
	public const CAP    = 'momo_manage_languages';
	public const STATE  = 'momos_language_gate';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/** @return array<string, array<string,mixed>> per-language gate record */
	public static function records(): array {
		$v = get_option( self::STATE, [] );
		return is_array( $v ) ? $v : [];
	}

	/** @return list<string> LCCS gaps reported for a language */
	public static function gaps( string $lang ): array {
		return array_values( array_map( 'strval', (array) apply_filters( 'momos/i18n/language_gate_errors', [], $lang ) ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'language', null, null, [ 'cap' => self::CAP, 'channel' => 'admin-post', 'route' => self::ACTION ] );
			wp_die( esc_html__( 'You do not have permission to change public languages.', 'momos-core' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( (string) $_POST['lang'] ) ) : '';
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		$sign = [
			'by'  => isset( $_POST['signoff_by'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['signoff_by'] ) ) : '',
			'at'  => isset( $_POST['signoff_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['signoff_at'] ) ) : '',
			'ref' => isset( $_POST['signoff_ref'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['signoff_ref'] ) ) : '',
		];
		$result = 'enable' === $op ? self::enable( $lang, $sign, get_current_user_id() ) : ( 'disable' === $op ? self::disable( $lang, get_current_user_id() ) : [ __( 'Unknown action.', 'momos-core' ) ] );
		$key    = 'momos_lang_gate_msg_' . get_current_user_id();
		set_transient( $key, $result ? [ 'error', $result ] : [ 'success', [ 'enable' === $op ? sprintf( /* translators: %s: language */ __( '%s is now public.', 'momos-core' ), Lang::native( $lang ) ) : sprintf( /* translators: %s: language */ __( '%s is now hidden.', 'momos-core' ), Lang::native( $lang ) ) ] ], 120 );
		wp_safe_redirect( add_query_arg( [ 'page' => 'momo-settings', 'tab' => 'languages' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @param array{by:string,at:string,ref:string} $sign
	 * @return list<string> errors (empty = enabled)
	 */
	public static function enable( string $lang, array $sign, int $actor ): array {
		if ( ! Lang::is_valid( $lang ) || Lang::SOURCE === $lang ) {
			return [ __( 'Unknown language.', 'momos-core' ) ];
		}
		$admin_lang = str_starts_with( (string) get_user_locale( $actor ), 'ja' ) ? 'ja' : 'en';
		$name       = Lang::native( $lang );
		$gaps       = self::gaps( $lang );
		if ( $gaps ) {
			return array_merge( [ momos_t( 'admin.lang.err.gate', [ 'language' => $name, 'n' => count( $gaps ) ], $admin_lang ) ], array_slice( $gaps, 0, 20 ) );
		}
		$sign['by']  = mb_substr( trim( $sign['by'] ), 0, 100 );
		$sign['ref'] = mb_substr( trim( $sign['ref'] ), 0, 200 );
		$date        = \DateTimeImmutable::createFromFormat( '!Y-m-d', $sign['at'], Clock::tz() );
		if ( '' === $sign['by'] || ! $date || $date->format( 'Y-m-d' ) !== $sign['at'] || $sign['at'] > Clock::jst()->format( 'Y-m-d' ) ) {
			return [ momos_t( 'admin.lang.err.signoff', [ 'language' => $name ], $admin_lang ) ];
		}
		return self::write( $lang, true, $actor, [ 'lccs_signoff_by' => $sign['by'], 'lccs_signoff_at' => $sign['at'], 'lccs_signoff_ref' => $sign['ref'] ] );
	}

	/** @return list<string> errors */
	public static function disable( string $lang, int $actor ): array {
		if ( ! Lang::is_valid( $lang ) || Lang::SOURCE === $lang ) {
			return [ __( 'The source language is always public.', 'momos-core' ) ];
		}
		return self::write( $lang, false, $actor, [] );
	}

	/**
	 * @param array<string,string> $sign
	 * @return list<string>
	 */
	private static function write( string $lang, bool $enable, int $actor, array $sign ): array {
		if ( ! NamedLock::acquire( self::STATE, 5 ) ) {
			return [ __( 'Another language change is in progress. Please try again.', 'momos-core' ) ];
		}
		try {
			$enabled = Lang::enabled();
			if ( $enable === in_array( $lang, $enabled, true ) ) {
				return [];
			}
			$enabled = $enable ? array_values( array_unique( array_merge( $enabled, [ $lang ] ) ) ) : array_values( array_diff( $enabled, [ $lang ] ) );
			update_option( 'momos_languages_enabled', array_values( array_intersect( Lang::codes(), $enabled ) ), true );
			$records          = self::records();
			$records[ $lang ] = array_merge(
				(array) ( $records[ $lang ] ?? [] ),
				$sign,
				[ 'state' => $enable ? 'enabled' : 'hidden', 'changed_by' => $actor, 'changed_at' => Clock::mysql_utc() ]
			);
			update_option( self::STATE, $records, true );
		} finally {
			NamedLock::release( self::STATE );
		}
		do_action( 'momos/cache/purge', [ 'momo-global' ] );
		momos_audit( $enable ? 'language.enabled' : 'language.disabled', 'language', null, null, array_merge( [ 'lang' => $lang ], $enable ? [ 'lccs_gaps' => 0, 'signoff_by' => $sign['lccs_signoff_by'], 'signoff_at' => $sign['lccs_signoff_at'], 'signoff_ref' => $sign['lccs_signoff_ref'] ] : [] ) );
		do_action( 'momos/i18n/language_state_changed', $lang, $enable );
		return [];
	}

	public static function render(): void {
		$records = self::records();
		$enabled = Lang::enabled();
		$msg     = get_transient( 'momos_lang_gate_msg_' . get_current_user_id() );
		if ( is_array( $msg ) ) {
			delete_transient( 'momos_lang_gate_msg_' . get_current_user_id() );
		}
		$rows = [];
		foreach ( Lang::targets() as $code ) {
			$rows[ $code ] = [
				'enabled' => in_array( $code, $enabled, true ),
				'gaps'    => self::gaps( $code ),
				'record'  => (array) ( $records[ $code ] ?? [] ),
			];
		}
		$today = Clock::jst()->format( 'Y-m-d' );
		include MOMOS_CORE_DIR . '/templates/admin/settings-languages.php';
	}
}
