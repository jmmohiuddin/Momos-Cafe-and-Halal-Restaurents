<?php
declare(strict_types=1);

namespace Momos\Admin\Content;

use Momos\Kernel\I18n\Admin\TranslationEditor;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Media library fields (SCR-A18, screens.md §3.18/§5.18 [Proposed] keys, content-strings.md §8.7 C22):
 *  - `momo_gallery_include` (bool) — show on the gallery page (C22); refused for images flagged
 *    `momo_image_illustrative` (DEC-047: demo images never on the gallery),
 *  - `momo_media_branch` (branch post ID, 0 = none) — Branch Managers only their own branches,
 *  - `momo_media_category` (menu category term ID, 0 = none).
 * Alt text and caption per language go through the translation workflow (kind `post:attachment`, fields
 * `alt` ← `_wp_attachment_image_alt`, `caption` ← post_excerpt); the gallery shows an image in a language
 * only with an approved translation (Content::gallery). Works in the media modal and on the attachment
 * edit screen (core verifies the nonce; we check `edit_post`).
 */
final class MediaFields {
	public const INCLUDE  = 'momo_gallery_include';
	public const BRANCH   = 'momo_media_branch';
	public const CATEGORY = 'momo_media_category';
	public const ILLUS    = 'momo_image_illustrative';

	public static function register(): void {
		Translations::register(
			'post:attachment',
			[
				'alt'     => [ 'label' => 'Alternative text', 'type' => 'text', 'source' => 'meta:_wp_attachment_image_alt', 'max' => 250 ],
				'caption' => [ 'label' => 'Caption', 'type' => 'textarea', 'source' => 'post_excerpt', 'max' => 500 ],
			]
		);
		add_filter( 'attachment_fields_to_edit', [ self::class, 'fields_to_edit' ], 20, 2 );
		add_filter( 'attachment_fields_to_save', [ self::class, 'fields_to_save' ], 20, 2 );
	}

	/**
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields_to_edit( $fields, $post ): array {
		$fields = is_array( $fields ) ? $fields : [];
		if ( ! $post instanceof \WP_Post || ! wp_attachment_is_image( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}
		$id                       = (int) $post->ID;
		$include                  = '1' === (string) get_post_meta( $id, self::INCLUDE, true );
		$illus                    = '1' === (string) get_post_meta( $id, 'momo_image_illustrative', true );
		$fields[ self::INCLUDE ]  = [
			'label' => __( 'Gallery', 'momos-core' ),
			'input' => 'html',
			'html'  => '<label><input type="checkbox" name="attachments[' . $id . '][' . self::INCLUDE . ']" value="1"' . checked( $include, true, false ) . disabled( $illus && ! $include, true, false ) . '> ' . esc_html__( 'Show on the gallery page', 'momos-core' ) . '</label>'
				. '<input type="hidden" name="attachments[' . $id . '][' . self::INCLUDE . '_present]" value="1">',
			'helps' => $illus ? __( 'Illustrative (demo) images cannot be shown on the gallery.', 'momos-core' ) : '',
		];
		$fields[ self::ILLUS ]    = [
			'label' => __( 'Illustrative image', 'momos-core' ),
			'input' => 'html',
			'html'  => '<label><input type="checkbox" name="attachments[' . $id . '][' . self::ILLUS . ']" value="1"' . checked( $illus, true, false ) . '> ' . esc_html__( 'Image for illustration (not the actual dish or venue)', 'momos-core' ) . '</label>'
				. '<input type="hidden" name="attachments[' . $id . '][' . self::ILLUS . '_present]" value="1">',
			'helps' => __( 'Shown with the caption "Image for illustration" on dish and offer pages; never used on the gallery or branch pages (DEC-047).', 'momos-core' ),
		];
		$fields[ self::BRANCH ]   = [
			'label' => __( 'Branch', 'momos-core' ),
			'input' => 'html',
			'html'  => self::select( 'attachments[' . $id . '][' . self::BRANCH . ']', 'attachments-' . $id . '-' . self::BRANCH, self::branch_choices(), (int) get_post_meta( $id, self::BRANCH, true ) ),
		];
		$fields[ self::CATEGORY ] = [
			'label' => __( 'Menu category', 'momos-core' ),
			'input' => 'html',
			'html'  => self::select( 'attachments[' . $id . '][' . self::CATEGORY . ']', 'attachments-' . $id . '-' . self::CATEGORY, self::category_choices(), (int) get_post_meta( $id, self::CATEGORY, true ) ),
		];
		if ( current_user_can( 'momo_translate' ) ) {
			$links = [];
			foreach ( TranslationEditor::user_langs() as $lang ) {
				$links[] = '<a href="' . esc_url( TranslationEditor::url( 'post', $id, $lang ) ) . '">' . esc_html( Lang::native( $lang ) . ' — ' . TranslationEditor::status_label( Translations::status( 'post', $id, $lang ) ) ) . '</a>';
			}
			$fields['momos_translations'] = [
				'label' => __( 'Alt text & caption translations', 'momos-core' ),
				'input' => 'html',
				'html'  => implode( '<br>', $links ),
			];
		}
		return $fields;
	}

	/** @param array<int,string> $choices */
	private static function select( string $name, string $id, array $choices, int $current ): string {
		$html = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"><option value="0">' . esc_html__( '— None —', 'momos-core' ) . '</option>';
		foreach ( $choices as $value => $label ) {
			$html .= '<option value="' . (int) $value . '"' . selected( $current, (int) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** Branches the current user may tag (published + draft, branch scope applied). @return array<int,string> */
	public static function branch_choices(): array {
		$out   = [];
		$scope = momos_user_branch_ids();
		foreach ( get_posts( [ 'post_type' => 'momo_branch', 'post_status' => [ 'publish', 'draft', 'private' ], 'posts_per_page' => 200, 'orderby' => 'menu_order title', 'order' => 'ASC', 'no_found_rows' => true ] ) as $b ) {
			if ( null === $scope || in_array( (int) $b->ID, $scope, true ) ) {
				$out[ (int) $b->ID ] = (string) $b->post_title;
			}
		}
		return $out;
	}

	/** @return array<int,string> */
	public static function category_choices(): array {
		$terms = get_terms( [ 'taxonomy' => 'momo_menu_category', 'hide_empty' => false ] );
		$out   = [];
		foreach ( is_array( $terms ) ? $terms : [] as $t ) {
			$out[ (int) $t->term_id ] = (string) $t->name;
		}
		return $out;
	}

	/**
	 * Validate and store the gallery fields. Errors are reported through core's `errors` key.
	 *
	 * @param array<string,mixed> $post
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public static function fields_to_save( $post, $input ): array {
		$post  = is_array( $post ) ? $post : [];
		$input = is_array( $input ) ? $input : [];
		$id    = (int) ( $post['ID'] ?? 0 );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			if ( array_intersect_key( $input, array_flip( [ self::INCLUDE . '_present', self::ILLUS . '_present', self::BRANCH, self::CATEGORY ] ) ) ) {
				momos_audit( 'access.denied', 'attachment', $id, null, [ 'cap' => 'edit_post', 'channel' => 'media' ] );
			}
			return $post;
		}
		$errors = self::apply( $id, $input );
		foreach ( $errors as $field => $msg ) {
			$post['errors'][ $field ]['errors'][] = $msg;
		}
		return $post;
	}

	/**
	 * Apply submitted values (testable without the media request). Only fields present in $input change.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,string> field => error
	 */
	public static function apply( int $id, array $input ): array {
		$errors = [];
		if ( isset( $input[ self::ILLUS . '_present' ] ) || isset( $input[ self::ILLUS ] ) ) {
			$want = ! empty( $input[ self::ILLUS ] ) && '0' !== (string) $input[ self::ILLUS ];
			$was  = '1' === (string) get_post_meta( $id, self::ILLUS, true );
			if ( $want !== $was ) {
				if ( $want ) {
					update_post_meta( $id, self::ILLUS, '1' );
					delete_post_meta( $id, self::INCLUDE ); // DEC-047: illustrative images never on the gallery.
					unset( $input[ self::INCLUDE ], $input[ self::INCLUDE . '_present' ] );
				} else {
					delete_post_meta( $id, self::ILLUS );
				}
				momos_audit( 'media.illustrative_changed', 'attachment', $id, null, [ 'illustrative' => $want ] );
			}
		}
		if ( isset( $input[ self::INCLUDE . '_present' ] ) || isset( $input[ self::INCLUDE ] ) ) {
			$on = ! empty( $input[ self::INCLUDE ] ) && '0' !== (string) $input[ self::INCLUDE ];
			if ( $on && '1' === (string) get_post_meta( $id, 'momo_image_illustrative', true ) ) {
				$errors[ self::INCLUDE ] = __( 'Illustrative (demo) images cannot be shown on the gallery.', 'momos-core' );
			} elseif ( $on ) {
				update_post_meta( $id, self::INCLUDE, '1' );
			} else {
				delete_post_meta( $id, self::INCLUDE );
			}
		}
		if ( array_key_exists( self::BRANCH, $input ) ) {
			$b   = absint( $input[ self::BRANCH ] );
			$old = (int) get_post_meta( $id, self::BRANCH, true );
			if ( $b === $old ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Unchanged.
			} elseif ( $b && 'momo_branch' !== get_post_type( $b ) ) {
				$errors[ self::BRANCH ] = __( 'Choose a branch from the list.', 'momos-core' );
			} elseif ( ( $b && ! momos_user_can_branch( $b ) ) || ( $old && ! momos_user_can_branch( $old ) ) ) {
				momos_audit( 'access.denied', 'attachment', $id, $b ?: $old, [ 'channel' => 'media', 'field' => self::BRANCH, 'reason' => 'branch_scope' ] );
				$errors[ self::BRANCH ] = __( 'You can only tag images with your own branches.', 'momos-core' );
			} elseif ( $b ) {
				update_post_meta( $id, self::BRANCH, $b );
			} else {
				delete_post_meta( $id, self::BRANCH );
			}
		}
		if ( array_key_exists( self::CATEGORY, $input ) ) {
			$c = absint( $input[ self::CATEGORY ] );
			if ( $c && ! ( get_term( $c, 'momo_menu_category' ) instanceof \WP_Term ) ) {
				$errors[ self::CATEGORY ] = __( 'Choose a menu category from the list.', 'momos-core' );
			} elseif ( $c ) {
				update_post_meta( $id, self::CATEGORY, $c );
			} else {
				delete_post_meta( $id, self::CATEGORY );
			}
		}
		$gallery = class_exists( \Momos\Presentation\SystemPages::class ) ? \Momos\Presentation\SystemPages::id( 'gallery' ) : 0;
		if ( $gallery ) {
			do_action( 'momos/cache/purge', [ 'momo-post-' . $gallery ] );
		}
		return $errors;
	}
}
