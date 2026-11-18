<?php
declare(strict_types=1);

namespace Momos\Notification\Transport;

use Momos\Kernel\Env;
use Momos\Notification\Queue\RetryPolicy;

/**
 * wp_mail() + PHPMailer over SMTP (SmtpConfigurator). multipart/alternative (HTML + text), one To,
 * no Cc/Bcc, stable Message-ID `<{uuid}@{MOMOS_MAIL_DOMAIN}>`, `Auto-Submitted`, `Content-Language`
 * (spec §4.11). Errors are captured from `wp_mail_failed` and the SMTP reply code.
 */
final class WpMailTransport implements TransportInterface {
	private ?\WP_Error $last_error = null;

	private ?int $smtp_code = null;

	public function name(): string {
		return 'smtp';
	}

	public function send( Message $message ): SendResult {
		if ( Env::is_production() && ! SmtpConfigurator::is_configured() ) {
			return SendResult::fail( 'transport_not_configured', 'MOMOS_SMTP_HOST is not defined; host mail is never used' );
		}
		$result = $this->attempt( $message );
		if ( ! $result->ok && in_array( $result->error_code, [ 'smtp_connect_failed', 'smtp_auth_failed', 'smtp_timeout' ], true ) && SmtpConfigurator::is_configured( true ) ) {
			$result = SmtpConfigurator::with_fallback( fn() => $this->attempt( $message ) );
		}
		return $result;
	}

	private function attempt( Message $m ): SendResult {
		$this->last_error = null;
		$this->smtp_code  = null;
		$headers          = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: "%s" <%s>', $m->from_name, $m->from_email ),
			'Auto-Submitted: auto-generated',
			'Content-Language: ' . $m->lang,
		];
		if ( null !== $m->reply_to ) {
			$headers[] = 'Reply-To: ' . $m->reply_to;
		}
		$init = static function ( $mailer ) use ( $m ): void {
			$mailer->clearCCs();
			$mailer->clearBCCs();
			$mailer->AltBody   = $m->text;
			$mailer->MessageID = $m->message_id;
			$mailer->XMailer   = ' ';
		};
		$fail = function ( \WP_Error $e ): void {
			$this->last_error = $e;
			global $phpmailer;
			if ( is_object( $phpmailer ) && method_exists( $phpmailer, 'getSMTPInstance' ) ) {
				$err = $phpmailer->getSMTPInstance()->getError();
				if ( ! empty( $err['smtp_code'] ) ) {
					$this->smtp_code = (int) $err['smtp_code'];
				}
			}
		};
		add_action( 'phpmailer_init', $init, 20 );
		add_action( 'wp_mail_failed', $fail );
		try {
			$ok = wp_mail( $m->to, $m->subject, $m->html, $headers );
		} catch ( \Throwable $e ) {
			$ok               = false;
			$this->last_error = new \WP_Error( 'wp_mail_failed', $e->getMessage() );
		} finally {
			remove_action( 'phpmailer_init', $init, 20 );
			remove_action( 'wp_mail_failed', $fail );
		}
		if ( $ok ) {
			return SendResult::ok( $m->message_id );
		}
		$msg  = $this->last_error ? $this->last_error->get_error_message() : 'wp_mail returned false';
		$code = RetryPolicy::classify( $this->smtp_code, $msg );
		return SendResult::fail( $code, ( $this->smtp_code ? $this->smtp_code . ' ' : '' ) . $msg );
	}
}
