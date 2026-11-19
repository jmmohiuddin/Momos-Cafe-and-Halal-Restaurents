<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Tables;
use Momos\Kernel\Support\Hash;
use Momos\Notification\Recipient\RecipientResolver;
use Momos\Tests\TestCase;

/**
 * Privacy closers (Wave 3b):
 *  - DSR lookups of notification rows by `recipient_hash` use the same purpose-separated HMAC as the queue
 *    (Hash::purpose('email', …), DEC-059, QA-075), so an export by email finds every row written for it.
 *  - BR-PRV-04: no machine translation and no AI service — no MT/AI endpoint appears in the plugin, theme or
 *    mu-plugins (customer text is translated by people, DEC-027).
 */
final class PrivacyWave3bTest extends TestCase {
	public function test_recipient_hash_is_the_email_purpose_hmac(): void {
		$this->assertSame( Hash::purpose( 'email', 'guest.name@example.org' ), RecipientResolver::hash( '  Guest.Name@Example.ORG ' ) );
		$this->assertNotSame( Hash::hmac( 'guest.name@example.org' ), RecipientResolver::hash( 'guest.name@example.org' ), 'not the bare pepper HMAC' );
	}

	public function test_dsr_export_by_email_finds_rows_hashed_by_the_queue(): void {
		global $wpdb;
		$now = Clock::mysql_utc();
		$wpdb->insert(
			Tables::notifications(),
			[
				'uuid'             => wp_generate_uuid4(),
				'dedupe_key'       => 'qa-prv-hash-1',
				'template_key'     => 'E01',
				'template_variant' => 'received',
				'lang'             => 'en',
				'recipient_type'   => 'customer',
				'recipient_email'  => 'Guest.Name@Example.org',
				'recipient_hash'   => RecipientResolver::hash( 'Guest.Name@Example.org' ),
				'recipient_masked' => 'g***@e***.org',
				'status'           => 'sent',
				'created_at'       => $now,
				'updated_at'       => $now,
				'sent_at'          => $now,
			]
		);
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook
		$this->assertArrayHasKey( 'momos-notifications', $exporters );
		$cb = $exporters['momos-notifications']['callback'];

		$out = call_user_func( $cb, 'guest.name@example.ORG', 1 ); // The address as the data subject types it.
		$this->assertCount( 1, $out['data'] );
		$this->assertSame( 'momos-notifications', $out['data'][0]['group_id'] );
		$this->assertStringNotContainsString( 'Guest.Name', (string) wp_json_encode( $out['data'] ), 'metadata only, no address echoed' );

		$this->assertCount( 0, call_user_func( $cb, 'someone.else@example.org', 1 )['data'] );
	}

	/** BR-PRV-04 (Doc 11 PRV, DEC-027): fail if any machine-translation or AI endpoint/client appears. */
	public function test_no_machine_translation_or_ai_service_is_integrated(): void {
		$root     = dirname( __DIR__, 3 );
		$dirs     = [ $root . '/wp-content/plugins/momos-core', $root . '/wp-content/themes/momos', $root . '/wp-content/mu-plugins' ];
		$patterns = [
			'/\bdeepl\b|deepl\.com/i',
			'/translate\.googleapis\.com|translation\.googleapis\.com|translate\.google\.[a-z.]+\/translate_a|cloud\.google\.com\/translate/i',
			'/generativelanguage\.googleapis\.com|aiplatform\.googleapis\.com/i',
			'/api\.openai\.com|\bopenai\b/i',
			'/api\.anthropic\.com|\banthropic\b/i',
			'/microsofttranslator\.com|api\.cognitive\.microsoft|openai\.azure\.com/i',
			'/translate\.yandex|translate\.api\.cloud\.yandex/i',
			'/translate\.[a-z0-9-]+\.amazonaws\.com|bedrock(-runtime)?\.[a-z0-9-]+\.amazonaws\.com/i',
			'/libretranslate|papago\.apigw|api\.mistral\.ai|api\.cohere\.(ai|com)|api\.together\.xyz|api\.groq\.com|huggingface\.co\/api|api-inference\.huggingface/i',
		];
		$hits    = [];
		$scanned = 0;
		foreach ( $dirs as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveCallbackFilterIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), static fn( \SplFileInfo $f ): bool => ! in_array( $f->getFilename(), [ 'vendor', 'node_modules', '.git' ], true ) ) );
			foreach ( $it as $file ) {
				/** @var \SplFileInfo $file */
				if ( ! preg_match( '/\.(php|js|mjs|json|html|css|po|pot|txt|md)$/i', $file->getFilename() ) ) {
					continue;
				}
				++$scanned;
				$src = (string) file_get_contents( $file->getPathname() );
				foreach ( $patterns as $p ) {
					if ( preg_match( $p, $src, $m ) ) {
						$hits[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ': ' . $m[0];
					}
				}
			}
		}
		$this->assertGreaterThan( 100, $scanned, 'the scan reached the sources' );
		$this->assertSame( [], $hits, 'BR-PRV-04: customer text is translated by people only; no MT/AI service may be called' );
	}
}
