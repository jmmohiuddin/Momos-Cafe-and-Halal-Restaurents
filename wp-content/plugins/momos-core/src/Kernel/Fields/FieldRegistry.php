<?php
declare(strict_types=1);

namespace Momos\Kernel\Fields;

/**
 * Code-registered field groups (native replacement for ACF Pro, IMPL-001).
 *
 * A group targets one of:
 *   ['post_type' => 'momo_branch']   → meta box on the edit screen, values in post meta
 *   ['taxonomy'  => 'momo_allergen'] → term add/edit form, values in term meta
 *   ['options_page' => 'momo-brand'] → options page, values in option momos_opt_{page}
 *
 * Field definition keys (all optional except name/type):
 *   name, label, type, help, default, required (true|'publish'), edit_cap, view_cap, readonly,
 *   choices, multiple, min, max, step, maxlength, pattern, pattern_message, post_type, post_status,
 *   taxonomy, save_terms, sub_fields, max_rows, min_rows, immutable_after_publish, validate
 *   (callable(mixed $value, int|string $object_id, array $all_values): ?string error),
 *   translatable (bool) + translate_type (text|textarea|html) + lccs (bool), show_if [field => value],
 *   column (bool: show in admin list), stamp ('user'|'datetime' read-only system values)
 *
 * Supported types: text, textarea, wysiwyg, number, email, url, tel, true_false, select, radio,
 * checkbox, date, time, datetime, image, gallery, post_object, relationship, taxonomy, user,
 * repeater, message, hidden.
 *
 * Every change is reported with `momos/fields/saved` (object kind, id, group key, changes, user) so
 * the Audit module records before/after values; rejected changes fire `momos/fields/denied`.
 */
final class FieldRegistry {
	/** @var array<string, array<string,mixed>> group key => group */
	private static array $groups = [];

	/** @var array<string,string> option field name → page */
	private static array $option_names = [];

	private static bool $booted = false;

	/** @param array<string,mixed> $group */
	public static function group( array $group ): void {
		if ( empty( $group['key'] ) || empty( $group['fields'] ) || ! is_array( $group['fields'] ) ) {
			throw new \InvalidArgumentException( 'Field group needs key and fields' );
		}
		$group['title']                = $group['title'] ?? $group['key'];
		$group['context']              = $group['context'] ?? 'normal';
		$group['priority']             = $group['priority'] ?? 'high';
		$group['order']                = $group['order'] ?? 10;
		$group['fields']               = array_map( [ self::class, 'normalize_field' ], $group['fields'] );
		self::$groups[ $group['key'] ] = $group;

		if ( isset( $group['options_page'] ) ) {
			foreach ( $group['fields'] as $f ) {
				self::$option_names[ $f['name'] ] = (string) $group['options_page'];
			}
		}
		// Translatable fields join the translation registry.
		$kind = self::kind_of_group( $group );
		$tr   = [];
		foreach ( $group['fields'] as $f ) {
			if ( ! empty( $f['translatable'] ) ) {
				$tr[ $f['name'] ] = [
					'label'  => (string) $f['label'],
					'type'   => (string) ( $f['translate_type'] ?? ( in_array( $f['type'], [ 'textarea' ], true ) ? 'textarea' : ( 'wysiwyg' === $f['type'] ? 'html' : 'text' ) ) ),
					'source' => isset( $group['options_page'] ) ? 'option:' . $f['name'] : 'meta:' . $f['name'],
					'lccs'   => ! empty( $f['lccs'] ),
				] + ( isset( $f['maxlength'] ) ? [ 'max' => (int) $f['maxlength'] ] : [] );
			}
		}
		if ( $tr ) {
			\Momos\Kernel\I18n\Translations::register( $kind, $tr );
		}
	}

	/**
	 * @param array<string,mixed> $f
	 * @return array<string,mixed>
	 */
	private static function normalize_field( array $f ): array {
		if ( empty( $f['name'] ) || empty( $f['type'] ) ) {
			throw new \InvalidArgumentException( 'Field needs name and type' );
		}
		$f['label'] = $f['label'] ?? $f['name'];
		if ( 'repeater' === $f['type'] ) {
			$f['sub_fields'] = array_map( [ self::class, 'normalize_field' ], (array) ( $f['sub_fields'] ?? [] ) );
		}
		if ( ! empty( $f['stamp'] ) ) {
			$f['readonly'] = true;
		}
		return $f;
	}

	/** @param array<string,mixed> $group */
	public static function kind_of_group( array $group ): string {
		if ( isset( $group['post_type'] ) ) {
			return 'post:' . $group['post_type'];
		}
		if ( isset( $group['taxonomy'] ) ) {
			return 'term:' . $group['taxonomy'];
		}
		return 'option:' . ( $group['options_page'] ?? '' );
	}

	/** @return list<array<string,mixed>> */
	public static function groups_for( string $kind ): array {
		$out = array_values( array_filter( self::$groups, static fn( $g ) => self::kind_of_group( $g ) === $kind ) );
		usort( $out, static fn( $a, $b ) => $a['order'] <=> $b['order'] );
		return $out;
	}

	/** @return array<string,mixed>|null */
	public static function get_group( string $key ): ?array {
		return self::$groups[ $key ] ?? null;
	}

	/** @return array<string,mixed>|null */
	public static function field_def( string $kind, string $name ): ?array {
		foreach ( self::groups_for( $kind ) as $g ) {
			foreach ( $g['fields'] as $f ) {
				if ( $f['name'] === $name ) {
					return $f;
				}
			}
		}
		return null;
	}

	public static function option_page_of( string $name ): ?string {
		return self::$option_names[ $name ] ?? null;
	}

	/** @return list<string> */
	public static function option_pages(): array {
		return array_values( array_unique( self::$option_names ) );
	}

	// ================================================================= hooks

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'add_meta_boxes', [ self::class, 'add_meta_boxes' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ self::class, 'validate_post_before_save' ], 99, 2 );
		add_action( 'save_post', [ self::class, 'save_post' ], 10, 2 );
		add_action( 'admin_notices', [ self::class, 'admin_notices' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		add_action( 'init', [ self::class, 'register_term_hooks' ], 99 );
	}

	public static function enqueue( string $hook ): void {
		wp_enqueue_media();
		wp_enqueue_style( 'momos-admin', MOMOS_CORE_URL . '/assets/css/admin.css', [], MOMOS_CORE_VERSION );
		wp_enqueue_script( 'momos-fields', MOMOS_CORE_URL . '/assets/js/admin-fields.js', [ 'jquery' ], MOMOS_CORE_VERSION, true );
		wp_localize_script(
			'momos-fields',
			'momosFields',
			[
				'chooseImage' => __( 'Choose image', 'momos-core' ),
				'remove'      => __( 'Remove', 'momos-core' ),
			]
		);
	}

	// ----------------------------------------------------------------- posts

	public static function add_meta_boxes( string $post_type, $post ): void {
		foreach ( self::groups_for( 'post:' . $post_type ) as $group ) {
			if ( ! empty( $group['view_cap'] ) && ! current_user_can( $group['view_cap'] ) ) {
				continue;
			}
			add_meta_box(
				'momos-' . $group['key'],
				(string) $group['title'],
				static function ( \WP_Post $p ) use ( $group ): void {
					wp_nonce_field( 'momos_fields_' . $group['key'], '_momos_nonce_' . $group['key'] );
					$values = self::post_values( $p->ID, $group );
					Renderer::group( $group, $values, $p->ID, 'post', $p );
				},
				$post_type,
				(string) $group['context'],
				(string) $group['priority']
			);
		}
	}

	/**
	 * @param array<string,mixed> $group
	 * @return array<string,mixed>
	 */
	public static function post_values( int $post_id, array $group ): array {
		$values = [];
		foreach ( $group['fields'] as $f ) {
			if ( 'message' === $f['type'] ) {
				continue;
			}
			$exists               = metadata_exists( 'post', $post_id, $f['name'] );
			$values[ $f['name'] ] = $exists ? get_post_meta( $post_id, $f['name'], true ) : ( $f['default'] ?? null );
		}
		return $values;
	}

	/** Read a single field value with its default. */
	public static function get_post_field( int $post_id, string $name ): mixed {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		if ( metadata_exists( 'post', $post_id, $name ) ) {
			return get_post_meta( $post_id, $name, true );
		}
		$def = self::field_def( 'post:' . $post->post_type, $name );
		return $def['default'] ?? null;
	}

	/**
	 * Validate submitted fields before WordPress writes the post. If publish requirements fail,
	 * a new publication is downgraded to draft; already-published content stays published and
	 * the invalid changes are rejected in save_post.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function validate_post_before_save( array $data, array $postarr ): array {
		$post_type = (string) ( $data['post_type'] ?? '' );
		$groups    = self::groups_for( 'post:' . $post_type );
		if ( ! $groups || ! in_array( $data['post_status'] ?? '', [ 'publish', 'future' ], true ) ) {
			return $data;
		}
		$post_id   = (int) ( $postarr['ID'] ?? 0 );
		$old       = $post_id ? get_post( $post_id ) : null;
		$was_pub   = $old && 'publish' === $old->post_status;
		$submitted = self::submitted_fields();
		$errors    = [];
		$merged    = [];
		foreach ( $groups as $g ) {
			$current = $post_id ? self::post_values( $post_id, $g ) : [];
			foreach ( $g['fields'] as $f ) {
				$name            = $f['name'];
				$val             = ( null !== $submitted && array_key_exists( $name, $submitted ) && self::can_edit( $f, $post_id ) )
					? Sanitizer::sanitize( $f, $submitted[ $name ] )
					: ( $current[ $name ] ?? ( $f['default'] ?? null ) );
				$merged[ $name ] = $val;
			}
		}
		foreach ( $groups as $g ) {
			foreach ( $g['fields'] as $f ) {
				if ( ! empty( $f['required'] ) && self::is_empty( $merged[ $f['name'] ] ?? null ) && self::visible_by_condition( $f, $merged ) ) {
					/* translators: %s: field label */
					$errors[] = sprintf( __( '"%s" is required to publish.', 'momos-core' ), wp_strip_all_tags( (string) $f['label'] ) );
				}
			}
		}
		if ( '' === trim( (string) ( $data['post_title'] ?? '' ) ) && post_type_supports( $post_type, 'title' ) ) {
			$errors[] = __( 'A title is required to publish.', 'momos-core' );
		}
		/**
		 * Module-specific publish validators (BranchPublishValidator, DishPublishValidator…).
		 *
		 * @param list<string>          $errors
		 * @param array<string,mixed>   $merged field values after this save
		 * @param array<string,mixed>   $data   post data
		 * @param int                   $post_id
		 */
		$errors = (array) apply_filters( 'momos/fields/publish_errors/' . $post_type, $errors, $merged, $data, $post_id );
		if ( $errors ) {
			self::add_errors( $errors );
			if ( ! $was_pub ) {
				$data['post_status'] = 'draft';
				self::add_errors( [ __( 'The item was saved as a draft and not published.', 'momos-core' ) ] );
			} else {
				$GLOBALS['momos_fields_reject_invalid'] = true;
			}
		}
		return $data;
	}

	public static function save_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$submitted = self::submitted_fields();
		if ( null === $submitted ) {
			return;
		}
		foreach ( self::groups_for( 'post:' . $post->post_type ) as $group ) {
			$nonce = isset( $_POST[ '_momos_nonce_' . $group['key'] ] ) ? sanitize_text_field( wp_unslash( $_POST[ '_momos_nonce_' . $group['key'] ] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'momos_fields_' . $group['key'] ) ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			self::save_group_values( 'post', $post_id, $group, $submitted, $post );
		}
		unset( $GLOBALS['momos_fields_reject_invalid'] );
		if ( 'publish' === $post->post_status ) {
			update_post_meta( $post_id, '_momos_was_published', 1 );
		}
	}

	/**
	 * Shared save routine for posts/terms/options.
	 *
	 * @param array<string,mixed> $group
	 * @param array<string,mixed> $submitted
	 */
	public static function save_group_values( string $type, int|string $object_id, array $group, array $submitted, ?\WP_Post $post = null ): void {
		$current = match ( $type ) {
			'post' => self::post_values( (int) $object_id, $group ),
			'term' => self::term_values( (int) $object_id, $group ),
			default => \Momos\Kernel\Settings::page( (string) $object_id ),
		};
		$changes = [];
		$errors  = [];
		$denied  = [];
		$new_all = $current;
		foreach ( $group['fields'] as $f ) {
			$name = $f['name'];
			if ( in_array( $f['type'], [ 'message' ], true ) || ! empty( $f['readonly'] ) ) {
				continue;
			}
			if ( ! array_key_exists( $name, $submitted ) && ! in_array( $f['type'], [ 'true_false', 'checkbox', 'gallery', 'relationship', 'repeater', 'taxonomy' ], true ) ) {
				continue;
			}
			$raw = $submitted[ $name ] ?? null;
			if ( ! array_key_exists( $name, $submitted ) && ! self::field_was_rendered( $name ) ) {
				continue; // Not part of the submitted form (e.g. hidden for this role).
			}
			$new = Sanitizer::sanitize( $f, $raw );
			$old = $current[ $name ] ?? null;
			if ( self::same( $old, $new ) ) {
				continue;
			}
			if ( ! self::can_edit( $f, $type === 'post' ? (int) $object_id : 0 ) ) {
				$denied[] = $name;
				continue;
			}
			if ( 'post' === $type && ! empty( $f['immutable_after_publish'] ) && $post && ! self::is_empty( $old ) && get_post_meta( (int) $object_id, '_momos_was_published', true ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '"%s" cannot be changed after the first publication.', 'momos-core' ), wp_strip_all_tags( (string) $f['label'] ) );
				continue;
			}
			$err = Sanitizer::validate( $f, $new );
			if ( null === $err && isset( $f['validate'] ) && is_callable( $f['validate'] ) ) {
				$err = call_user_func( $f['validate'], $new, $object_id, array_merge( $new_all, [ $name => $new ] ) );
			}
			if ( null === $err && ! empty( $f['required'] ) && true === $f['required'] && self::is_empty( $new ) ) {
				/* translators: %s: field label */
				$err = sprintf( __( '"%s" is required.', 'momos-core' ), wp_strip_all_tags( (string) $f['label'] ) );
			}
			if ( null === $err && 'publish' === ( $f['required'] ?? null ) && ! empty( $GLOBALS['momos_fields_reject_invalid'] ) && self::is_empty( $new ) ) {
				/* translators: %s: field label */
				$err = sprintf( __( '"%s" is required while published.', 'momos-core' ), wp_strip_all_tags( (string) $f['label'] ) );
			}
			if ( null !== $err ) {
				$errors[] = $err;
				continue;
			}
			$new_all[ $name ] = $new;
			$changes[ $name ] = [ $old, $new ];
		}

		/**
		 * Cross-field validation for a group. Return error strings to reject the whole change set.
		 *
		 * @param list<string>        $errors
		 * @param array<string,mixed> $new_all values after the change
		 * @param array<string,mixed> $changes
		 * @param int|string          $object_id post/term ID, or the options-page slug
		 */
		$group_errors = (array) apply_filters( 'momos/fields/validate_group/' . $group['key'], [], $new_all, $changes, $object_id );
		if ( $group_errors ) {
			self::add_errors( $group_errors );
			$changes = [];
		}
		if ( $errors ) {
			self::add_errors( $errors );
		}
		if ( $denied ) {
			do_action( 'momos/fields/denied', $type, $object_id, $group['key'], $denied, get_current_user_id() );
		}
		if ( ! $changes ) {
			return;
		}
		if ( 'option' === $type ) {
			$page_values = \Momos\Kernel\Settings::page( (string) $object_id );
			foreach ( $changes as $name => [ , $new ] ) {
				$page_values[ $name ] = $new;
			}
			\Momos\Kernel\Settings::save_page( (string) $object_id, $page_values );
		} else {
			foreach ( $changes as $name => [ , $new ] ) {
				$f = self::field_from_group( $group, $name );
				if ( 'post' === $type ) {
					update_post_meta( (int) $object_id, $name, is_string( $new ) ? wp_slash( $new ) : $new );
				} else {
					update_term_meta( (int) $object_id, $name, is_string( $new ) ? wp_slash( $new ) : $new );
				}
				if ( $f && 'taxonomy' === $f['type'] && ! empty( $f['save_terms'] ) && 'post' === $type ) {
					wp_set_object_terms( (int) $object_id, array_map( 'intval', (array) $new ), (string) $f['taxonomy'] );
				}
				if ( $f && 'post_object' === $f['type'] && ! empty( $f['writes_parent'] ) && 'post' === $type ) {
					wp_update_post( [ 'ID' => (int) $object_id, 'post_parent' => (int) $new ] );
				}
			}
		}
		if ( isset( $group['on_save'] ) && is_callable( $group['on_save'] ) ) {
			call_user_func( $group['on_save'], $object_id, $new_all, $changes );
		}
		do_action( 'momos/fields/saved', $type, $object_id, $group['key'], $changes, get_current_user_id() );
	}

	/** @param array<string,mixed> $group */
	private static function field_from_group( array $group, string $name ): ?array {
		foreach ( $group['fields'] as $f ) {
			if ( $f['name'] === $name ) {
				return $f;
			}
		}
		return null;
	}

	private static function field_was_rendered( string $name ): bool {
		$rendered = isset( $_POST['_momos_rendered'] ) ? (array) wp_unslash( $_POST['_momos_rendered'] ) : []; // phpcs:ignore
		return in_array( $name, array_map( 'strval', $rendered ), true );
	}

	/** @return array<string,mixed>|null */
	private static function submitted_fields(): ?array {
		if ( ! isset( $_POST['momos_fields'] ) || ! is_array( $_POST['momos_fields'] ) ) { // phpcs:ignore
			return isset( $_POST['_momos_rendered'] ) ? [] : null; // phpcs:ignore
		}
		return wp_unslash( $_POST['momos_fields'] ); // phpcs:ignore -- sanitised per field by Sanitizer.
	}

	/** @param array<string,mixed> $f */
	public static function can_edit( array $f, int $post_id = 0 ): bool {
		if ( ! empty( $f['readonly'] ) ) {
			return false;
		}
		if ( ! empty( $f['edit_cap'] ) ) {
			$caps = (array) $f['edit_cap'];
			foreach ( $caps as $cap ) {
				if ( ! current_user_can( $cap, ...( $post_id && in_array( $cap, [ 'edit_post' ], true ) ? [ $post_id ] : [] ) ) ) {
					return false;
				}
			}
		}
		return (bool) apply_filters( 'momos/fields/can_edit', true, $f, $post_id );
	}

	/**
	 * @param array<string,mixed> $f
	 * @param array<string,mixed> $values
	 */
	public static function visible_by_condition( array $f, array $values ): bool {
		if ( empty( $f['show_if'] ) ) {
			return true;
		}
		foreach ( (array) $f['show_if'] as $field => $expected ) {
			$actual = $values[ $field ] ?? null;
			if ( is_array( $expected ) ? ! in_array( (string) $actual, array_map( 'strval', $expected ), true ) : (string) $actual !== (string) $expected ) {
				return false;
			}
		}
		return true;
	}

	public static function is_empty( mixed $v ): bool {
		return null === $v || '' === $v || ( is_array( $v ) && [] === array_filter( $v, static fn( $x ) => null !== $x && '' !== $x ) );
	}

	private static function same( mixed $a, mixed $b ): bool {
		if ( is_array( $a ) || is_array( $b ) ) {
			return wp_json_encode( $a ?? [] ) === wp_json_encode( $b ?? [] );
		}
		return (string) $a === (string) $b;
	}

	// ----------------------------------------------------------------- terms

	public static function register_term_hooks(): void {
		$taxes = [];
		foreach ( self::$groups as $g ) {
			if ( isset( $g['taxonomy'] ) ) {
				$taxes[ $g['taxonomy'] ] = true;
			}
		}
		foreach ( array_keys( $taxes ) as $tax ) {
			add_action(
				$tax . '_add_form_fields',
				static function () use ( $tax ): void {
					foreach ( self::groups_for( 'term:' . $tax ) as $g ) {
						wp_nonce_field( 'momos_fields_' . $g['key'], '_momos_nonce_' . $g['key'] );
						Renderer::group( $g, [], 0, 'term_add' );
					}
				}
			);
			add_action(
				$tax . '_edit_form_fields',
				static function ( \WP_Term $term ) use ( $tax ): void {
					foreach ( self::groups_for( 'term:' . $tax ) as $g ) {
						echo '<tr class="form-field"><th scope="row">' . esc_html( (string) $g['title'] ) . '</th><td>';
						wp_nonce_field( 'momos_fields_' . $g['key'], '_momos_nonce_' . $g['key'] );
						Renderer::group( $g, self::term_values( $term->term_id, $g ), $term->term_id, 'term' );
						echo '</td></tr>';
					}
				}
			);
			$save = static function ( int $term_id ) use ( $tax ): void {
				$submitted = self::submitted_fields();
				if ( null === $submitted ) {
					return;
				}
				foreach ( self::groups_for( 'term:' . $tax ) as $g ) {
					$nonce = isset( $_POST[ '_momos_nonce_' . $g['key'] ] ) ? sanitize_text_field( wp_unslash( $_POST[ '_momos_nonce_' . $g['key'] ] ) ) : '';
					if ( wp_verify_nonce( $nonce, 'momos_fields_' . $g['key'] ) && current_user_can( 'edit_term', $term_id ) ) {
						self::save_group_values( 'term', $term_id, $g, $submitted );
					}
				}
			};
			add_action( 'created_' . $tax, $save );
			add_action( 'edited_' . $tax, $save );
		}
	}

	/**
	 * @param array<string,mixed> $group
	 * @return array<string,mixed>
	 */
	public static function term_values( int $term_id, array $group ): array {
		$values = [];
		foreach ( $group['fields'] as $f ) {
			$values[ $f['name'] ] = metadata_exists( 'term', $term_id, $f['name'] ) ? get_term_meta( $term_id, $f['name'], true ) : ( $f['default'] ?? null );
		}
		return $values;
	}

	// ----------------------------------------------------------------- errors

	/** @param list<string> $errors */
	public static function add_errors( array $errors ): void {
		$uid = get_current_user_id();
		$key = 'momos_field_errors_' . $uid;
		$all = (array) get_transient( $key );
		set_transient( $key, array_values( array_unique( array_filter( array_merge( $all, $errors ) ) ) ), 120 );
		$GLOBALS['momos_last_field_errors'] = array_merge( $GLOBALS['momos_last_field_errors'] ?? [], $errors );
	}

	/** @return list<string> */
	public static function last_errors(): array {
		return $GLOBALS['momos_last_field_errors'] ?? [];
	}

	public static function admin_notices(): void {
		$key    = 'momos_field_errors_' . get_current_user_id();
		$errors = array_filter( array_map( 'strval', (array) get_transient( $key ) ), static fn( $e ) => '' !== trim( $e ) );
		if ( ! $errors ) {
			delete_transient( $key );
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-error momos-field-errors" role="alert"><ul>';
		foreach ( $errors as $e ) {
			echo '<li>' . esc_html( (string) $e ) . '</li>';
		}
		echo '</ul></div>';
	}
}
