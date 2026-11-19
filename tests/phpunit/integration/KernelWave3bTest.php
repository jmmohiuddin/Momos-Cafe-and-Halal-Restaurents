<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Db\NamedLock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\I18n\Digits;
use Momos\Kernel\I18n\Translations;
use Momos\Kernel\Migration\MigrationInterface;
use Momos\Kernel\Migration\Runner;
use Momos\Tests\TestCase;

/**
 * Wave 3b kernel closers: migration runner on a fresh install (lock scope, signature only when complete),
 * Western digits on translation save (DEC-062, QA-050).
 */
final class KernelWave3bTest extends TestCase {
	/** @var list<MigrationInterface> */
	private array $extra = [];

	private ?\mysqli $other = null;

	private mixed $applied_before = null;

	private mixed $signature_before = null;

	public function set_up(): void {
		parent::set_up();
		$this->applied_before   = get_option( Runner::OPTION );
		$this->signature_before = get_option( Runner::SIGNATURE_OPTION );
		add_filter( 'momos/migrations', [ $this, 'add_extra' ] );
	}

	public function tear_down(): void {
		remove_filter( 'momos/migrations', [ $this, 'add_extra' ] );
		if ( $this->other ) {
			$this->other->close();
			$this->other = null;
		}
		update_option( Runner::OPTION, $this->applied_before, true );
		update_option( Runner::SIGNATURE_OPTION, $this->signature_before, true );
		parent::tear_down();
	}

	/**
	 * @param list<MigrationInterface> $list
	 * @return list<MigrationInterface>
	 */
	public function add_extra( array $list ): array {
		return array_merge( $list, $this->extra );
	}

	private function migration( string $id, ?\Closure $up = null ): MigrationInterface {
		return new class( $id, $up ) implements MigrationInterface {
			public int $runs = 0;

			public function __construct( private string $id, private ?\Closure $up ) {}

			public function id(): string {
				return $this->id;
			}

			public function up(): void {
				++$this->runs;
				if ( $this->up ) {
					( $this->up )();
				}
			}
		};
	}

	/** A second database session (another site/process) holding GET_LOCK($key). */
	private function hold_lock_elsewhere( string $key ): void {
		$this->other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__mysqli -- a second, independent session is the point of the test.
		$res         = $this->other->query( "SELECT GET_LOCK('" . $this->other->real_escape_string( $key ) . "', 0)" );
		$this->assertSame( '1', (string) $res->fetch_row()[0], 'other session holds the lock' );
	}

	// ------------------------------------------------------------- fresh-install migrations (Wave 3 tooling)

	public function test_lock_name_is_scoped_to_this_database_and_prefix(): void {
		global $wpdb;
		$legacy = 'momos_' . substr( hash( 'sha256', Runner::LOCK ), 0, 40 );
		$this->assertNotSame( $legacy, NamedLock::key( Runner::LOCK ), 'not the server-global name any more' );
		$this->assertSame( 'momos_' . substr( hash( 'sha256', DB_NAME . '|' . $wpdb->prefix . '|' . Runner::LOCK ), 0, 40 ), NamedLock::key( Runner::LOCK ) );
		$this->assertLessThanOrEqual( 64, strlen( NamedLock::key( str_repeat( 'x', 300 ) ) ) );
	}

	/** Reproduction: another site's migration held the (then global) lock → "up to date" over an empty schema. */
	public function test_a_lock_held_by_another_site_does_not_skip_migrations(): void {
		$m           = $this->migration( 'M9990_QaFresh' );
		$this->extra = [ $m ];
		$this->hold_lock_elsewhere( 'momos_' . substr( hash( 'sha256', Runner::LOCK ), 0, 40 ) ); // Legacy global name.
		update_option( Runner::SIGNATURE_OPTION, 'stale', true );

		$this->assertContains( 'M9990_QaFresh', Runner::pending() );
		$this->assertSame( [ 'M9990_QaFresh' ], Runner::run() );
		$this->assertSame( 1, $m->runs );
		$this->assertSame( [], Runner::pending() );
		$this->assertSame( Runner::signature(), get_option( Runner::SIGNATURE_OPTION ) );
	}

	public function test_lock_contention_in_the_same_database_leaves_the_signature_unwritten(): void {
		$m           = $this->migration( 'M9991_QaLocked' );
		$this->extra = [ $m ];
		update_option( Runner::SIGNATURE_OPTION, 'stale', true );
		$this->hold_lock_elsewhere( NamedLock::key( Runner::LOCK ) );

		$this->assertSame( [], Runner::run() );
		Runner::maybe_run();
		$this->assertSame( 0, $m->runs );
		$this->assertSame( 'stale', get_option( Runner::SIGNATURE_OPTION ), 'next request retries' );
		$this->assertSame( [ 'M9991_QaLocked' ], Runner::pending() );

		$this->other->query( "SELECT RELEASE_LOCK('" . $this->other->real_escape_string( NamedLock::key( Runner::LOCK ) ) . "')" );
		Runner::maybe_run();
		$this->assertSame( 1, $m->runs );
		$this->assertSame( Runner::signature(), get_option( Runner::SIGNATURE_OPTION ) );
	}

	public function test_a_failed_migration_stops_the_run_and_is_retried(): void {
		$fail        = true;
		$broken      = $this->migration(
			'M9992_QaBroken',
			static function () use ( &$fail ): void {
				if ( $fail ) {
					throw new \RuntimeException( 'boom' );
				}
			}
		);
		$after       = $this->migration( 'M9993_QaAfter' );
		$this->extra = [ $broken, $after ];
		update_option( Runner::SIGNATURE_OPTION, 'stale', true );

		$this->assertSame( [], Runner::run() );
		$this->assertSame( 0, $after->runs, 'later migrations wait' );
		$this->assertSame( [ 'M9992_QaBroken', 'M9993_QaAfter' ], Runner::pending() );
		$this->assertSame( 'stale', get_option( Runner::SIGNATURE_OPTION ) );

		$fail = false;
		$this->assertSame( [ 'M9992_QaBroken', 'M9993_QaAfter' ], Runner::run() );
		$this->assertSame( Runner::signature(), get_option( Runner::SIGNATURE_OPTION ) );
	}

	public function test_schema_is_complete_after_bootstrap(): void {
		$this->assertSame( [], Tables::missing() );
		$this->extra = [];
		$this->assertSame( [], Runner::pending() );
	}

	// ------------------------------------------------------------- DEC-062 Western digits (QA-050)

	public function test_digit_normalisation(): void {
		$this->assertSame( 'কিতাহামা স্টেশন থেকে 3 মিনিট', Digits::western( 'কিতাহামা স্টেশন থেকে ৩ মিনিট' ) );
		$this->assertSame( '2য় তলা', Digits::western( '২য় তলা' ) );
		$this->assertSame( '1,500.50 ین', Digits::western( '١٬٥٠٠٫٥٠ ین' ), 'Arabic-Indic digits and separators' );
		$this->assertSame( '12 منٹ', Digits::western( '۱۲ منٹ' ), 'Extended Arabic-Indic (ur)' );
		$this->assertSame( 'स्टेशन से 5 मिनट', Digits::western( 'स्टेशन से ५ मिनट' ) );
		$this->assertSame( 'سعر، ٬ بدون أرقام', Digits::western( 'سعر، ٬ بدون أرقام' ), 'separators untouched without digits' );
		$this->assertSame( 'Floor 2', Digits::western( 'Floor 2' ) );
	}

	public function test_translation_save_stores_western_digits_in_every_field_type(): void {
		$page = self::factory()->post->create( [ 'post_type' => 'page', 'post_title' => 'アクセス', 'post_name' => 'qa-digits', 'post_content' => '<p>北浜駅から3分</p>' ] );
		update_post_meta( $page, \Momos\Admin\Content\FaqEditor::META, wp_json_encode( [ [ 'id' => 'floor', 'question' => '何階？', 'answer' => '2階' ] ] ) );
		Translations::save(
			'post',
			$page,
			'bn',
			[
				'title'     => 'কিতাহামা স্টেশন থেকে ৩ মিনিট',
				'content'   => '<p>২য় তলা, ৫ মিনিট</p>',
				'excerpt'   => "লাইন ১\nলাইন ২",
				'faq_items' => [ [ 'id' => 'floor', 'question' => 'কোন তলা?', 'answer' => '<p>৪র্থ তলা</p>' ] ],
			],
			'draft'
		);
		$data = Translations::data( 'post', $page, 'bn' );
		$this->assertSame( 'কিতাহামা স্টেশন থেকে 3 মিনিট', $data['title'] );
		$this->assertSame( '<p>2য় তলা, 5 মিনিট</p>', $data['content'] );
		$this->assertSame( "লাইন 1\nলাইন 2", $data['excerpt'] );
		$this->assertSame( '<p>4র্থ তলা</p>', $data['faq_items'][0]['answer'] );
		$this->assertDoesNotMatchRegularExpression( '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}\x{0966}-\x{096F}\x{09E6}-\x{09EF}]/u', (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );

		Translations::save( 'post', $page, 'ar', [ 'title' => 'الطابق ٢ — ٣ دقائق' ], 'draft' );
		$this->assertSame( 'الطابق 2 — 3 دقائق', Translations::data( 'post', $page, 'ar' )['title'] );
	}
}
