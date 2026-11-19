<?php
declare(strict_types=1);

namespace Momos\Kernel\Monitoring;

use Momos\Kernel\Jobs\Schedule;

/**
 * Read-only monitoring panel (FR-FND-003(f), FR-FND-006): job heartbeats and the plugin register,
 * rendered at the end of the Settings page (SCR-A15) for SA and Head Office.
 */
final class StatusPanel {
	/** Plugin cap including the must-use loaded momos-core (FR-FND-006, Doc 11 SEC-060). */
	public const MAX_PLUGINS = 15;

	public static function register(): void {
		add_action( 'momos/options_page/after_fields', [ self::class, 'render' ] );
		add_action( 'admin_notices', [ self::class, 'unregistered_plugin_notice' ] );
	}

	/** @return list<array<string,mixed>> */
	public static function inventory(): array {
		$rows = include MOMOS_CORE_DIR . '/config/plugin-inventory.php';
		return is_array( $rows ) ? $rows : [];
	}

	/** Active plugins missing from the register. @return list<string> */
	public static function unregistered_plugins(): array {
		$known = array_column( self::inventory(), 'slug' );
		$out   = [];
		foreach ( (array) get_option( 'active_plugins', [] ) as $file ) {
			$slug = dirname( (string) $file );
			if ( ! in_array( '.' === $slug ? basename( (string) $file, '.php' ) : $slug, $known, true ) ) {
				$out[] = (string) $file;
			}
		}
		return $out;
	}

	public static function active_count(): int {
		$active = array_filter( (array) get_option( 'active_plugins', [] ), static fn( $f ) => 'momos-core/momos-core.php' !== $f );
		return count( $active ) + 1; // + momos-core via mu-loader.
	}

	public static function unregistered_plugin_notice(): void {
		if ( ! current_user_can( 'momo_manage_brand' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$bad = self::unregistered_plugins();
		if ( $bad || self::active_count() > self::MAX_PLUGINS ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Unapproved plugin active (not in the plugin register) or more than 15 plugins:', 'momos-core' ) . ' ' . esc_html( implode( ', ', $bad ) ) . '</p></div>';
		}
	}

	public static function render( string $slug ): void {
		if ( 'momo-settings' !== $slug || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'momo_manage_brand' ) ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Monitoring — scheduled jobs', 'momos-core' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Job', 'momos-core' ) . '</th><th>' . esc_html__( 'Interval', 'momos-core' ) . '</th><th>' . esc_html__( 'Last success (JST)', 'momos-core' ) . '</th><th>' . esc_html__( 'State', 'momos-core' ) . '</th></tr></thead><tbody>';
		foreach ( Schedule::health() as $hook => $h ) {
			$last  = $h['last'] ? wp_date( 'Y-m-d H:i', (int) strtotime( $h['last'] ), new \DateTimeZone( 'Asia/Tokyo' ) ) : '—';
			$state = [ 'ok' => __( 'OK', 'momos-core' ), 'late' => __( 'Late', 'momos-core' ), 'never' => __( 'Not run yet', 'momos-core' ) ][ $h['state'] ];
			echo '<tr><td><code>' . esc_html( $hook ) . '</code></td><td>' . esc_html( (string) round( $h['interval'] / 60 ) ) . ' min</td><td>' . esc_html( $last ) . '</td><td>' . esc_html( $state ) . '</td></tr>';
		}
		echo '</tbody></table>';
		/* translators: %d: number of active plugins */
		echo '<h2>' . esc_html__( 'Plugin register', 'momos-core' ) . '</h2><p>' . esc_html( sprintf( __( 'Active plugins: %d / 15', 'momos-core' ), self::active_count() ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Component', 'momos-core' ) . '</th><th>' . esc_html__( 'Version', 'momos-core' ) . '</th><th>' . esc_html__( 'Purpose', 'momos-core' ) . '</th><th>' . esc_html__( 'Data sent to third parties', 'momos-core' ) . '</th><th>' . esc_html__( 'Last review', 'momos-core' ) . '</th></tr></thead><tbody>';
		foreach ( self::inventory() as $r ) {
			echo '<tr><td>' . esc_html( (string) $r['name'] ) . '</td><td>' . esc_html( (string) $r['version'] ) . '</td><td>' . esc_html( (string) $r['purpose'] ) . '</td><td>' . esc_html( (string) $r['third_party_data'] ) . '</td><td>' . esc_html( (string) $r['last_review'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
