<?php
declare(strict_types=1);

namespace Momos\Seed;

/**
 * Demo images (DEC-047, IMPL-005): food photos of the private UI baseline checkout are imported into
 * the media library at seed time only (they live in uploads, never in the repository); without that
 * checkout a neutral GD placeholder is generated. Every image is flagged `momo_image_illustrative = 1`
 * ("Image for illustration") and `momo_is_sample = 1`, so the publish guards keep them off branch,
 * gallery, halal and About contexts.
 */
final class Media {
	/** Default location of the UI baseline photos (override with --photos=<dir> or MOMOS_SEED_PHOTO_DIR). */
	public const DEFAULT_DIR = '/home/user/momos-cafe/momo-prototype/assets';

	private ?string $dir;

	/** @var array<string,int> slug => attachment id (per run) */
	private array $cache = [];

	public int $imported = 0;

	public int $generated = 0;

	public function __construct( ?string $dir, bool $use_photos = true ) {
		$dir       = $dir ?? ( getenv( 'MOMOS_SEED_PHOTO_DIR' ) ?: self::DEFAULT_DIR );
		$this->dir = $use_photos && is_dir( $dir ) && is_readable( $dir ) ? rtrim( $dir, '/' ) : null;
	}

	public function source(): string {
		return null !== $this->dir ? 'photos' : 'placeholders';
	}

	/**
	 * Attachment for a photo slug ("chicken-biryani" → items_chicken-biryani-lg.jpg; "gallery:x" →
	 * gallery_x-lg.jpg). Idempotent: an existing seeded attachment with the same key is reused.
	 *
	 * @param array<string,string> $alt alt text per language (ja required)
	 */
	public function image( string $slug, array $alt ): int {
		if ( isset( $this->cache[ $slug ] ) ) {
			return $this->cache[ $slug ];
		}
		$key = 'media:' . $slug;
		$id  = Writer::find_post( 'attachment', $key );
		if ( ! $id ) {
			$id = $this->create( $slug, $key, (string) ( $alt['ja'] ?? $slug ) );
		}
		$this->ensure_meta( $id, (string) ( $alt['ja'] ?? '' ) );
		$this->cache[ $slug ] = $id;
		return $id;
	}

	private function create( string $slug, string $key, string $title ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file = null;
		if ( null !== $this->dir ) {
			$name = str_starts_with( $slug, 'gallery:' ) ? 'gallery_' . substr( $slug, 8 ) . '-lg.jpg' : 'items_' . $slug . '-lg.jpg';
			$path = $this->dir . '/' . basename( $name );
			if ( is_file( $path ) && in_array( strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ), [ 'jpg', 'jpeg' ], true ) ) {
				$file = $path;
			}
		}
		$tmp = wp_tempnam( 'momos-seed-' . sanitize_file_name( str_replace( ':', '-', $slug ) ) );
		if ( null !== $file ) {
			if ( ! copy( $file, $tmp ) ) {
				throw new SeedException( 'Could not copy demo photo ' . basename( $file ) );
			}
			++$this->imported;
		} else {
			$this->placeholder( $slug, $tmp );
			++$this->generated;
		}
		$upload = [
			'name'     => 'demo-' . sanitize_file_name( str_replace( ':', '-', $slug ) ) . '.jpg',
			'tmp_name' => $tmp,
		];
		$id     = media_handle_sideload( $upload, 0, $title );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			throw new SeedException( 'Could not import demo image ' . $slug . ': ' . $id->get_error_message() );
		}
		update_post_meta( (int) $id, Writer::KEY_META, $key );
		return (int) $id;
	}

	private function ensure_meta( int $id, string $alt_ja ): void {
		foreach ( [ Writer::SAMPLE_META => '1', 'momo_image_illustrative' => '1', '_wp_attachment_image_alt' => $alt_ja ] as $k => $v ) {
			if ( (string) get_post_meta( $id, $k, true ) !== $v ) {
				update_post_meta( $id, $k, $v );
			}
		}
	}

	/** Neutral 1200×900 JPEG placeholder (no text that could be mistaken for a real photo). */
	private function placeholder( string $slug, string $target ): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			throw new SeedException( 'GD is not available and no demo photo directory was found.' );
		}
		$img  = imagecreatetruecolor( 1200, 900 );
		$hash = crc32( $slug );
		$bg   = imagecolorallocate( $img, 200 + ( $hash & 0x1f ), 170 + ( ( $hash >> 5 ) & 0x2f ), 120 + ( ( $hash >> 11 ) & 0x3f ) );
		$fg   = imagecolorallocate( $img, 90, 60, 40 );
		imagefilledrectangle( $img, 0, 0, 1199, 899, $bg );
		imagefilledellipse( $img, 600, 450, 560, 560, imagecolorallocate( $img, 245, 240, 230 ) );
		imagestring( $img, 5, 40, 850, 'SAMPLE IMAGE - ' . strtoupper( substr( preg_replace( '/[^a-z0-9-]/', '', str_replace( 'gallery:', '', $slug ) ) ?? '', 0, 40 ) ), $fg );
		imagejpeg( $img, $target, 82 );
		imagedestroy( $img );
	}
}
