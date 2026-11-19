<?php
declare(strict_types=1);

namespace Momos\Seo\Admin;

use Momos\Kernel\Clock\Clock;
use Momos\Seo\Redirects\NotFoundLog;
use Momos\Seo\Redirects\RedirectStore;
use Momos\Seo\SeoSettings;

/**
 * SEO → Redirects & 404 log (i18n-seo-analytics.md §14.4 [IMPL], §16; SA/HO via `momo_manage_redirects`):
 * redirect list with add/edit/delete and "Verify all" (HEAD checks), aggregated 404 log sorted by hits
 * with "Create redirect", weekly review record (8 weeks after launch), Restaurant structured-data values
 * (SA/HO: servesCuisine per language and priceRange, FR-SEO-006(b), F-SEO-02, Q-415) and SA-only technical settings.
 * Every handler: capability + nonce; denial → 403 + `access.denied`; changes audited
 * (`redirect.created/updated/deleted`, `settings.updated`) with paths only — never personal data.
 */
final class SeoAdmin {
	public const SLUG = 'momos-seo';
	public const CAP  = 'momo_manage_redirects';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 30 );
		foreach ( [ 'momos_redirect_save', 'momos_redirect_delete', 'momos_redirect_verify', 'momos_404_review', 'momos_404_clear', 'momos_seo_settings', 'momos_seo_schema' ] as $action ) {
			add_action( 'admin_post_' . $action, [ self::class, 'handle_' . substr( $action, 6 ) ] );
		}
	}

	public static function menu(): void {
		add_submenu_page( 'momos', __( 'Redirects & 404 log', 'momos-core' ), __( 'Redirections', 'momos-core' ), self::CAP, self::SLUG, [ self::class, 'render' ] );
	}

	public static function url( string $tab = 'redirects', array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG, 'tab' => $tab ], $args ), admin_url( 'admin.php' ) );
	}

	// ------------------------------------------------------------------ handlers

	private static function guard( string $action, string $cap = self::CAP ): void {
		if ( ! current_user_can( $cap ) ) {
			momos_audit( 'access.denied', 'redirect', null, null, [ 'action' => $action ] );
			wp_die( esc_html__( 'You are not allowed to do this.', 'momos-core' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	/** @param array<string,string> $errors */
	private static function back( string $tab, string $notice, array $errors = [], array $form = [], array $args = [] ): void {
		if ( $errors || $form ) {
			set_transient( 'momos_seo_form_' . get_current_user_id(), [ 'errors' => $errors, 'form' => $form ], 120 );
		}
		wp_safe_redirect( self::url( $tab, array_merge( [ 'momos_notice' => $notice ], $args ) ) );
		exit;
	}

	public static function handle_redirect_save(): void {
		self::guard( 'momos_redirect_save' );
		$id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '';
		$form = [
			'from'   => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from'] ) ) : '',
			'to'     => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['to'] ) ) : '',
			'status' => isset( $_POST['status'] ) ? (int) $_POST['status'] : 301,
			'owner'  => isset( $_POST['owner'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['owner'] ) ) : '',
			'source' => 'manual',
		];
		$res  = RedirectStore::save( $form, $id );
		if ( $res['errors'] ) {
			self::back( 'redirects', 'error', $res['errors'], $form + [ 'id' => $id ], '' !== $id ? [ 'edit' => $id ] : [] );
		}
		$action = null === $res['before'] ? 'redirect.created' : 'redirect.updated';
		momos_audit( $action, 'redirect', null, null, [ 'id' => $res['rule']['id'], 'before' => RedirectStore::audit_view( $res['before'] ), 'after' => RedirectStore::audit_view( $res['rule'] ) ] );
		self::back( 'redirects', 'saved' );
	}

	public static function handle_redirect_delete(): void {
		self::guard( 'momos_redirect_delete' );
		$id   = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( (string) $_POST['id'] ) ) : '';
		$rule = RedirectStore::delete( $id );
		if ( null === $rule ) {
			self::back( 'redirects', 'missing' );
		}
		momos_audit( 'redirect.deleted', 'redirect', null, null, [ 'id' => $id, 'before' => RedirectStore::audit_view( $rule ) ] );
		self::back( 'redirects', 'deleted' );
	}

	/** "Verify all": HEAD check of every 301/302 target (must answer 200 without a further redirect). */
	public static function handle_redirect_verify(): void {
		self::guard( 'momos_redirect_verify' );
		$results = self::verify( RedirectStore::all() );
		RedirectStore::set_verified( $results );
		self::back( 'redirects', 'verified', [], [], [ 'ok' => count( array_filter( $results ) ), 'bad' => count( $results ) - count( array_filter( $results ) ) ] );
	}

	/**
	 * @param list<array<string,mixed>> $rules
	 * @return array<string,bool> id => target answered 200
	 */
	public static function verify( array $rules ): array {
		$out = [];
		foreach ( array_slice( $rules, 0, 200 ) as $r ) {
			if ( 410 === (int) $r['status'] ) {
				$out[ (string) $r['id'] ] = true;
				continue;
			}
			$res                      = wp_remote_head( RedirectStore::target_url( $r ), [ 'timeout' => 5, 'redirection' => 0, 'sslverify' => \Momos\Kernel\Env::is_production() ] );
			$out[ (string) $r['id'] ] = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
		}
		return $out;
	}

	public static function handle_404_review(): void {
		self::guard( 'momos_404_review' );
		$week    = isset( $_POST['week_of'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['week_of'] ) ) : '';
		$actions = isset( $_POST['actions'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['actions'] ) ) : '';
		$errors  = NotFoundLog::add_review( $week, $actions, get_current_user_id() );
		if ( $errors ) {
			self::back( 'reviews', 'error', $errors, [ 'week_of' => $week, 'actions' => $actions ] );
		}
		momos_audit( 'settings.updated', 'seo_404_review', null, null, [ 'week_of' => $week ] );
		self::back( 'reviews', 'saved' );
	}

	public static function handle_404_clear(): void {
		self::guard( 'momos_404_clear' );
		NotFoundLog::clear();
		momos_audit( 'settings.updated', 'seo_404_log', null, null, [ 'action' => 'cleared' ] );
		self::back( 'notfound', 'cleared' );
	}

	public static function handle_seo_settings(): void {
		self::guard( 'momos_seo_settings', 'manage_options' );
		$res = SeoSettings::save(
			[
				'faq_schema'         => ! empty( $_POST['faq_schema'] ),
				'log_retention_days' => isset( $_POST['log_retention_days'] ) ? (int) $_POST['log_retention_days'] : 90,
			]
		);
		momos_audit( 'settings.updated', 'seo_settings', null, null, $res );
		self::back( 'settings', 'saved' );
	}

	/** Structured-data values (FR-SEO-006(b), Q-415): SA and HO. */
	public static function handle_seo_schema(): void {
		self::guard( 'momos_seo_schema' );
		$cuisine = [];
		$posted  = isset( $_POST['serves_cuisine'] ) && is_array( $_POST['serves_cuisine'] ) ? wp_unslash( $_POST['serves_cuisine'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per language in SeoSettings::save_schema().
		foreach ( \Momos\Kernel\I18n\Lang::codes() as $lang ) {
			$cuisine[ $lang ] = isset( $posted[ $lang ] ) && is_string( $posted[ $lang ] ) ? $posted[ $lang ] : '';
		}
		$res = SeoSettings::save_schema(
			[
				'serves_cuisine' => $cuisine,
				'price_range'    => isset( $_POST['price_range'] ) && is_string( $_POST['price_range'] ) ? wp_unslash( $_POST['price_range'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in SeoSettings::save_schema().
			]
		);
		if ( $res['errors'] ) {
			self::back( 'schema', 'error', $res['errors'], $res['after'] );
		}
		momos_audit( 'settings.updated', 'seo_schema', null, null, [ 'before' => $res['before'], 'after' => $res['after'] ] );
		do_action( 'momos/cache/purge', [ 'momo-global' ] ); // Branch pages carry the Restaurant JSON-LD.
		self::back( 'schema', 'saved' );
	}

	// ------------------------------------------------------------------ screen

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			momos_audit( 'access.denied', 'redirect', null, null, [ 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You are not allowed to view this page.', 'momos-core' ), '', [ 'response' => 403 ] );
		}
		$tabs = [
			'redirects' => __( 'Redirects', 'momos-core' ),
			'notfound'  => __( '404 log', 'momos-core' ),
			'reviews'   => __( 'Weekly review', 'momos-core' ),
			'schema'    => __( 'Structured data', 'momos-core' ),
		];
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['settings'] = __( 'Settings', 'momos-core' );
		}
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'redirects'; // phpcs:ignore WordPress.Security.NonceVerification
		$tab   = isset( $tabs[ $tab ] ) ? $tab : 'redirects';
		$state = get_transient( 'momos_seo_form_' . get_current_user_id() );
		delete_transient( 'momos_seo_form_' . get_current_user_id() );
		$state = is_array( $state ) ? $state : [ 'errors' => [], 'form' => [] ];

		echo '<div class="wrap"><h1>' . esc_html__( 'Redirects & 404 log', 'momos-core' ) . '</h1>';
		self::notice( $state['errors'] );
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'SEO sections', 'momos-core' ) . '">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="%s" class="nav-tab%s"%s>%s</a>', esc_url( self::url( $k ) ), $k === $tab ? ' nav-tab-active' : '', $k === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';
		match ( $tab ) {
			'notfound' => self::tab_404(),
			'reviews' => self::tab_reviews( $state['form'] ),
			'settings' => self::tab_settings(),
			'schema' => self::tab_schema( $state['form'] ),
			default => self::tab_redirects( $state['form'] ),
		};
		echo '</div>';
	}

	/** @param array<string,string> $errors */
	private static function notice( array $errors ): void {
		$code = isset( $_GET['momos_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['momos_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msg  = [
			'saved'   => __( 'Saved.', 'momos-core' ),
			'deleted' => __( 'Redirect deleted.', 'momos-core' ),
			'missing' => __( 'This redirect no longer exists.', 'momos-core' ),
			'cleared' => __( '404 log cleared.', 'momos-core' ),
		];
		if ( 'verified' === $code ) {
			$ok  = isset( $_GET['ok'] ) ? (int) $_GET['ok'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$bad = isset( $_GET['bad'] ) ? (int) $_GET['bad'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: 1: number of working targets, 2: number of failing targets */
			$msg['verified'] = sprintf( __( 'Verification finished: %1$d OK, %2$d failing.', 'momos-core' ), $ok, $bad );
		}
		if ( $errors ) {
			echo '<div class="notice notice-error" role="alert"><p>' . esc_html__( 'Please correct the following:', 'momos-core' ) . '</p><ul>';
			foreach ( $errors as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul></div>';
		} elseif ( isset( $msg[ $code ] ) ) {
			echo '<div class="notice notice-success is-dismissible" role="status"><p>' . esc_html( $msg[ $code ] ) . '</p></div>';
		}
	}

	private static function jst( string $mysql_utc ): string {
		$t = Clock::from_mysql_utc( $mysql_utc );
		return $t ? $t->setTimezone( Clock::tz() )->format( 'Y-m-d H:i' ) : '—';
	}

	/** @param array<string,mixed> $form */
	private static function tab_redirects( array $form ): void {
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( (string) $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$editing = '' !== $edit_id ? RedirectStore::get( $edit_id ) : null;
		$prefill = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$v       = $form ?: ( $editing ?? [ 'from' => $prefill, 'to' => '', 'status' => 301, 'owner' => '' ] );
		$status  = (int) ( $v['status'] ?? 301 );

		echo '<h2>' . esc_html( null !== $editing ? __( 'Edit redirect', 'momos-core' ) : __( 'Add redirect', 'momos-core' ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Exact paths only (no patterns). The target must be the final URL of this site — chains and loops are refused.', 'momos-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'momos_redirect_save' );
		echo '<input type="hidden" name="action" value="momos_redirect_save"><input type="hidden" name="id" value="' . esc_attr( (string) ( null !== $editing ? $editing['id'] : ( $form['id'] ?? '' ) ) ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row"><label for="momo-r-from">%s</label></th><td><input id="momo-r-from" class="regular-text code" name="from" value="%s" required maxlength="500" dir="ltr" placeholder="/old-page/"></td></tr>', esc_html__( 'Old path', 'momos-core' ), esc_attr( (string) ( $v['from'] ?? '' ) ) );
		printf( '<tr><th scope="row"><label for="momo-r-to">%s</label></th><td><input id="momo-r-to" class="regular-text code" name="to" value="%s" maxlength="500" dir="ltr" placeholder="/menu/"><p class="description">%s</p></td></tr>', esc_html__( 'New URL', 'momos-core' ), esc_attr( (string) ( $v['to'] ?? '' ) ), esc_html__( 'Leave empty for 410 Gone.', 'momos-core' ) );
		echo '<tr><th scope="row"><label for="momo-r-status">' . esc_html__( 'Type', 'momos-core' ) . '</label></th><td><select id="momo-r-status" name="status">';
		foreach ( [ 301 => __( '301 Moved permanently', 'momos-core' ), 302 => __( '302 Temporary', 'momos-core' ), 410 => __( '410 Gone', 'momos-core' ) ] as $code => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $code, selected( $status, $code, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';
		printf( '<tr><th scope="row"><label for="momo-r-owner">%s</label></th><td><input id="momo-r-owner" class="regular-text" name="owner" value="%s" maxlength="100"></td></tr>', esc_html__( 'Owner', 'momos-core' ), esc_attr( (string) ( $v['owner'] ?? '' ) ) );
		echo '</tbody></table>';
		submit_button( null !== $editing ? __( 'Update redirect', 'momos-core' ) : __( 'Add redirect', 'momos-core' ) );
		echo '</form>';

		$rows = RedirectStore::all();
		echo '<h2>' . esc_html__( 'Redirects', 'momos-core' ) . ' <span class="count">(' . count( $rows ) . ')</span></h2>';
		if ( $rows ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'momos_redirect_verify' );
			echo '<input type="hidden" name="action" value="momos_redirect_verify">';
			submit_button( __( 'Verify all', 'momos-core' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No redirects yet.', 'momos-core' ) . '</p>';
			return;
		}
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'From', 'momos-core' ), __( 'To', 'momos-core' ), __( 'Type', 'momos-core' ), __( 'Source', 'momos-core' ), __( 'Owner', 'momos-core' ), __( 'Verified', 'momos-core' ), __( 'Created (JST)', 'momos-core' ), __( 'Actions', 'momos-core' ) ] as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td><code dir="ltr">' . esc_html( (string) $r['from'] ) . '</code></td>';
			echo '<td>' . ( '' !== (string) $r['to'] ? '<code dir="ltr">' . esc_html( (string) $r['to'] ) . '</code>' : '—' ) . '</td>';
			echo '<td>' . (int) $r['status'] . '</td><td>' . esc_html( (string) $r['source'] ) . '</td><td>' . esc_html( (string) $r['owner'] ) . '</td>';
			echo '<td>' . ( ! empty( $r['verified'] ) ? esc_html__( 'Yes', 'momos-core' ) : '<span style="color:#b32d2e">' . esc_html__( 'No', 'momos-core' ) . '</span>' ) . '</td>';
			echo '<td>' . esc_html( self::jst( (string) $r['created_at'] ) ) . '</td><td>';
			echo '<a href="' . esc_url( self::url( 'redirects', [ 'edit' => $r['id'] ] ) ) . '">' . esc_html__( 'Edit', 'momos-core' ) . '</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( 'momos_redirect_delete' );
			echo '<input type="hidden" name="action" value="momos_redirect_delete"><input type="hidden" name="id" value="' . esc_attr( (string) $r['id'] ) . '">';
			echo '<button type="submit" class="button-link button-link-delete" onclick="return confirm(this.dataset.c)" data-c="' . esc_attr__( 'Delete this redirect?', 'momos-core' ) . '">' . esc_html__( 'Delete', 'momos-core' ) . '</button></form>';
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function tab_404(): void {
		$rows = NotFoundLog::top( 300 );
		echo '<p class="description">' . esc_html__( 'Aggregated per path and language. No IP address, user agent or full referrer is stored.', 'momos-core' ) . '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No 404 errors recorded.', 'momos-core' ) . '</p>';
			return;
		}
		echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
		foreach ( [ __( 'Path', 'momos-core' ), __( 'Language', 'momos-core' ), __( 'Referrer host', 'momos-core' ), __( 'Hits', 'momos-core' ), __( 'First seen (JST)', 'momos-core' ), __( 'Last seen (JST)', 'momos-core' ), __( 'Actions', 'momos-core' ) ] as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td><code dir="ltr">%s</code></td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td><a href="%s">%s</a></td></tr>',
				esc_html( (string) $r['path'] ),
				esc_html( (string) $r['lang'] ),
				esc_html( '' !== (string) $r['referrer_host'] ? (string) $r['referrer_host'] : '—' ),
				(int) $r['hits'],
				esc_html( self::jst( (string) $r['first_seen_utc'] ) ),
				esc_html( self::jst( (string) $r['last_seen_utc'] ) ),
				esc_url( self::url( 'redirects', [ 'from' => (string) $r['path'] ] ) ),
				esc_html__( 'Create redirect', 'momos-core' )
			);
		}
		echo '</tbody></table></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'momos_404_clear' );
		echo '<input type="hidden" name="action" value="momos_404_clear">';
		submit_button( __( 'Clear 404 log', 'momos-core' ), 'delete', 'submit', true, [ 'onclick' => 'return confirm(this.dataset.c)', 'data-c' => __( 'Clear the 404 log?', 'momos-core' ) ] );
		echo '</form>';
	}

	/** @param array<string,mixed> $form */
	private static function tab_reviews( array $form ): void {
		$reviews = NotFoundLog::reviews();
		/* translators: %d: number of recorded weekly reviews */
		echo '<p>' . esc_html( sprintf( __( '%d of 8 weekly reviews after launch recorded.', 'momos-core' ), min( 8, count( $reviews ) ) ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'momos_404_review' );
		echo '<input type="hidden" name="action" value="momos_404_review"><table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row"><label for="momo-rv-week">%s</label></th><td><input type="date" id="momo-rv-week" name="week_of" required value="%s" max="%s"></td></tr>', esc_html__( 'Week of', 'momos-core' ), esc_attr( (string) ( $form['week_of'] ?? Clock::jst()->format( 'Y-m-d' ) ) ), esc_attr( Clock::jst()->format( 'Y-m-d' ) ) );
		printf( '<tr><th scope="row"><label for="momo-rv-actions">%s</label></th><td><textarea id="momo-rv-actions" name="actions" rows="3" class="large-text" maxlength="500">%s</textarea></td></tr>', esc_html__( 'Actions taken', 'momos-core' ), esc_textarea( (string) ( $form['actions'] ?? '' ) ) );
		echo '</tbody></table>';
		submit_button( __( 'Record weekly review', 'momos-core' ) );
		echo '</form>';
		if ( $reviews ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Week of', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Reviewed by', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Recorded (JST)', 'momos-core' ) . '</th><th scope="col">' . esc_html__( 'Actions taken', 'momos-core' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $reviews ) as $r ) {
				$u = get_userdata( (int) $r['reviewed_by'] );
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td dir="auto">%s</td></tr>', esc_html( (string) $r['week_of'] ), esc_html( $u ? $u->display_name : '—' ), esc_html( self::jst( (string) $r['reviewed_at'] ) ), esc_html( (string) $r['actions'] ) );
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Restaurant JSON-LD values (FR-SEO-006(b), Q-415): one cuisine text per language, one price range.
	 *
	 * @param array<string,mixed> $form Values of a refused save (shown again).
	 */
	private static function tab_schema( array $form ): void {
		$v = $form ? $form + [ 'serves_cuisine' => [], 'price_range' => '' ] : SeoSettings::schema();
		echo '<p class="description">' . esc_html__( 'Restaurant structured data (JSON-LD) on every branch page. Each value is output only when filled in; leave a field empty to omit it. Values must match what the site shows and be under 100 characters.', 'momos-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'momos_seo_schema' );
		echo '<input type="hidden" name="action" value="momos_seo_schema"><table class="form-table" role="presentation"><tbody>';
		foreach ( \Momos\Kernel\I18n\Lang::ALL as $code => $l ) {
			printf(
				'<tr><th scope="row"><label for="momo-seo-cuisine-%1$s">%2$s</label></th><td><input type="text" id="momo-seo-cuisine-%1$s" class="regular-text" name="serves_cuisine[%1$s]" value="%3$s" maxlength="%4$d" lang="%5$s" dir="%6$s"></td></tr>',
				esc_attr( $code ),
				/* translators: %s: language name, e.g. Japanese */
				esc_html( sprintf( __( 'Cuisine (servesCuisine) — %s', 'momos-core' ), $l['english'] ) ),
				esc_attr( (string) ( $v['serves_cuisine'][ $code ] ?? '' ) ),
				(int) SeoSettings::SCHEMA_MAX_CHARS,
				esc_attr( $l['html'] ),
				esc_attr( $l['dir'] )
			);
		}
		printf(
			'<tr><th scope="row"><label for="momo-seo-price">%1$s</label></th><td><input type="text" id="momo-seo-price" class="regular-text" name="price_range" value="%2$s" maxlength="%3$d" dir="auto" aria-describedby="momo-seo-price-help"><p class="description" id="momo-seo-price-help">%4$s</p></td></tr>',
			esc_html__( 'Price range (priceRange)', 'momos-core' ),
			esc_attr( (string) ( $v['price_range'] ?? '' ) ),
			(int) SeoSettings::SCHEMA_MAX_CHARS,
			esc_html__( 'Same value for every language and branch.', 'momos-core' )
		);
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}

	private static function tab_settings(): void {
		$s   = SeoSettings::all();
		$gtm = SeoSettings::gtm_id();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'momos_seo_settings' );
		echo '<input type="hidden" name="action" value="momos_seo_settings"><table class="form-table" role="presentation"><tbody>';
		printf( '<tr><th scope="row">%s</th><td><label><input type="checkbox" name="faq_schema" value="1"%s> %s</label></td></tr>', esc_html__( 'FAQ structured data', 'momos-core' ), checked( $s['faq_schema'], true, false ), esc_html__( 'Output FAQPage JSON-LD for the visible questions on the FAQ and Halal pages', 'momos-core' ) );
		printf( '<tr><th scope="row"><label for="momo-seo-ret">%s</label></th><td><input type="number" id="momo-seo-ret" name="log_retention_days" min="7" max="730" value="%d"> %s</td></tr>', esc_html__( '404 log retention', 'momos-core' ), (int) $s['log_retention_days'], esc_html__( 'days', 'momos-core' ) );
		printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html__( 'Google Tag Manager', 'momos-core' ), '' !== $gtm ? '<code>' . esc_html( $gtm ) . '</code>' : esc_html__( 'Not configured — no tags are loaded.', 'momos-core' ) );
		echo '</tbody></table>';
		submit_button();
		echo '</form>';
	}
}
