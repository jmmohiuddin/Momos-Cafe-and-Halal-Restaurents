<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Cli\Commands;
use Momos\Kernel\Monitoring\AlertMailer;
use Momos\Kernel\Monitoring\StatusPanel;
use Momos\Kernel\Settings;
use Momos\Tests\TestCase;

/**
 * FR-SEC-012 / NFR-OBS-01 alert transport and FR-FND-006 plugin register (TC-SEC-009): `momos/monitoring/alert`
 * sends one throttled mail per type to the G3 alert recipients; an active plugin missing from
 * config/plugin-inventory.php (or more than 15 plugins) is reported and fails `wp momos smoke`.
 */
final class KernelMonitoringTest extends TestCase {
	/** @var list<array<string,mixed>> */
	private array $mails = [];

	public function set_up(): void {
		parent::set_up();
		$this->mails = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture' ], 1, 2 );
		foreach ( [ 'qa_job_late', 'qa_other' ] as $t ) {
			delete_transient( 'momos_alert_' . $t );
		}
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', [ $this, 'capture' ], 1 );
		Settings::flush();
		parent::tear_down();
	}

	/**
	 * @param null|bool           $short
	 * @param array<string,mixed> $atts
	 */
	public function capture( $short, array $atts ): bool {
		$this->mails[] = $atts;
		return true; // Never reaches a transport.
	}

	public function test_alert_action_is_wired_to_the_mailer(): void {
		$this->assertSame( 10, has_action( 'momos/monitoring/alert', [ AlertMailer::class, 'send' ] ) );
	}

	public function test_alert_sends_one_throttled_mail_to_the_alert_recipients(): void {
		Settings::set( 'momo_alert_recipients', "ops@example.org\nvendor-oncall@example.org, not-an-email" );

		do_action( 'momos/monitoring/alert', 'qa_job_late', [ 'job' => 'momos_job_retention', 'minutes_late' => 95 ] );
		do_action( 'momos/monitoring/alert', 'qa_job_late', [ 'job' => 'momos_job_retention', 'minutes_late' => 96 ] );
		do_action( 'momos/monitoring/alert', 'QA_JOB_LATE', [] ); // Same type after sanitize_key.

		$this->assertCount( 1, $this->mails, 'one mail per type per hour' );
		$mail = $this->mails[0];
		$this->assertSame( [ 'ops@example.org', 'vendor-oncall@example.org' ], array_values( (array) $mail['to'] ) );
		$this->assertStringContainsString( 'Alert: qa_job_late', (string) $mail['subject'] );
		$this->assertStringContainsString( 'job: momos_job_retention', (string) $mail['message'] );
		$this->assertStringContainsString( 'minutes_late: 95', (string) $mail['message'] );
		$this->assertNotFalse( get_transient( 'momos_alert_qa_job_late' ) );

		do_action( 'momos/monitoring/alert', 'qa_other', [] );
		$this->assertCount( 2, $this->mails, 'other alert types are not throttled by this one' );

		delete_transient( 'momos_alert_qa_job_late' ); // The hour has passed.
		do_action( 'momos/monitoring/alert', 'qa_job_late', [] );
		$this->assertCount( 3, $this->mails );
	}

	public function test_alert_falls_back_to_the_site_admin_email(): void {
		Settings::set( 'momo_alert_recipients', '' );
		update_option( 'admin_email', 'site-admin@example.org' );
		$this->mails = []; // Core/Access may announce the address change itself.
		$this->assertSame( [ 'site-admin@example.org' ], AlertMailer::recipients() );
		do_action( 'momos/monitoring/alert', 'qa_other', [] );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( [ 'site-admin@example.org' ], array_values( (array) $this->mails[0]['to'] ) );
	}

	public function test_unregistered_plugin_is_reported_and_fails_smoke(): void {
		update_option( 'active_plugins', [ 'momos-core/momos-core.php' ] );
		$this->assertSame( [], StatusPanel::unregistered_plugins(), 'momos-core is in the register' );
		$this->assertNotContains( 'unregistered plugin momos-core/momos-core.php', Commands::smoke_failures() );

		update_option( 'active_plugins', [ 'momos-core/momos-core.php', 'rogue-seo/rogue-seo.php', 'hello.php' ] );
		$this->assertSame( [ 'rogue-seo/rogue-seo.php', 'hello.php' ], StatusPanel::unregistered_plugins() );
		$fail = Commands::smoke_failures();
		$this->assertContains( 'unregistered plugin rogue-seo/rogue-seo.php', $fail );
		$this->assertContains( 'unregistered plugin hello.php', $fail );
	}

	public function test_plugin_cap_of_fifteen(): void {
		$slugs = array_column( StatusPanel::inventory(), 'slug' );
		$this->assertContains( 'momos-core', $slugs );
		foreach ( StatusPanel::inventory() as $row ) {
			foreach ( [ 'slug', 'name', 'version', 'purpose', 'third_party_data', 'last_review' ] as $k ) {
				$this->assertNotSame( '', (string) ( $row[ $k ] ?? '' ), $k . ' of ' . ( $row['slug'] ?? '?' ) );
			}
		}
		update_option( 'active_plugins', [] );
		$this->assertSame( 1, StatusPanel::active_count(), 'momos-core through the mu-loader' );
		$this->assertNotContains( 'more than 15 active plugins (FR-FND-006)', Commands::smoke_failures() );

		update_option( 'active_plugins', array_map( static fn( int $i ): string => "p{$i}/p{$i}.php", range( 1, 15 ) ) );
		$this->assertSame( 16, StatusPanel::active_count() );
		$this->assertContains( 'more than 15 active plugins (FR-FND-006)', Commands::smoke_failures() );
	}
}
