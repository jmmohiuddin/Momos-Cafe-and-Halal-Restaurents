<?php
declare(strict_types=1);

namespace Momos\Reservation\Cli;

use Momos\Reservation\Service\TokenService;

/**
 * `wp momos token reissue --key-version=<n>` (DEC-064): recomputes the hashes of unexpired status
 * tokens with key version n and fires `momos/reservation/token_reissued` per reservation (E06 new_link).
 * `wp momos reservations expire` runs the expiry job once.
 */
final class TokenCommand {
	public static function register(): void {
		\WP_CLI::add_command(
			'momos token reissue',
			static function ( array $args, array $assoc ): void {
				$version = (int) ( $assoc['key-version'] ?? 0 );
				if ( $version < 1 ) {
					\WP_CLI::error( '--key-version=<n> is required.' );
				}
				try {
					$n = TokenService::reissue( $version );
				} catch ( \RuntimeException $e ) {
					\WP_CLI::error( $e->getMessage() );
					return; // WP_CLI::error() exits; keeps $n defined on every path that reaches success().
				}
				\WP_CLI::success( sprintf( 'Re-issued %d status tokens with key version %d.', $n, $version ) );
			}
		);
		\WP_CLI::add_command(
			'momos reservations expire',
			static function (): void {
				\WP_CLI::success( sprintf( 'Expired %d request(s).', \Momos\Reservation\Job\ExpiryJob::run() ) );
			}
		);
		\WP_CLI::add_command(
			'momos reservations sla',
			static function (): void {
				$r = \Momos\Reservation\Job\SlaJob::run();
				\WP_CLI::success( sprintf( 'Reminders %d, escalations %d.', $r['reminders'], $r['escalations'] ) );
			}
		);
	}
}
