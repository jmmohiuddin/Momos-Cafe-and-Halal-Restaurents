<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n\Admin;

use Momos\Kernel\I18n\Lang;

/**
 * SCR-A11 "Translation gaps" (Momo's › Translation gaps, `admin.php?page=momos-translation-gaps`;
 * FR-I18N-008, i18n-seo-analytics.md §6.3/§14.2, screens.md §5.11).
 *
 * Cap `momo_translate`; translators see their languages only ("My queue"). Header: per language
 * "{native} — LCCS {p} % ({n} gaps)"; counts per language × content type × state; filters language,
 * content type, state, LCCS only; rows link to the translation editor / strings editor (deep links).
 * Bulk "Mark needs review" for approved translations whose source changed (`momo_approve_translations`,
 * because it hides the previous translation until re-approved). CSV download for SA/HO
 * (`momo_manage_languages`), formula-escaped, no PII.
 */
final class GapsPage {
	public const SLUG     = 'momos-translation-gaps';
	public const CAP      = 'momo_translate';
	public const PER_PAGE = 50;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 25 );
		add_action( 'admin_post_momos_gaps_mark_review', [ self::class, 'handle_mark_review' ] );
		add_action( 'admin_post_momos_gaps_csv', [ self::class, 'handle_csv' ] );
	}

	public static function menu(): void {
		add_submenu_page( 'momos', __( 'Translation gaps', 'momos-core' ), __( 'Translation gaps', 'momos-core' ), self::CAP, self::SLUG, [ self::class, 'render' ] );
	}

	/**
	 * Sanitised filters from a request array, limited to the user's languages.
	 *
	 * @param array<string,mixed> $req
	 * @return array{lang:string,type:string,state:string,lccs:bool,langs:list<string>}
	 */
	public static function filters( array $req ): array {
		$langs = TranslationEditor::user_langs();
		$lang  = isset( $req['lang'] ) ? sanitize_key( (string) $req['lang'] ) : '';
		$type  = isset( $req['type'] ) ? sanitize_key( (string) $req['type'] ) : '';
		$state = isset( $req['state'] ) ? sanitize_key( (string) $req['state'] ) : '';
		return [
			'lang'  => in_array( $lang, $langs, true ) ? $lang : '',
			'type'  => in_array( $type, TranslationGaps::TYPES, true ) ? $type : '',
			'state' => in_array( $state, TranslationGaps::STATES, true ) ? $state : '',
			'lccs'  => ! empty( $req['lccs'] ),
			'langs' => $langs,
		];
	}

	private static function deny( string $channel ): void {
		momos_audit( 'access.denied', 'translation', null, null, [ 'cap' => self::CAP, 'channel' => $channel, 'screen' => self::SLUG ] );
		wp_die( esc_html__( 'You do not have permission to view translation gaps.', 'momos-core' ), 403 );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny( 'admin' );
		}
		$f     = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore -- read-only filters, sanitised in filters().
		$rows  = TranslationGaps::rows( $f );
		$page  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore
		$pages = max( 1, (int) ceil( count( $rows ) / self::PER_PAGE ) );
		$page  = min( $page, $pages );
		$shown = array_slice( $rows, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$langs = '' !== $f['lang'] ? [ $f['lang'] ] : $f['langs'];
		$count = TranslationGaps::counts( $langs, $f['lccs'] );
		$msg   = get_transient( 'momos_gaps_msg_' . get_current_user_id() );
		if ( false !== $msg ) {
			delete_transient( 'momos_gaps_msg_' . get_current_user_id() );
		}
		$base = admin_url( 'admin.php' );

		echo '<div class="wrap momos-admin momos-gaps"><h1>' . esc_html__( 'Translation gaps', 'momos-core' ) . '</h1>';
		if ( is_array( $msg ) ) {
			echo '<div class="notice notice-' . ( 'error' === $msg[0] ? 'error' : 'success' ) . '" role="' . ( 'error' === $msg[0] ? 'alert' : 'status' ) . '"><p>' . esc_html( (string) $msg[1] ) . '</p></div>';
		}

		// Per-language header summary.
		echo '<ul class="momos-gaps__summary">';
		foreach ( $langs as $lang ) {
			$gaps = count( TranslationGaps::lccs_gaps( $lang ) );
			$name = Lang::native( $lang );
			echo '<li lang="en">';
			if ( 0 === $gaps ) {
				echo esc_html( momos_t( 'admin.translation.gaps_none', [ 'language' => $name ], str_starts_with( (string) get_user_locale(), 'ja' ) ? 'ja' : 'en' ) . ' — LCCS 100 %' );
			} else {
				/* translators: 1: language 2: percent 3: number of gaps */
				echo esc_html( sprintf( __( '%1$s — LCCS %2$d %% (%3$d gaps)', 'momos-core' ), $name, TranslationGaps::lccs_percent( $lang ), $gaps ) );
			}
			echo ' · ' . ( in_array( $lang, Lang::enabled(), true ) ? esc_html__( 'public', 'momos-core' ) : esc_html__( 'hidden', 'momos-core' ) ) . '</li>';
		}
		echo '</ul>';

		// Filters (GET).
		echo '<form method="get" action="' . esc_url( $base ) . '" class="momos-gaps__filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<label for="momos-gaps-lang">' . esc_html__( 'Language', 'momos-core' ) . '</label> <select id="momos-gaps-lang" name="lang"><option value="">' . esc_html__( 'All my languages', 'momos-core' ) . '</option>';
		foreach ( $f['langs'] as $l ) {
			echo '<option value="' . esc_attr( $l ) . '"' . selected( $f['lang'], $l, false ) . '>' . esc_html( Lang::native( $l ) . ' (' . $l . ')' ) . '</option>';
		}
		echo '</select> <label for="momos-gaps-type">' . esc_html__( 'Content type', 'momos-core' ) . '</label> <select id="momos-gaps-type" name="type"><option value="">' . esc_html__( 'All types', 'momos-core' ) . '</option>';
		foreach ( TranslationGaps::TYPES as $t ) {
			echo '<option value="' . esc_attr( $t ) . '"' . selected( $f['type'], $t, false ) . '>' . esc_html( TranslationGaps::type_label( $t ) ) . '</option>';
		}
		echo '</select> <label for="momos-gaps-state">' . esc_html__( 'State', 'momos-core' ) . '</label> <select id="momos-gaps-state" name="state"><option value="">' . esc_html__( 'All states', 'momos-core' ) . '</option>';
		foreach ( TranslationGaps::STATES as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $f['state'], $s, false ) . '>' . esc_html( TranslationGaps::state_label( $s ) ) . '</option>';
		}
		echo '</select> <label><input type="checkbox" name="lccs" value="1"' . checked( $f['lccs'], true, false ) . '> ' . esc_html__( 'Launch-critical only', 'momos-core' ) . '</label> ';
		submit_button( __( 'Filter', 'momos-core' ), 'secondary', '', false );
		if ( current_user_can( 'momo_manage_languages' ) ) {
			$csv = wp_nonce_url( add_query_arg( array_filter( [ 'action' => 'momos_gaps_csv', 'lang' => $f['lang'], 'type' => $f['type'], 'state' => $f['state'], 'lccs' => $f['lccs'] ? 1 : null ] ), admin_url( 'admin-post.php' ) ), 'momos_gaps_csv' );
			echo ' <a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Download CSV', 'momos-core' ) . '</a>';
		}
		echo '</form>';

		// Counts: content type × language (missing / draft / needs review / needs update).
		echo '<h2>' . esc_html__( 'Counts', 'momos-core' ) . '</h2><div class="momos-table-scroll" role="region" aria-label="' . esc_attr__( 'Gap counts', 'momos-core' ) . '" tabindex="0"><table class="widefat striped momos-gaps__counts"><thead><tr><th scope="col">' . esc_html__( 'Content type', 'momos-core' ) . '</th>';
		foreach ( $langs as $lang ) {
			echo '<th scope="col">' . esc_html( Lang::native( $lang ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( TranslationGaps::TYPES as $t ) {
			$has = false;
			foreach ( $langs as $lang ) {
				$has = $has || isset( $count[ $lang ][ $t ] );
			}
			if ( ! $has || ( '' !== $f['type'] && $t !== $f['type'] ) ) {
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( TranslationGaps::type_label( $t ) ) . '</th>';
			foreach ( $langs as $lang ) {
				$c     = $count[ $lang ][ $t ] ?? array_fill_keys( TranslationGaps::STATES, 0 );
				$parts = [];
				foreach ( TranslationGaps::STATES as $s ) {
					if ( $c[ $s ] > 0 ) {
						$url     = add_query_arg( [ 'page' => self::SLUG, 'lang' => $lang, 'type' => $t, 'state' => $s ], $base );
						$parts[] = '<a href="' . esc_url( $url ) . '">' . esc_html( TranslationGaps::state_label( $s ) . ' ' . $c[ $s ] ) . '</a>';
					}
				}
				echo '<td>' . ( $parts ? implode( ' · ', $parts ) : esc_html__( 'Complete', 'momos-core' ) ) . '</td>'; // phpcs:ignore -- parts escaped above.
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		// Item rows.
		/* translators: %d: number of rows */
		echo '<h2>' . esc_html( sprintf( _n( '%d gap', '%d gaps', count( $rows ), 'momos-core' ), count( $rows ) ) ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No gaps for these filters.', 'momos-core' ) . '</p></div>';
			return;
		}
		$can_bulk = current_user_can( 'momo_approve_translations' );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="momos_gaps_mark_review">';
		wp_nonce_field( 'momos_gaps_mark_review' );
		foreach ( [ 'lang', 'type', 'state' ] as $k ) {
			echo '<input type="hidden" name="f_' . esc_attr( $k ) . '" value="' . esc_attr( (string) $f[ $k ] ) . '">';
		}
		echo '<div class="momos-table-scroll" role="region" aria-label="' . esc_attr__( 'Translation gaps', 'momos-core' ) . '" tabindex="0"><table class="widefat striped momos-gaps__rows"><thead><tr>';
		if ( $can_bulk ) {
			echo '<td class="check-column"><span class="screen-reader-text">' . esc_html__( 'Select', 'momos-core' ) . '</span></td>';
		}
		foreach ( [ __( 'Type', 'momos-core' ), __( 'Japanese title', 'momos-core' ), __( 'Language', 'momos-core' ), __( 'State', 'momos-core' ), __( 'Age (days)', 'momos-core' ), __( 'Launch-critical', 'momos-core' ) ] as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $shown as $i => $r ) {
			echo '<tr>';
			if ( $can_bulk ) {
				echo '<th scope="row" class="check-column">';
				if ( 'outdated' === $r['state'] ) {
					$cid = 'momos-gap-' . $i;
					echo '<label class="screen-reader-text" for="' . esc_attr( $cid ) . '">' . esc_html__( 'Select', 'momos-core' ) . '</label><input id="' . esc_attr( $cid ) . '" type="checkbox" name="items[]" value="' . esc_attr( $r['type'] . '|' . $r['id'] . '|' . $r['lang'] ) . '">';
				}
				echo '</th>';
			}
			$detail = '';
			if ( 'strings' === $r['type'] ) {
				$bits = [];
				foreach ( $r['counts'] as $s => $n ) {
					if ( $n > 0 ) {
						$bits[] = TranslationGaps::state_label( $s ) . ' ' . $n;
					}
				}
				$detail = ' (' . implode( ', ', $bits ) . ')';
			}
			echo '<td>' . esc_html( TranslationGaps::type_label( (string) $r['content_type'] ) ) . '</td>';
			echo '<td lang="ja"><a href="' . esc_url( TranslationGaps::edit_url( $r, (string) $r['lang'] ) ) . '">' . esc_html( wp_strip_all_tags( (string) $r['title'] ) ) . '</a></td>';
			echo '<td>' . esc_html( Lang::native( (string) $r['lang'] ) ) . '</td>';
			echo '<td><span class="momos-badge momos-badge--' . esc_attr( (string) $r['state'] ) . '">' . esc_html( TranslationGaps::state_label( (string) $r['state'] ) . $detail ) . '</span></td>';
			echo '<td>' . ( null === $r['age'] ? '—' : (int) $r['age'] ) . '</td>';
			echo '<td>' . esc_html( $r['lccs'] ? __( 'Yes', 'momos-core' ) : __( 'No', 'momos-core' ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $pages > 1 ) {
			echo '<p class="momos-gaps__pages">';
			for ( $p = 1; $p <= $pages; $p++ ) {
				$url = add_query_arg( array_filter( [ 'page' => self::SLUG, 'lang' => $f['lang'], 'type' => $f['type'], 'state' => $f['state'], 'lccs' => $f['lccs'] ? 1 : null, 'paged' => $p ] ), $base );
				echo $p === $page ? '<strong aria-current="page">' . (int) $p . '</strong> ' : '<a href="' . esc_url( $url ) . '">' . (int) $p . '</a> ';
			}
			echo '</p>';
		}
		if ( $can_bulk ) {
			echo '<p>';
			submit_button( __( 'Mark selected as needs review', 'momos-core' ), 'secondary', 'mark_selected', false );
			echo ' ';
			submit_button( __( 'Mark all outdated (these languages) as needs review', 'momos-core' ), 'secondary', 'mark_all', false );
			echo '</p><p class="description">' . esc_html__( 'Use when the Japanese source changed: the translation is hidden in that language until a reviewer approves it again.', 'momos-core' ) . '</p>';
		}
		echo '</form></div>';
	}

	public static function handle_mark_review(): void {
		if ( ! current_user_can( 'momo_approve_translations' ) ) {
			momos_audit( 'access.denied', 'translation', null, null, [ 'cap' => 'momo_approve_translations', 'channel' => 'admin-post', 'route' => 'momos_gaps_mark_review' ] );
			wp_die( esc_html__( 'You do not have permission to change translation status.', 'momos-core' ), 403 );
		}
		check_admin_referer( 'momos_gaps_mark_review' );
		$f     = self::filters( [ 'lang' => wp_unslash( (string) ( $_POST['f_lang'] ?? '' ) ), 'type' => wp_unslash( (string) ( $_POST['f_type'] ?? '' ) ), 'state' => wp_unslash( (string) ( $_POST['f_state'] ?? '' ) ) ] );
		$langs = '' !== $f['lang'] ? [ $f['lang'] ] : $f['langs'];
		$n     = 0;
		$user  = get_current_user_id();
		if ( isset( $_POST['mark_all'] ) ) {
			$n = TranslationGaps::mark_outdated_needs_review( $langs, $user );
		} else {
			$by_lang = [];
			foreach ( (array) ( $_POST['items'] ?? [] ) as $raw ) {
				$parts = explode( '|', sanitize_text_field( wp_unslash( (string) $raw ) ) );
				if ( 3 !== count( $parts ) || ! in_array( $parts[2], $langs, true ) || ! in_array( $parts[0], [ 'post', 'term', 'option', 'strings' ], true ) ) {
					continue;
				}
				$id                          = in_array( $parts[0], [ 'post', 'term' ], true ) ? absint( $parts[1] ) : sanitize_key( $parts[1] );
				$by_lang[ $parts[2] ][]      = [ 'type' => $parts[0], 'id' => $id ];
			}
			foreach ( $by_lang as $lang => $only ) {
				$n += TranslationGaps::mark_outdated_needs_review( [ (string) $lang ], $user, $only );
			}
		}
		/* translators: %d: number of translations */
		set_transient( 'momos_gaps_msg_' . $user, [ 'success', sprintf( _n( '%d translation marked as needs review.', '%d translations marked as needs review.', $n, 'momos-core' ), $n ) ], 120 );
		wp_safe_redirect( add_query_arg( array_filter( [ 'page' => self::SLUG, 'lang' => $f['lang'], 'type' => $f['type'], 'state' => $f['state'] ] ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Spreadsheet-safe cell (formula injection guard). */
	public static function csv_cell( string $v ): string {
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	public static function handle_csv(): void {
		if ( ! current_user_can( 'momo_manage_languages' ) ) {
			momos_audit( 'access.denied', 'translation', null, null, [ 'cap' => 'momo_manage_languages', 'channel' => 'admin-post', 'route' => 'momos_gaps_csv' ] );
			wp_die( esc_html__( 'You do not have permission to download translation gaps.', 'momos-core' ), 403 );
		}
		check_admin_referer( 'momos_gaps_csv' );
		$rows = TranslationGaps::rows( self::filters( wp_unslash( $_GET ) ) ); // phpcs:ignore -- sanitised in filters().
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="translation-gaps.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [ 'type', 'id', 'title_ja', 'language', 'state', 'age_days', 'lccs', 'keys' ] );
		foreach ( $rows as $r ) {
			fputcsv( $out, array_map( [ self::class, 'csv_cell' ], [ (string) $r['content_type'], (string) $r['id'], wp_strip_all_tags( (string) $r['title'] ), (string) $r['lang'], (string) $r['state'], null === $r['age'] ? '' : (string) $r['age'], $r['lccs'] ? 'yes' : 'no', 'strings' === $r['type'] ? (string) array_sum( $r['counts'] ) : '' ] ) );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}
}
