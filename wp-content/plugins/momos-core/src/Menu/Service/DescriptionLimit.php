<?php
declare(strict_types=1);

namespace Momos\Menu\Service;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;
use Momos\Menu\ContentTypes;

/**
 * Long description of a dish: at most 1,500 characters in Japanese and in every translation
 * (FR-MENU-004(a), PRD §F-MENU-03). Characters are counted on the text a reader sees (tags removed,
 * entities decoded), so formatting markup never counts against the limit.
 *
 *  - Japanese (`post_content`): publish validation in DishPublishValidator (a new publication is saved as a
 *    draft; on a published dish the previous description is kept) and a counter under the editor.
 *  - Translations (`content` field): a translation over the limit cannot be approved — it is returned to
 *    `draft` immediately after the save with a notice naming the language and the length. Drafts may be
 *    saved over the limit (not public) and show the same notice.
 *
 * The kernel's raw `max` key is not used for this HTML field: it counts markup and truncates silently.
 */
final class DescriptionLimit {
	public const MAX    = 1500;
	public const NOTICE = 'momos_dish_desc_notice_';

	public static function register(): void {
		add_action( 'momos/i18n/translation_saved', [ self::class, 'on_translation_saved' ], 5, 7 );
		add_filter( 'wp_insert_post_data', [ self::class, 'keep_published_description' ], 100, 2 );
		if ( is_admin() ) {
			add_action( 'edit_form_after_editor', [ self::class, 'editor_counter' ] );
			add_action( 'admin_notices', [ self::class, 'notices' ] );
		}
	}

	/** Visible characters of an HTML description. */
	public static function length( string $html ): int {
		return mb_strlen( trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	public static function exceeds( string $html ): bool {
		return self::length( $html ) > self::MAX;
	}

	/** Clear validation message (admin language). */
	public static function message( int $length, ?string $lang = null ): string {
		if ( null === $lang || Lang::SOURCE === $lang ) {
			/* translators: %s: current number of characters */
			return sprintf( __( 'The long description can have at most 1,500 characters (currently %s). Shorten it before publishing.', 'momos-core' ), number_format_i18n( $length ) );
		}
		/* translators: 1: language code, 2: current number of characters */
		return sprintf( __( 'The %1$s description can have at most 1,500 characters (currently %2$s). The translation was kept as a draft and cannot be approved until it is shortened.', 'momos-core' ), $lang, number_format_i18n( $length ) );
	}

	/**
	 * Translation validation: an over-length `content` translation of a dish never stays approved.
	 *
	 * @param list<string> $fields
	 */
	public static function on_translation_saved( string $type, int|string $id, string $lang, string $old_status, string $status, array $fields = [], int $user_id = 0 ): void {
		if ( 'post' !== $type || ContentTypes::DISH !== get_post_type( (int) $id ) || ! in_array( $status, [ 'approved', 'needs_review', 'draft' ], true ) ) {
			return;
		}
		$text = Translations::post( (int) $id, 'content', $lang, true );
		if ( ! self::exceeds( $text ) ) {
			return;
		}
		self::notify( $user_id, self::message( self::length( $text ), $lang ) );
		if ( in_array( $status, [ 'approved', 'needs_review' ], true ) ) {
			Translations::set_status( 'post', (int) $id, $lang, 'draft', $user_id );
		}
	}

	/**
	 * A published dish keeps its previous Japanese description when an update exceeds the limit
	 * (DishPublishValidator reports the error; FieldRegistry rejects the field changes of that save).
	 *
	 * @param array<string,mixed> $data    slashed post data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function keep_published_description( array $data, array $postarr ): array {
		if ( ContentTypes::DISH !== ( $data['post_type'] ?? '' ) || ! in_array( $data['post_status'] ?? '', [ 'publish', 'future' ], true ) ) {
			return $data;
		}
		$old = empty( $postarr['ID'] ) ? null : get_post( (int) $postarr['ID'] );
		if ( ! $old || 'publish' !== $old->post_status || ! self::exceeds( wp_unslash( (string) ( $data['post_content'] ?? '' ) ) ) ) {
			return $data;
		}
		$data['post_content'] = wp_slash( $old->post_content );
		FieldRegistry::add_errors( [ __( 'The previous long description was kept.', 'momos-core' ) ] );
		return $data;
	}

	/** Field max shown under the Japanese editor (server-rendered, no script). */
	public static function editor_counter( \WP_Post $post ): void {
		if ( ContentTypes::DISH !== $post->post_type ) {
			return;
		}
		$len = self::length( (string) $post->post_content );
		/* translators: 1: current number of characters, 2: maximum */
		$text = sprintf( __( 'Long description: %1$s / %2$s characters (saved text).', 'momos-core' ), number_format_i18n( $len ), number_format_i18n( self::MAX ) );
		echo '<p class="description momos-desc-count' . ( $len > self::MAX ? ' momos-tr-count--over' : '' ) . '">' . esc_html( $text ) . '</p>';
	}

	public static function notify( int $user_id, string $message ): void {
		$user_id = $user_id ? $user_id : get_current_user_id();
		$all     = get_transient( self::NOTICE . $user_id );
		$all     = is_array( $all ) ? $all : [];
		$all[]   = $message;
		set_transient( self::NOTICE . $user_id, array_values( array_unique( $all ) ), 120 );
	}

	public static function notices(): void {
		$uid  = get_current_user_id();
		$msgs = $uid ? get_transient( self::NOTICE . $uid ) : false;
		if ( ! is_array( $msgs ) || ! $msgs ) {
			return;
		}
		delete_transient( self::NOTICE . $uid );
		echo '<div class="notice notice-error" role="alert">';
		foreach ( $msgs as $m ) {
			echo '<p>' . esc_html( (string) $m ) . '</p>';
		}
		echo '</div>';
	}
}
