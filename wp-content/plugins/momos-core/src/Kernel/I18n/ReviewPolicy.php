<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

/**
 * Translator ≠ reviewer (FR-I18N-007(b), AC-I18N-03-01, i18n-seo-analytics.md §6.2, BR-DIET-05).
 *
 * A translation (post, term, option text, claim statement translation) becomes public only when a reviewer
 * approves it, and the reviewer must be someone other than the user who last saved its text
 * (`_momos_i18n_translated_by_{lang}`, option `_translated_by`, statement `updated_by`). The PRD names no
 * exception for Super Administrators or Head Office (FR-I18N-007(b): "published only after a reviewer
 * approves it"), so the rule applies to every role. A translator of 0 (unknown: seeded or imported text)
 * does not block approval.
 *
 * Callers outside the translation editor (Dietary `StatementRepository::save_translation`) call
 * {@see self::refuse_self_approval()} before storing status `approved` and keep the text with status
 * `needs_review` when it returns a message.
 */
final class ReviewPolicy {
	/** True when $reviewer_id last changed the text and therefore may not approve it. */
	public static function is_self_approval( int $reviewer_id, int $translator_id ): bool {
		return $reviewer_id > 0 && $reviewer_id === $translator_id;
	}

	/** The admin error shown when an approval is refused (§6.2 [Prop copy]). */
	public static function message(): string {
		return __( 'You cannot approve your own translation of this content.', 'momos-core' );
	}

	/**
	 * Check an approval and record the refusal (`access.denied`, reason `self_approval`).
	 *
	 * @param string              $object_type audit object type (e.g. `translation`, `dietary_statement`)
	 * @param int|null            $object_id   post/term ID, null for options and statements
	 * @param array<string,mixed> $context     extra audit keys (no personal data), e.g. ['type' => 'post']
	 * @return string|null null when the approval is allowed, else the error message
	 */
	public static function refuse_self_approval( string $object_type, ?int $object_id, string $lang, int $reviewer_id, int $translator_id, array $context = [] ): ?string {
		if ( ! self::is_self_approval( $reviewer_id, $translator_id ) ) {
			return null;
		}
		momos_audit( 'access.denied', $object_type, $object_id, null, [ 'reason' => 'self_approval', 'cap' => 'momo_approve_translations', 'lang' => $lang ] + $context );
		return self::message();
	}
}
