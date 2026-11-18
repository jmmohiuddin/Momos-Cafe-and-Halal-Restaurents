<?php
declare(strict_types=1);

namespace Momos\Audit\Cli;

use Momos\Audit\AuditRepository;
use Momos\Audit\Job\AuditRetentionJob;

/**
 * `wp momos audit verify [--from=<id>]` — recompute the row_hmac chain (SEC-082).
 * `wp momos audit purge`               — run the retention purge now.
 */
final class AuditCommand {
	public static function register(): void {
		\WP_CLI::add_command( 'momos audit', static function ( $args, $assoc ): void {
			$sub = $args[0] ?? 'verify';
			if ( 'purge' === $sub ) {
				$r = AuditRetentionJob::run();
				null === $r ? \WP_CLI::warning( 'Another purge is running.' ) : \WP_CLI::success( sprintf( 'Deleted %d domain and %d activity rows.', $r['domain'], $r['activity'] ) );
				return;
			}
			$r = AuditRepository::verify( (int) ( $assoc['from'] ?? 0 ) );
			if ( $r['failed'] ) {
				\WP_CLI::error( sprintf( '%d of %d rows failed the integrity check: %s', count( $r['failed'] ), $r['checked'], implode( ', ', array_slice( $r['failed'], 0, 50 ) ) ) );
			}
			\WP_CLI::success( sprintf( '%d rows verified.', $r['checked'] ) );
		} );
	}
}
