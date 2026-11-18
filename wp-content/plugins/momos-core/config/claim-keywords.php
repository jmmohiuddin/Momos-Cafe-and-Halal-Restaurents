<?php
/**
 * Master claim-term list for halal, alcohol and allergen wording (Doc 11 §8.6 + §9.5, spec
 * menu-dietary §6.4). Used by Dietary\Guard\WordingGuard (save-time checks) and the claim-term scan
 * (FR-DIET-009). Matching is case-insensitive substring matching.
 *
 * Structure: class => lang => list<term>. Lang `*` applies to every language (numbers, Latin terms
 * that also appear in other-language text).
 * ar/ur/hi/bn lists are DRAFT seeds only — native reviewers complete them (GATE-04).
 *
 * Classes:
 *  - certification: allowed only through a `certified.*` template of a displayable record.
 *  - absolute:      never as free text ("100% halal" …).
 *  - group:         "all branches halal" …
 *  - practice:      "pork-free kitchen" …
 *  - recognition:   recognition / religious-ruling wording.
 *  - allergen:      BR-DIET-03 — never "allergen-free", "safe for allergies" or equivalents.
 *  - ordering:      UI-001 clean-up (no online ordering, DEC-042/057).
 *
 * `scan_base` holds the base terms that the claim-term scan and the save-time warning look for in free
 * text (halal statements come only from register-driven blocks — LC-001 REQ-HAL-01).
 */

defined( 'ABSPATH' ) || exit;

return [
	'classes'   => [
		'certification' => [
			'en' => [ 'halal certified', 'halal-certified', 'certified halal', 'halal certified kitchen' ],
			'ja' => [ 'ハラール認証', 'ハラル認証', '認証取得' ],
			'ar' => [ 'حلال معتمد' ],
			'ur' => [ 'حلال سرٹیفائیڈ' ],
			'hi' => [],
			'bn' => [],
		],
		'absolute'      => [
			'*'  => [ '100% halal', '100%halal' ],
			'en' => [ '100% halal prepared', 'fully halal', 'strictly halal certified', 'halal guaranteed' ],
			'ja' => [ '100%ハラール', '100％ハラール', '完全ハラール', '完全ハラル' ],
			'ar' => [],
			'ur' => [],
			'hi' => [],
			'bn' => [],
		],
		'group'         => [
			'en' => [ 'all branches halal', 'fully halal restaurant group' ],
			'ja' => [],
		],
		'practice'      => [
			'en' => [ 'pork-free kitchen', 'pork free kitchen', 'alcohol-free restaurant', 'alcohol free restaurant' ],
			'ja' => [ '豚肉不使用の厨房' ],
		],
		'recognition'   => [
			'en' => [ 'approved by jakim', 'approved by bpjph', 'approved by muis', 'permissible for all muslims' ],
			'ja' => [],
		],
		'allergen'      => [
			'*'  => [ '100% safe' ],
			'en' => [ 'allergen-free', 'allergen free', 'allergy-free', 'allergy free', 'allergy-safe', 'allergy safe', 'safe for allergies', 'safe for allergy sufferers', 'no risk', 'gluten-free', 'gluten free', 'nut-free', 'nut free', 'dairy-free', 'dairy free' ],
			'ja' => [ 'アレルゲンフリー', 'アレルギー対応済み', '完全除去', 'アレルギーの方も安心', '100%安全', '100％安全', 'リスクなし', 'グルテンフリー' ],
			'ar' => [],
			'ur' => [],
			'hi' => [],
			'bn' => [],
		],
		'ordering'      => [
			'en' => [ 'order now', 'order online', 'add to cart', 'add to order', 'checkout', 'place order' ],
			'ja' => [ '今すぐ注文', 'カートに追加', '注文を確定' ],
		],
	],
	// Base terms for the claim-term scan and save-time warnings (Doc 11 §8.6).
	'scan_base' => [ 'halal', 'ハラール', 'ハラル', 'حلال', 'हलाल', 'হালাল', 'certified', '認証', '100%', '100％' ],
	// Image file names / alt texts containing these are listed by the scan (unless a protected register mark).
	'image_terms' => [ 'halal', 'seal' ],
];
