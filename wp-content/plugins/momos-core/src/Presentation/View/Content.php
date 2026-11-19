<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;
use Momos\Presentation\Format\DateFormatter;

/**
 * Editorial content for corporate screens (content-strings.md §8): page title/body/lead in the URL
 * language, legal page meta + table of contents (C15–C18), FAQ items (C13, `momo_faq_items`), news list
 * (C14) and gallery photos (C22). Untranslated items are never mixed in (DEC-027): they read as ''.
 */
final class Content {
	public const NEWS_PER_PAGE = 10;
	public const FAQ_GROUPS    = [ 'reservations', 'halal_dietary', 'allergens', 'branches_access', 'payments' ];

	public static function title( int $id, ?string $lang = null ): string {
		return Translations::post( $id, 'title', $lang ?? Locale::current() );
	}

	public static function excerpt( int $id, ?string $lang = null ): string {
		return trim( wp_strip_all_tags( Translations::post( $id, 'excerpt', $lang ?? Locale::current() ) ) );
	}

	/** Body HTML through the core content filters (blocks, kses applied on save). */
	public static function body( int $id, ?string $lang = null ): string {
		$raw = Translations::post( $id, 'content', $lang ?? Locale::current() );
		return '' === trim( $raw ) ? '' : (string) apply_filters( 'the_content', $raw ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
	}

	/**
	 * Legal page meta (FR-CORP-006): version label + last updated (written out) + "Japanese is authoritative".
	 *
	 * @return array{version:string,updated:string,ja_note:bool}
	 */
	public static function legal_meta( int $id, ?string $lang = null ): array {
		$lang    = $lang ?? Locale::current();
		$date    = (string) get_post_meta( $id, 'momo_legal_last_updated', true );
		$updated = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? DateFormatter::long( $date, $lang ) : '';
		return [ 'version' => (string) get_post_meta( $id, 'momo_legal_version', true ), 'updated' => $updated, 'ja_note' => Lang::SOURCE !== $lang ];
	}

	/**
	 * Add stable ids to H2 headings and return the TOC (shown when > 5 headings, content-strings §3.4).
	 *
	 * @return array{html:string,toc:list<array{id:string,text:string}>}
	 */
	public static function with_toc( string $html ): array {
		$toc = [];
		$n   = 0;
		$out = (string) preg_replace_callback(
			'#<h2([^>]*)>(.*?)</h2>#is',
			static function ( array $m ) use ( &$toc, &$n ): string {
				++$n;
				$text = trim( wp_strip_all_tags( $m[2] ) );
				if ( preg_match( '/\sid="([^"]+)"/', $m[1], $idm ) ) {
					$id    = $idm[1];
					$attrs = $m[1];
				} else {
					$id    = 'section-' . $n;
					$attrs = $m[1] . ' id="' . $id . '"';
				}
				$toc[] = [ 'id' => $id, 'text' => $text ];
				return '<h2' . $attrs . '>' . $m[2] . '</h2>';
			},
			$html
		);
		return [ 'html' => $out, 'toc' => count( $toc ) > 5 ? $toc : [] ];
	}

	/**
	 * FAQ items of a page grouped in the fixed group order; question/answer from the approved translation
	 * (items without text in the language are hidden; empty groups hidden).
	 *
	 * @return array<string, list<array{id:string,question:string,answer:string}>>
	 */
	public static function faq( int $page_id, ?string $lang = null ): array {
		$lang  = $lang ?? Locale::current();
		$items = self::decode( get_post_meta( $page_id, 'momo_faq_items', true ) );
		$tr    = [];
		if ( Lang::SOURCE !== $lang ) {
			foreach ( self::decode( Translations::post( $page_id, 'faq_items', $lang ) ) as $t ) {
				if ( isset( $t['id'] ) ) {
					$tr[ (string) $t['id'] ] = $t;
				}
			}
		}
		usort( $items, static fn( $a, $b ) => (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 ) );
		$out = [];
		foreach ( $items as $it ) {
			$id    = sanitize_title( (string) ( $it['id'] ?? '' ) );
			$group = (string) ( $it['group'] ?? '' );
			if ( '' === $id || ! in_array( $group, self::FAQ_GROUPS, true ) ) {
				continue;
			}
			$src = Lang::SOURCE === $lang ? $it : ( $tr[ $id ] ?? [] );
			$q   = trim( wp_strip_all_tags( (string) ( $src['question'] ?? '' ) ) );
			$a   = trim( (string) ( $src['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue; // Not translated: hidden, never mixed.
			}
			$out[ $group ][] = [ 'id' => $id, 'question' => $q, 'answer' => wp_kses_post( $a ) ];
		}
		$ordered = [];
		foreach ( self::FAQ_GROUPS as $g ) {
			if ( ! empty( $out[ $g ] ) ) {
				$ordered[ $g ] = $out[ $g ];
			}
		}
		return $ordered;
	}

	/** @return list<array<string,mixed>> */
	private static function decode( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/**
	 * News list (C14): native posts visible in the language, newest first, 10 per page.
	 *
	 * @return array{items:list<array{id:int,title:string,excerpt:string,date_iso:string,date:string,url:string,image_id:int}>,pages:int}
	 */
	public static function news( int $page, ?string $lang = null ): array {
		$lang  = $lang ?? Locale::current();
		$q     = new \WP_Query(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => self::NEWS_PER_PAGE,
				'paged'               => max( 1, $page ),
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'meta_query'          => Translations::meta_query( $lang ), // phpcs:ignore
			]
		);
		$items = [];
		foreach ( $q->posts as $p ) {
			$title = Translations::post( $p, 'title', $lang );
			if ( '' === $title ) {
				continue;
			}
			$items[] = [
				'id'       => (int) $p->ID,
				'title'    => $title,
				'excerpt'  => trim( wp_strip_all_tags( Translations::post( $p, 'excerpt', $lang ) ) ),
				'date_iso' => (string) get_post_time( 'c', true, $p ),
				'date'     => DateFormatter::long( new \DateTimeImmutable( (string) get_post_time( 'c', true, $p ) ), $lang ),
				'url'      => Url::localize( (string) get_permalink( $p ), $lang ),
				'image_id' => (int) get_post_thumbnail_id( $p ),
			];
		}
		return [ 'items' => $items, 'pages' => (int) $q->max_num_pages ];
	}

	/**
	 * Gallery photos (C22, [Proposed] keys): attachments with `momo_gallery_include = 1`, caption and alt in
	 * the language, never demo-flagged images (DEC-047). Filter keys: branch code / category slug.
	 *
	 * @return list<array{id:int,caption:string,alt:string,branch:string,branch_name:string,category:string,category_name:string}>
	 */
	public static function gallery( ?string $lang = null, int $limit = 60 ): array {
		$lang = $lang ?? Locale::current();
		$ids  = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => [ 'menu_order' => 'ASC', 'date' => 'DESC' ],
				'meta_query'     => array_merge( [ [ 'key' => 'momo_gallery_include', 'value' => '1' ] ], Translations::meta_query( $lang ) ), // phpcs:ignore
			]
		);
		$out  = [];
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( '1' === (string) get_post_meta( $id, 'momo_image_illustrative', true ) ) {
				continue;
			}
			$caption = Lang::SOURCE === $lang ? (string) wp_get_attachment_caption( $id ) : Translations::post( $id, 'caption', $lang );
			$b       = (int) get_post_meta( $id, 'momo_media_branch', true );
			$c       = (int) get_post_meta( $id, 'momo_media_category', true );
			$bname   = $b && class_exists( \Momos\Branch\Repository\BranchRepository::class ) && \Momos\Branch\Repository\BranchRepository::is_public( $b, $lang ) ? \Momos\Branch\Repository\BranchRepository::name( $b, $lang ) : '';
			$cterm   = $c ? get_term( $c, 'momo_menu_category' ) : null;
			$cname   = $cterm instanceof \WP_Term ? Translations::term( $cterm, 'name', $lang ) : '';
			$out[]   = [
				'id'            => $id,
				'caption'       => trim( $caption ),
				'alt'           => MenuView::alt( $id, $lang ),
				'branch'        => '' !== $bname ? (string) get_post_meta( $b, 'momo_branch_code', true ) : '',
				'branch_name'   => $bname,
				'category'      => '' !== $cname ? (string) $cterm->slug : '',
				'category_name' => $cname,
			];
		}
		return $out;
	}
}
