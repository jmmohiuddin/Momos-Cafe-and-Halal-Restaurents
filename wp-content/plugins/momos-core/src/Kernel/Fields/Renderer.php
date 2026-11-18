<?php
declare(strict_types=1);

namespace Momos\Kernel\Fields;

/**
 * Renders field groups in admin forms. All output is escaped. Fields the current user may not
 * edit are shown read-only (TRD §2.5 "other users see the field read-only").
 */
final class Renderer {
	/**
	 * @param array<string,mixed> $group
	 * @param array<string,mixed> $values
	 */
	public static function group( array $group, array $values, int|string $object_id, string $context, ?\WP_Post $post = null ): void {
		if ( ! empty( $group['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $group['description'] ) . '</p>';
		}
		$table = 'term' !== $context;
		echo $table ? '<table class="form-table momos-fields" role="presentation"><tbody>' : '<div class="momos-fields">';
		foreach ( $group['fields'] as $f ) {
			if ( ! empty( $f['view_cap'] ) && ! current_user_can( $f['view_cap'] ) ) {
				continue;
			}
			$value    = $values[ $f['name'] ] ?? ( $f['default'] ?? null );
			$editable = FieldRegistry::can_edit( $f, $post ? $post->ID : 0 );
			$cond     = empty( $f['show_if'] ) ? '' : ' data-show-if="' . esc_attr( (string) wp_json_encode( $f['show_if'] ) ) . '"';
			$id       = 'momos-f-' . sanitize_html_class( $f['name'] );
			if ( $table ) {
				echo '<tr class="momos-field momos-field--' . esc_attr( $f['type'] ) . '"' . $cond . '><th scope="row">'; // phpcs:ignore
				if ( 'message' !== $f['type'] ) {
					echo '<label for="' . esc_attr( $id ) . '">' . esc_html( (string) $f['label'] ) . ( ! empty( $f['required'] ) ? ' <span class="momos-req" aria-hidden="true">*</span>' : '' ) . '</label>';
				}
				echo '</th><td>';
			} else {
				echo '<div class="form-field momos-field"' . $cond . '><label for="' . esc_attr( $id ) . '">' . esc_html( (string) $f['label'] ) . '</label>'; // phpcs:ignore
			}
			if ( 'message' !== $f['type'] ) {
				echo '<input type="hidden" name="_momos_rendered[]" value="' . esc_attr( $f['name'] ) . '">';
			}
			if ( $editable ) {
				self::input( $f, $value, 'momos_fields[' . $f['name'] . ']', $id );
			} else {
				self::readonly( $f, $value );
			}
			if ( ! empty( $f['help'] ) ) {
				echo '<p class="description" id="' . esc_attr( $id ) . '-help">' . esc_html( (string) $f['help'] ) . '</p>';
			}
			echo $table ? '</td></tr>' : '</div>';
		}
		echo $table ? '</tbody></table>' : '</div>';
	}

	/** @param array<string,mixed> $f */
	public static function input( array $f, mixed $value, string $name, string $id ): void {
		$req      = ! empty( $f['required'] ) && true === $f['required'] ? ' required' : '';
		$describe = ! empty( $f['help'] ) ? ' aria-describedby="' . esc_attr( $id ) . '-help"' : '';
		$common   = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req . $describe;
		switch ( $f['type'] ) {
			case 'text':
			case 'email':
			case 'url':
			case 'tel':
			case 'date':
			case 'time':
				$type = [ 'text' => 'text', 'email' => 'email', 'url' => 'url', 'tel' => 'tel', 'date' => 'date', 'time' => 'time' ][ $f['type'] ];
				$attrs = isset( $f['maxlength'] ) ? ' maxlength="' . (int) $f['maxlength'] . '"' : '';
				$attrs .= isset( $f['pattern'] ) && 'text' === $type ? ' pattern="' . esc_attr( (string) $f['pattern'] ) . '"' : '';
				$attrs .= isset( $f['placeholder'] ) ? ' placeholder="' . esc_attr( (string) $f['placeholder'] ) . '"' : '';
				echo '<input type="' . esc_attr( $type ) . '" class="regular-text"' . $common . $attrs . ' value="' . esc_attr( (string) $value ) . '">'; // phpcs:ignore
				break;
			case 'datetime':
				$v = str_replace( ' ', 'T', (string) $value );
				echo '<input type="datetime-local"' . $common . ' value="' . esc_attr( $v ) . '">'; // phpcs:ignore
				echo ' <span class="description">JST</span>';
				break;
			case 'hidden':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
				break;
			case 'number':
				$attrs  = isset( $f['min'] ) ? ' min="' . esc_attr( (string) $f['min'] ) . '"' : '';
				$attrs .= isset( $f['max'] ) ? ' max="' . esc_attr( (string) $f['max'] ) . '"' : '';
				$attrs .= ' step="' . esc_attr( (string) ( $f['step'] ?? 1 ) ) . '"';
				echo '<input type="number" class="small-text"' . $common . $attrs . ' value="' . esc_attr( (string) $value ) . '">'; // phpcs:ignore
				break;
			case 'textarea':
				$attrs = isset( $f['maxlength'] ) ? ' maxlength="' . (int) $f['maxlength'] . '"' : '';
				echo '<textarea class="large-text" rows="' . (int) ( $f['rows'] ?? 4 ) . '"' . $common . $attrs . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore
				break;
			case 'wysiwyg':
				wp_editor( (string) $value, $id, [ 'textarea_name' => $name, 'textarea_rows' => 8, 'media_buttons' => false ] );
				break;
			case 'true_false':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
				echo '<label><input type="checkbox" value="1"' . $common . checked( '1', (string) $value, false ) . '> ' . esc_html( (string) ( $f['message'] ?? __( 'Yes', 'momos-core' ) ) ) . '</label>'; // phpcs:ignore
				break;
			case 'select':
				$multi = ! empty( $f['multiple'] );
				echo '<select' . ( $multi ? ' multiple' : '' ) . ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name . ( $multi ? '[]' : '' ) ) . '"' . $req . $describe . '>'; // phpcs:ignore
				if ( ! $multi && empty( $f['no_empty'] ) ) {
					echo '<option value="">' . esc_html__( '— Select —', 'momos-core' ) . '</option>';
				}
				foreach ( (array) ( $f['choices'] ?? [] ) as $k => $label ) {
					$sel = $multi ? in_array( (string) $k, array_map( 'strval', (array) $value ), true ) : (string) $k === (string) $value;
					echo '<option value="' . esc_attr( (string) $k ) . '"' . selected( $sel, true, false ) . '>' . esc_html( (string) $label ) . '</option>';
				}
				echo '</select>';
				break;
			case 'radio':
			case 'checkbox':
				$is_cb = 'checkbox' === $f['type'];
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( (string) $f['label'] ) . '</legend>';
				if ( $is_cb ) {
					echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="">';
				}
				foreach ( (array) ( $f['choices'] ?? [] ) as $k => $label ) {
					$checked = $is_cb ? in_array( (string) $k, array_map( 'strval', (array) $value ), true ) : (string) $k === (string) $value;
					echo '<label class="momos-choice"><input type="' . ( $is_cb ? 'checkbox' : 'radio' ) . '" name="' . esc_attr( $name . ( $is_cb ? '[]' : '' ) ) . '" value="' . esc_attr( (string) $k ) . '"' . checked( $checked, true, false ) . '> ' . esc_html( (string) $label ) . '</label> ';
				}
				echo '</fieldset>';
				break;
			case 'image':
				$img = $value ? wp_get_attachment_image( (int) $value, 'thumbnail' ) : '';
				echo '<div class="momos-media" data-multiple="0"><input type="hidden" class="momos-media__value"' . $common . ' value="' . esc_attr( (string) $value ) . '"><div class="momos-media__preview">' . $img . '</div>'; // phpcs:ignore
				echo '<button type="button" class="button momos-media__choose">' . esc_html__( 'Choose image', 'momos-core' ) . '</button> <button type="button" class="button-link momos-media__clear">' . esc_html__( 'Remove', 'momos-core' ) . '</button></div>';
				break;
			case 'gallery':
				$ids  = array_map( 'intval', (array) $value );
				$imgs = '';
				foreach ( $ids as $aid ) {
					$imgs .= wp_get_attachment_image( $aid, 'thumbnail' );
				}
				echo '<div class="momos-media" data-multiple="1"><input type="hidden" class="momos-media__value"' . $common . ' value="' . esc_attr( implode( ',', $ids ) ) . '"><div class="momos-media__preview">' . $imgs . '</div>'; // phpcs:ignore
				echo '<button type="button" class="button momos-media__choose">' . esc_html__( 'Choose images', 'momos-core' ) . '</button> <button type="button" class="button-link momos-media__clear">' . esc_html__( 'Remove all', 'momos-core' ) . '</button></div>';
				break;
			case 'post_object':
			case 'relationship':
				$multi = 'relationship' === $f['type'] || ! empty( $f['multiple'] );
				$posts = get_posts(
					[
						'post_type'      => $f['post_type'] ?? 'page',
						'post_status'    => $f['post_status'] ?? [ 'publish', 'draft', 'pending', 'private' ],
						'posts_per_page' => 500,
						'orderby'        => 'title',
						'order'          => 'ASC',
						'meta_query'     => $f['meta_query'] ?? [], // phpcs:ignore
					]
				);
				echo '<select' . ( $multi ? ' multiple size="6"' : '' ) . ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name . ( $multi ? '[]' : '' ) ) . '"' . $describe . '>'; // phpcs:ignore
				if ( ! $multi ) {
					echo '<option value="">' . esc_html__( '— None —', 'momos-core' ) . '</option>';
				}
				foreach ( $posts as $p ) {
					$sel = in_array( (string) $p->ID, array_map( 'strval', (array) $value ), true );
					echo '<option value="' . (int) $p->ID . '"' . selected( $sel, true, false ) . '>' . esc_html( $p->post_title . ( 'publish' !== $p->post_status ? ' (' . $p->post_status . ')' : '' ) ) . '</option>';
				}
				echo '</select>';
				break;
			case 'taxonomy':
				$multi = ! empty( $f['multiple'] );
				$terms = get_terms( [ 'taxonomy' => (string) $f['taxonomy'], 'hide_empty' => false ] );
				$terms = is_array( $terms ) ? $terms : [];
				$terms = (array) apply_filters( 'momos/fields/taxonomy_choices', $terms, $f );
				echo '<select' . ( $multi ? ' multiple size="6"' : '' ) . ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name . ( $multi ? '[]' : '' ) ) . '"' . $describe . '>'; // phpcs:ignore
				if ( ! $multi ) {
					echo '<option value="">' . esc_html__( '— None —', 'momos-core' ) . '</option>';
				}
				foreach ( $terms as $t ) {
					$sel = in_array( (string) $t->term_id, array_map( 'strval', (array) $value ), true );
					echo '<option value="' . (int) $t->term_id . '"' . selected( $sel, true, false ) . '>' . esc_html( $t->name ) . '</option>';
				}
				echo '</select>';
				break;
			case 'repeater':
				self::repeater( $f, is_array( $value ) ? $value : [], $name, $id );
				break;
			case 'message':
				echo '<div class="momos-message">' . wp_kses_post( (string) ( $f['message'] ?? '' ) ) . '</div>';
				break;
			default:
				self::readonly( $f, $value );
		}
	}

	/**
	 * @param array<string,mixed>      $f
	 * @param list<array<string,mixed>> $rows
	 */
	private static function repeater( array $f, array $rows, string $name, string $id ): void {
		echo '<div class="momos-repeater" id="' . esc_attr( $id ) . '" data-max="' . (int) ( $f['max_rows'] ?? 50 ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[__index__]" value="">';
		echo '<table class="widefat momos-repeater__table"><thead><tr>';
		foreach ( $f['sub_fields'] as $sf ) {
			echo '<th scope="col">' . esc_html( (string) $sf['label'] ) . '</th>';
		}
		echo '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'momos-core' ) . '</span></th></tr></thead><tbody>';
		foreach ( array_values( $rows ) as $i => $row ) {
			self::repeater_row( $f, $row, $name, (string) $i );
		}
		echo '</tbody></table>';
		echo '<template class="momos-repeater__template">';
		self::repeater_row( $f, [], $name, '__i__' );
		echo '</template>';
		echo '<p><button type="button" class="button momos-repeater__add">' . esc_html( (string) ( $f['button_label'] ?? __( 'Add row', 'momos-core' ) ) ) . '</button></p></div>';
	}

	/**
	 * @param array<string,mixed> $f
	 * @param array<string,mixed> $row
	 */
	private static function repeater_row( array $f, array $row, string $name, string $index ): void {
		echo '<tr class="momos-repeater__row">';
		foreach ( $f['sub_fields'] as $sf ) {
			echo '<td>';
			$sid = 'momos-f-' . sanitize_html_class( $f['name'] . '-' . $index . '-' . $sf['name'] );
			echo '<label class="screen-reader-text" for="' . esc_attr( $sid ) . '">' . esc_html( (string) $sf['label'] ) . '</label>';
			self::input( $sf, $row[ $sf['name'] ] ?? ( $sf['default'] ?? null ), $name . '[' . $index . '][' . $sf['name'] . ']', $sid );
			echo '</td>';
		}
		echo '<td><button type="button" class="button-link momos-repeater__remove">' . esc_html__( 'Remove', 'momos-core' ) . '</button></td></tr>';
	}

	/** @param array<string,mixed> $f */
	public static function readonly( array $f, mixed $value ): void {
		echo '<div class="momos-readonly">' . esc_html( self::display( $f, $value ) ) . '</div>';
	}

	/** Human-readable value. @param array<string,mixed> $f */
	public static function display( array $f, mixed $value ): string {
		if ( FieldRegistry::is_empty( $value ) ) {
			return '—';
		}
		switch ( $f['type'] ) {
			case 'true_false':
				return '1' === (string) $value ? __( 'Yes', 'momos-core' ) : __( 'No', 'momos-core' );
			case 'select':
			case 'radio':
			case 'checkbox':
				$labels = [];
				foreach ( (array) $value as $v ) {
					$labels[] = (string) ( $f['choices'][ $v ] ?? $v );
				}
				return implode( ', ', $labels );
			case 'user':
				$u = get_userdata( (int) $value );
				return $u ? $u->display_name : '—';
			case 'post_object':
			case 'relationship':
				return implode( ', ', array_map( static fn( $id ) => get_the_title( (int) $id ), (array) $value ) );
			case 'taxonomy':
				return implode( ', ', array_map( static function ( $id ) {
					$t = get_term( (int) $id );
					return $t instanceof \WP_Term ? $t->name : '';
				}, (array) $value ) );
			case 'repeater':
				/* translators: %d: number of rows */
				return sprintf( _n( '%d row', '%d rows', count( (array) $value ), 'momos-core' ), count( (array) $value ) );
			case 'image':
			case 'gallery':
				return implode( ', ', array_map( 'strval', (array) $value ) );
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}
}
