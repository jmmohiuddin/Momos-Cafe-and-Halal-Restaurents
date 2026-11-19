<?php
declare(strict_types=1);

namespace Momos\Reservation\Front;

use Momos\Api\Captcha\TurnstileVerifier;
use Momos\Branch\Repository\BranchRepository;
use Momos\Branch\Repository\BranchSettingsRepository;
use Momos\Kernel\I18n\Lang;
use Momos\Kernel\I18n\Locale;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\I18n\Url;
use Momos\Kernel\Settings;
use Momos\Presentation\Assets;
use Momos\Presentation\Format\PhoneFormatter;
use Momos\Presentation\SystemPages;
use Momos\Presentation\Templates\TemplateRouter;
use Momos\Presentation\View\BranchLinks;
use Momos\Reservation\Service\SlotGenerator;
use Momos\Reservation\Support\Options;

/**
 * View models and assets of the customer reservation screens (reservation.md §6, screens.md §3.9–3.11):
 *  - SCR-C09 form (F-RES-01, FR-RES-001..005/027/029): server-rendered shell + `reservation-form.js`
 *    on API-01/API-02. JavaScript is required (pickers + Turnstile, Doc 05 WF-CUS-07 precondition);
 *    without it a `<noscript>` message lists the branch phones.
 *  - SCR-C10 submitted (F-RES-02, FR-RES-006): rendered client-side from sessionStorage, no request,
 *    no analytics / Turnstile / third-party script (DEC-066).
 *  - SCR-C11 status (F-RES-03, FR-RES-019/020): non-personalised shell; the `#t=` token is read by
 *    `reservation-status.js`, removed from the address bar and sent only in `X-Momo-Token` (DEC-058).
 * The theme templates only print what these methods return (DEC-008: no business logic in the theme).
 */
final class CustomerPages {
	public const ROLES = [ 'reservation', 'submitted', 'status' ];

	/** Status reference after normalisation (Crockford, spec §3.5). */
	public const REF_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{8}$/';

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ], 20 );
		add_action( 'template_redirect', [ self::class, 'guard_status_ref' ], 4 );
		add_filter( 'momos/presentation/switcher_visible', [ self::class, 'switcher_visible' ], 10, 3 );
	}

	/**
	 * C09–C11 are string-driven (no translated page body): every enabled language has them, so the
	 * switcher keeps the visitor on the same screen (C11 re-attaches `#t=` client-side) instead of
	 * sending them to the home page with the "untranslated" notice.
	 *
	 * @param mixed $object queried object
	 */
	public static function switcher_visible( bool $visible, string $lang, $object ): bool {
		if ( $object instanceof \WP_Post && in_array( SystemPages::role_of( (int) $object->ID ), self::ROLES, true ) ) {
			return Lang::is_enabled( $lang );
		}
		return $visible;
	}

	/** Reservation role of the current request ('' when not a reservation screen). */
	public static function role(): string {
		if ( ! is_page() ) {
			return '';
		}
		$role = SystemPages::role_of( (int) get_queried_object_id() );
		return in_array( $role, self::ROLES, true ) ? $role : '';
	}

	/**
	 * C11 without a well-formed reference is a friendly 404 (no lookup is ever made by the server;
	 * unknown, expired and purged references are answered by API-03 and shown as `status.link_invalid`).
	 */
	public static function guard_status_ref(): void {
		if ( 'status' !== self::role() ) {
			return;
		}
		if ( null === self::status_ref() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/** Normalised reference of the C11 URL, or null when missing/malformed. */
	public static function status_ref(): ?string {
		$raw = (string) get_query_var( TemplateRouter::QV_REF );
		if ( '' === $raw ) {
			return null;
		}
		$ref = strtr( strtoupper( str_replace( [ '-', ' ' ], '', $raw ) ), [ 'O' => '0', 'I' => '1', 'L' => '1' ] );
		return preg_match( self::REF_PATTERN, $ref ) ? $ref : null;
	}

	public static function enqueue(): void {
		$role = self::role();
		if ( '' === $role || ! Assets::active() ) {
			return;
		}
		wp_enqueue_style( 'momos-reservation', get_theme_file_uri( 'assets/css/reservation.css' ), [ 'momos-screens' ], Assets::version( 'assets/css/reservation.css' ) );
		if ( 'reservation' === $role ) {
			add_action( 'wp_head', [ self::class, 'noscript_style' ], 20 );
		}
		$args = [ 'in_footer' => true, 'strategy' => 'defer' ];
		wp_enqueue_script( 'momos-reservation-common', get_theme_file_uri( 'assets/js/reservation-common.js' ), [], Assets::version( 'assets/js/reservation-common.js' ), $args );
		$script = [
			'reservation' => 'reservation-form',
			'submitted'   => 'reservation-submitted',
			'status'      => 'reservation-status',
		][ $role ];
		wp_enqueue_script( 'momos-' . $script, get_theme_file_uri( 'assets/js/' . $script . '.js' ), [ 'momos-reservation-common' ], Assets::version( 'assets/js/' . $script . '.js' ), $args );
		// Turnstile loads on C09 only (screens.md §3.9), and only in live mode: test mode never needs the network.
		if ( 'reservation' === $role && 'live' === TurnstileVerifier::mode() && '' !== TurnstileVerifier::site_key() ) {
			wp_enqueue_script( 'momos-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', [], null, $args ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party URL must not carry ?ver.
		}
	}

	/**
	 * C09 without JavaScript: the form (visible by default so nothing shifts when the script runs,
	 * NFR-PERF-01) is hidden and only the `<noscript>` phone list remains (reservation.md §6.1).
	 */
	public static function noscript_style(): void {
		echo '<noscript><style>.momo-res-form{display:none}</style></noscript>' . "\n";
	}

	/**
	 * Raw catalog templates (placeholders kept) for client-side rendering in $lang.
	 *
	 * @param list<string> $keys
	 * @return array<string,string>
	 */
	public static function strings( array $keys, string $lang ): array {
		$out = [];
		foreach ( $keys as $k ) {
			$out[ $k ] = momos_t( $k, [], $lang );
		}
		return $out;
	}

	/**
	 * Branches offered by the C09 selector in $lang, in Head Office order (BranchRepository order).
	 * Hidden: unpublished, permanently closed, not approved in $lang (BR-I18N-02), TEST unless the
	 * logged-in user is assigned to it (FR-RES-026).
	 *
	 * @return list<array{code:string,name:string,accepting:bool,reason:?string,phone:string,tel:string,policy_url:?string,is_test:bool}>
	 */
	public static function branches( string $lang ): array {
		$user = get_current_user_id();
		$out  = [];
		foreach ( BranchRepository::published_ids( true ) as $id ) {
			$b = BranchRepository::get( (int) $id );
			if ( null === $b || 'publish' !== $b['status'] || 'closed_permanently' === $b['operating_status'] || '' === (string) $b['code'] ) {
				continue;
			}
			if ( $b['is_test'] && ! ( $user && momos_user_can_branch( (int) $id, $user ) && user_can( $user, 'momo_view_reservations' ) ) ) {
				continue;
			}
			$row = BranchLinks::row( (int) $id, $lang );
			if ( null === $row ) {
				continue; // Not approved in $lang.
			}
			$settings = BranchSettingsRepository::get( (int) $id );
			$acc      = SlotGenerator::accepting( $b, $settings );
			$policy   = null;
			if ( ! empty( $settings['policy_page'] ) && Translations::post_visible( (int) $settings['policy_page'], $lang ) ) {
				$policy = Url::localize( (string) get_permalink( (int) $settings['policy_page'] ), $lang );
			}
			$out[] = [
				'code'       => (string) $b['code'],
				'name'       => (string) $row['name'],
				'accepting'  => (bool) $acc['accepting'],
				'reason'     => $acc['reason'],
				'phone'      => (string) $row['phone'],
				'tel'        => (string) $row['tel'],
				'policy_url' => $policy,
				'is_test'    => (bool) $b['is_test'],
			];
		}
		return $out;
	}

	/**
	 * Head Office contact for anyone unable to use the form (reasonable accommodation, CMP-032, DEC-036,
	 * TC-A11Y-012): Brand settings G1 phone and e-mail; empty values are omitted.
	 *
	 * @return array{phone:string,tel:string,email:string}
	 */
	public static function alt_contact( ?string $lang = null ): array {
		$e164  = trim( (string) Settings::get( 'momo_contact_phone_e164', '' ) );
		$phone = trim( (string) Settings::get( 'momo_contact_phone_display', '' ) );
		$email = sanitize_email( (string) Settings::get( 'momo_contact_email', '' ) );
		$tel   = preg_match( '/^\+\d{8,15}$/', $e164 ) ? 'tel:' . $e164 : '';
		return [
			'phone' => '' !== $tel ? PhoneFormatter::display( $e164, $phone, $lang ?? Locale::current() ) : '', // national in ja, international elsewhere
			'tel'   => $tel,
			'email' => is_email( $email ) ? $email : '',
		];
	}

	/** `?branch=CODE` (and `date`/`time` from "Request another time" links); invalid values are ignored. @return array{branch:string,date:string,time:string} */
	public static function prefill(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only prefill of a public form.
		$code = isset( $_GET['branch'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_GET['branch'] ) ) ) : '';
		$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : '';
		$time = isset( $_GET['time'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['time'] ) ) : '';
		// phpcs:enable
		return [
			'branch' => preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '',
			'date'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '',
			'time'   => preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '',
		];
	}

	/** Keys rendered by reservation-form.js. */
	public const FORM_KEYS = [
		'res.err.summary', 'res.err.branch.required', 'res.err.branch.unavailable', 'res.err.branch.not_found', 'res.err.date.required',
		'res.err.date.closed', 'res.err.date.window', 'res.err.date.too_soon', 'res.err.time.too_soon', 'res.err.time.required',
		'res.err.time.unavailable', 'res.err.party.required', 'res.err.party.min', 'res.err.party.max_call', 'res.err.name.required',
		'res.err.name.length', 'res.err.name.format', 'res.err.email.required', 'res.err.email.format', 'res.err.phone.format', 'res.err.note.length',
		'res.err.consent.required', 'res.err.special_consent.required', 'res.err.limit.active', 'res.err.rate', 'res.err.captcha', 'res.err.system',
		'res.err.reference', 'common.retry', 'res.err.lang', 'res.info.no_times', 'res.info.branch_changed', 'res.date.closed_suffix',
		'res.date.too_soon_suffix', 'res.date.today', 'res.date.tomorrow', 'res.party.more', 'res.call_alt',
		'res.submitted.promise', 'res.summary.reply_to', 'res.summary.empty', 'res.submit', 'res.submit.busy', 'res.loading_times',
		'res.date.choose', 'res.policy.link', 'res.slots.error', 'common.tz_label', 'cta.call', 'email.duration.minutes',
		'email.duration.hour', 'email.duration.hours', 'period.lunch', 'period.dinner', 'period.all_day', 'period.other',
		'form.error_prefix', 'res.info.entries_cleared',
	];

	/**
	 * Everything C09 needs (screens.md §3.9). No personal data; safe to cache per language.
	 *
	 * @return array<string,mixed>
	 */
	public static function form_view( ?string $lang = null ): array {
		$lang     = $lang ?? Locale::current();
		$branches = self::branches( $lang );
		$prefill  = self::prefill();
		$codes    = array_column( $branches, 'code' );
		if ( '' !== $prefill['branch'] && ! in_array( $prefill['branch'], $codes, true ) ) {
			$prefill = [ 'branch' => '', 'date' => '', 'time' => '' ]; // Unknown/unpublished: left empty, no error.
		}
		$test  = '' !== $prefill['branch'] && ! empty( array_values( array_filter( $branches, static fn( $b ) => $b['code'] === $prefill['branch'] ) )[0]['is_test'] );
		$langs = [];
		foreach ( Lang::enabled() as $code ) {
			$langs[ $code ] = Lang::native( $code );
		}
		return [
			'lang'             => $lang,
			'branches'         => $branches,
			'any_accepting'    => (bool) array_filter( $branches, static fn( $b ) => $b['accepting'] ),
			'prefill'          => $prefill,
			'is_test'          => $test,
			'alt_contact'      => self::alt_contact( $lang ),
			'langs'            => $langs,
			'privacy_url'      => SystemPages::legal_url( 'privacy', $lang ),
			'res_policy_url'   => SystemPages::legal_url( 'res_policy', $lang ),
			'separate_consent' => Options::bool( 'momo_special_request_separate_consent' ),
			'consent_version'  => Options::consent_version(),
			'config'           => [
				'lang'            => $lang,
				'sprite'          => Assets::sprite_url(),
				'api'             => esc_url_raw( rest_url( 'momos/v1/' ) ),
				'submittedUrl'    => (string) SystemPages::url( 'submitted', $lang ),
				'captcha'         => [
					'mode'    => TurnstileVerifier::mode(),
					'siteKey' => TurnstileVerifier::site_key(),
					'action'  => TurnstileVerifier::ACTION,
				],
				'prefill'         => $prefill,
				'branches'        => $branches,
				'analytics'       => ! is_user_logged_in(),
				'separateConsent' => Options::bool( 'momo_special_request_separate_consent' ),
				'i18n'            => self::strings( self::FORM_KEYS, $lang ),
			],
		];
	}

	public const SUBMITTED_KEYS = [
		'res.submitted.heading', 'res.info.duplicate', 'res.submitted.reference', 'res.submitted.email_to', 'res.submitted.next',
		'res.submitted.expiry', 'res.submitted.dont_travel', 'res.submitted.promise', 'res.submitted.status_link', 'res.copied',
		'res.copy', 'res.carrier_tip', 'res.label.branch', 'res.label.date', 'res.label.time', 'res.label.party', 'res.label.name',
		'res.summary.title', 'res.next.title', 'res.ref.aria', 'common.tz_label', 'cta.call',
		'email.duration.minutes', 'email.duration.hour', 'email.duration.hours',
	];

	/** @return array<string,mixed> C10 shell data (no personal data). */
	public static function submitted_view( ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		return [
			'lang'   => $lang,
			'config' => [
				'lang'   => $lang,
				'sprite' => Assets::sprite_url(),
				'i18n'   => self::strings( self::SUBMITTED_KEYS, $lang ),
			],
		];
	}

	public const STATUS_KEYS = [
		'status.pending', 'status.confirmed', 'status.rejected', 'status.cancelled', 'status.expired', 'status.past',
		'status.meaning.pending', 'status.meaning.confirmed', 'status.meaning.rejected', 'status.meaning.cancelled', 'status.meaning.expired',
		'status.cancelled_by.customer', 'status.cancelled_by.branch', 'status.cancelled_by.system', 'status.cancel.cta.pending',
		'status.cancel.cta.confirmed', 'status.cancel.confirm', 'status.cancel.keep', 'status.cancel.yes', 'status.cancel.done',
		'status.link_invalid', 'status.cancel.not_allowed', 'status.request_another', 'status.history', 'status.details',
		'status.message_from_branch', 'status.branch_info', 'status.cancel.deadline', 'status.error', 'status.title',
		'decline.suggested', 'res.submitted.reference', 'res.label.branch', 'res.label.date', 'res.label.time', 'res.label.party',
		'res.label.name', 'res.ref.aria', 'res.policy.title', 'common.tz_label', 'common.retry', 'cta.call', 'cta.directions',
		'a11y.new_tab',
	];

	/** @return array<string,mixed> C11 shell data: same for every visitor (DEC-058). */
	public static function status_view( ?string $lang = null ): array {
		$lang = $lang ?? Locale::current();
		$ref  = self::status_ref();
		return [
			'lang'     => $lang,
			'ref'      => $ref,
			'branches' => BranchLinks::all( $lang ),
			'config'   => [
				'lang'         => $lang,
				'sprite'       => Assets::sprite_url(),
				'ref'          => $ref,
				'api'          => esc_url_raw( rest_url( 'momos/v1/' ) ),
				'formUrl'      => (string) SystemPages::url( 'reservation', $lang ),
				'resPolicyUrl' => SystemPages::legal_url( 'res_policy', $lang ),
				'i18n'         => self::strings( self::STATUS_KEYS, $lang ),
			],
		];
	}

	/** JSON for a `<script type="application/json">` block (safe inside HTML). @param array<string,mixed> $data */
	public static function json( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	}
}
