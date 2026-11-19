<?php
declare(strict_types=1);

namespace Momos\Admin\Content;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Fields\FieldRegistry;

/**
 * "Legal page" meta box (SCR-A14, G16 `group_momo_page_settings` subset; content-strings.md §2.3/§3.4,
 * FR-CORP-006, AC-CORP-06-01/02, TC-CORP-005/016).
 *
 * Fields (copy fields — never translated, so every language shows the JA master's version and date):
 *   momo_page_type          standard | legal
 *   momo_legal_version      ≤ 20 chars, e.g. `1.0` (consent registry label for Privacy/Reservation policy)
 *   momo_legal_last_updated date (JST) ≤ today
 * All three are edited only with `momo_publish_legal_pages` (SA/HO); Content Editors see them read-only.
 * Version and date are required to publish a legal page. Publishing/updating a published legal page needs
 * `momo_publish_legal_pages` (Access MetaCapMapper M5 — CE can only save drafts / submit for review);
 * the Privacy module blocks policy versions without a consent-registry row. Changes are audited
 * `settings.updated` (object page) through the field layer.
 */
final class LegalPageBox {
	public const GROUP = 'momo_legal_page';
	public const CAP   = 'momo_publish_legal_pages';

	public static function register(): void {
		FieldRegistry::group(
			[
				'key'       => self::GROUP,
				'title'     => __( 'Legal page', 'momos-core' ),
				'post_type' => 'page',
				'context'   => 'side',
				'priority'  => 'default',
				'order'     => 20,
				'fields'    => [
					[
						'name'     => 'momo_page_type',
						'label'    => __( 'Page type', 'momos-core' ),
						'type'     => 'select',
						'choices'  => [ 'standard' => __( 'Standard page', 'momos-core' ), 'legal' => __( 'Legal page', 'momos-core' ) ],
						'default'  => 'standard',
						'edit_cap' => self::CAP,
					],
					[
						'name'      => 'momo_legal_version',
						'label'     => __( 'Version', 'momos-core' ),
						'type'      => 'text',
						'maxlength' => 20,
						'required'  => 'publish',
						'show_if'   => [ 'momo_page_type' => 'legal' ],
						'edit_cap'  => self::CAP,
						'validate'  => [ self::class, 'validate_version' ],
						'help'      => __( 'Same label as the consent registry, e.g. 1.0. Translations always show this version.', 'momos-core' ),
					],
					[
						'name'     => 'momo_legal_last_updated',
						'label'    => __( 'Last updated', 'momos-core' ),
						'type'     => 'date',
						'required' => 'publish',
						'show_if'  => [ 'momo_page_type' => 'legal' ],
						'edit_cap' => self::CAP,
						'validate' => [ self::class, 'validate_date' ],
					],
				],
			]
		);
		add_filter( 'momos/audit/field_save_action', [ self::class, 'audit_action' ], 10, 4 );
		add_action( 'post_submitbox_misc_actions', [ self::class, 'submitbox_notice' ] );
	}

	public static function validate_version( mixed $value ): ?string {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return null;
		}
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9.\-]{0,19}$/', $v ) ? null : __( 'Use letters, digits, dots and hyphens only (max. 20), e.g. 1.0.', 'momos-core' );
	}

	public static function validate_date( mixed $value ): ?string {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return null;
		}
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $v, Clock::tz() );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $v ) {
			return __( 'Enter a valid date.', 'momos-core' );
		}
		return $v > Clock::jst()->format( 'Y-m-d' ) ? __( 'The last-updated date cannot be in the future.', 'momos-core' ) : null;
	}

	/** Audit legal meta changes (field layer only audits groups that opt in). */
	public static function audit_action( $action, string $type, string $post_type, string $group_key ) {
		return self::GROUP === $group_key ? 'settings.updated' : $action;
	}

	/** Tell Content Editors why they cannot publish a legal page (content-strings §3.4 `admin.legal.publish_denied`). */
	public static function submitbox_notice( $post ): void {
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'legal' !== (string) get_post_meta( $post->ID, 'momo_page_type', true ) || current_user_can( self::CAP ) ) {
			return;
		}
		$lang = str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en';
		echo '<div class="misc-pub-section"><p class="description" role="note">' . esc_html( momos_t( 'admin.legal.publish_denied', [], $lang ) ) . '</p></div>';
	}
}
