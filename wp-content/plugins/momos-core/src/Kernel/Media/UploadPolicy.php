<?php
declare(strict_types=1);

namespace Momos\Kernel\Media;

/**
 * Media-library upload policy (SEC-028, FR-ADM-005; access-security-privacy.md §7.7).
 *
 * - MIME allow-list JPEG, PNG, WebP for every role (SVG, AVIF, PDF, archives, office files … rejected).
 *   PDF certificate evidence never goes through the media library: the Dietary evidence handler stores it
 *   outside the web root with its own checks (EvidenceStore).
 * - Size ≤ 5 MB (⟦TBD 5 MB⟧, FR-ADM-005).
 * - JPEG/WebP originals lose their EXIF/GPS metadata on upload (FR-ADM-005(c), `MetadataStripper`).
 * - Enforced in `upload_mimes` (what core accepts and advertises) and again in the upload/sideload prefilters,
 *   so neither ALLOW_UNFILTERED_UPLOADS nor a plugin re-adding types widens the list.
 */
final class UploadPolicy {
	public const MAX_BYTES = 5 * 1048576;

	/** Extension pattern (core `upload_mimes` format) => MIME. */
	public const MIMES = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	];

	public static function register(): void {
		add_filter( 'upload_mimes', [ self::class, 'mimes' ], PHP_INT_MAX );
		add_filter( 'upload_size_limit', [ self::class, 'size_limit' ], PHP_INT_MAX );
		add_filter( 'wp_handle_upload_prefilter', [ self::class, 'prefilter' ], PHP_INT_MAX );
		add_filter( 'wp_handle_sideload_prefilter', [ self::class, 'prefilter' ], PHP_INT_MAX );
		// EXIF/GPS removed from public originals before sub-sizes are generated (FR-ADM-005(c), AC-ADM-04-03).
		MetadataStripper::register();
	}

	/**
	 * `upload_mimes` callback: replaces core's list (and any plugin additions) with the allow-list.
	 *
	 * @return array<string,string>
	 */
	public static function mimes(): array {
		return self::MIMES;
	}

	public static function size_limit( $bytes ): int {
		$bytes = (int) $bytes;
		return $bytes > 0 ? min( $bytes, self::MAX_BYTES ) : self::MAX_BYTES;
	}

	/**
	 * @param array<string,mixed> $file `$_FILES`-style entry.
	 * @return array<string,mixed>
	 */
	public static function prefilter( $file ): array {
		$file = (array) $file;
		if ( ! empty( $file['error'] ) ) {
			return $file;
		}
		$error = self::error_for( (string) ( $file['name'] ?? '' ), (string) ( $file['tmp_name'] ?? '' ), (int) ( $file['size'] ?? 0 ) );
		if ( null !== $error ) {
			$file['error'] = $error;
		}
		return $file;
	}

	/** Reason the file is refused, or null when it is allowed. */
	public static function error_for( string $name, string $tmp_name, int $size ): ?string {
		if ( '' !== $tmp_name && is_file( $tmp_name ) ) {
			$size = max( $size, (int) filesize( $tmp_name ) );
		}
		if ( $size > self::MAX_BYTES ) {
			return __( 'Images may be at most 5 MB.', 'momos-core' );
		}
		$type = wp_check_filetype( $name, self::MIMES );
		if ( empty( $type['type'] ) || ! in_array( $type['type'], self::MIMES, true ) ) {
			return __( 'Only JPEG, PNG and WebP images can be uploaded.', 'momos-core' );
		}
		return null;
	}
}
