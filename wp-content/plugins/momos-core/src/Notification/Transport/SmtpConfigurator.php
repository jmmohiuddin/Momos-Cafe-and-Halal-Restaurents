<?php
declare(strict_types=1);

namespace Momos\Notification\Transport;

/**
 * `phpmailer_init`: SMTP submission to the provider from `MOMOS_SMTP_*` constants (IMPL-004 replaces
 * FluentSMTP; spec §4.11). Credentials never leave the constants (never stored, never logged).
 * Applies to every wp_mail() of the site so host mail()/Hostinger Mail is never used (DEC-018).
 */
final class SmtpConfigurator {
	/** When true the optional `MOMOS_SMTP_FALLBACK_*` connection is used (one attempt). */
	private static bool $use_fallback = false;

	public static function register(): void {
		add_action( 'phpmailer_init', [ self::class, 'configure' ], 10 );
	}

	public static function prefix(): string {
		return self::$use_fallback ? 'MOMOS_SMTP_FALLBACK_' : 'MOMOS_SMTP_';
	}

	public static function is_configured( bool $fallback = false ): bool {
		$k = $fallback ? 'MOMOS_SMTP_FALLBACK_HOST' : 'MOMOS_SMTP_HOST';
		return defined( $k ) && '' !== (string) constant( $k );
	}

	public static function with_fallback( callable $fn ): mixed {
		self::$use_fallback = true;
		try {
			return $fn();
		} finally {
			self::$use_fallback = false;
		}
	}

	/** @param \PHPMailer\PHPMailer\PHPMailer $mailer */
	public static function configure( $mailer ): void {
		$mailer->CharSet  = 'UTF-8';
		$mailer->Encoding = 'base64';
		$p                = self::prefix();
		if ( ! self::is_configured( self::$use_fallback ) ) {
			return;
		}
		$c                   = static fn( string $k, mixed $d = '' ): mixed => defined( $p . $k ) ? constant( $p . $k ) : $d;
		$mailer->isSMTP();
		$mailer->Host        = (string) $c( 'HOST' );
		$mailer->Port        = (int) $c( 'PORT', 587 );
		$secure              = strtolower( (string) $c( 'SECURE', 'tls' ) );
		$mailer->SMTPSecure  = in_array( $secure, [ 'tls', 'ssl' ], true ) ? $secure : '';
		$mailer->SMTPAutoTLS = true;
		$mailer->Timeout     = (int) ( defined( 'MOMOS_SMTP_TIMEOUT' ) ? MOMOS_SMTP_TIMEOUT : 15 );
		$user                = (string) $c( 'USER' );
		if ( '' !== $user ) {
			$mailer->SMTPAuth = true;
			$mailer->Username = $user;
			$mailer->Password = (string) $c( 'PASS' );
		}
		// Envelope sender on the provider's custom MAIL FROM subdomain for SPF alignment ⟦DEC-018⟧.
		if ( defined( 'MOMOS_MAIL_BOUNCE' ) && is_email( (string) MOMOS_MAIL_BOUNCE ) ) {
			$mailer->Sender = (string) MOMOS_MAIL_BOUNCE;
		}
	}

	/**
	 * Read-only transport status for SCR-A15 (never credentials).
	 *
	 * @return array<string,string>
	 */
	public static function status(): array {
		return [
			'transport' => defined( 'MOMOS_MAIL_TRANSPORT' ) ? (string) MOMOS_MAIL_TRANSPORT : 'smtp',
			'host'      => self::is_configured() ? (string) MOMOS_SMTP_HOST : '—',
			'port'      => defined( 'MOMOS_SMTP_PORT' ) ? (string) MOMOS_SMTP_PORT : '587',
			'tls'       => defined( 'MOMOS_SMTP_SECURE' ) ? (string) MOMOS_SMTP_SECURE : 'tls',
			'fallback'  => self::is_configured( true ) ? 'yes' : 'no',
			'from'      => MailIdentity::from_address(),
			'sandbox'   => null !== MailIdentity::sandbox() ? 'on' : 'off',
		];
	}
}
