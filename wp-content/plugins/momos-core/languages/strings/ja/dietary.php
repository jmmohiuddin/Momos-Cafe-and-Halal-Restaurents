<?php
/**
 * Dietary module customer strings — Japanese master (Doc 11 §8.1/§9.4 drafts; FOOD + counsel review).
 * Halal/alcohol STATEMENTS are G2 templates (option momos_dietary_statements), not catalog strings.
 */

defined( 'ABSPATH' ) || exit;

return [
	'allergen.heading'               => 'アレルゲン',
	'allergen.state.contains'        => '含む',
	'allergen.state.may_contain'     => '含む可能性あり（混入の可能性）',
	'allergen.state.not_contained'   => '含まない（確認済み）',
	'allergen.state.not_verified'    => '未確認',
	'allergen.summary.contains'      => '含む：{list}',
	'allergen.summary.may_contain'   => '混入の可能性あり：{list}',
	'allergen.banner.not_verified'   => 'アレルゲン情報は未確認です。スタッフへお尋ねください。',
	'allergen.disclaimer.voluntary'  => 'アレルゲン情報は参考として任意で提供しています。仕入れ先やレシピの変更により変わる場合があり、店舗によって食材が異なることがあります。',
	'allergen.cross_contact'         => '同じ厨房で他の食材も扱っており、調理器具や揚げ油を共用する場合があるため、微量の混入がないことは保証できません。',
	'allergen.tell_staff'            => '重いアレルギーをお持ちの方は、ご来店時およびご注文時に必ずスタッフへお申し出ください。対応できない料理の場合は、その旨を正直にお伝えします。',
	'allergen.last_verified'         => '最終確認日：{date}',
	'allergen.group.mandatory'       => '特定原材料（9品目・容器包装食品の表示義務対象）',
	'allergen.group.recommended'     => '特定原材料に準ずるもの（20品目）',
	'allergen.group.other'           => 'その他',
	'allergen.legend'                => '凡例',
	'allergen.table.caption'         => 'アレルゲン情報',
	'allergen.table.allergen'        => 'アレルゲン',
	'allergen.table.status'          => '状況',
	'allergen.toggle.relevant'       => '「含む」「含む可能性あり」のみ表示',
	'ingredients.heading'            => '主な食材（すべての食材ではありません）',
	'last_verified'                  => '最終確認日：{date}',
	'halal.heading'                  => 'ハラールに関する情報',
	'halal.label.certified'          => 'ハラール認証取得',
	'halal.label.ingredients'        => 'ハラール食材使用',
	'halal.not_applicable'           => '該当なし',
	'halal.mark_alt'                 => '{certifier}のハラール認証マーク',
	'halal.verify_link'              => '認証機関で確認する',
	'halal.certifier'                => '認証機関',
	'halal.scope'                    => '範囲',
	'halal.cert_no'                  => '認証番号',
	'halal.valid_until'              => '有効期限',
	'halal.scope.whole_kitchen'      => '厨房全体',
	'halal.scope.specific_menu'      => '表示した料理',
	'halal.scope.specific_ingredients' => '特定の食材',
	'alcohol.heading'                => 'アルコール',
	'diet.list.separator'            => '、',
	// FR-DIET-002(f) audit label (SCR-A16).
	'admin.audit.action.dietary.prohibited_terms_updated' => '禁止表現リストの更新',
];
