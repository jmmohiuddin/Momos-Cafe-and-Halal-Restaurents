<?php
declare(strict_types=1);

namespace Momos\Admin\Content;

use Momos\Kernel\I18n\Translations;

/**
 * FAQ items editor (SCR-C13 data, FR-CORP-004; content-strings.md §2.3 `momo_faq_items` [Prop], §8.4).
 *
 * Page meta `momo_faq_items` = JSON list of {id, group, question, answer, order}:
 *   id       slug `^[a-z0-9-]{3,60}$`, unique (URL hash `#id`); generated once when left empty
 *   group    reservations | halal_dietary | allergens | branches_access | payments
 *   question plain text ≤ 200, answer HTML (kses) ≤ 2000, order int.
 * Translations go through the translation workflow as repeater field `faq_items` of `post:page`
 * (stored in `_momos_i18n_{lang}['faq_items']` as [{id, question, answer}]); the theme reads them with
 * Presentation\View\Content::faq() (an item without text in a language is hidden there).
 *
 * The meta box shows on the FAQ system page, pages using the FAQ template and pages that already hold
 * items. Saving needs `edit_post` on the page + nonce; an invalid list is rejected as a whole.
 */
final class FaqEditor {
	public const META    = 'momo_faq_items';
	public const GROUPS  = [ 'reservations', 'halal_dietary', 'allergens', 'branches_access', 'payments' ];
	public const MAX_Q   = 200;
	public const MAX_A   = 2000;
	public const MAX_ROWS = 100;
	private const NONCE  = '_momos_faq_nonce';

	public static function register(): void {
		Translations::register(
			'post:page',
			[
				'faq_items' => [
					'label'      => 'FAQ items',
					'type'       => 'repeater',
					'source'     => 'meta:' . self::META,
					'row_key'    => 'id',
					'sub_fields' => [
						'question' => [ 'label' => 'Question', 'type' => 'text', 'max' => self::MAX_Q ],
						'answer'   => [ 'label' => 'Answer', 'type' => 'html', 'max' => self::MAX_A ],
					],
				],
			]
		);
		add_action( 'add_meta_boxes_page', [ self::class, 'meta_box' ] );
		add_action( 'save_post_page', [ self::class, 'save_from_post_screen' ], 15, 2 );
		add_action( 'admin_notices', [ self::class, 'notices' ] );
	}

	public static function group_label( string $group ): string {
		return match ( $group ) {
			'reservations' => __( 'Reservations', 'momos-core' ),
			'halal_dietary' => __( 'Halal & dietary', 'momos-core' ),
			'allergens' => __( 'Allergens', 'momos-core' ),
			'branches_access' => __( 'Branches & access', 'momos-core' ),
			default => __( 'Payments', 'momos-core' ),
		};
	}

	public static function applies_to( \WP_Post $post ): bool {
		$is = ( class_exists( \Momos\Presentation\SystemPages::class ) && \Momos\Presentation\SystemPages::id( 'faq' ) === (int) $post->ID )
			|| 'page-templates/faq.php' === (string) get_post_meta( $post->ID, '_wp_page_template', true )
			|| [] !== self::items( (int) $post->ID );
		return (bool) apply_filters( 'momos/content/faq_editor', $is, $post );
	}

	/** @return list<array{id:string,group:string,question:string,answer:string,order:int}> */
	public static function items( int $page_id ): array {
		$out = [];
		foreach ( Translations::decode_rows( get_post_meta( $page_id, self::META, true ) ) as $r ) {
			$out[] = [
				'id'       => (string) ( $r['id'] ?? '' ),
				'group'    => (string) ( $r['group'] ?? '' ),
				'question' => (string) ( $r['question'] ?? '' ),
				'answer'   => (string) ( $r['answer'] ?? '' ),
				'order'    => (int) ( $r['order'] ?? 0 ),
			];
		}
		return $out;
	}

	public static function meta_box( \WP_Post $post ): void {
		if ( ! self::applies_to( $post ) ) {
			return;
		}
		add_meta_box( 'momos-faq-items', __( 'FAQ items', 'momos-core' ), [ self::class, 'render' ], 'page', 'normal', 'high' );
	}

	public static function render( \WP_Post $post ): void {
		wp_nonce_field( 'momos_faq_' . $post->ID, self::NONCE );
		$rows = self::items( (int) $post->ID );
		usort( $rows, static fn( $a, $b ) => $a['order'] <=> $b['order'] );
		echo '<p class="description">' . esc_html__( 'Questions and answers in Japanese. Halal and allergen answers link to the halal page or dish pages instead of restating claims. Translations are edited in the Translations box or the translation editor. Leave a row empty to delete it.', 'momos-core' ) . '</p>';
		echo '<input type="hidden" name="momos_faq_present" value="1">';
		echo '<div class="momos-repeater" data-max="' . (int) self::MAX_ROWS . '"><table class="widefat momos-repeater__table"><thead><tr>';
		foreach ( [ __( 'Group', 'momos-core' ), __( 'Question / answer', 'momos-core' ), __( 'Order', 'momos-core' ), __( 'Anchor ID', 'momos-core' ) ] as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'momos-core' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			self::row( (string) $i, $row );
		}
		echo '</tbody></table><template class="momos-repeater__template">';
		self::row( '__i__', [ 'id' => '', 'group' => 'reservations', 'question' => '', 'answer' => '', 'order' => ( count( $rows ) + 1 ) * 10 ] );
		echo '</template><p><button type="button" class="button momos-repeater__add">' . esc_html__( 'Add question', 'momos-core' ) . '</button></p></div>';
	}

	/** @param array{id:string,group:string,question:string,answer:string,order:int} $row */
	private static function row( string $i, array $row ): void {
		$n   = 'momos_faq[' . $i . ']';
		$fid = 'momos-faq-' . $i;
		echo '<tr class="momos-repeater__row"><td><label class="screen-reader-text" for="' . esc_attr( $fid ) . '-g">' . esc_html__( 'Group', 'momos-core' ) . '</label><select id="' . esc_attr( $fid ) . '-g" name="' . esc_attr( $n ) . '[group]">';
		foreach ( self::GROUPS as $g ) {
			echo '<option value="' . esc_attr( $g ) . '"' . selected( $row['group'], $g, false ) . '>' . esc_html( self::group_label( $g ) ) . '</option>';
		}
		echo '</select></td><td><label for="' . esc_attr( $fid ) . '-q">' . esc_html__( 'Question', 'momos-core' ) . '</label><input class="large-text" type="text" lang="ja" maxlength="' . (int) self::MAX_Q . '" id="' . esc_attr( $fid ) . '-q" name="' . esc_attr( $n ) . '[question]" value="' . esc_attr( $row['question'] ) . '">';
		echo '<label for="' . esc_attr( $fid ) . '-a">' . esc_html__( 'Answer', 'momos-core' ) . '</label><textarea class="large-text" rows="4" lang="ja" maxlength="' . (int) self::MAX_A . '" id="' . esc_attr( $fid ) . '-a" name="' . esc_attr( $n ) . '[answer]">' . esc_textarea( $row['answer'] ) . '</textarea></td>';
		echo '<td><label class="screen-reader-text" for="' . esc_attr( $fid ) . '-o">' . esc_html__( 'Order', 'momos-core' ) . '</label><input type="number" step="1" min="0" max="9999" class="small-text" id="' . esc_attr( $fid ) . '-o" name="' . esc_attr( $n ) . '[order]" value="' . (int) $row['order'] . '"></td>';
		echo '<td><label class="screen-reader-text" for="' . esc_attr( $fid ) . '-id">' . esc_html__( 'Anchor ID', 'momos-core' ) . '</label><input type="text" class="regular-text" pattern="[a-z0-9\-]{3,60}" id="' . esc_attr( $fid ) . '-id" name="' . esc_attr( $n ) . '[id]" value="' . esc_attr( $row['id'] ) . '" aria-describedby="' . esc_attr( $fid ) . '-idh"><p class="description" id="' . esc_attr( $fid ) . '-idh">' . esc_html__( 'Lowercase letters, digits and hyphens (3–60). Empty = generated.', 'momos-core' ) . '</p></td>';
		echo '<td><button type="button" class="button-link momos-repeater__remove">' . esc_html__( 'Remove', 'momos-core' ) . '</button></td></tr>';
	}

	/**
	 * Validate and normalise submitted rows. Rows with neither question nor answer are dropped.
	 *
	 * @param array<int|string,mixed>                          $raw
	 * @param list<array<string,mixed>>                         $existing current rows (ids kept stable)
	 * @return array{0:list<array{id:string,group:string,question:string,answer:string,order:int}>,1:list<string>}
	 */
	public static function sanitize( array $raw, array $existing = [] ): array {
		$rows   = [];
		$errors = [];
		$seen   = [];
		$n      = 0;
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$q = trim( sanitize_text_field( (string) ( $r['question'] ?? '' ) ) );
			$a = trim( wp_kses_post( (string) ( $r['answer'] ?? '' ) ) );
			if ( '' === $q && '' === trim( wp_strip_all_tags( $a ) ) ) {
				continue;
			}
			++$n;
			if ( $n > self::MAX_ROWS ) {
				/* translators: %d: maximum number of items */
				$errors[] = sprintf( __( 'At most %d FAQ items are allowed.', 'momos-core' ), self::MAX_ROWS );
				break;
			}
			/* translators: %d: row number */
			$label = sprintf( __( 'FAQ item %d', 'momos-core' ), $n );
			$group = sanitize_key( (string) ( $r['group'] ?? '' ) );
			if ( ! in_array( $group, self::GROUPS, true ) ) {
				$errors[] = $label . ': ' . __( 'choose a group.', 'momos-core' );
			}
			if ( '' === $q || '' === trim( wp_strip_all_tags( $a ) ) ) {
				$errors[] = $label . ': ' . __( 'question and answer are both required.', 'momos-core' );
			}
			if ( mb_strlen( $q ) > self::MAX_Q ) {
				/* translators: %d: max characters */
				$errors[] = $label . ': ' . sprintf( __( 'the question is longer than %d characters.', 'momos-core' ), self::MAX_Q );
			}
			if ( mb_strlen( $a ) > self::MAX_A ) {
				/* translators: %d: max characters */
				$errors[] = $label . ': ' . sprintf( __( 'the answer is longer than %d characters.', 'momos-core' ), self::MAX_A );
			}
			$id = strtolower( trim( (string) ( $r['id'] ?? '' ) ) );
			if ( '' === $id ) {
				$id = self::new_id( $q, array_merge( array_keys( $seen ), array_map( static fn( $e ) => (string) ( $e['id'] ?? '' ), $existing ) ) );
			} elseif ( ! preg_match( '/^[a-z0-9-]{3,60}$/', $id ) ) {
				$errors[] = $label . ': ' . __( 'the anchor ID may only contain lowercase letters, digits and hyphens (3–60).', 'momos-core' );
			}
			if ( isset( $seen[ $id ] ) ) {
				/* translators: %s: anchor id */
				$errors[] = $label . ': ' . sprintf( __( 'the anchor ID “%s” is used twice.', 'momos-core' ), $id );
			}
			$seen[ $id ] = true;
			$order       = isset( $r['order'] ) && is_numeric( $r['order'] ) ? max( 0, min( 9999, (int) $r['order'] ) ) : $n * 10;
			$rows[]      = [ 'id' => $id, 'group' => $group, 'question' => $q, 'answer' => $a, 'order' => $order ];
		}
		usort( $rows, static fn( $x, $y ) => [ $x['order'], $x['id'] ] <=> [ $y['order'], $y['id'] ] );
		return [ $rows, $errors ];
	}

	/** @param list<string> $taken */
	private static function new_id( string $question, array $taken ): string {
		$base = substr( trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $question ) ) ), '-' ), 0, 40 );
		$base = strlen( $base ) >= 3 ? $base : 'faq';
		do {
			$id = $base . '-' . strtolower( \Momos\Kernel\Support\Crockford::random( 4 ) );
		} while ( in_array( $id, $taken, true ) );
		return $id;
	}

	/**
	 * Store a validated list (JSON). Returns errors; nothing is stored when there are errors.
	 *
	 * @param array<int|string,mixed> $raw
	 * @return list<string>
	 */
	public static function save_items( int $page_id, array $raw ): array {
		[ $rows, $errors ] = self::sanitize( $raw, self::items( $page_id ) );
		if ( $errors ) {
			return $errors;
		}
		$json = [] === $rows ? '' : (string) wp_json_encode( $rows, JSON_UNESCAPED_UNICODE );
		if ( $json === (string) get_post_meta( $page_id, self::META, true ) ) {
			return [];
		}
		if ( '' === $json ) {
			delete_post_meta( $page_id, self::META );
		} else {
			update_post_meta( $page_id, self::META, wp_slash( $json ) );
		}
		do_action( 'momos/content/faq_saved', $page_id, count( $rows ) );
		return [];
	}

	public static function save_from_post_screen( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! isset( $_POST['momos_faq_present'], $_POST[ self::NONCE ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) ), 'momos_faq_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			momos_audit( 'access.denied', 'page', $post_id, null, [ 'cap' => 'edit_post', 'channel' => 'admin', 'field' => self::META ] );
			return;
		}
		$raw    = isset( $_POST['momos_faq'] ) && is_array( $_POST['momos_faq'] ) ? wp_unslash( $_POST['momos_faq'] ) : []; // phpcs:ignore -- sanitised in sanitize().
		$errors = self::save_items( $post_id, $raw );
		if ( $errors ) {
			array_unshift( $errors, __( 'FAQ items were not saved:', 'momos-core' ) );
			set_transient( 'momos_faq_err_' . get_current_user_id(), $errors, 120 );
		}
	}

	public static function notices(): void {
		$key  = 'momos_faq_err_' . get_current_user_id();
		$msgs = get_transient( $key );
		if ( ! is_array( $msgs ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-error" role="alert">';
		foreach ( $msgs as $m ) {
			echo '<p>' . esc_html( (string) $m ) . '</p>';
		}
		echo '</div>';
	}
}
