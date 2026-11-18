<?php
declare(strict_types=1);

namespace Momos\Branch\Fields;

use Momos\Branch\Domain\NoticeScope;
use Momos\Branch\PostType\BranchScopeTaxonomy;
use Momos\Branch\Service\ValidityMeta;
use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Translations;

/**
 * G15 notice fields (spec §3.5, FR-BR-009, ENT-13).
 */
final class NoticeFields {
	public const GROUP = 'momo_notice_content';

	/** BM notices last at most 60 days (PRD FR-BR-009). */
	public const BM_MAX_DAYS = 60;

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_groups' ], 8 );
		add_filter( 'momos/fields/validate_group/' . self::GROUP, [ self::class, 'validate_group' ], 10, 4 );
		add_filter( 'momos/fields/publish_errors/momo_notice', [ self::class, 'publish_errors' ], 10, 4 );
	}

	public static function register_groups(): void {
		$scope_choices = [
			NoticeScope::SiteWide->value => __( 'Site-wide', 'momos-core' ),
			NoticeScope::Branches->value => __( 'Selected branches', 'momos-core' ),
		];
		if ( is_user_logged_in() && null !== momos_user_branch_ids() ) {
			unset( $scope_choices[ NoticeScope::SiteWide->value ] ); // BM: "Site-wide" not offered (SCR-A13).
		}
		FieldRegistry::group(
			[
				'key'       => self::GROUP,
				'title'     => __( 'Notice', 'momos-core' ),
				'post_type' => 'momo_notice',
				'fields'    => [
					[ 'name' => 'momo_notice_body', 'label' => __( 'Text', 'momos-core' ), 'type' => 'textarea', 'rows' => 3, 'maxlength' => 300, 'required' => 'publish', 'translatable' => true, 'help' => __( 'Plain text, up to 300 characters. Add one optional link below.', 'momos-core' ) ],
					[ 'name' => 'momo_notice_severity', 'label' => __( 'Severity', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => 'info', 'required' => true, 'column' => true, 'choices' => [ 'info' => __( 'Information', 'momos-core' ), 'warning' => __( 'Warning', 'momos-core' ) ] ],
					[ 'name' => 'momo_notice_scope', 'label' => __( 'Shown on', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => NoticeScope::Branches->value, 'required' => true, 'column' => true, 'choices' => $scope_choices ],
					[ 'name' => 'momo_notice_branches', 'label' => __( 'Branches', 'momos-core' ), 'type' => 'taxonomy', 'taxonomy' => BranchScopeTaxonomy::TAX, 'multiple' => true, 'save_terms' => true, 'show_if' => [ 'momo_notice_scope' => NoticeScope::Branches->value ] ],
					[ 'name' => 'momo_notice_start_at', 'label' => __( 'Start (JST)', 'momos-core' ), 'type' => 'datetime', 'required' => 'publish', 'column' => true ],
					[ 'name' => 'momo_notice_end_at', 'label' => __( 'End (JST)', 'momos-core' ), 'type' => 'datetime', 'column' => true, 'help' => __( 'Required for Branch Managers (max. 60 days). The notice disappears automatically at this time.', 'momos-core' ) ],
					[ 'name' => 'momo_notice_link_url', 'label' => __( 'Link URL', 'momos-core' ), 'type' => 'text', 'maxlength' => 255, 'translatable' => true, 'help' => __( 'Site path such as /menu/ or a https:// address.', 'momos-core' ) ],
					[ 'name' => 'momo_notice_link_label', 'label' => __( 'Link text', 'momos-core' ), 'type' => 'text', 'maxlength' => 40, 'translatable' => true ],
				],
			]
		);
		Translations::register( 'post:momo_notice', [ 'title' => [ 'label' => __( 'Title', 'momos-core' ), 'type' => 'text', 'source' => 'post_title', 'max' => 80 ] ] );
	}

	/**
	 * Rules that apply to every save (spec §3.5).
	 *
	 * @param array<string,mixed> $v values after the change
	 * @return list<string>
	 */
	public static function errors( array $v, int $post_id, ?int $user_id = null ): array {
		$errors = [];
		$scope  = (string) ( $v['momo_notice_scope'] ?? NoticeScope::Branches->value );
		$terms  = array_map( 'intval', (array) ( $v['momo_notice_branches'] ?? [] ) );
		if ( ! $terms && $post_id && ! metadata_exists( 'post', $post_id, 'momo_notice_branches' ) ) {
			$terms = array_map( 'intval', (array) wp_get_object_terms( $post_id, BranchScopeTaxonomy::TAX, [ 'fields' => 'ids' ] ) ); // Created outside the form.
		}
		$uid    = $user_id ?? get_current_user_id();
		$scoped = $uid > 0 && null !== momos_user_branch_ids( $uid ); // System/CLI writes are not branch-scoped.
		if ( NoticeScope::Branches->value === $scope && ! $terms ) {
			$errors[] = __( 'Select at least one branch.', 'momos-core' );
		}
		$start = ValidityMeta::to_instant( (string) ( $v['momo_notice_start_at'] ?? '' ) );
		$end   = ValidityMeta::to_instant( (string) ( $v['momo_notice_end_at'] ?? '' ) );
		if ( null !== $start && null !== $end && $end <= $start ) {
			$errors[] = __( 'End must be after start.', 'momos-core' );
		}
		if ( $scoped ) {
			if ( null === $end ) {
				$errors[] = __( 'Set an end date and time for the notice.', 'momos-core' );
			} elseif ( null !== $start && $end > $start->modify( '+' . self::BM_MAX_DAYS . ' days' ) ) {
				/* translators: %d: maximum days */
				$errors[] = sprintf( __( 'A notice can run for at most %d days.', 'momos-core' ), self::BM_MAX_DAYS );
			}
		}
		$url = trim( (string) ( $v['momo_notice_link_url'] ?? '' ) );
		if ( '' !== $url && ! ( preg_match( '#^/[^/]#', $url ) || '/' === $url || str_starts_with( $url, 'https://' ) ) ) {
			$errors[] = __( 'The link must be a site path (starting with /) or a https:// address.', 'momos-core' );
		}
		if ( '' !== $url && '' === trim( (string) ( $v['momo_notice_link_label'] ?? '' ) ) ) {
			$errors[] = __( 'Add a link text for the link.', 'momos-core' );
		}
		return $errors;
	}

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $new_all
	 * @param array<string,mixed> $changes
	 * @return list<string>
	 */
	public static function validate_group( array $errors, array $new_all, array $changes, int|string $object_id ): array {
		return array_merge( $errors, self::errors( $new_all, (int) $object_id ) );
	}

	/**
	 * @param list<string>        $errors
	 * @param array<string,mixed> $merged
	 * @param array<string,mixed> $data
	 * @return list<string>
	 */
	public static function publish_errors( array $errors, array $merged, array $data, int $post_id ): array {
		if ( mb_strlen( (string) ( $data['post_title'] ?? '' ) ) > 80 ) {
			$errors[] = __( 'The title can have at most 80 characters.', 'momos-core' );
		}
		return array_values( array_unique( array_merge( $errors, self::errors( $merged, $post_id ) ) ) );
	}
}
