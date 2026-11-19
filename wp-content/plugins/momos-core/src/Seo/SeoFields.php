<?php
declare(strict_types=1);

namespace Momos\Seo;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\I18n\Translations;

/**
 * SEO meta box (FR-SEO-005, i18n-seo-analytics.md §3.2, §14.5) on pages, branches, dishes, news posts
 * and menu categories. JA values live in `momo_seo_*` / `momo_og_*` meta; translations are the
 * translatable keys `seo_title`, `seo_description`, `og_title`, `og_description` edited per language in
 * the translation editor (approved only are public). Edit right: `momo_edit_seo_meta` + edit right on
 * the object. Length guidance is a live, non-blocking warning; hard limits are enforced on save.
 */
final class SeoFields {
	public const POST_TYPES = [ 'page', 'momo_branch', 'momo_dish', 'post' ];
	public const TAXONOMY   = 'momo_menu_category';

	/** Recommended maximum lengths (warning only): [ja full-width, other]. */
	public const TITLE_WARN = [ 32, 60 ];
	public const DESC_WARN  = [ 120, 160 ];

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_groups' ], 8 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'counter_script' ], 20 );
	}

	/** @return list<array<string,mixed>> */
	public static function fields( bool $term = false ): array {
		$cap = $term ? [ 'momo_edit_seo_meta' ] : [ 'edit_post', 'momo_edit_seo_meta' ];
		return [
			[ 'name' => 'momo_seo_title', 'label' => __( 'SEO title', 'momos-core' ), 'type' => 'text', 'maxlength' => 120, 'edit_cap' => $cap, 'help' => __( 'Leave empty to use the default title template. Recommended: up to 32 full-width characters (Japanese).', 'momos-core' ) ],
			[ 'name' => 'momo_seo_description', 'label' => __( 'Meta description', 'momos-core' ), 'type' => 'textarea', 'rows' => 3, 'maxlength' => 300, 'edit_cap' => $cap, 'validate' => [ self::class, 'validate_description' ], 'help' => __( 'Leave empty to use the summary. Recommended: up to 120 full-width characters. No prices or promotions.', 'momos-core' ) ],
			[ 'name' => 'momo_og_title', 'label' => __( 'Social share title', 'momos-core' ), 'type' => 'text', 'maxlength' => 120, 'edit_cap' => $cap ],
			[ 'name' => 'momo_og_description', 'label' => __( 'Social share description', 'momos-core' ), 'type' => 'textarea', 'rows' => 2, 'maxlength' => 300, 'edit_cap' => $cap, 'validate' => [ self::class, 'validate_description' ] ],
			[ 'name' => 'momo_og_image', 'label' => __( 'Social share image', 'momos-core' ), 'type' => 'image', 'edit_cap' => $cap, 'validate' => [ self::class, 'validate_image' ], 'help' => __( 'JPEG, PNG or WebP. Defaults to the featured image, then the logo.', 'momos-core' ) ],
		];
	}

	public static function register_groups(): void {
		foreach ( self::POST_TYPES as $pt ) {
			FieldRegistry::group(
				[
					'key'       => 'momo_seo_' . $pt,
					'title'     => __( 'SEO', 'momos-core' ),
					'post_type' => $pt,
					'context'   => 'normal',
					'priority'  => 'low',
					'order'     => 90,
					'fields'    => self::fields(),
				]
			);
			Translations::register( 'post:' . $pt, self::translatable() );
		}
		FieldRegistry::group(
			[
				'key'      => 'momo_seo_term',
				'title'    => __( 'SEO', 'momos-core' ),
				'taxonomy' => self::TAXONOMY,
				'order'    => 90,
				'fields'   => self::fields( true ),
			]
		);
		Translations::register( 'term:' . self::TAXONOMY, self::translatable() );
	}

	/** @return array<string, array{label:string,type:string,source:string,max:int}> */
	public static function translatable(): array {
		return [
			'seo_title'       => [ 'label' => 'SEO title', 'type' => 'text', 'source' => 'meta:momo_seo_title', 'max' => 120 ],
			'seo_description' => [ 'label' => 'Meta description', 'type' => 'textarea', 'source' => 'meta:momo_seo_description', 'max' => 300 ],
			'og_title'        => [ 'label' => 'Social share title', 'type' => 'text', 'source' => 'meta:momo_og_title', 'max' => 120 ],
			'og_description'  => [ 'label' => 'Social share description', 'type' => 'textarea', 'source' => 'meta:momo_og_description', 'max' => 300 ],
		];
	}

	/** No prices or promotions in descriptions (Doc 06 §1.3). */
	public static function validate_description( mixed $value ): ?string {
		$v = (string) $value;
		if ( '' !== $v && preg_match( '/[¥￥]\s*\d|\d[\d,]*\s*円|\bJPY\b|\d+\s*%\s*(off|オフ|引き)|割引/iu', $v ) ) {
			return __( 'Descriptions must not contain prices or promotions.', 'momos-core' );
		}
		return null;
	}

	/** JPEG/PNG/WebP only; no illustrative image on branch or About pages (DEC-047). */
	public static function validate_image( mixed $value, int|string $object_id = 0 ): ?string {
		$id = (int) $value;
		if ( $id <= 0 ) {
			return null;
		}
		$mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
			return __( 'Choose a JPEG, PNG or WebP image.', 'momos-core' );
		}
		$post = is_int( $object_id ) || ctype_digit( (string) $object_id ) ? get_post( (int) $object_id ) : null;
		if ( $post instanceof \WP_Post && '1' === (string) get_post_meta( $id, 'momo_image_illustrative', true )
			&& ( 'momo_branch' === $post->post_type || 'about' === Context::role_of( $post->ID ) ) ) {
			return __( 'Illustrative images cannot be used on branch or About pages.', 'momos-core' );
		}
		return null;
	}

	/** Live length counters for the JA source fields (ja limits: 32 / 120 full-width) — warning, not blocking. */
	public static function counter_script( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'term.php', 'edit-tags.php' ], true ) || ! wp_script_is( 'momos-fields', 'enqueued' ) ) {
			return;
		}
		$data = [
			'title' => self::TITLE_WARN[0],
			'desc'  => self::DESC_WARN[0],
			/* translators: 1: current length, 2: recommended maximum */
			'msg'   => __( 'Longer than recommended (%1$d/%2$d)', 'momos-core' ),
		];
		$js = 'jQuery(function($){var c=' . wp_json_encode( $data ) . ';function w(n,m){var f=$(\'[name="momos_fields[\'+n+\']"]\');if(!f.length){return;}var o=$(\'<p class="description momo-seo-count" aria-live="polite"></p>\').insertAfter(f);var u=function(){var l=[...String(f.val())].length;o.text(l>m?c.msg.replace("%1$d",l).replace("%2$d",m):l+"/"+m);o.css("color",l>m?"#b32d2e":"");};f.on("input",u);u();}w("momo_seo_title",c.title);w("momo_og_title",c.title);w("momo_seo_description",c.desc);w("momo_og_description",c.desc);});';
		wp_add_inline_script( 'momos-fields', $js );
	}
}
