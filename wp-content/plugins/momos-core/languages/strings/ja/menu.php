<?php
/**
 * Menu module customer strings — Japanese (master drafts; Doc 07 §6 keys, ⟦JA draft⟧ items drafted by the
 * build team — pending native/legal review, Q-039/DEC-047).
 */

defined( 'ABSPATH' ) || exit;

return [
	'price.tax_incl'                      => '（税込）',
	'menu.title'                          => 'メニュー',
	'menu.branch_select.label'            => '価格を表示する店舗',
	'menu.branch_select.all'              => '全店舗（参考価格）',
	'menu.prices_may_differ'              => '店舗により価格が異なる場合があります',
	'menu.price_all_branches'             => '全店舗 {price}',
	'menu.price_at_branch'                => '{branch}：{price}',
	'menu.change_branch'                  => '変更',
	'menu.sold_out'                       => '本日売り切れ',
	'menu.unavailable'                    => 'この店舗では現在ご提供していません',
	'menu.available_today'                => '本日ご提供中',
	'menu.not_available'                  => '現在ご提供していません',
	'menu.advance_order'                  => '{hours}時間前までに要予約注文',
	'menu.advance_order.notice'           => 'この料理は{hours}時間前までのご注文が必要です。予約リクエストの際に、ご要望欄でお知らせください。',
	'menu.advance_order.mention'          => '予約リクエストでお知らせください',
	'menu.filters.heading'                => '絞り込み',
	'menu.filters.toggle'                 => '絞り込み（{n}）',
	'menu.filters.empty'                  => '条件に合う料理がありません。',
	'menu.filters.clear'                  => '条件をクリア',
	'menu.filters.count.one'              => '{n}品を表示しています',
	'menu.filters.count.other'            => '{n}品を表示しています',
	'menu.filter.diet'                    => '食事の条件',
	'menu.filter.max_spice'               => '辛さの上限',
	'menu.filter.max_spice.any'           => '指定なし',
	'menu.filter.exclude'                 => '次を含む可能性がある料理を非表示',
	'menu.filter.allergen_note'           => '任意で提供している情報に基づきます。アレルギーについては必ずスタッフにお伝えください。',
	'menu.category.branch_empty'          => 'この店舗では、このカテゴリーの料理をご提供していません。',
	'menu.category.all_branches_link'     => '全店舗を表示',
	'menu.available_at'                   => 'ご提供店舗',
	'menu.related'                        => '同じカテゴリーの料理',
	'menu.table.branch'                   => '店舗',
	'menu.table.price'                    => '価格',
	'menu.table.availability'             => 'ご提供状況',
	'menu.spice'                          => '辛さ：{label}',
	'spice.0'                             => '辛くない',
	'spice.1'                             => '少し辛い',
	'spice.2'                             => '中辛',
	'spice.3'                             => '辛口',
	'availability_reason.sold_out_today'  => '本日売り切れ',
	'availability_reason.seasonal'        => '季節限定',
	'availability_reason.ingredient_unavailable' => '食材の入荷待ち',
	'availability_reason.advance_order_only'     => '要予約注文',
	'media.illustration'                  => '写真はイメージです',
	// FR-MENU-005(a) audit label (SCR-A16).
	'admin.audit.action.menu_category.reordered' => 'カテゴリーの並び順変更',
];
