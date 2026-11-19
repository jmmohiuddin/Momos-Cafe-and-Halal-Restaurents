<?php
declare(strict_types=1);

namespace Momos\Notification\Template;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Strings;
use Momos\Kernel\I18n\Url;
use Momos\Kernel\Support\Crockford;
use Momos\Notification\Support\Brand;
use Momos\Notification\Support\ReservationReader;
use Momos\Notification\Transport\MailIdentity;

/**
 * Renders a notification row at send time from current data (no body is stored — PRV-011; spec §4.6).
 *
 * Language: customer emails use the row language if it is publicly enabled, every string key used
 * exists in that language and the branch translation is approved; else `en` (same test), else `ja`
 * — never a mixed-language email (DEC-027). Staff emails: ja/en, missing en key → ja.
 * Templates: templates/email/{slug}.php returns the template-specific view (headline, blocks,
 * button); the shared layouts render the accessible HTML part and the plain-text part.
 */
final class Renderer {
	/** @var array<string,true> keys missing in the language being rendered */
	private static array $missing = [];

	private static string $lang = 'ja';

	public static function dir(): string {
		return MOMOS_CORE_DIR . '/templates/email';
	}

	/** @param array<string,mixed> $row notification row */
	public static function render( array $row ): Rendered {
		$key = (string) $row['template_key'];
		$def = TemplateRegistry::get( $key );
		if ( null === $def ) {
			throw new RenderException( 'unknown template' );
		}
		$reservation = ! empty( $row['reservation_id'] ) ? ReservationReader::find( (int) $row['reservation_id'] ) : null;
		if ( $def['needs_reservation'] && null === $reservation ) {
			throw new RenderException( 'reservation not found' );
		}
		$requested = (string) $row['lang'];
		$customer  = 'customer' === $def['audience'];
		$cands     = $customer ? [ $requested, 'en', 'ja' ] : [ $requested, 'ja' ];
		$cands     = array_values( array_unique( array_filter( $cands, static fn( $l ) => Lang::is_valid( $l ) && ( $customer ? Lang::is_enabled( $l ) : in_array( $l, Lang::ADMIN, true ) ) ) ) );
		if ( ! $cands || 'ja' !== end( $cands ) ) {
			$cands[] = 'ja';
		}
		$last = null;
		foreach ( $cands as $i => $lang ) {
			$out  = self::build( $row, $def, $reservation, $lang );
			$last = $out;
			$ok   = ! self::$missing && ( ! $customer || self::branch_ready( $reservation, $lang ) );
			$ok   = (bool) apply_filters( 'momos/notification/lang_ready', $ok, $lang, $row );
			if ( $ok || count( $cands ) - 1 === $i ) {
				break;
			}
		}
		/** @var Rendered $last */
		return new Rendered( $last->subject, $last->html, $last->text, $last->lang, $requested, $last->reply_to, $last->from_name );
	}

	/** Translated catalog string (raw, placeholders kept); records keys missing in the current language. */
	public static function t( string $key ): string {
		$v = Strings::raw( $key, self::$lang );
		if ( null === $v ) {
			self::$missing[ $key ] = true;
			$v                     = Strings::raw( $key, 'ja' ) ?? Strings::raw( $key, 'en' ) ?? $key;
		}
		return $v;
	}

	/** @return list<string> keys missing in the last rendered language (tests, gaps view). */
	public static function missing_keys(): array {
		return array_keys( self::$missing );
	}

	/**
	 * @param array<string,mixed>                                                            $row
	 * @param array{slug:string,audience:string,variants:list<string>,needs_reservation:bool} $def
	 * @param array<string,mixed>|null                                                       $reservation
	 */
	private static function build( array $row, array $def, ?array $reservation, string $lang ): Rendered {
		self::$missing = [];
		self::$lang    = $lang;
		$customer      = 'customer' === $def['audience'];
		$d             = self::data( $row, $reservation, $lang, $customer );
		$t             = [ self::class, 't' ];
		$file          = self::dir() . '/' . $def['slug'] . '.php';
		if ( ! is_readable( $file ) ) {
			throw new RenderException( 'template file missing' );
		}
		$view = ( static function ( string $__file, array $d, callable $t ) {
			return include $__file;
		} )( $file, $d, $t );
		if ( ! is_array( $view ) ) {
			throw new RenderException( 'template returned no view' );
		}
		$subject = Html::fill_text( $t( (string) $view['subject_key'] ), (array) ( $view['subject_args'] ?? [] ) );
		$subject = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $subject ) );
		$v       = array_merge( $d, $view, [ 'subject' => $subject ] );
		if ( $customer ) {
			$v = array_merge( $v, self::customer_chrome( $d ) );
		}
		$layout = $customer ? '_layout' : '_layout-compact';
		$html   = self::include_layout( $layout . '.html.php', $v );
		$text   = self::include_layout( $layout . '.txt.php', $v );
		return new Rendered( $subject, $html, $text, $lang, $lang, $customer ? ( $d['branch']['email'] ?: null ) : null, Brand::sender_name( $lang ) );
	}

	/** @param array<string,mixed> $v */
	private static function include_layout( string $file, array $v ): string {
		$path = self::dir() . '/' . $file;
		if ( ! is_readable( $path ) ) {
			throw new RenderException( 'layout missing' );
		}
		ob_start();
		try {
			( static function ( string $__path, array $v ): void {
				include $__path;
			} )( $path, $v );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw new RenderException( 'layout error', 0, $e );
		}
		return (string) ob_get_clean();
	}

	/**
	 * Data contract shared by all templates.
	 *
	 * @param array<string,mixed>      $row
	 * @param array<string,mixed>|null $r
	 * @return array<string,mixed>
	 */
	private static function data( array $row, ?array $r, string $lang, bool $customer ): array {
		$branch_id = (int) ( $r['branch_id'] ?? $row['branch_id'] ?? 0 );
		$branch    = $branch_id ? self::branch_view( $branch_id, $lang, $customer ) : self::empty_branch();
		$d         = [
			'row'         => $row,
			'variant'     => (string) ( $row['template_variant'] ?? '' ),
			'lang'        => $lang,
			'dir'         => Lang::is_rtl( $lang ) ? 'rtl' : 'ltr',
			'brand'       => Brand::trading_name( $lang ),
			'branch'      => $branch,
			'branch_id'   => $branch_id,
			'reservation' => $r,
			'created_utc' => Clock::from_mysql_utc( (string) $row['created_at'] ) ?? Clock::utc(),
			'settings'    => self::branch_settings( $branch_id ),
			'jst'         => 'ja' === $lang ? '' : self::t( 'email.jst' ),
		];
		if ( $r ) {
			$d['ref']       = Crockford::display( (string) $r['public_ref'] );
			$d['status']    = (string) $r['status'];
			$d['date']      = EmailFormatter::date_long( (string) $r['requested_date'], $lang );
			$d['time']      = $customer ? EmailFormatter::customer_time( (string) $r['requested_time'], $lang, $d['jst'] ) : EmailFormatter::time( (string) $r['requested_time'] ) . ( 'en' === $lang ? ' JST' : '' );
			$d['party']     = self::party( (int) $r['party_size'] );
			$d['message']   = trim( (string) ( $r['message_to_customer'] ?? '' ) );
			$d['carrier']   = self::is_carrier( (string) ( $r['email'] ?? '' ) );
			$d['note']      = '' !== trim( (string) ( $r['special_request'] ?? '' ) );
			$d['admin_url'] = self::admin_reservation_url( (int) $r['id'] );
			if ( $customer ) {
				$d['status_url']  = self::status_url( $r, $lang );
				$d['request_url'] = Url::to( '/reservation/?branch=' . rawurlencode( $branch['code'] ), $lang );
			} else {
				$d['given_name'] = EmailFormatter::given_name( (string) ( $r['guest_name'] ?? '' ) );
			}
		}
		return $d;
	}

	/** @return array<string,mixed> chrome for the customer layout (header, branch block, help, footer) */
	private static function customer_chrome( array $d ): array {
		return [
			'details_rows'   => isset( $d['ref'] ) ? [
				[ self::t( 'email.details.branch' ), $d['branch']['name'] ],
				[ self::t( 'email.details.date' ), $d['date'] ],
				[ self::t( 'email.details.time' ), $d['time'] ],
				[ self::t( 'email.details.party' ), $d['party'] ],
			] : [],
			'reference_text' => self::t( 'res.submitted.reference' ),
			'labels'         => [
				'address_ja' => self::t( 'email.branch.address_ja' ),
				'call'       => self::t( 'email.branch.call' ),
				'directions' => self::t( 'cta.directions' ),
				'carrier'    => self::t( 'res.carrier_tip' ),
				'auto'       => self::t( 'email.footer.auto' ),
				'privacy'    => self::t( 'ftr.privacy' ),
				'copyright'  => self::t( 'email.footer.copyright' ),
			],
			'carrier_domain' => MailIdentity::whitelist_domain(),
			'privacy_url'    => Url::to( '/privacy-policy/', $d['lang'] ),
			'operator'       => Brand::operator_name(),
			'year'           => Clock::jst()->format( 'Y' ),
		];
	}

	public static function party( int $n ): string {
		return Html::fill_text( self::t( 1 === $n ? 'email.details.party_one' : 'email.details.party_other' ), [ 'n' => $n ] );
	}

	/** Customer duration text for `{duration}` (minutes → hours when whole). */
	public static function duration( int $minutes ): string {
		if ( $minutes >= 60 && 0 === $minutes % 60 ) {
			$h = intdiv( $minutes, 60 );
			return Html::fill_text( self::t( 1 === $h ? 'email.duration.hour' : 'email.duration.hours' ), [ 'n' => $h ] );
		}
		return Html::fill_text( self::t( 'email.duration.minutes' ), [ 'n' => $minutes ] );
	}

	/** @return array<string,mixed> */
	private static function branch_view( int $branch_id, string $lang, bool $customer ): array {
		$b = class_exists( \Momos\Branch\Repository\BranchRepository::class ) ? \Momos\Branch\Repository\BranchRepository::get( $branch_id ) : null;
		if ( null === $b ) {
			$p = get_post( $branch_id );
			$m = static fn( string $k ): string => (string) get_post_meta( $branch_id, $k, true );
			$b = [
				'code'          => $m( 'momo_branch_code' ),
				'name'          => $p ? (string) $p->post_title : '',
				'phone_e164'    => $m( 'momo_phone_e164' ),
				'phone_display' => $m( 'momo_phone_display' ),
				'email'         => $m( 'momo_branch_email' ),
				'address_ja'    => $m( 'momo_address_ja' ),
				'lat'           => '' !== $m( 'momo_lat' ) ? (float) $m( 'momo_lat' ) : null,
				'lng'           => '' !== $m( 'momo_lng' ) ? (float) $m( 'momo_lng' ) : null,
				'place_id'      => $m( 'momo_place_id' ),
				'is_test'       => '1' === $m( 'momo_is_test_branch' ),
			];
		}
		$name = \Momos\Kernel\I18n\Translations::post( $branch_id, 'title', $lang );
		if ( '' === $name && ! $customer ) {
			$name = (string) $b['name']; // Staff emails may show the source name (admin context).
		}
		$local = '';
		if ( 'ja' !== $lang ) {
			$parts = [];
			foreach ( [ 'momo_address_line2', 'momo_address_line1', 'momo_city', 'momo_prefecture' ] as $f ) {
				$v = trim( \Momos\Kernel\I18n\Translations::post( $branch_id, $f, $lang ) );
				if ( '' !== $v ) {
					$parts[] = $v;
				}
			}
			$postal = (string) get_post_meta( $branch_id, 'momo_postal_code', true );
			if ( $parts && '' !== $postal ) {
				$parts[] = $postal;
			}
			$local = implode( ', ', $parts );
		}
		$directions = '';
		if ( null !== $b['lat'] && null !== $b['lng'] ) {
			$directions = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode( $b['lat'] . ',' . $b['lng'] );
			if ( '' !== (string) ( $b['place_id'] ?? '' ) ) {
				$directions .= '&destination_place_id=' . rawurlencode( (string) $b['place_id'] );
			}
		}
		$email = strtolower( trim( (string) $b['email'] ) );
		return [
			'id'            => $branch_id,
			'code'          => (string) $b['code'],
			'name'          => $name,
			'address_local' => $local,
			'address_ja'    => (string) $b['address_ja'],
			'phone'         => EmailFormatter::phone( (string) $b['phone_e164'], (string) $b['phone_display'], $lang ),
			'tel'           => '' !== (string) $b['phone_e164'] ? 'tel:' . preg_replace( '/[^+\d]/', '', (string) $b['phone_e164'] ) : '',
			'directions'    => $directions,
			'email'         => is_email( $email ) ? $email : '',
			'is_test'       => (bool) $b['is_test'],
		];
	}

	/** @return array<string,mixed> */
	private static function empty_branch(): array {
		return [ 'id' => 0, 'code' => '', 'name' => '', 'address_local' => '', 'address_ja' => '', 'phone' => '', 'tel' => '', 'directions' => '', 'email' => '', 'is_test' => false ];
	}

	/** Branch-level name translation must be approved for a customer email in that language. */
	private static function branch_ready( ?array $r, string $lang ): bool {
		if ( 'ja' === $lang || null === $r ) {
			return true;
		}
		return '' !== \Momos\Kernel\I18n\Translations::post( (int) $r['branch_id'], 'title', $lang );
	}

	/** @return array<string,mixed> */
	private static function branch_settings( int $branch_id ): array {
		$defaults = [ 'decision_cutoff_minutes' => 60, 'response_sla_minutes' => 120, 'show_response_promise' => false, 'policy_page' => null ];
		if ( ! $branch_id || ! class_exists( \Momos\Branch\Repository\BranchSettingsRepository::class ) ) {
			return $defaults;
		}
		return array_merge( $defaults, \Momos\Branch\Repository\BranchSettingsRepository::get( $branch_id ) );
	}

	/** Customer status link (DEC-058/064) when the status page is live; '' otherwise. */
	private static function status_url( array $r, string $lang ): string {
		if ( ! class_exists( \Momos\Reservation\Service\TokenService::class ) || ! empty( $r['pii_purged_at'] ) ) {
			return '';
		}
		$live = class_exists( \Momos\Reservation\Support\Options::class ) ? \Momos\Reservation\Support\Options::bool( 'momo_res_status_page_enabled' ) : true;
		if ( ! $live ) {
			return '';
		}
		try {
			$url = \Momos\Reservation\Service\TokenService::status_url( $r );
		} catch ( \Throwable $e ) {
			return '';
		}
		$hash = strstr( $url, '#' );
		$base = false === $hash ? $url : substr( $url, 0, -strlen( $hash ) );
		return Url::localize( $base, $lang ) . ( false === $hash ? '' : $hash );
	}

	/** Staff deep link (login + 2FA required; 403 when unassigned). Filterable for the inbox route. */
	public static function admin_reservation_url( int $id ): string {
		$url = admin_url( 'admin.php?page=momos-reservations&id=' . $id );
		return (string) apply_filters( 'momos/notification/admin_reservation_url', $url, $id );
	}

	/** Carrier email (FR-RES-029): domain ∈ `momo_carrier_domains`. */
	public static function is_carrier( string $email ): bool {
		$domain = strtolower( (string) substr( (string) strrchr( trim( $email ), '@' ), 1 ) );
		if ( '' === $domain ) {
			return false;
		}
		if ( class_exists( \Momos\Reservation\Support\Options::class ) ) {
			$list = \Momos\Reservation\Support\Options::domains( 'momo_carrier_domains' );
		} else {
			$list = [ 'docomo.ne.jp', 'ezweb.ne.jp', 'au.com', 'softbank.ne.jp', 'i.softbank.jp', 'ymobile.ne.jp' ];
		}
		return in_array( $domain, $list, true );
	}

	/** JST label for times inside customer sentences (non-ja only). */
	public static function jst_time( \DateTimeImmutable $utc, string $requested_date, string $lang ): string {
		$jst  = $utc->setTimezone( Clock::tz() );
		$time = EmailFormatter::customer_time( $jst->format( 'H:i' ), $lang, 'ja' === $lang ? '' : self::t( 'email.jst' ) );
		if ( $jst->format( 'Y-m-d' ) !== substr( $requested_date, 0, 10 ) ) {
			return EmailFormatter::date_long( $jst->format( 'Y-m-d' ), $lang ) . ' ' . $time;
		}
		return $time;
	}
}
