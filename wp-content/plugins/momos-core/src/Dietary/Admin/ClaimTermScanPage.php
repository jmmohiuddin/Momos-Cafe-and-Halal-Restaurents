<?php
declare(strict_types=1);

namespace Momos\Dietary\Admin;

use Momos\Dietary\Guard\ClaimFieldGuard;
use Momos\Dietary\Guard\WordingGuard;
use Momos\Kernel\I18n\Lang;

/**
 * Claim-term scan (FR-DIET-009 Should, US-DIET-007, TC-DIET-021, spec §3.17): published posts, pages,
 * offers, notices, dishes and branches — titles, content, excerpts, dish short descriptions, every
 * `_momos_i18n_{lang}` translation — plus image file names / alt texts, in all languages, for the master
 * term list and the base terms. Register-driven statements are not stored in content, so they never
 * appear. Access: SA, HO, CAP-DCA (others 403 + `access.denied`). No CSV export.
 */
final class ClaimTermScanPage {
	public const SLUG       = 'momo-claim-scan';
	public const POST_TYPES = [ 'post', 'page', 'momo_offer', 'momo_notice', 'momo_dish', 'momo_branch' ];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ], 22 );
	}

	public static function can_access( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		return user_can( $user_id, ClaimFieldGuard::CAP ) || user_can( $user_id, 'momo_manage_brand' );
	}

	public static function menu(): void {
		add_submenu_page( 'momos', __( 'Claim-term scan', 'momos-core' ), __( 'Claim-term scan', 'momos-core' ), 'read', self::SLUG, [ self::class, 'render' ] );
		if ( ! self::can_access() ) {
			remove_submenu_page( 'momos', self::SLUG ); // Hidden in the menu; direct access is still checked below.
		}
	}

	public static function render(): void {
		if ( ! self::can_access() ) {
			momos_audit( 'access.denied', 'claim_scan', null, null, [ 'reason' => 'forbidden', 'screen' => self::SLUG ] );
			wp_die( esc_html__( 'You do not have permission to view this page.', 'momos-core' ), 403 );
		}
		$results = isset( $_GET['run'] ) && check_admin_referer( 'momos_claim_scan', '_wpnonce' ) ? self::scan() : null; // phpcs:ignore
		include MOMOS_CORE_DIR . '/templates/admin/dietary-claim-scan.php';
	}

	/**
	 * @return list<array{object_type:string,id:int,title:string,lang:string,field:string,term:string,edit_link:string}>
	 */
	public static function scan(): array {
		$hits = [];
		$ids  = get_posts( [ 'post_type' => self::POST_TYPES, 'post_status' => 'publish', 'posts_per_page' => 3000, 'fields' => 'ids', 'no_found_rows' => true ] );
		update_postmeta_cache( $ids );
		foreach ( $ids as $id ) {
			$p      = get_post( (int) $id );
			$fields = [
				[ Lang::SOURCE, 'title', (string) $p->post_title ],
				[ Lang::SOURCE, 'content', (string) $p->post_content ],
				[ Lang::SOURCE, 'excerpt', (string) $p->post_excerpt ],
				[ Lang::SOURCE, 'momo_short_description', (string) get_post_meta( $p->ID, 'momo_short_description', true ) ],
			];
			foreach ( Lang::targets() as $lang ) {
				$raw  = get_post_meta( $p->ID, '_momos_i18n_' . $lang, true );
				$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
				foreach ( is_array( $data ) ? $data : [] as $field => $value ) {
					$fields[] = [ $lang, (string) $field, (string) $value ];
				}
			}
			foreach ( $fields as [ $lang, $field, $text ] ) {
				foreach ( self::terms_in( $text ) as $term ) {
					$hits[] = [ 'object_type' => $p->post_type, 'id' => $p->ID, 'title' => $p->post_title, 'lang' => $lang, 'field' => $field, 'term' => $term, 'edit_link' => (string) get_edit_post_link( $p->ID, 'raw' ) ];
				}
			}
		}
		// Images: file names and alt texts containing "halal"/"seal" (register marks are excluded).
		$images = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 5000, 'fields' => 'ids', 'no_found_rows' => true ] );
		$words  = (array) ( WordingGuard::config()['image_terms'] ?? [] );
		foreach ( $images as $aid ) {
			if ( get_post_meta( (int) $aid, 'momo_protected_mark', true ) ) {
				continue;
			}
			foreach ( [ 'file' => basename( (string) get_post_meta( (int) $aid, '_wp_attached_file', true ) ), 'alt' => (string) get_post_meta( (int) $aid, '_wp_attachment_image_alt', true ) ] as $field => $value ) {
				foreach ( $words as $w ) {
					if ( '' !== $value && false !== mb_stripos( $value, (string) $w ) ) {
						$hits[] = [ 'object_type' => 'attachment', 'id' => (int) $aid, 'title' => get_the_title( (int) $aid ), 'lang' => '—', 'field' => $field, 'term' => (string) $w, 'edit_link' => (string) get_edit_post_link( (int) $aid, 'raw' ) ];
					}
				}
			}
		}
		return $hits;
	}

	/** @return list<string> matched terms (master list + base terms), unique */
	public static function terms_in( string $text ): array {
		if ( '' === trim( $text ) ) {
			return [];
		}
		return array_values( array_unique( array_merge( array_column( WordingGuard::check( $text ), 'term' ), WordingGuard::base_terms( $text ) ) ) );
	}
}
