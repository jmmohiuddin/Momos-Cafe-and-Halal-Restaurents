<?php
declare(strict_types=1);

namespace Momos\Kernel\Cli;

use Momos\Kernel\Migration\Runner;

/**
 * WP-CLI commands: `wp momos migrate`, `wp momos jobs run`, plus module commands registered
 * through the `momos/cli` action.
 */
final class Commands {
	public static function register(): void {
		\WP_CLI::add_command( 'momos migrate', static function ( $args, $assoc ): void {
			$ran = Runner::run( isset( $assoc['force'] ) );
			\WP_CLI::success( $ran ? 'Applied: ' . implode( ', ', $ran ) : 'Nothing to migrate.' );
		} );
		do_action( 'momos/cli' );
	}
}
