<?php
declare(strict_types=1);

namespace Momos\Seed\Data;

/**
 * Demo staff accounts (one per role, BM/RS for two branches; qa.md §3.2 shape) and sample
 * reservations in every status (DEC-011 state machine). All people are FICTIONAL; every address is
 * on example.com. The development password is only ever set outside production.
 */
final class People {
	public const DEV_PASSWORD = 'Momos-Dev-2026!';

	/** Idempotency-key prefix that marks sample reservations (UUID v4 shape). */
	public const RES_KEY_PREFIX = '5eed0000-0000-4000-8000-';

	/**
	 * login => role, display name, branch codes (scoped roles), locale, translator languages, CAP-DCA.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	public static function users(): array {
		return [
			'demo_ho'          => [ 'role' => 'momo_head_office', 'name' => 'Demo Head Office', 'branches' => [], 'locale' => 'ja', 'dca' => true ],
			'demo_mm'          => [ 'role' => 'momo_menu_manager', 'name' => 'Demo Menu Manager', 'branches' => [], 'locale' => 'ja' ],
			'demo_ce'          => [ 'role' => 'momo_content_editor', 'name' => 'Demo Content Editor', 'branches' => [], 'locale' => 'ja' ],
			'demo_tr'          => [ 'role' => 'momo_translator', 'name' => 'Demo Translator', 'branches' => [], 'locale' => 'en_US', 'langs' => [ 'en', 'ar', 'ur', 'hi', 'bn' ] ],
			'demo_bm_kitahama' => [ 'role' => 'momo_branch_manager', 'name' => 'Demo Manager Kitahama', 'branches' => [ 'DKH' ], 'locale' => 'ja' ],
			'demo_rs_kitahama' => [ 'role' => 'momo_reservation_staff', 'name' => 'Demo Staff Kitahama', 'branches' => [ 'DKH' ], 'locale' => 'ja' ],
			'demo_bm_namba'    => [ 'role' => 'momo_branch_manager', 'name' => 'Demo Manager Namba', 'branches' => [ 'DNB' ], 'locale' => 'en_US' ],
			'demo_rs_namba'    => [ 'role' => 'momo_reservation_staff', 'name' => 'Demo Staff Namba', 'branches' => [ 'DNB' ], 'locale' => 'en_US' ],
		];
	}

	/**
	 * Sample reservations. `created`: day offset (from today, JST) of the submission; `day`: offset of
	 * the requested date (moved forward to the next open day when needed); `steps`: transitions after
	 * submission [action, actor login|customer|system, day offset, extra input].
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function reservations(): array {
		return [
			[ 'n' => 1, 'branch' => 'DKH', 'created' => 0, 'day' => 5, 'time' => '19:00', 'party' => 2, 'lang' => 'ja', 'name' => 'デモ 花子', 'phone' => '090-0000-0101', 'request' => 'サンプルの備考です。窓側の席を希望します。', 'steps' => [] ],
			[ 'n' => 2, 'branch' => 'DNB', 'created' => 0, 'day' => 3, 'time' => '12:00', 'party' => 4, 'lang' => 'en', 'name' => 'Alex Sample', 'phone' => '', 'request' => 'One high chair, please (sample request).', 'steps' => [] ],
			[ 'n' => 3, 'branch' => 'DKY', 'created' => 0, 'day' => 7, 'time' => '18:30', 'party' => 6, 'lang' => 'ar', 'name' => 'ضيف تجريبي', 'phone' => '', 'request' => '', 'steps' => [ [ 'accept', 'demo_ho', 0, [ 'message_to_customer' => 'We look forward to seeing you. (Sample message)' ] ] ] ],
			[ 'n' => 4, 'branch' => 'DNB', 'created' => 0, 'day' => 4, 'time' => '19:30', 'party' => 9, 'lang' => 'ur', 'name' => 'نمونہ مہمان', 'phone' => '', 'request' => '', 'steps' => [ [ 'decline', 'demo_rs_namba', 0, [ 'reason_code' => 'fully_booked' ] ] ] ],
			[ 'n' => 5, 'branch' => 'DKH', 'created' => 0, 'day' => 6, 'time' => '12:30', 'party' => 3, 'lang' => 'hi', 'name' => 'नमूना अतिथि', 'phone' => '', 'request' => '', 'steps' => [ [ 'cancel', 'customer', 0, [] ] ] ],
			[ 'n' => 6, 'branch' => 'DKY', 'created' => 0, 'day' => 9, 'time' => '12:00', 'party' => 2, 'lang' => 'bn', 'name' => 'নমুনা অতিথি', 'phone' => '', 'request' => '', 'steps' => [ [ 'accept', 'demo_ho', 0, [] ], [ 'cancel', 'demo_ho', 1, [ 'reason_code' => 'customer_request_by_phone', 'note' => 'Guest called to cancel (sample note).' ] ] ] ],
			[ 'n' => 7, 'branch' => 'DKH', 'created' => -10, 'day' => -8, 'time' => '13:00', 'party' => 2, 'lang' => 'ja', 'name' => 'デモ 次郎', 'phone' => '', 'request' => '', 'steps' => [ [ 'expire', 'system', -8, [] ] ] ],
			[ 'n' => 8, 'branch' => 'DNB', 'created' => -12, 'day' => -7, 'time' => '19:00', 'party' => 5, 'lang' => 'en', 'name' => 'Jordan Example', 'phone' => '', 'request' => '', 'steps' => [ [ 'accept', 'demo_rs_namba', -11, [] ], [ 'mark_completed', 'demo_rs_namba', -7, [] ] ] ],
			[ 'n' => 9, 'branch' => 'DKH', 'created' => -6, 'day' => -3, 'time' => '18:00', 'party' => 4, 'lang' => 'ja', 'name' => 'デモ 三郎', 'phone' => '', 'request' => '', 'steps' => [ [ 'accept', 'demo_rs_kitahama', -5, [] ], [ 'mark_no_show', 'demo_rs_kitahama', -3, [] ] ] ],
			[ 'n' => 10, 'branch' => 'DKY', 'created' => -1, 'day' => 2, 'time' => '19:00', 'party' => 2, 'lang' => 'en', 'name' => 'Sam Placeholder', 'phone' => '', 'request' => '', 'steps' => [ [ 'accept', 'demo_ho', -1, [] ] ] ],
		];
	}

	public static function reservation_key( int $n ): string {
		return self::RES_KEY_PREFIX . sprintf( '%012d', $n );
	}

	public static function reservation_email( int $n ): string {
		return sprintf( 'demo.guest%02d@example.com', $n );
	}
}
