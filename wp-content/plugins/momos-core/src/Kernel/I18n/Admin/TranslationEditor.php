<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n\Admin;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Translation editing UI (F-I18N-03):
 *  - a "Translations" meta box on edit screens of translatable post types (editors), and
 *  - a stand-alone editor page (admin.php?page=momos-translate&type=post&id=N&lang=en) used by
 *    Translators from the Translation gaps view (SCR-A11) without access to source fields.
 * Permission: `momos_translate` (Translator, HO, SA) or edit rights on the item.
 * Only users with `momo_approve_translations` may set status "approved" (DEC-027 human review).
 */
final class TranslationEditor {
	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'meta_box' ], 30, 2 );
		add_action( 'save_post', [ self::class, 'save_from_post_screen' ], 20, 2 );
		add_action( 'admin_menu', [ self::class, 'menu' ], 30 );
		add_action( 'admin_post_momos_save_translation', [ self::class, 'save_from_page' ] );
	}

	public static function can_translate( string $type, int $id ): bool {
		if ( current_user_can( 'momo_translate' ) ) {
			return true;
		}
		return 'post' === $type ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_term', $id );
	}

	public static function can_approve(): bool {
		return current_user_can( 'momo_approve_translations' );
	}

	public static function meta_box( string $post_type, $post ): void {
		if ( ! Translations::fields( 'post:' . $post_type ) ) {
			return;
		}
		add_meta_box( 'momos-translations', __( 'Translations', 'momos-core' ), static function ( \WP_Post $p ): void {
			wp_nonce_field( 'momos_translations_' . $p->ID, '_momos_tr_nonce' );
			self::render_tabs( 'post', $p->ID );
		}, $post_type, 'normal', 'low' );
	}

	public static function render_tabs( string $type, int $id, ?string $only_lang = null ): void {
		$kind   = 'term' === $type ? 'term:' . ( get_term( $id )->taxonomy ?? '' ) : Translations::kind_of_post( $id );
		$fields = Translations::fields( $kind );
		$langs  = $only_lang ? [ $only_lang ] : Lang::targets();
		echo '<div class="momos-tr">';
		foreach ( $langs as $lang ) {
			$data    = Translations::data( $type, $id, $lang );
			$status  = Translations::status( $type, $id, $lang );
			$dir     = Lang::is_rtl( $lang ) ? 'rtl' : 'ltr';
			$missing = Translations::missing_fields( $type, $id, $lang );
			echo '<details class="momos-tr__lang"' . ( $only_lang ? ' open' : '' ) . '><summary><strong>' . esc_html( Lang::native( $lang ) ) . '</strong> (' . esc_html( $lang ) . ') — ' . esc_html( self::status_label( $status ) );
			if ( Translations::is_outdated( $type, $id, $lang ) ) {
				echo ' — <em>' . esc_html__( 'source changed since approval', 'momos-core' ) . '</em>';
			}
			if ( $missing && 'missing' !== $status ) {
				/* translators: %d: number of fields */
				echo ' — ' . esc_html( sprintf( _n( '%d field empty', '%d fields empty', count( $missing ), 'momos-core' ), count( $missing ) ) );
			}
			echo '</summary><table class="form-table" role="presentation"><tbody>';
			foreach ( $fields as $key => $def ) {
				$src   = 'term' === $type ? Translations::source_term_value( get_term( $id ), $key ) : Translations::source_post_value( get_post( $id ), $key );
				$name  = 'momos_tr[' . $lang . '][' . $key . ']';
				$fid   = 'momos-tr-' . $lang . '-' . $key;
				$value = (string) ( $data[ $key ] ?? '' );
				echo '<tr><th scope="row"><label for="' . esc_attr( $fid ) . '">' . esc_html( $def['label'] ) . '</label></th><td>';
				echo '<p class="description" lang="ja">' . esc_html__( 'Source (ja):', 'momos-core' ) . ' ' . esc_html( wp_trim_words( wp_strip_all_tags( $src ), 40 ) ) . '</p>';
				if ( 'text' === $def['type'] ) {
					echo '<input class="large-text" type="text" dir="' . esc_attr( $dir ) . '" lang="' . esc_attr( $lang ) . '" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
				} else {
					echo '<textarea class="large-text" rows="' . ( 'html' === $def['type'] ? 8 : 3 ) . '" dir="' . esc_attr( $dir ) . '" lang="' . esc_attr( $lang ) . '" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '">' . esc_textarea( $value ) . '</textarea>';
				}
				echo '</td></tr>';
			}
			echo '<tr><th scope="row"><label for="momos-tr-status-' . esc_attr( $lang ) . '">' . esc_html__( 'Status', 'momos-core' ) . '</label></th><td><select id="momos-tr-status-' . esc_attr( $lang ) . '" name="momos_tr_status[' . esc_attr( $lang ) . ']">';
			foreach ( Translations::STATUSES as $s ) {
				if ( 'approved' === $s && ! self::can_approve() && 'approved' !== $status ) {
					continue;
				}
				echo '<option value="' . esc_attr( $s ) . '"' . selected( $status, $s, false ) . '>' . esc_html( self::status_label( $s ) ) . '</option>';
			}
			echo '</select>';
			if ( ! self::can_approve() ) {
				echo '<p class="description">' . esc_html__( 'A native-speaker reviewer approves translations before they are published.', 'momos-core' ) . '</p>';
			}
			echo '</td></tr></tbody></table></details>';
		}
		echo '</div>';
	}

	public static function status_label( string $status ): string {
		return match ( $status ) {
			'draft' => __( 'Draft', 'momos-core' ),
			'needs_review' => __( 'Needs review', 'momos-core' ),
			'approved' => __( 'Approved (published)', 'momos-core' ),
			default => __( 'Not translated', 'momos-core' ),
		};
	}

	public static function save_from_post_screen( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || ! isset( $_POST['_momos_tr_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_momos_tr_nonce'] ) ), 'momos_translations_' . $post_id ) ) {
			return;
		}
		self::persist( 'post', $post_id );
	}

	private static function persist( string $type, int $id ): void {
		if ( ! self::can_translate( $type, $id ) ) {
			return;
		}
		$values   = isset( $_POST['momos_tr'] ) && is_array( $_POST['momos_tr'] ) ? wp_unslash( $_POST['momos_tr'] ) : []; // phpcs:ignore -- sanitised in Translations::save().
		$statuses = isset( $_POST['momos_tr_status'] ) && is_array( $_POST['momos_tr_status'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['momos_tr_status'] ) ) : [];
		foreach ( Lang::targets() as $lang ) {
			if ( ! isset( $values[ $lang ] ) && ! isset( $statuses[ $lang ] ) ) {
				continue;
			}
			$status = $statuses[ $lang ] ?? Translations::status( $type, $id, $lang );
			if ( 'approved' === $status && ! self::can_approve() && 'approved' !== Translations::status( $type, $id, $lang ) ) {
				$status = 'needs_review';
			}
			$row = array_map( 'strval', (array) ( $values[ $lang ] ?? [] ) );
			if ( 'missing' === $status && '' !== implode( '', $row ) ) {
				$status = 'draft';
			}
			$before = Translations::data( $type, $id, $lang );
			if ( $row === array_intersect_key( $before, $row ) && $status === Translations::status( $type, $id, $lang ) ) {
				continue;
			}
			Translations::save( $type, $id, $lang, $row, $status, get_current_user_id() );
		}
	}

	public static function menu(): void {
		add_submenu_page( 'options.php', __( 'Edit translation', 'momos-core' ), __( 'Edit translation', 'momos-core' ), 'momo_translate', 'momos-translate', [ self::class, 'page' ] );
	}

	public static function page(): void {
		$type = isset( $_GET['type'] ) && 'term' === $_GET['type'] ? 'term' : 'post'; // phpcs:ignore
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore
		if ( ! $id || ! Lang::is_valid( $lang ) || Lang::SOURCE === $lang || ! self::can_translate( $type, $id ) ) {
			wp_die( esc_html__( 'You do not have permission to translate this item.', 'momos-core' ), 403 );
		}
		$title = 'term' === $type ? ( get_term( $id )->name ?? '' ) : get_the_title( $id );
		echo '<div class="wrap momos-admin"><h1>' . esc_html( sprintf( /* translators: 1: item 2: language */ __( 'Translate “%1$s” — %2$s', 'momos-core' ), $title, Lang::native( $lang ) ) ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success" role="status"><p>' . esc_html__( 'Translation saved.', 'momos-core' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="momos_save_translation">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		wp_nonce_field( 'momos_translate_' . $type . $id, '_momos_tr_nonce' );
		self::render_tabs( $type, $id, $lang );
		submit_button( __( 'Save translation', 'momos-core' ) );
		echo '</form></div>';
	}

	public static function save_from_page(): void {
		$type = isset( $_POST['type'] ) && 'term' === $_POST['type'] ? 'term' : 'post';
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		check_admin_referer( 'momos_translate_' . $type . $id, '_momos_tr_nonce' );
		if ( ! self::can_translate( $type, $id ) ) {
			wp_die( esc_html__( 'You do not have permission to translate this item.', 'momos-core' ), 403 );
		}
		self::persist( $type, $id );
		wp_safe_redirect( add_query_arg( [ 'page' => 'momos-translate', 'type' => $type, 'id' => $id, 'lang' => $lang, 'saved' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
