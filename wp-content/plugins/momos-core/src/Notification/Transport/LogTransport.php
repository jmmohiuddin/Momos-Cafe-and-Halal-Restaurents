<?php
declare(strict_types=1);

namespace Momos\Notification\Transport;

use Momos\Kernel\Env;

/**
 * Development/test transport (`MOMOS_MAIL_TRANSPORT=log`): writes the rendered message to
 * `MOMOS_MAIL_CATCHER_DIR` as `{stamp}-{uuid}.eml` (raw MIME, multipart/alternative) and `.json`
 * (headers + both parts) instead of sending. Refused in production (synthetic data only, PRV-029,
 * SEC-055): files contain message bodies.
 */
final class LogTransport implements TransportInterface {
	public function name(): string {
		return 'log';
	}

	public static function dir(): string {
		if ( defined( 'MOMOS_MAIL_CATCHER_DIR' ) && '' !== (string) MOMOS_MAIL_CATCHER_DIR ) {
			return rtrim( (string) MOMOS_MAIL_CATCHER_DIR, '/' );
		}
		return rtrim( WP_CONTENT_DIR, '/' ) . '/mail-catcher';
	}

	public function send( Message $m ): SendResult {
		if ( Env::is_production() ) {
			return SendResult::fail( 'transport_not_configured', 'log transport is disabled in production' );
		}
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return SendResult::fail( 'transport_error', 'mail catcher directory is not writable' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			@file_put_contents( $dir . '/index.php', "<?php // Silence.\n" ); // phpcs:ignore
		}
		$local = trim( (string) strtok( trim( $m->message_id, '<>' ), '@' ) );
		$base  = $dir . '/' . gmdate( 'Ymd-His' ) . '-' . sanitize_file_name( '' !== $local ? $local : wp_generate_uuid4() );
		$json  = [
			'message_id' => $m->message_id,
			'date'       => gmdate( 'r' ),
			'from'       => [ 'name' => $m->from_name, 'email' => $m->from_email ],
			'to'         => $m->to,
			'reply_to'   => $m->reply_to,
			'subject'    => $m->subject,
			'lang'       => $m->lang,
			'headers'    => self::headers( $m ),
			'html'       => $m->html,
			'text'       => $m->text,
		];
		$ok1 = false !== file_put_contents( $base . '.eml', self::mime( $m ) ); // phpcs:ignore
		$ok2 = false !== file_put_contents( $base . '.json', (string) wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore
		if ( ! $ok1 || ! $ok2 ) {
			return SendResult::fail( 'transport_error', 'could not write mail catcher file' );
		}
		do_action( 'momos/notification/log_transport_written', $base, $json );
		return SendResult::ok( $m->message_id );
	}

	/** @return array<string,string> */
	public static function headers( Message $m ): array {
		$h = [
			'From'             => self::encode_name( $m->from_name ) . ' <' . $m->from_email . '>',
			'To'               => $m->to,
			'Subject'          => $m->subject,
			'Message-ID'       => $m->message_id,
			'Date'             => gmdate( 'r' ),
			'MIME-Version'     => '1.0',
			'Auto-Submitted'   => 'auto-generated',
			'Content-Language' => $m->lang,
		];
		if ( null !== $m->reply_to ) {
			$h['Reply-To'] = $m->reply_to;
		}
		return $h;
	}

	/** Raw RFC 5322 message, multipart/alternative, base64 UTF-8 parts. */
	public static function mime( Message $m ): string {
		$boundary          = 'b' . md5( $m->message_id );
		$h                 = self::headers( $m );
		$h['Subject']      = '=?UTF-8?B?' . base64_encode( $m->subject ) . '?=';
		$h['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
		$out               = '';
		foreach ( $h as $k => $v ) {
			$out .= $k . ': ' . $v . "\r\n";
		}
		$out .= "\r\n";
		foreach ( [ 'text/plain' => $m->text, 'text/html' => $m->html ] as $type => $body ) {
			$out .= '--' . $boundary . "\r\nContent-Type: " . $type . "; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
			$out .= chunk_split( base64_encode( $body ), 76, "\r\n" );
		}
		return $out . '--' . $boundary . "--\r\n";
	}

	private static function encode_name( string $name ): string {
		return preg_match( '/[^\x20-\x7E]/', $name ) ? '=?UTF-8?B?' . base64_encode( $name ) . '?=' : '"' . $name . '"';
	}
}
