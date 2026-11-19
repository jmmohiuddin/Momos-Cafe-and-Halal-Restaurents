<?php
declare(strict_types=1);

namespace Momos\Audit;

/**
 * Audit action catalogue (SDD §2.4.7 authoritative list + Access spec §6.4 [IMPL] activity class).
 *
 * Domain actions are kept `momo_retention_audit_months` (24, DEC-034); activity-class actions
 * (replacing the activity-log/security plugin, IMPL-004) are kept `momo_retention_activity_months`
 * (12, PD-18). Modules may add names through the `momos/audit/catalogue` filter (CTO change control).
 */
final class Catalogue {
	/** SDD §2.4.7 (+ Doc 05 variants mapped to these names). */
	public const DOMAIN = [
		'access.denied', 'auth.disabled_login',
		'user.created', 'user.role_changed', 'user.translator_langs_changed', 'user.branch_assigned', 'user.branch_unassigned', 'user.disabled', 'user.enabled',
		'user.access_window_set', 'user.cap_dca_granted', 'user.cap_dca_revoked', 'user.2fa_reset',
		'vendor.breakglass_granted', 'vendor.breakglass_revoked',
		'language.enabled', 'language.disabled', 'media.illustrative_changed',
		'branch.identity_updated', 'branch.settings_updated', 'branch.hours_updated', 'branch.closure_created', 'branch.closure_updated',
		'branch.closure_deleted', 'branch.status_changed', 'branch.content_updated',
		'notice.published', 'notice.updated', 'notice.trashed',
		'offer.submitted', 'offer.published', 'offer.updated', 'offer.withdrawn', 'offer.trashed',
		'dish.published', 'dish.updated', 'dish.retired', 'dish.reference_price_changed',
		'menu_item.offered_changed', 'menu_item.price_changed', 'menu_item.availability_changed', 'menu_item.auto_reset',
		'dietary.statement_updated', 'dietary.branch_claims_updated', 'dietary.dish_claims_updated', 'dietary.review_required_set',
		'certificate.created', 'certificate.updated', 'certificate.status_changed', 'certificate.expired', 'certificate.evidence_downloaded',
		'reservation.status_changed', 'reservation.claimed', 'reservation.note_added', 'reservation.modified', 'reservation.detail_viewed',
		'reservation.exported', 'reservation.export_with_notes',
		'dsr.searched', 'dsr.exported', 'dsr.corrected', 'dsr.anonymized',
		'security.captcha_degraded', 'settings.updated', 'brand.updated', 'retention.run', 'privacy.retention_failed', 'migration.applied',
		// [IMPL] additions proposed by module specs (CTO change control).
		'notification.resent', 'notification.test_sent', 'environment.sanitized',
		'translation.approved', 'translation.updated', 'translation.returned',
		'redirect.created', 'redirect.updated', 'redirect.deleted',
	];

	/** [IMPL] activity class (Access spec §6.4), retention 12 months. */
	public const ACTIVITY = [
		'auth.login_succeeded', 'auth.login_failed', 'auth.lockout', 'auth.logout', 'auth.password_reset',
		'user.2fa_enrolled', 'user.2fa_recovery_used', 'user.updated',
		'wp.plugin_activated', 'wp.plugin_deactivated', 'wp.theme_switched', 'wp.core_updated', 'wp.option_updated',
		'security.file_integrity_failed', 'security.rate_limited', 'audit.exported', 'user.list_exported',
	];

	/** Doc 05 variant names → catalogue names. */
	public const ALIASES = [
		'user.role_assigned'      => 'user.role_changed',
		'user.capability_granted' => 'user.cap_dca_granted',
		'dsr.anonymised'          => 'dsr.anonymized',
		'momo_disabled'           => 'user.disabled',
	];

	/** @return list<string> */
	public static function all(): array {
		/** @var list<string> $extra */
		$extra = (array) apply_filters( 'momos/audit/catalogue', [] );
		return array_values( array_unique( array_merge( self::DOMAIN, self::ACTIVITY, array_map( 'strval', $extra ) ) ) );
	}

	public static function normalize( string $action ): string {
		return self::ALIASES[ $action ] ?? $action;
	}

	public static function is_known( string $action ): bool {
		return in_array( $action, self::all(), true );
	}

	public static function is_activity( string $action ): bool {
		return in_array( $action, self::ACTIVITY, true );
	}

	/** Action prefixes for the viewer's grouped action filter. @return array<string, list<string>> */
	public static function grouped(): array {
		$out = [];
		foreach ( self::all() as $a ) {
			$out[ strtok( $a, '.' ) ][] = $a;
		}
		ksort( $out );
		return $out;
	}
}
