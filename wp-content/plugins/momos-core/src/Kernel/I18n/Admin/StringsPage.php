<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n\Admin;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Strings;

/**
 * SCR-A11 Strings tab — string overrides and per-key review status (content-strings.md §2.1/§12/§13,
 * i18n-seo-analytics.md §6.2/§14.2). `admin.php?page=momos-strings`.
 *
 * Search catalog keys (key or text), filter by group (catalog file), status, launch-critical; edit the
 * value per language (stored in `momos_strings_overrides`; a value equal to the shipped text removes the
 * override). Placeholders `{name}` must match the EN source exactly — a mismatch is refused per key.
 * Status per key: draft / needs review / approved (`momo_approve_translations`; review-class keys need a
 * reviewer other than the last translator). Every save is audited (translation.updated/approved, object
 * `strings`, keys only — no values). Cap `momo_translate`; translators limited to their languages.
 */
final class StringsPage {
	public const SLUG     = 'momos-strings';
	public const CAP      = 'momo_translate';
	public const ACTION   = 'momos_strings_save';
	public const PER_PAGE = 50;
	public const MAX_LEN  = 2000;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 26 );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'momos', __( 'Interface strings', 'momos-core' ), __( 'Interface strings', 'momos-core' ), self::CAP, self::SLUG, [ self::class, 'render' ] );
	}

	/**
	 * Languages whose strings the user may edit: translators their assigned languages; others all six
	 * (Japanese source strings also need review, content-strings §3.2).
	 *
	 * @return list<string>
	 */
	public static function langs( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$own     = get_user_meta( $user_id, 'momo_translator_langs', true );
		return ! empty( $own ) ? TranslationEditor::user_langs( $user_id ) : Lang::codes();
	}

	/**
	 * Matching keys for the filters.
	 *
	 * @param array{q?:string,group?:string,status?:string,lccs?:bool} $f
	 * @return list<string>
	 */
	public static function search( string $lang, array $f ): array {
		$q   = mb_strtolower( trim( (string) ( $f['q'] ?? '' ) ) );
		$out = [];
		foreach ( Strings::keys() as $key ) {
			if ( ! empty( $f['group'] ) && Strings::group_of( $key ) !== $f['group'] ) {
				continue;
			}
			if ( ! empty( $f['lccs'] ) && ! Strings::is_lccs_key( $key ) ) {
				continue;
			}
			if ( ! empty( $f['status'] ) ) {
				$st = 'outdated' === $f['status'] ? ( Strings::is_outdated( $key, $lang ) ? 'outdated' : '' ) : Strings::status( $key, $lang );
				if ( $st !== $f['status'] ) {
					continue;
				}
			}
			if ( '' !== $q ) {
				$hay = mb_strtolower( $key . "\n" . (string) Strings::raw( $key, $lang ) . "\n" . (string) Strings::raw( $key, 'en' ) . "\n" . (string) Strings::raw( $key, Lang::SOURCE ) );
				if ( ! str_contains( $hay, $q ) ) {
					continue;
				}
			}
			$out[] = $key;
		}
		return $out;
	}

	/** @return list<string> catalog groups (module files) */
	public static function groups(): array {
		$g = [];
		foreach ( Strings::keys() as $key ) {
			$g[ Strings::group_of( $key ) ?: 'other' ] = true;
		}
		$g = array_keys( $g );
		sort( $g, SORT_STRING );
		return $g;
	}

	/**
	 * Apply edits for one language. $values: key => submitted text (only changed rows need be sent);
	 * $statuses: key => draft|needs_review|approved. Returns [saved keys, errors].
	 *
	 * @param array<string,mixed> $values
	 * @param array<string,mixed> $statuses
	 * @return array{0:list<string>,1:list<string>}
	 */
	public static function apply( string $lang, array $values, array $statuses, int $user_id ): array {
		if ( ! in_array( $lang, self::langs( $user_id ), true ) || ! user_can( $user_id, self::CAP ) ) {
			return [ [], [ __( 'You cannot edit strings in this language.', 'momos-core' ) ] ];
		}
		$known      = array_flip( Strings::keys() );
		$can_review = user_can( $user_id, 'momo_approve_translations' );
		$errors     = [];
		$saved      = [];
		$by_status  = [];
		$changed    = [];
		foreach ( $values as $key => $raw ) {
			$key = (string) $key;
			if ( ! isset( $known[ $key ] ) || ! is_scalar( $raw ) ) {
				continue;
			}
			$value = trim( str_replace( "\r\n", "\n", (string) $raw ) );
			if ( mb_strlen( $value ) > self::MAX_LEN ) {
				/* translators: 1: key 2: max length */
				$errors[] = sprintf( __( '%1$s: the text is longer than %2$d characters.', 'momos-core' ), $key, self::MAX_LEN );
				continue;
			}
			if ( $value === (string) Strings::raw( $key, $lang ) ) {
				continue;
			}
			if ( '' !== $value ) {
				[ $missing, $extra ] = Strings::placeholder_diff( $key, $value, $lang );
				if ( $missing || $extra ) {
					$errors[] = self::placeholder_error( $key, $missing, $extra );
					continue;
				}
			}
			$file = Strings::file_value( $key, $lang );
			Strings::save_override( $lang, $key, null !== $file && $value === $file ? '' : $value );
			$changed[ $key ] = true;
			$saved[]         = $key;
		}
		if ( $changed ) {
			do_action( 'momos/cache/purge', [ 'momo-global' ] ); // Interface strings render on every page.
		}
		foreach ( array_keys( $changed ) as $key ) {
			// A changed text is a new draft unless a status was chosen explicitly below.
			$by_status[ in_array( $statuses[ $key ] ?? '', Strings::STATUSES, true ) ? (string) $statuses[ $key ] : 'draft' ][] = $key;
		}
		foreach ( $statuses as $key => $status ) {
			$key    = (string) $key;
			$status = sanitize_key( (string) $status );
			if ( isset( $changed[ $key ] ) || ! isset( $known[ $key ] ) || ! in_array( $status, Strings::STATUSES, true ) || null === Strings::raw( $key, $lang ) ) {
				continue;
			}
			$current = Strings::status( $key, $lang );
			if ( $current === $status ) {
				continue;
			}
			$by_status[ $status ][] = $key;
		}
		// Approval rules first, so refused keys fall back to "needs review" in the same save.
		$approve = [];
		foreach ( $by_status['approved'] ?? [] as $key ) {
			$translator = isset( $changed[ $key ] ) ? $user_id : (int) ( Strings::record( $key, $lang )['translated_by'] ?? 0 );
			if ( ! $can_review ) {
				/* translators: %s: string key */
				$errors[] = sprintf( __( '%s: only a reviewer can approve strings.', 'momos-core' ), $key );
			} elseif ( Strings::is_review_class( $key ) && $translator === $user_id ) {
				/* translators: %s: string key */
				$errors[] = sprintf( __( '%s: You cannot approve your own translation of this content.', 'momos-core' ), $key );
			} else {
				$approve[] = $key;
				continue;
			}
			$by_status['needs_review'][] = $key;
		}
		$by_status['approved'] = $approve;
		foreach ( Strings::STATUSES as $status ) {
			$keys = array_values( array_unique( $by_status[ $status ] ?? [] ) );
			if ( ! $keys ) {
				continue;
			}
			$changed_keys = array_values( array_filter( $keys, static fn( $k ) => isset( $changed[ $k ] ) ) );
			$plain_keys   = array_values( array_diff( $keys, $changed_keys ) );
			if ( $changed_keys ) {
				Strings::set_status( $lang, $changed_keys, $status, $user_id, 'human', true );
			}
			if ( $plain_keys ) {
				Strings::set_status( $lang, $plain_keys, $status, $user_id );
			}
			$saved = array_merge( $saved, $keys );
			momos_audit( 'approved' === $status ? 'translation.approved' : ( 'draft' === $status && ! $changed_keys ? 'translation.returned' : 'translation.updated' ), 'strings', null, null, [ 'lang' => $lang, 'status' => $status, 'keys' => array_slice( $keys, 0, 100 ), 'count' => count( $keys ) ] );
		}
		return [ array_values( array_unique( $saved ) ), $errors ];
	}

	/**
	 * @param list<string> $missing
	 * @param list<string> $extra
	 */
	public static function placeholder_error( string $key, array $missing, array $extra ): string {
		$wrap = static fn( array $l ) => implode( ', ', array_map( static fn( $p ) => '{' . $p . '}', $l ) );
		if ( $missing && $extra ) {
			/* translators: 1: key 2: missing placeholders 3: unexpected placeholders */
			return sprintf( __( '%1$s: placeholders must match the source — missing %2$s, unexpected %3$s. Not saved.', 'momos-core' ), $key, $wrap( $missing ), $wrap( $extra ) );
		}
		if ( $missing ) {
			/* translators: 1: key 2: placeholders */
			return sprintf( __( '%1$s: placeholders must match the source — missing %2$s. Not saved.', 'momos-core' ), $key, $wrap( $missing ) );
		}
		/* translators: 1: key 2: placeholders */
		return sprintf( __( '%1$s: placeholders must match the source — unexpected %2$s. Not saved.', 'momos-core' ), $key, $wrap( $extra ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'strings', null, null, [ 'cap' => self::CAP, 'channel' => 'admin-post', 'route' => self::ACTION ] );
			wp_die( esc_html__( 'You do not have permission to edit strings.', 'momos-core' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( (string) $_POST['lang'] ) ) : '';
		if ( ! in_array( $lang, self::langs(), true ) ) {
			momos_audit( 'access.denied', 'strings', null, null, [ 'cap' => self::CAP, 'channel' => 'admin-post', 'route' => self::ACTION, 'reason' => 'language' ] );
			wp_die( esc_html__( 'You cannot edit strings in this language.', 'momos-core' ), 403 );
		}
		$values   = isset( $_POST['values'] ) && is_array( $_POST['values'] ) ? wp_unslash( $_POST['values'] ) : []; // phpcs:ignore -- validated per key in apply().
		$original = isset( $_POST['original'] ) && is_array( $_POST['original'] ) ? wp_unslash( $_POST['original'] ) : []; // phpcs:ignore
		$statuses = isset( $_POST['status'] ) && is_array( $_POST['status'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['status'] ) ) : [];
		// Only rows whose text was edited in the form are applied (avoids overwriting concurrent edits).
		$edited = [];
		foreach ( $values as $k => $v ) {
			if ( is_scalar( $v ) && (string) $v !== (string) ( $original[ $k ] ?? '' ) ) {
				$edited[ (string) $k ] = (string) $v;
			}
		}
		if ( isset( $_POST['approve_all'] ) ) {
			foreach ( array_keys( $values ) as $k ) {
				$statuses[ (string) $k ] = 'approved';
			}
		}
		[ $saved, $errors ] = self::apply( $lang, $edited, $statuses, get_current_user_id() );
		set_transient( 'momos_strings_msg_' . get_current_user_id(), [ 'saved' => count( $saved ), 'errors' => $errors ], 120 );
		$back = isset( $_POST['_back'] ) ? esc_url_raw( wp_unslash( (string) $_POST['_back'] ) ) : '';
		wp_safe_redirect( $back && str_starts_with( $back, admin_url() ) ? $back : add_query_arg( [ 'page' => self::SLUG, 'lang' => $lang ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function status_label( string $s ): string {
		return match ( $s ) {
			'draft' => __( 'Draft', 'momos-core' ),
			'needs_review' => __( 'Needs review', 'momos-core' ),
			'approved' => __( 'Approved', 'momos-core' ),
			'outdated' => __( 'Needs update', 'momos-core' ),
			default => __( 'Missing', 'momos-core' ),
		};
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'strings', null, null, [ 'cap' => self::CAP, 'channel' => 'admin', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to edit strings.', 'momos-core' ), 403 );
		}
		$langs = self::langs();
		$lang  = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( (string) $_GET['lang'] ) ) : ''; // phpcs:ignore
		$lang  = in_array( $lang, $langs, true ) ? $lang : ( in_array( 'en', $langs, true ) ? 'en' : $langs[0] );
		$f     = [
			'q'      => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '', // phpcs:ignore
			'group'  => isset( $_GET['group'] ) ? sanitize_key( wp_unslash( (string) $_GET['group'] ) ) : '', // phpcs:ignore
			'status' => isset( $_GET['status'] ) && in_array( $_GET['status'], [ 'missing', 'draft', 'needs_review', 'approved', 'outdated' ], true ) ? (string) $_GET['status'] : '', // phpcs:ignore
			'lccs'   => ! empty( $_GET['lccs'] ), // phpcs:ignore
		];
		$keys  = self::search( $lang, $f );
		$pages = max( 1, (int) ceil( count( $keys ) / self::PER_PAGE ) );
		$page  = min( $pages, max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ) ); // phpcs:ignore
		$shown = array_slice( $keys, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$rtl   = Lang::is_rtl( $lang ) ? 'rtl' : 'ltr';
		$can_r = current_user_can( 'momo_approve_translations' );
		$msg   = get_transient( 'momos_strings_msg_' . get_current_user_id() );
		if ( false !== $msg ) {
			delete_transient( 'momos_strings_msg_' . get_current_user_id() );
		}
		$base  = admin_url( 'admin.php' );
		$query = array_filter( [ 'page' => self::SLUG, 'lang' => $lang, 'q' => $f['q'], 'group' => $f['group'], 'status' => $f['status'], 'lccs' => $f['lccs'] ? 1 : null ] );

		echo '<div class="wrap momos-admin momos-strings"><h1>' . esc_html__( 'Interface strings', 'momos-core' ) . '</h1>';
		if ( is_array( $msg ) ) {
			if ( ! empty( $msg['errors'] ) ) {
				echo '<div class="notice notice-error" role="alert">';
				foreach ( (array) $msg['errors'] as $e ) {
					echo '<p>' . esc_html( (string) $e ) . '</p>';
				}
				echo '</div>';
			}
			/* translators: %d: number of strings */
			echo '<div class="notice notice-success" role="status"><p>' . esc_html( sprintf( _n( '%d string saved.', '%d strings saved.', (int) $msg['saved'], 'momos-core' ), (int) $msg['saved'] ) ) . '</p></div>';
		}
		echo '<form method="get" action="' . esc_url( $base ) . '"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="momos-str-lang">' . esc_html__( 'Language', 'momos-core' ) . '</label> <select id="momos-str-lang" name="lang">';
		foreach ( $langs as $l ) {
			echo '<option value="' . esc_attr( $l ) . '"' . selected( $lang, $l, false ) . '>' . esc_html( Lang::native( $l ) . ' (' . $l . ')' ) . '</option>';
		}
		echo '</select> <label for="momos-str-q">' . esc_html__( 'Search', 'momos-core' ) . '</label> <input type="search" id="momos-str-q" name="q" value="' . esc_attr( $f['q'] ) . '">';
		echo ' <label for="momos-str-group">' . esc_html__( 'Group', 'momos-core' ) . '</label> <select id="momos-str-group" name="group"><option value="">' . esc_html__( 'All groups', 'momos-core' ) . '</option>';
		foreach ( self::groups() as $g ) {
			echo '<option value="' . esc_attr( $g ) . '"' . selected( $f['group'], $g, false ) . '>' . esc_html( $g ) . '</option>';
		}
		echo '</select> <label for="momos-str-status">' . esc_html__( 'Status', 'momos-core' ) . '</label> <select id="momos-str-status" name="status"><option value="">' . esc_html__( 'All', 'momos-core' ) . '</option>';
		foreach ( [ 'missing', 'draft', 'needs_review', 'approved', 'outdated' ] as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $f['status'], $s, false ) . '>' . esc_html( self::status_label( $s ) ) . '</option>';
		}
		echo '</select> <label><input type="checkbox" name="lccs" value="1"' . checked( $f['lccs'], true, false ) . '> ' . esc_html__( 'Launch-critical only', 'momos-core' ) . '</label> ';
		submit_button( __( 'Filter', 'momos-core' ), 'secondary', '', false );
		echo '</form>';

		/* translators: %d: number of keys */
		echo '<p>' . esc_html( sprintf( _n( '%d key', '%d keys', count( $keys ), 'momos-core' ), count( $keys ) ) ) . '</p>';
		if ( ! $keys ) {
			echo '<p>' . esc_html__( 'No strings match these filters.', 'momos-core' ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="lang" value="' . esc_attr( $lang ) . '">';
		echo '<input type="hidden" name="_back" value="' . esc_attr( add_query_arg( $query + [ 'paged' => $page ], $base ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<div class="momos-table-scroll" role="region" aria-label="' . esc_attr__( 'Strings', 'momos-core' ) . '" tabindex="0"><table class="widefat striped momos-strings__table"><thead><tr>';
		foreach ( [ __( 'Key', 'momos-core' ), __( 'English reference', 'momos-core' ), __( 'Japanese', 'momos-core' ), Lang::native( $lang ), __( 'Status', 'momos-core' ) ] as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $shown as $i => $key ) {
			$fid    = 'momos-str-' . $i;
			$val    = (string) Strings::raw( $key, $lang );
			$status = Strings::status( $key, $lang );
			$rec    = Strings::record( $key, $lang );
			$ph     = Strings::placeholders( (string) ( Strings::file_value( $key, 'en' ) ?? Strings::reference( $key, $lang ) ?? '' ) );
			$over   = isset( Strings::overrides()[ $lang ][ $key ] );
			echo '<tr><th scope="row"><code>' . esc_html( $key ) . '</code><br><span class="description">' . esc_html( Strings::group_of( $key ) ) . ( Strings::is_lccs_key( $key ) ? ' · LCCS' : '' ) . ( $over ? ' · ' . esc_html__( 'override', 'momos-core' ) : '' ) . '</span></th>';
			echo '<td lang="en">' . esc_html( (string) Strings::raw( $key, 'en' ) ) . '</td><td lang="ja">' . esc_html( (string) Strings::raw( $key, Lang::SOURCE ) ) . '</td>';
			echo '<td><label class="screen-reader-text" for="' . esc_attr( $fid ) . '">' . esc_html( $key ) . '</label><textarea class="large-text" rows="2" maxlength="' . (int) self::MAX_LEN . '" id="' . esc_attr( $fid ) . '" name="values[' . esc_attr( $key ) . ']" lang="' . esc_attr( $lang ) . '" dir="' . esc_attr( $rtl ) . '">' . esc_textarea( $val ) . '</textarea>';
			echo '<input type="hidden" name="original[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '">';
			if ( $ph ) {
				echo '<p class="description">' . esc_html__( 'Placeholders:', 'momos-core' ) . ' <code>' . esc_html( implode( ' ', array_map( static fn( $p ) => '{' . $p . '}', $ph ) ) ) . '</code></p>';
			}
			echo '</td><td><label class="screen-reader-text" for="' . esc_attr( $fid ) . '-st">' . esc_html__( 'Status', 'momos-core' ) . '</label><select id="' . esc_attr( $fid ) . '-st" name="status[' . esc_attr( $key ) . ']"><option value="">' . esc_html( self::status_label( $status ) ) . '</option>';
			foreach ( Strings::STATUSES as $s ) {
				if ( 'approved' === $s && ! $can_r ) {
					continue;
				}
				echo '<option value="' . esc_attr( $s ) . '">' . esc_html( '→ ' . self::status_label( $s ) ) . '</option>';
			}
			echo '</select>';
			if ( Strings::is_outdated( $key, $lang ) ) {
				echo '<p class="description">' . esc_html__( 'Reference text changed since approval.', 'momos-core' ) . '</p>';
			}
			if ( $rec && ! empty( $rec['by'] ) ) {
				$u = get_userdata( (int) $rec['by'] );
				echo '<p class="description">' . esc_html( ( $u ? $u->display_name : '#' . (int) $rec['by'] ) . ' · ' . substr( (string) ( $rec['at'] ?? '' ), 0, 10 ) ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $p = 1; $p <= $pages; $p++ ) {
				echo $p === $page ? '<strong aria-current="page">' . (int) $p . '</strong> ' : '<a href="' . esc_url( add_query_arg( $query + [ 'paged' => $p ], $base ) ) . '">' . (int) $p . '</a> ';
			}
			echo '</p>';
		}
		echo '<p>';
		submit_button( __( 'Save', 'momos-core' ), 'primary', 'save', false );
		if ( $can_r ) {
			echo ' ';
			submit_button( __( 'Approve all strings on this page', 'momos-core' ), 'secondary', 'approve_all', false );
		}
		echo '</p></form></div>';
	}
}
