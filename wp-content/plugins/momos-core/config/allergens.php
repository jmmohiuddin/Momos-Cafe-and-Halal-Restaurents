<?php
/**
 * Allergen reference list (Doc 11 §9.1, spec menu-dietary §2.5) — exactly 29 items, seeded by
 * Dietary\Migration\M0003_SeedAllergens (idempotent, lookup by code). Japanese = source term name,
 * English = approved `en` translation; other languages are added by human reviewers only.
 *
 * Note: cashew_nut is mandatory with a transition period to 31 Mar 2028 (internal note only, never displayed).
 * Custom items (class `other`) are added by CAP-DCA holders in the term screen, never here.
 *
 * @return list<array{code:string, ja:string, en:string, class:'mandatory'|'recommended', sort:int}>
 */

defined( 'ABSPATH' ) || exit;

return [
	[ 'code' => 'shrimp', 'ja' => 'えび', 'en' => 'Shrimp/prawn', 'class' => 'mandatory', 'sort' => 1 ],
	[ 'code' => 'cashew_nut', 'ja' => 'カシューナッツ', 'en' => 'Cashew nut', 'class' => 'mandatory', 'sort' => 2 ],
	[ 'code' => 'crab', 'ja' => 'かに', 'en' => 'Crab', 'class' => 'mandatory', 'sort' => 3 ],
	[ 'code' => 'walnut', 'ja' => 'くるみ', 'en' => 'Walnut', 'class' => 'mandatory', 'sort' => 4 ],
	[ 'code' => 'wheat', 'ja' => '小麦', 'en' => 'Wheat', 'class' => 'mandatory', 'sort' => 5 ],
	[ 'code' => 'buckwheat', 'ja' => 'そば', 'en' => 'Buckwheat', 'class' => 'mandatory', 'sort' => 6 ],
	[ 'code' => 'egg', 'ja' => '卵', 'en' => 'Egg', 'class' => 'mandatory', 'sort' => 7 ],
	[ 'code' => 'milk', 'ja' => '乳', 'en' => 'Milk', 'class' => 'mandatory', 'sort' => 8 ],
	[ 'code' => 'peanut', 'ja' => '落花生', 'en' => 'Peanut', 'class' => 'mandatory', 'sort' => 9 ],
	[ 'code' => 'almond', 'ja' => 'アーモンド', 'en' => 'Almond', 'class' => 'recommended', 'sort' => 10 ],
	[ 'code' => 'abalone', 'ja' => 'あわび', 'en' => 'Abalone', 'class' => 'recommended', 'sort' => 11 ],
	[ 'code' => 'squid', 'ja' => 'いか', 'en' => 'Squid', 'class' => 'recommended', 'sort' => 12 ],
	[ 'code' => 'salmon_roe', 'ja' => 'いくら', 'en' => 'Salmon roe', 'class' => 'recommended', 'sort' => 13 ],
	[ 'code' => 'orange', 'ja' => 'オレンジ', 'en' => 'Orange', 'class' => 'recommended', 'sort' => 14 ],
	[ 'code' => 'kiwifruit', 'ja' => 'キウイフルーツ', 'en' => 'Kiwifruit', 'class' => 'recommended', 'sort' => 15 ],
	[ 'code' => 'beef', 'ja' => '牛肉', 'en' => 'Beef', 'class' => 'recommended', 'sort' => 16 ],
	[ 'code' => 'sesame', 'ja' => 'ごま', 'en' => 'Sesame', 'class' => 'recommended', 'sort' => 17 ],
	[ 'code' => 'salmon', 'ja' => 'さけ', 'en' => 'Salmon', 'class' => 'recommended', 'sort' => 18 ],
	[ 'code' => 'mackerel', 'ja' => 'さば', 'en' => 'Mackerel', 'class' => 'recommended', 'sort' => 19 ],
	[ 'code' => 'soybean', 'ja' => '大豆', 'en' => 'Soybean', 'class' => 'recommended', 'sort' => 20 ],
	[ 'code' => 'chicken', 'ja' => '鶏肉', 'en' => 'Chicken', 'class' => 'recommended', 'sort' => 21 ],
	[ 'code' => 'banana', 'ja' => 'バナナ', 'en' => 'Banana', 'class' => 'recommended', 'sort' => 22 ],
	[ 'code' => 'pistachio', 'ja' => 'ピスタチオ', 'en' => 'Pistachio', 'class' => 'recommended', 'sort' => 23 ],
	[ 'code' => 'pork', 'ja' => '豚肉', 'en' => 'Pork', 'class' => 'recommended', 'sort' => 24 ],
	[ 'code' => 'macadamia_nut', 'ja' => 'マカダミアナッツ', 'en' => 'Macadamia nut', 'class' => 'recommended', 'sort' => 25 ],
	[ 'code' => 'peach', 'ja' => 'もも', 'en' => 'Peach', 'class' => 'recommended', 'sort' => 26 ],
	[ 'code' => 'yam', 'ja' => 'やまいも', 'en' => 'Yam', 'class' => 'recommended', 'sort' => 27 ],
	[ 'code' => 'apple', 'ja' => 'りんご', 'en' => 'Apple', 'class' => 'recommended', 'sort' => 28 ],
	[ 'code' => 'gelatin', 'ja' => 'ゼラチン', 'en' => 'Gelatin', 'class' => 'recommended', 'sort' => 29 ],
];
