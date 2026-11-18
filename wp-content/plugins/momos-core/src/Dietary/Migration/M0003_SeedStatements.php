<?php
declare(strict_types=1);

namespace Momos\Dietary\Migration;

use Momos\Dietary\Service\StatementRepository;
use Momos\Kernel\Migration\MigrationInterface;

/**
 * Seeds the canonical G2 statement templates (Doc 11 §8.1 halal, §8.7 alcohol; JA master + EN draft).
 * All rows start `approved = false` and the EN text as `needs_review` until counsel + certifier sign-off
 * (spec §10, Q-407, GATE-02). Factual elements are placeholders filled from the register. Idempotent:
 * existing rows (possibly edited by CAP-DCA holders) are never overwritten.
 */
final class M0003_SeedStatements implements MigrationInterface {
	public function id(): string {
		return 'M0003_SeedStatements';
	}

	/** @return list<array<string,mixed>> */
	public static function rows(): array {
		$rows = [
			[ 'certified.whole_kitchen', 'certified', '当店舗は{certifier}によるハラール認証を取得しています（認証番号：{cert_no}、範囲：厨房全体、有効期限：{valid_until}）。', 'This branch is halal-certified by {certifier} (certificate no. {cert_no}, scope: whole kitchen, valid until {valid_until}).' ],
			[ 'certified.specific_menu', 'certified', 'メニューに表示した料理は{certifier}によるハラール認証の対象です（認証番号：{cert_no}、有効期限：{valid_until}）。その他の料理は本認証の対象外です。', 'Dishes marked on our menu are halal-certified by {certifier} (certificate no. {cert_no}, valid until {valid_until}). Other dishes are not covered by this certification.' ],
			[ 'certified.specific_ingredients', 'certified', '当店舗では、{covered_ingredients}が{certifier}によるハラール認証の対象です（認証番号：{cert_no}、有効期限：{valid_until}）。厨房およびその他の食材は本認証の対象外です。', 'At this branch, {covered_ingredients} are covered by halal certification from {certifier} (certificate no. {cert_no}, valid until {valid_until}). The kitchen and other ingredients are not covered by this certification.' ],
			[ 'halal_ingredients', 'halal_ingredients', '当店舗では、{covered_ingredients}についてハラール認証を受けた仕入先のものを使用しています（ハラール登録記録による）。当店舗の厨房はハラール認証を取得していません。調理方法についてはスタッフにお尋ねください。', 'At this branch, {covered_ingredients} come from suppliers holding halal certification, as recorded in our halal register. This branch\'s kitchen is not halal-certified. Please ask our staff about preparation if you have specific requirements.' ],
			[ 'no_claim', 'no_claim', '食材や調理方法については、この店舗のスタッフにお尋ねください。', 'Please ask our staff at this branch about ingredients and preparation.' ],
			[ 'branch_default', 'neutral', 'ハラールに関する情報は店舗ごとに表示しています。この料理を提供している店舗：{branch_links}', 'Halal information is shown for each branch. Branches offering this dish: {branch_links}' ],
			[ 'brand.neutral', 'neutral', 'ハラールに関する情報は店舗ごとに表示しています。', 'Halal information is shown for each branch.' ],
			[ 'alcohol.served.yes', 'alcohol', 'この店舗ではアルコール飲料を提供しています。', 'Alcoholic drinks are served at this branch.' ],
			[ 'alcohol.served.no', 'alcohol', 'この店舗ではアルコール飲料を提供していません。', 'Alcoholic drinks are not served at this branch.' ],
			[ 'alcohol.cooking.yes', 'alcohol', 'この店舗では一部の料理の調理にアルコールを使用しています。該当する料理はスタッフにお尋ねください。', 'Alcohol is used in cooking some dishes at this branch. Please ask our staff which dishes.' ],
			[ 'alcohol.cooking.no', 'alcohol', 'この店舗では調理にアルコールを使用していません。', 'Alcohol is not used in cooking at this branch.' ],
		];
		return array_map(
			static fn( array $r ) => [
				'key'        => $r[0],
				'claim_type' => $r[1],
				'text_ja'    => $r[2],
				'approved'   => false,
				'i18n'       => [ 'en' => [ 'text' => $r[3], 'status' => 'needs_review' ] ],
			],
			$rows
		);
	}

	public function up(): void {
		StatementRepository::seed( self::rows() );
		if ( false === get_option( StatementRepository::CROSS_CONTACT, false ) ) {
			add_option( StatementRepository::CROSS_CONTACT, [ 'confirmed' => false, 'confirmed_by' => null, 'confirmed_at' => null ], '', false );
		}
	}
}
