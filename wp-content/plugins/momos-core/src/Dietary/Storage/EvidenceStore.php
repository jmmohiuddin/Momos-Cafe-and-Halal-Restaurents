<?php
declare(strict_types=1);

namespace Momos\Dietary\Storage;

use Momos\Kernel\Clock\Clock;

/**
 * Private evidence store (SEC-028, spec §3.15): certificate copies and mark-permission documents are
 * kept outside the web root (`MOMOS_EVIDENCE_DIR`, default `{ABSPATH}/../momos-private/evidence`) with
 * random names and deny-all guards, recorded as {path (relative), sha256, mime, size, uploaded_by,
 * uploaded_at}. Only PDF/JPEG/PNG ≤ 10 MB. Files are served only by the authenticated download handler.
 */
final class EvidenceStore {
	public const MAX_BYTES = 10 * 1024 * 1024;
	public const MIMES     = [ 'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png' ];

	public static function dir(): string {
		$dir = defined( 'MOMOS_EVIDENCE_DIR' ) ? (string) MOMOS_EVIDENCE_DIR : (string) getenv( 'MOMOS_EVIDENCE_DIR' );
		if ( '' === $dir ) {
			$dir = dirname( untrailingslashit( ABSPATH ) ) . '/momos-private/evidence';
		}
		/** Evidence directory (must be outside the web root or deny-all, covered by backups). */
		return untrailingslashit( (string) apply_filters( 'momos/evidence/dir', $dir ) );
	}

	/** Create the directory with deny-all guards (Apache/LiteSpeed/IIS) and an empty index. */
	public static function ensure_dir(): string {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'Evidence directory is not writable.' );
		}
		$guards = [
			'.htaccess'  => "Require all denied\nDeny from all\n",
			'index.php'  => "<?php\nhttp_response_code( 404 );\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		];
		foreach ( $guards as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore
			}
		}
		return $dir;
	}

	/**
	 * Store an uploaded file ($_FILES entry).
	 *
	 * @param array<string,mixed> $file
	 * @return array{path:string,sha256:string,mime:string,size:int,uploaded_by:int,uploaded_at:string}
	 */
	public static function store_upload( array $file ): array {
		if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			throw new \InvalidArgumentException( __( 'The file could not be uploaded.', 'momos-core' ) );
		}
		return self::store_file( (string) $file['tmp_name'], true );
	}

	/**
	 * Store a local file (CLI/tests, or after upload checks).
	 *
	 * @return array{path:string,sha256:string,mime:string,size:int,uploaded_by:int,uploaded_at:string}
	 */
	public static function store_file( string $source, bool $is_upload = false ): array {
		$size = (int) @filesize( $source ); // phpcs:ignore
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( __( 'Evidence files must be PDF, JPEG or PNG and at most 10 MB.', 'momos-core' ) );
		}
		$mime = self::detect_mime( $source );
		if ( ! isset( self::MIMES[ $mime ] ) ) {
			throw new \InvalidArgumentException( __( 'Evidence files must be PDF, JPEG or PNG and at most 10 MB.', 'momos-core' ) );
		}
		$dir  = self::ensure_dir();
		$sub  = Clock::utc()->format( 'Y' );
		if ( ! is_dir( $dir . '/' . $sub ) ) {
			wp_mkdir_p( $dir . '/' . $sub );
		}
		$rel  = $sub . '/' . bin2hex( random_bytes( 16 ) ) . '.' . self::MIMES[ $mime ];
		$dest = $dir . '/' . $rel;
		$ok   = $is_upload ? move_uploaded_file( $source, $dest ) : copy( $source, $dest );
		if ( ! $ok ) {
			throw new \RuntimeException( __( 'The file could not be stored.', 'momos-core' ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore
		return [
			'path'        => $rel,
			'sha256'      => (string) hash_file( 'sha256', $dest ),
			'mime'        => $mime,
			'size'        => $size,
			'uploaded_by' => get_current_user_id(),
			'uploaded_at' => Clock::mysql_utc(),
		];
	}

	/** Absolute path of a stored record, or null when the record is invalid (no traversal). */
	public static function path( array $record ): ?string {
		$rel = (string) ( $record['path'] ?? '' );
		if ( ! preg_match( '#^\d{4}/[a-f0-9]{32}\.(pdf|jpg|png)$#', $rel ) ) {
			return null;
		}
		$abs = self::dir() . '/' . $rel;
		return is_file( $abs ) ? $abs : null;
	}

	/** File exists and its SHA-256 matches the stored hash. */
	public static function verify( array $record ): bool {
		$abs = self::path( $record );
		return null !== $abs && hash_equals( (string) ( $record['sha256'] ?? '' ), (string) hash_file( 'sha256', $abs ) );
	}

	public static function is_valid_record( mixed $record ): bool {
		return is_array( $record ) && null !== self::path( $record );
	}

	private static function detect_mime( string $file ): string {
		if ( function_exists( 'finfo_open' ) ) {
			$f = finfo_open( FILEINFO_MIME_TYPE );
			if ( $f ) {
				$m = (string) finfo_file( $f, $file );
				finfo_close( $f );
				return $m;
			}
		}
		$check = wp_check_filetype_and_ext( $file, basename( $file ) );
		return (string) ( $check['type'] ?? '' );
	}
}
