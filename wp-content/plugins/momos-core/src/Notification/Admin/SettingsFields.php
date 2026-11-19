<?php
declare(strict_types=1);

namespace Momos\Notification\Admin;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\OptionsPage;
use Momos\Kernel\Settings;
use Momos\Notification\Transport\SmtpConfigurator;

/**
 * SCR-A15 tab "Notifications / 通知設定" (spec §3.3, §7.4): options registered in code on the G3
 * options page `momo-settings` (cap `momo_manage_brand`). Option names are the documented ones.
 * Read-only transport status (SA only, never credentials) is appended after the fields.
 */
final class SettingsFields {
	public const PAGE = 'momo-settings';

	public const MAX_RECIPIENTS = 10;

	public static function register(): void {
		add_action( 'init', [ self::class, 'register_groups' ], 8 );
		add_action( 'momos/options_page/after_fields', [ self::class, 'transport_status' ], 20 );
	}

	public static function register_groups(): void {
		// The G3 page is shared with other modules (Privacy/Monitoring tabs); registering it again is idempotent.
		OptionsPage::add( self::PAGE, __( 'Settings', 'momos-core' ), 'momo_manage_brand' );
		$recipient_sub = [
			[ 'name' => 'email', 'label' => __( 'Email', 'momos-core' ), 'type' => 'email', 'required' => true ],
			[ 'name' => 'language', 'label' => __( 'Language', 'momos-core' ), 'type' => 'select', 'no_empty' => true, 'default' => 'ja', 'choices' => [ 'ja' => '日本語', 'en' => 'English' ] ],
			[ 'name' => 'active', 'label' => __( 'Active', 'momos-core' ), 'type' => 'true_false', 'default' => '1' ],
		];
		FieldRegistry::group(
			[
				'key'          => 'momo_settings_notifications',
				'title'        => __( 'Notifications / 通知設定', 'momos-core' ),
				'options_page' => self::PAGE,
				'order'        => 30,
				'fields'       => [
					[
						'name'         => 'momo_sender_name',
						'label'        => __( 'Sender display name', 'momos-core' ),
						'type'         => 'text',
						'maxlength'    => 60,
						'translatable' => true,
						'edit_cap'     => 'momo_manage_brand',
						'help'         => __( 'Shown as the From name of all emails. Translations per language in the translation editor; missing languages use the trading name.', 'momos-core' ),
						'validate'     => [ self::class, 'validate_sender_name' ],
					],
					[
						'name'         => 'momo_ho_escalation_recipients',
						'label'        => __( 'Head Office notification list (escalations, daily summary, certificate alerts)', 'momos-core' ),
						'type'         => 'repeater',
						'max_rows'     => self::MAX_RECIPIENTS,
						'edit_cap'     => 'momo_manage_brand',
						'button_label' => __( 'Add recipient', 'momos-core' ),
						'help'         => __( 'Business-controlled addresses only (no personal webmail). At least one active address is required before launch.', 'momos-core' ),
						'sub_fields'   => $recipient_sub,
						'validate'     => [ self::class, 'validate_recipients' ],
					],
					[
						'name'         => 'momo_digest_recipients',
						'label'        => __( 'Additional daily summary recipients', 'momos-core' ),
						'type'         => 'repeater',
						'max_rows'     => self::MAX_RECIPIENTS,
						'edit_cap'     => 'momo_manage_brand',
						'button_label' => __( 'Add recipient', 'momos-core' ),
						'sub_fields'   => $recipient_sub,
						'validate'     => [ self::class, 'validate_recipients' ],
					],
					[ 'name' => 'momo_digest_enabled', 'label' => __( 'Send the daily summary (E10)', 'momos-core' ), 'type' => 'true_false', 'default' => '1', 'edit_cap' => 'momo_manage_brand' ],
					[
						'name'     => 'momo_digest_time',
						'label'    => __( 'Daily summary time (JST)', 'momos-core' ),
						'type'     => 'time',
						'default'  => '09:00',
						'required' => true,
						'edit_cap' => 'momo_manage_brand',
						'validate' => [ self::class, 'validate_digest_time' ],
					],
					[ 'name' => 'momo_res_show_dont_travel', 'label' => __( 'Show "don\'t travel based on this request" line in E01', 'momos-core' ), 'type' => 'true_false', 'default' => '1', 'edit_cap' => 'momo_manage_brand' ],
				],
			]
		);
	}

	/** Setting value from the options page (with registered default), else a plain option. */
	public static function get( string $name, mixed $default = null ): mixed {
		if ( null !== FieldRegistry::option_page_of( $name ) ) {
			return Settings::get( $name, $default );
		}
		return get_option( $name, $default );
	}

	public static function validate_sender_name( mixed $value ): ?string {
		$v = (string) $value;
		if ( preg_match( '/[\r\n<>@"]/', $v ) ) {
			return __( 'The sender name cannot contain line breaks or the characters < > @ ".', 'momos-core' );
		}
		return null;
	}

	public static function validate_digest_time( mixed $value ): ?string {
		$v = (string) $value;
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $v, $m ) || 0 !== ( (int) $m[2] ) % 5 ) {
			return __( 'Enter the summary time as HH:MM in 5-minute steps (JST).', 'momos-core' );
		}
		return null;
	}

	/** Same rules as branch recipients (DEC-049, PRV-010, FR-RES-023): ≤ 10, valid, unique, business domains. */
	public static function validate_recipients( mixed $value ): ?string {
		$rows = is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : [];
		if ( class_exists( \Momos\Branch\Service\SettingsValidator::class ) ) {
			$errors = \Momos\Branch\Service\SettingsValidator::validate_recipients( $rows, 0 );
			return $errors ? implode( ' ', $errors ) : null;
		}
		if ( count( $rows ) > self::MAX_RECIPIENTS ) {
			/* translators: %d: maximum */
			return sprintf( __( 'Add at most %d notification recipients.', 'momos-core' ), self::MAX_RECIPIENTS );
		}
		$seen = [];
		foreach ( $rows as $row ) {
			$email = strtolower( trim( (string) ( $row['email'] ?? '' ) ) );
			if ( ! is_email( $email ) ) {
				return __( 'Enter a valid email address for every recipient.', 'momos-core' );
			}
			if ( isset( $seen[ $email ] ) ) {
				return __( 'Each recipient email can be listed only once.', 'momos-core' );
			}
			$seen[ $email ] = true;
		}
		return null;
	}

	/** Read-only transport status block (SA only — `manage_options`; never credentials). */
	public static function transport_status( string $slug ): void {
		if ( self::PAGE !== $slug || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = SmtpConfigurator::status();
		$counts = \Momos\Notification\Repository\NotificationRepository::counts();
		$beat   = (string) get_option( 'momos_job_heartbeat_queue', '' );
		echo '<h2>' . esc_html__( 'Email transport (read-only)', 'momos-core' ) . '</h2><table class="widefat striped" style="max-width:640px"><tbody>';
		$rows = [
			__( 'Transport', 'momos-core' )               => $s['transport'],
			__( 'SMTP host', 'momos-core' )               => $s['host'],
			__( 'Port / TLS', 'momos-core' )              => $s['port'] . ' / ' . $s['tls'],
			__( 'Fallback connection', 'momos-core' )     => $s['fallback'],
			__( 'From address', 'momos-core' )            => $s['from'],
			__( 'Sandbox (staging reroute)', 'momos-core' ) => $s['sandbox'],
			__( 'Last queue heartbeat (UTC)', 'momos-core' ) => '' !== $beat ? $beat : '—',
			__( 'Queued now', 'momos-core' )              => (string) $counts['queued'],
			__( 'Failed in the last 24 h', 'momos-core' ) => (string) $counts['failed_24h'],
		];
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}
}
