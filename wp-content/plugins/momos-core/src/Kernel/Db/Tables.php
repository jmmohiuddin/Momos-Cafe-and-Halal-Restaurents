<?php
declare(strict_types=1);

namespace Momos\Kernel\Db;

/**
 * Names of the eight custom tables (DEC-009, SDD §3.3).
 */
final class Tables {
	public const ALL = [
		'branch_hours', 'branch_closures', 'branch_menu_items', 'reservations',
		'reservation_events', 'notifications', 'user_branches', 'audit_log',
	];

	public static function name( string $short ): string {
		global $wpdb;
		return $wpdb->prefix . 'momo_' . $short;
	}

	public static function hours(): string { return self::name( 'branch_hours' ); }
	public static function closures(): string { return self::name( 'branch_closures' ); }
	public static function menu_items(): string { return self::name( 'branch_menu_items' ); }
	public static function reservations(): string { return self::name( 'reservations' ); }
	public static function events(): string { return self::name( 'reservation_events' ); }
	public static function notifications(): string { return self::name( 'notifications' ); }
	public static function user_branches(): string { return self::name( 'user_branches' ); }
	public static function audit(): string { return self::name( 'audit_log' ); }
}
