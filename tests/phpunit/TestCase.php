<?php
declare(strict_types=1);

namespace Momos\Tests;

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Clock\FrozenClock;

/**
 * Base class for momos integration tests. Custom tables are InnoDB, so the WP test transaction
 * rollback also resets them between tests.
 */
abstract class TestCase extends \WP_UnitTestCase {
	public function tear_down(): void {
		Clock::set( null );
		\Momos\Kernel\I18n\Locale::reset();
		parent::tear_down();
	}

	protected function freeze( string $jst_datetime ): FrozenClock {
		$clock = new FrozenClock( new \DateTimeImmutable( $jst_datetime, new \DateTimeZone( 'Asia/Tokyo' ) ) );
		Clock::set( $clock );
		return $clock;
	}

	protected function user_with_role( string $role, array $branch_ids = [] ): int {
		$id = self::factory()->user->create( [ 'role' => $role ] );
		global $wpdb;
		foreach ( $branch_ids as $b ) {
			$wpdb->insert( \Momos\Kernel\Db\Tables::user_branches(), [ 'user_id' => $id, 'branch_id' => $b, 'assigned_at' => gmdate( 'Y-m-d H:i:s' ) ] );
		}
		return $id;
	}
}
