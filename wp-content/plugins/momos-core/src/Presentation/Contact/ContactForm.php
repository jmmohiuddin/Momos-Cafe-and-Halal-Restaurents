<?php
declare(strict_types=1);

namespace Momos\Presentation\Contact;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Url;
use Momos\Kernel\Log\Logger;
use Momos\Presentation\SystemPages;
use Momos\Presentation\View\BranchLinks;
use Momos\Presentation\View\Brand;

/**
 * SCR-C12 Contact form handler (FR-CNT-001, BR-CNT-01, DEC-038; content-strings.md §8.5/§13):
 * `POST admin-post.php?action=momos_contact` (logged-out and logged-in). Nonce + honeypot (`website`)
 * + per-IP rate limit (HMAC of the IP in a transient counter — the IP itself is never stored) + server
 * validation. Sends one email to the Head Office inbox (`momo_contact_email`) with Reply-To = sender;
 * **nothing is stored** (no post, option, log line or audit row with the message or the sender).
 * The body contains no IP and no user agent (TC-PRV-015). Redirects back with `?sent=1` or
 * `?contact_error={codes}` (error codes only — never personal data in the URL).
 *
 * Public-write integrity (FR-SEC-006, SEC-022/024, access-security-privacy §10.1): the `Origin` (else `Referer`)
 * host must be the site host, and a Cloudflare Turnstile token (action `contact_submit`, `TurnstileVerifier`) must
 * verify before anything is sent. A siteverify outage does not block the form (rate limit still applies; logged),
 * as for the staff login. Non-production test mode renders a test token and never calls the network.
 */
final class ContactForm {
	public const ACTION      = 'momos_contact';
	public const NONCE       = 'momos_contact';
	public const TOPICS      = [ 'general', 'group_event', 'feedback', 'media', 'other' ];
	public const NAME_MAX    = 100;
	public const EMAIL_MAX   = 254;
	public const MESSAGE_MAX = 2000;
	public const RATE_LIMIT  = 5;   // ⟦5 per 10 min per IP hash⟧ screens.md §7.
	public const RATE_WINDOW = 600;
	public const CAPTCHA       = 'contact_submit';
	public const CAPTCHA_FIELD = 'cf-turnstile-response';

	public static function register(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function action_url(): string {
		return admin_url( 'admin-post.php' );
	}

	public static function handle(): void {
		$lang = isset( $_POST['lang'] ) && Lang::is_valid( sanitize_key( wp_unslash( (string) $_POST['lang'] ) ) ) ? sanitize_key( wp_unslash( (string) $_POST['lang'] ) ) : Lang::SOURCE; // phpcs:ignore WordPress.Security.NonceVerification -- verified below.
		$back = (string) ( SystemPages::url( 'contact', $lang ) ?? Url::to( '/contact/', $lang ) );

		if ( ! isset( $_POST['_momos_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['_momos_nonce'] ) ), self::NONCE ) ) {
			self::back( $back, [ 'expired' ] );
		}
		// Cross-site posts are refused before anything else is evaluated (no CORS, no third-party forms).
		if ( ! self::same_origin() ) {
			Logger::warning( 'contact.origin_rejected' );
			self::back( $back, [ 'expired' ] );
		}
		// Honeypot: bots fill the hidden "website" field → pretend success, send nothing.
		if ( '' !== trim( (string) wp_unslash( $_POST['website'] ?? '' ) ) ) {
			self::back( $back, [], true );
		}
		if ( ! self::rate_ok() ) {
			self::back( $back, [ 'rate_limited' ] );
		}
		if ( ! self::captcha_ok( isset( $_POST[ self::CAPTCHA_FIELD ] ) && is_string( $_POST[ self::CAPTCHA_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CAPTCHA_FIELD ] ) ) : '' ) ) {
			self::back( $back, [ 'captcha' ] );
		}
		$in     = self::input( wp_unslash( $_POST ) );
		$errors = self::validate( $in );
		if ( $errors ) {
			self::back( $back, array_keys( $errors ) );
		}
		$sent = self::send( $in, $lang );
		self::back( $back, $sent ? [] : [ 'system' ], $sent );
	}

	/** `Origin` (else `Referer`) host = site host; a request carrying neither is refused (browsers send one on POST). */
	public static function same_origin(): bool {
		$site   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		if ( '' === $origin || 'null' === $origin ) {
			$origin = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		}
		$host = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		return '' !== $host && $host === $site;
	}

	/** Turnstile check (action `contact_submit`); an outage lets the message through (rate limit applies). */
	public static function captcha_ok( string $token ): bool {
		if ( ! class_exists( \Momos\Api\Captcha\TurnstileVerifier::class ) ) {
			return true; // API module absent: honeypot + nonce + rate limit remain.
		}
		$result = '' === trim( $token ) ? \Momos\Api\Captcha\TurnstileVerifier::FAILED : \Momos\Api\Captcha\TurnstileVerifier::verify( substr( $token, 0, 2048 ), self::CAPTCHA );
		if ( \Momos\Api\Captcha\TurnstileVerifier::OUTAGE === $result ) {
			Logger::warning( 'contact.captcha_outage' );
			return true;
		}
		return \Momos\Api\Captcha\TurnstileVerifier::VERIFIED === $result;
	}

	/**
	 * Widget data for the form: site key, mode (`live` loads the Cloudflare script on C12 only, screens.md §3.9).
	 *
	 * @return array{site_key:string,mode:string,action:string,field:string}|null
	 */
	public static function captcha(): ?array {
		if ( ! class_exists( \Momos\Api\Captcha\TurnstileVerifier::class ) ) {
			return null;
		}
		return [ 'site_key' => \Momos\Api\Captcha\TurnstileVerifier::site_key(), 'mode' => \Momos\Api\Captcha\TurnstileVerifier::mode(), 'action' => self::CAPTCHA, 'field' => self::CAPTCHA_FIELD ];
	}

	/**
	 * @param array<string,mixed> $post
	 * @return array{name:string,email:string,topic:string,branch:string,message:string,consent:bool}
	 */
	public static function input( array $post ): array {
		return [
			'name'    => trim( sanitize_text_field( (string) ( $post['name'] ?? '' ) ) ),
			'email'   => trim( sanitize_email( (string) ( $post['email'] ?? '' ) ) ),
			'topic'   => sanitize_key( (string) ( $post['topic'] ?? '' ) ),
			'branch'  => strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $post['branch'] ?? '' ) ) ?? '' ),
			'message' => trim( sanitize_textarea_field( (string) ( $post['message'] ?? '' ) ) ),
			'consent' => ! empty( $post['consent'] ),
		];
	}

	/**
	 * Field → error code (keys are the form field ids; codes map to `contact.err.*` strings).
	 *
	 * @param array{name:string,email:string,topic:string,branch:string,message:string,consent:bool} $in
	 * @return array<string,string>
	 */
	public static function validate( array $in ): array {
		$e = [];
		if ( '' === $in['name'] || mb_strlen( $in['name'] ) > self::NAME_MAX ) {
			$e['name'] = 'name';
		}
		if ( '' === $in['email'] || mb_strlen( $in['email'] ) > self::EMAIL_MAX || ! is_email( $in['email'] ) ) {
			$e['email'] = 'email';
		}
		if ( ! in_array( $in['topic'], self::TOPICS, true ) ) {
			$e['topic'] = 'topic';
		}
		if ( '' !== $in['branch'] && ! in_array( $in['branch'], array_column( BranchLinks::all( Lang::SOURCE ), 'code' ), true ) ) {
			$e['branch'] = 'branch';
		}
		if ( '' === $in['message'] ) {
			$e['message'] = 'message';
		} elseif ( mb_strlen( $in['message'] ) > self::MESSAGE_MAX ) {
			$e['message'] = 'message_length';
		}
		if ( ! $in['consent'] ) {
			$e['consent'] = 'consent';
		}
		return $e;
	}

	/** Head Office inbox (`momo_contact_email`, G1); '' = not configured → system error state. */
	public static function recipient(): string {
		$to = (string) Brand::option( 'momo_contact_email' );
		if ( '' === $to && ! \Momos\Kernel\Env::is_production() ) {
			$to = (string) get_option( 'admin_email' ); // Development only.
		}
		return is_email( $to ) ? $to : '';
	}

	/** @param array{name:string,email:string,topic:string,branch:string,message:string,consent:bool} $in */
	public static function send( array $in, string $lang ): bool {
		$to = self::recipient();
		if ( '' === $to ) {
			Logger::error( 'contact.recipient_missing', [] );
			return false;
		}
		$topic   = momos_t( 'contact.topic.' . $in['topic'], [], 'ja' ) . ' / ' . momos_t( 'contact.topic.' . $in['topic'], [], 'en' );
		$subject = sprintf( '[Contact] %s — %s', momos_t( 'contact.topic.' . $in['topic'], [], 'en' ), '' !== $in['branch'] ? $in['branch'] : 'All' );
		$version = (string) get_post_meta( SystemPages::legal_id( 'privacy' ), 'momo_legal_version', true );
		$body    = implode(
			"\n",
			[
				'お問い合わせ / Contact form',
				'',
				'Name: ' . $in['name'],
				'Email: ' . $in['email'],
				'Topic: ' . $topic,
				'Branch: ' . ( '' !== $in['branch'] ? $in['branch'] : '—' ),
				'Language: ' . $lang,
				'Received (JST): ' . Clock::jst()->format( 'Y-m-d H:i' ),
				'Consent: yes' . ( '' !== $version ? ' — ' . $version : '' ),
				'',
				'Message:',
				$in['message'],
			]
		);
		$ok      = self::deliver( $to, $subject, $body, $in, $lang );
		if ( ! $ok ) {
			Logger::error( 'contact.mail_failed', [] ); // No PII.
		}
		return $ok;
	}

	/**
	 * Through the Notification transport when present (same SMTP identity, dev `log` transport / mail
	 * catcher, staging sandbox), else plain wp_mail. Exactly one recipient, Reply-To = sender.
	 *
	 * @param array{name:string,email:string,topic:string,branch:string,message:string,consent:bool} $in
	 */
	private static function deliver( string $to, string $subject, string $body, array $in, string $lang ): bool {
		$ns = '\\Momos\\Notification\\Transport\\';
		if ( class_exists( $ns . 'TransportFactory' ) && class_exists( $ns . 'Message' ) && class_exists( $ns . 'MailIdentity' ) ) {
			try {
				$msg_class = $ns . 'Message';
				$identity  = $ns . 'MailIdentity';
				$factory   = $ns . 'TransportFactory';
				$m         = new $msg_class( $to, $subject, '<pre style="font-family:inherit;white-space:pre-wrap">' . esc_html( $body ) . '</pre>', $body, $identity::from_address(), Brand::trading_name( 'en' ), $in['email'], $identity::message_id( wp_generate_uuid4() ), $lang );
				return (bool) $factory::make()->send( $identity::finalize( $m ) )->ok;
			} catch ( \Throwable $e ) {
				Logger::error( 'contact.transport_failed', [ 'error' => $e->getMessage() ] );
				return false;
			}
		}
		$headers = [ 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . str_replace( [ "\r", "\n", '<', '>' ], '', $in['name'] ) . ' <' . $in['email'] . '>' ];
		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	private static function rate_ok(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore
		$key = 'momos_contact_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 24 );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $n + 1, self::RATE_WINDOW );
		return true;
	}

	/** @param list<string> $codes */
	private static function back( string $url, array $codes, bool $sent = false ): never {
		$url = $sent ? add_query_arg( 'sent', '1', $url ) : add_query_arg( 'contact_error', implode( ',', array_map( 'sanitize_key', $codes ) ), $url );
		wp_safe_redirect( $url . ( $sent ? '#contact-status' : '#contact-errors' ), 303 );
		exit;
	}

	/**
	 * Error codes from the redirect (`?contact_error=`), whitelisted.
	 *
	 * @return list<string>
	 */
	public static function error_codes(): array {
		if ( empty( $_GET['contact_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return [];
		}
		$allowed = [ 'name', 'email', 'topic', 'branch', 'message', 'message_length', 'consent', 'expired', 'rate_limited', 'captcha', 'system' ];
		return array_values( array_intersect( explode( ',', sanitize_text_field( wp_unslash( (string) $_GET['contact_error'] ) ) ), $allowed ) ); // phpcs:ignore
	}
}
