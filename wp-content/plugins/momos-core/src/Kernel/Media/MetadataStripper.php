<?php
declare(strict_types=1);

namespace Momos\Kernel\Media;

use Momos\Kernel\Log\Logger;

/**
 * Strips EXIF/XMP/IPTC metadata (camera, date, **GPS location**) from uploaded JPEG and WebP originals
 * (FR-ADM-005(c), AC-ADM-04-03, US-ADM-006 "location data stripped").
 *
 * Runs on `wp_handle_upload` (media library uploads, REST uploads and sideloads), i.e. after the file is in
 * the uploads folder and before WordPress generates the scaled copy and sub-sizes from it, so every public
 * file derives from the clean original:
 *  1. the EXIF orientation is applied to the pixels first (`WP_Image_Editor::maybe_exif_rotate()`), so the
 *     photo keeps its visible orientation once the tag is gone;
 *  2. the file is re-saved in its own format at quality {@see self::QUALITY}: with Imagick, `stripImage()`
 *     removes every profile except the ICC colour profile, which is re-attached; with GD, re-encoding writes
 *     no metadata at all (GD cannot keep ICC).
 * The theme's JPEG→WebP output mapping applies to sub-sizes only; the original keeps its format.
 * A failure is logged (`media.metadata_strip_failed`) and the upload refused, so no file with location
 * data stays public.
 */
final class MetadataStripper {
	/** Re-encoding quality of the original (high, to keep the photo's quality; sub-sizes use their own). */
	public const QUALITY = 90;

	public const MIMES = [ 'image/jpeg', 'image/webp' ];

	public static function register(): void {
		add_filter( 'wp_handle_upload', [ self::class, 'on_upload' ], 5, 2 );
	}

	/**
	 * @param array<string,mixed> $upload {file, url, type} (or {error})
	 * @return array<string,mixed>
	 */
	public static function on_upload( $upload, $context = 'upload' ): array {
		$upload = (array) $upload;
		$file   = (string) ( $upload['file'] ?? '' );
		$type   = (string) ( $upload['type'] ?? '' );
		if ( ! empty( $upload['error'] ) || '' === $file || ! in_array( $type, self::MIMES, true ) || ! is_file( $file ) ) {
			return $upload;
		}
		if ( ! self::strip( $file, $type ) ) {
			wp_delete_file( $file );
			return [ 'error' => __( 'The image could not be processed. Save it again as JPEG, PNG or WebP and retry.', 'momos-core' ) ];
		}
		return $upload;
	}

	/** Remove the metadata of an image file in place. True on success. */
	public static function strip( string $file, string $mime ): bool {
		$editor = wp_get_image_editor( $file, [ 'mime_type' => $mime ] );
		if ( is_wp_error( $editor ) ) {
			Logger::error( 'media.metadata_strip_failed', [ 'reason' => $editor->get_error_code(), 'mime' => $mime ] );
			return false;
		}
		$keep_format = static fn(): array => [];
		add_filter( 'image_editor_output_format', $keep_format, PHP_INT_MAX );
		try {
			$rotated = true === $editor->maybe_exif_rotate();
			$editor->set_quality( self::QUALITY );
			if ( $editor instanceof \WP_Image_Editor_Imagick && class_exists( \Imagick::class ) ) {
				if ( $rotated ) {
					$saved = $editor->save( $file, $mime );
					if ( is_wp_error( $saved ) ) {
						throw new \RuntimeException( $saved->get_error_code() );
					}
				}
				self::imagick_strip( $file );
			} else {
				$saved = $editor->save( $file, $mime );
				if ( is_wp_error( $saved ) ) {
					throw new \RuntimeException( $saved->get_error_code() );
				}
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'media.metadata_strip_failed', [ 'reason' => $e->getMessage(), 'mime' => $mime ] );
			return false;
		} finally {
			remove_filter( 'image_editor_output_format', $keep_format, PHP_INT_MAX );
		}
		clearstatcache( true, $file );
		return true;
	}

	/** Imagick: drop every profile and property except ICC, keep the original compression quality. */
	private static function imagick_strip( string $file ): void {
		$im      = new \Imagick( $file );
		$icc     = null;
		$quality = (int) $im->getImageCompressionQuality();
		try {
			$icc = $im->getImageProfile( 'icc' );
		} catch ( \ImagickException $e ) {
			$icc = null;
		}
		$im->stripImage();
		if ( is_string( $icc ) && '' !== $icc ) {
			$im->profileImage( 'icc', $icc );
		}
		$im->setImageCompressionQuality( $quality > 0 ? $quality : self::QUALITY );
		$im->writeImage( $file );
		$im->clear();
	}
}
