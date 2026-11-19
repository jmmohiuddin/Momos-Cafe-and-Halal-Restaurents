<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n\Admin;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Translations;

/**
 * Translation editing UI (F-I18N-03, i18n-seo-analytics.md §6.1/§6.2):
 *  - a "Translations" meta box on edit screens of translatable post types (editors) incl. attachments, and
 *  - a stand-alone editor page (admin.php?page=momos-translate&type=post|term|option&id=N&lang=en) used by
 *    Translators from the Translation gaps view (SCR-A11) without access to source fields.
 * Permission: `momo_translate` (Translator, HO, SA, MM, CE) or edit rights on the item; translators with
 * user meta `momo_translator_langs` only see/save those languages.
 * Only users with `momo_approve_translations` may set status "approved" (DEC-027 human review); for
 * review-class content (legal pages, filter `momos/i18n/review_class`) the reviewer must differ from the
 * user who last changed the text.
 * Also bootstraps the other SCR-A11 screens (gaps, strings) and the content editors (Admin\Content).
 */
final class TranslationEditor {
	public const NOTICE = 'momos_tr_notice_';

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'meta_box' ], 30, 2 );
		add_action( 'save_post', [ self::class, 'save_from_post_screen' ], 20, 2 );
		add_action( 'edit_attachment', [ self::class, 'save_from_attachment_screen' ], 20 );
		add_action( 'admin_menu', [ self::class, 'menu' ], 30 );
		add_action( 'admin_post_momos_save_translation', [ self::class, 'save_from_page' ] );
		add_action( 'admin_notices', [ self::class, 'notices' ] );
		TranslationGaps::register();
		GapsPage::register();
		StringsPage::register();
		Cli::register();
		// Content editors (FAQ, legal meta, media fields) until Plugin::MODULES lists the module (kernel request).
		if ( class_exists( \Momos\Admin\Content\ContentModule::class ) && ! in_array( \Momos\Admin\Content\ContentModule::class, \Momos\Kernel\Plugin::MODULES, true ) ) {
			( new \Momos\Admin\Content\ContentModule() )->register();
		}
	}

	public static function can_translate( string $type, int|string $id ): bool {
		if ( current_user_can( 'momo_translate' ) ) {
			return true;
		}
		return match ( $type ) {
			'post' => current_user_can( 'edit_post', (int) $id ),
			'term' => current_user_can( 'edit_term', (int) $id ),
			default => current_user_can( 'momo_manage_brand' ),
		};
	}

	public static function can_approve(): bool {
		return current_user_can( 'momo_approve_translations' );
	}

	/**
	 * Target languages the user may translate: `momo_translator_langs` (ROLE-05 language pairs) when set,
	 * else every target language.
	 *
	 * @return list<string>
	 */
	public static function user_langs( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$own     = $user_id ? get_user_meta( $user_id, 'momo_translator_langs', true ) : [];
		$own     = is_string( $own ) ? array_filter( array_map( 'trim', explode( ',', $own ) ) ) : (array) $own;
		$own     = array_values( array_intersect( Lang::targets(), array_map( 'strval', $own ) ) );
		return $own ? $own : Lang::targets();
	}

	/** Content whose approval needs a reviewer other than the translator (i18n-seo-analytics §6.2). */
	public static function is_review_class( string $type, int|string $id ): bool {
		$rc = 'post' === $type && 'page' === get_post_type( (int) $id ) && 'legal' === (string) get_post_meta( (int) $id, 'momo_page_type', true );
		return (bool) apply_filters( 'momos/i18n/review_class', $rc, $type, $id );
	}

	public static function meta_box( string $post_type, $post ): void {
		if ( ! Translations::fields( 'post:' . $post_type ) ) {
			return;
		}
		add_meta_box(
			'momos-translations',
			__( 'Translations', 'momos-core' ),
			static function ( \WP_Post $p ): void {
				wp_nonce_field( 'momos_translations_' . $p->ID, '_momos_tr_nonce' );
				self::render_tabs( 'post', $p->ID );
			},
			$post_type,
			'normal',
			'low'
		);
	}

	public static function render_tabs( string $type, int|string $id, ?string $only_lang = null ): void {
		$kind   = Translations::kind_of( $type, $id );
		$fields = Translations::fields( $kind );
		$langs  = $only_lang ? [ $only_lang ] : self::user_langs();
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
				$src  = Translations::source_value( $type, $id, $key );
				$name = 'momos_tr[' . $lang . '][' . $key . ']';
				$fid  = 'momos-tr-' . $lang . '-' . $key;
				if ( 'repeater' === $def['type'] ) {
					self::render_repeater( $def, $key, $src, $data[ $key ] ?? [], $name, $fid, $lang, $dir );
					continue;
				}
				$value = is_string( $data[ $key ] ?? null ) ? (string) $data[ $key ] : '';
				echo '<tr><th scope="row"><label for="' . esc_attr( $fid ) . '">' . esc_html( (string) $def['label'] ) . '</label></th><td>';
				echo '<p class="description" lang="ja">' . esc_html__( 'Source (ja):', 'momos-core' ) . ' ' . esc_html( wp_trim_words( wp_strip_all_tags( $src ), 40 ) ) . '</p>';
				self::input( (string) $def['type'], $name, $fid, $value, $lang, $dir, isset( $def['max'] ) ? (int) $def['max'] : 0 );
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
			} elseif ( self::is_review_class( $type, $id ) ) {
				echo '<p class="description">' . esc_html__( 'Review-class content: the reviewer must be a different person from the translator.', 'momos-core' ) . '</p>';
			}
			echo '</td></tr></tbody></table></details>';
		}
		echo '</div>';
	}

	private static function input( string $type, string $name, string $fid, string $value, string $lang, string $dir, int $max ): void {
		$max_attr = $max > 0 ? ' maxlength="' . $max . '"' : '';
		if ( 'text' === $type ) {
			echo '<input class="large-text" type="text" dir="' . esc_attr( $dir ) . '" lang="' . esc_attr( $lang ) . '" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $max_attr . '>'; // phpcs:ignore -- $max_attr is an int.
		} else {
			echo '<textarea class="large-text" rows="' . ( 'html' === $type ? 8 : 3 ) . '" dir="' . esc_attr( $dir ) . '" lang="' . esc_attr( $lang ) . '" id="' . esc_attr( $fid ) . '" name="' . esc_attr( $name ) . '"' . $max_attr . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore -- $max_attr is an int.
		}
	}

	/**
	 * One block per source row: JA source read-only beside each translatable sub field (§6.1 step 3).
	 *
	 * @param array<string,mixed> $def
	 */
	private static function render_repeater( array $def, string $key, string $src, mixed $stored, string $name, string $fid, string $lang, string $dir ): void {
		$row_key = (string) ( $def['row_key'] ?? 'id' );
		$have    = [];
		foreach ( is_array( $stored ) ? $stored : Translations::decode_rows( $stored ) as $r ) {
			$have[ (string) ( $r[ $row_key ] ?? '' ) ] = $r;
		}
		$rows = Translations::decode_rows( $src );
		echo '<tr><th scope="row">' . esc_html( (string) $def['label'] ) . '</th><td>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'No source items yet.', 'momos-core' ) . '</p></td></tr>';
			return;
		}
		foreach ( $rows as $i => $r ) {
			$rk = (string) ( $r[ $row_key ] ?? '' );
			if ( '' === $rk ) {
				continue;
			}
			echo '<fieldset class="momos-tr__row"><legend>' . esc_html( $rk ) . '</legend>';
			echo '<input type="hidden" name="' . esc_attr( $name . '[' . $i . '][' . $row_key . ']' ) . '" value="' . esc_attr( $rk ) . '">';
			foreach ( (array) ( $def['sub_fields'] ?? [] ) as $sub => $sdef ) {
				$sdef = (array) $sdef;
				$sid  = $fid . '-' . $i . '-' . $sub;
				$sv   = is_scalar( $r[ $sub ] ?? '' ) ? (string) ( $r[ $sub ] ?? '' ) : '';
				echo '<p><label for="' . esc_attr( $sid ) . '"><strong>' . esc_html( (string) ( $sdef['label'] ?? $sub ) ) . '</strong></label><br>';
				echo '<span class="description" lang="ja">' . esc_html__( 'Source (ja):', 'momos-core' ) . ' ' . esc_html( wp_trim_words( wp_strip_all_tags( $sv ), 40 ) ) . '</span><br>';
				self::input( (string) ( $sdef['type'] ?? 'text' ), $name . '[' . $i . '][' . $sub . ']', $sid, (string) ( $have[ $rk ][ $sub ] ?? '' ), $lang, $dir, isset( $sdef['max'] ) ? (int) $sdef['max'] : 0 );
				echo '</p>';
			}
			echo '</fieldset>';
		}
		echo '</td></tr>';
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

	/** Attachments do not fire save_post (core returns early for them). */
	public static function save_from_attachment_screen( int $post_id ): void {
		if ( ! isset( $_POST['_momos_tr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_momos_tr_nonce'] ) ), 'momos_translations_' . $post_id ) ) {
			return;
		}
		self::persist( 'post', $post_id );
	}

	/**
	 * Save the submitted languages. Returns the refusals (translator approving own review-class text).
	 *
	 * @return list<string>
	 */
	public static function persist( string $type, int|string $id ): array {
		if ( ! self::can_translate( $type, $id ) ) {
			return [];
		}
		$values   = isset( $_POST['momos_tr'] ) && is_array( $_POST['momos_tr'] ) ? wp_unslash( $_POST['momos_tr'] ) : []; // phpcs:ignore -- sanitised in Translations::save().
		$statuses = isset( $_POST['momos_tr_status'] ) && is_array( $_POST['momos_tr_status'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['momos_tr_status'] ) ) : [];
		$errors   = [];
		$user     = get_current_user_id();
		foreach ( self::user_langs() as $lang ) {
			if ( ! isset( $values[ $lang ] ) && ! isset( $statuses[ $lang ] ) ) {
				continue;
			}
			$current = Translations::status( $type, $id, $lang );
			$status  = in_array( $statuses[ $lang ] ?? '', Translations::STATUSES, true ) ? $statuses[ $lang ] : $current;
			if ( 'approved' === $status && ! self::can_approve() && 'approved' !== $current ) {
				$status = 'needs_review';
			}
			$row = [];
			foreach ( (array) ( $values[ $lang ] ?? [] ) as $k => $v ) {
				$row[ (string) $k ] = is_array( $v ) ? array_values( array_filter( $v, 'is_array' ) ) : ( is_scalar( $v ) ? (string) $v : '' );
			}
			$filled = '' !== trim( implode( '', array_map( static fn( $v ) => is_array( $v ) ? (string) wp_json_encode( $v ) : $v, $row ) ) );
			if ( 'missing' === $status && $filled ) {
				$status = 'draft';
			}
			$changed = Translations::would_change( $type, $id, $lang, $row );
			if ( ! $changed && $status === $current ) {
				continue;
			}
			if ( 'approved' === $status && 'approved' !== $current && self::is_review_class( $type, $id ) ) {
				$translator = $changed ? $user : Translations::translated_by( $type, $id, $lang );
				if ( $translator === $user ) {
					$errors[] = __( 'You cannot approve your own translation of this content.', 'momos-core' );
					$status   = 'needs_review';
				}
			}
			Translations::save( $type, $id, $lang, $row, $status, $user );
		}
		if ( $errors ) {
			set_transient( self::NOTICE . $user, array_values( array_unique( $errors ) ), 120 );
		}
		return $errors;
	}

	public static function notices(): void {
		$user = get_current_user_id();
		$msgs = $user ? get_transient( self::NOTICE . $user ) : false;
		if ( ! is_array( $msgs ) ) {
			return;
		}
		delete_transient( self::NOTICE . $user );
		echo '<div class="notice notice-error" role="alert">';
		foreach ( $msgs as $m ) {
			echo '<p>' . esc_html( (string) $m ) . '</p>';
		}
		echo '</div>';
	}

	public static function menu(): void {
		add_submenu_page( 'options.php', __( 'Edit translation', 'momos-core' ), __( 'Edit translation', 'momos-core' ), 'momo_translate', 'momos-translate', [ self::class, 'page' ] );
	}

	/** @return array{0:string,1:int|string} sanitised type + id from a request array */
	private static function target( array $req ): array {
		$type = isset( $req['type'] ) && in_array( $req['type'], [ 'post', 'term', 'option' ], true ) ? (string) $req['type'] : 'post';
		$id   = 'option' === $type ? sanitize_key( wp_unslash( (string) ( $req['id'] ?? '' ) ) ) : absint( $req['id'] ?? 0 );
		return [ $type, $id ];
	}

	public static function url( string $type, int|string $id, string $lang ): string {
		return add_query_arg( [ 'page' => 'momos-translate', 'type' => $type, 'id' => $id, 'lang' => $lang ], admin_url( 'admin.php' ) );
	}

	private static function exists( string $type, int|string $id ): bool {
		return match ( $type ) {
			'term' => get_term( (int) $id ) instanceof \WP_Term,
			'option' => [] !== Translations::fields( 'option:' . $id ),
			default => null !== get_post( (int) $id ),
		};
	}

	public static function page(): void {
		[ $type, $id ] = self::target( $_GET ); // phpcs:ignore
		$lang          = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore
		if ( ! $id || ! Lang::is_valid( $lang ) || Lang::SOURCE === $lang || ! self::exists( $type, $id ) || ! self::can_translate( $type, $id ) || ! in_array( $lang, self::user_langs(), true ) ) {
			momos_audit( 'access.denied', 'translation', null, null, [ 'cap' => 'momo_translate', 'channel' => 'admin', 'screen' => 'momos-translate' ] );
			wp_die( esc_html__( 'You do not have permission to translate this item.', 'momos-core' ), 403 );
		}
		$title = match ( $type ) {
			'term' => get_term( (int) $id )->name ?? '',
			'option' => 'momo-brand' === $id ? __( 'Brand settings', 'momos-core' ) : (string) $id,
			default => get_the_title( (int) $id ),
		};
		echo '<div class="wrap momos-admin"><h1>' . esc_html( sprintf( /* translators: 1: item 2: language */ __( 'Translate “%1$s” — %2$s', 'momos-core' ), $title, Lang::native( $lang ) ) ) . '</h1>';
		if ( Translations::is_outdated( $type, $id, $lang ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Source changed since approval.', 'momos-core' ) . '</p></div>';
		}
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success" role="status"><p>' . esc_html__( 'Translation saved.', 'momos-core' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="momos_save_translation">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		wp_nonce_field( 'momos_translate_' . $type . $id, '_momos_tr_nonce' );
		self::render_tabs( $type, $id, $lang );
		submit_button( __( 'Save translation', 'momos-core' ) );
		echo '</form><p><a href="' . esc_url( add_query_arg( [ 'page' => GapsPage::SLUG, 'lang' => $lang ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Back to translation gaps', 'momos-core' ) . '</a></p></div>';
	}

	public static function save_from_page(): void {
		[ $type, $id ] = self::target( $_POST ); // phpcs:ignore -- nonce verified below.
		$lang          = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		check_admin_referer( 'momos_translate_' . $type . $id, '_momos_tr_nonce' );
		if ( ! $id || ! self::exists( $type, $id ) || ! self::can_translate( $type, $id ) ) {
			momos_audit( 'access.denied', 'translation', null, null, [ 'cap' => 'momo_translate', 'channel' => 'admin-post', 'route' => 'momos_save_translation' ] );
			wp_die( esc_html__( 'You do not have permission to translate this item.', 'momos-core' ), 403 );
		}
		self::persist( $type, $id );
		wp_safe_redirect( add_query_arg( 'saved', 1, self::url( $type, $id, $lang ) ) );
		exit;
	}
}
