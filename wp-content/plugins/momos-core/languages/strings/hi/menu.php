<?php
/**
 * Menu module customer strings — Hindi.
 * DRAFT – needs native review (DEC-027, IMPL-003). Not for production until GATE-04 sign-off.
 */

defined( 'ABSPATH' ) || exit;

return [
	'price.tax_incl'                      => '(कर सहित)',
	'menu.title'                          => 'मेन्यू',
	'menu.branch_select.label'            => 'इस शाखा की कीमतें दिखाएँ',
	'menu.branch_select.all'              => 'सभी शाखाएँ — संदर्भ कीमतें',
	'menu.prices_may_differ'              => 'कीमतें शाखा के अनुसार अलग हो सकती हैं',
	'menu.price_all_branches'             => 'सभी शाखाओं में {price}',
	'menu.price_at_branch'                => '{branch} में {price}',
	'menu.change_branch'                  => 'बदलें',
	'menu.sold_out'                       => 'आज समाप्त',
	'menu.unavailable'                    => 'इस शाखा में अभी उपलब्ध नहीं',
	'menu.available_today'                => 'आज उपलब्ध',
	'menu.not_available'                  => 'अभी उपलब्ध नहीं',
	'menu.advance_order'                  => 'कम से कम {hours} घंटे पहले ऑर्डर करें',
	'menu.advance_order.notice'           => 'यह व्यंजन कम से कम {hours} घंटे पहले ऑर्डर करना होता है। टेबल का अनुरोध करते समय विशेष अनुरोध में इसका उल्लेख करें।',
	'menu.advance_order.mention'          => 'अपने टेबल अनुरोध में इसका उल्लेख करें',
	'menu.filters.heading'                => 'फ़िल्टर',
	'menu.filters.toggle'                 => 'फ़िल्टर ({n})',
	'menu.filters.empty'                  => 'इन फ़िल्टरों से कोई व्यंजन मेल नहीं खाता।',
	'menu.filters.clear'                  => 'फ़िल्टर हटाएँ',
	'menu.filters.count.one'              => '{n} व्यंजन दिखाया गया',
	'menu.filters.count.other'            => '{n} व्यंजन दिखाए गए',
	'menu.filter.diet'                    => 'आहार संबंधी प्राथमिकताएँ',
	'menu.filter.max_spice'               => 'अधिकतम तीखापन',
	'menu.filter.max_spice.any'           => 'कोई भी',
	'menu.filter.exclude'                 => 'ऐसे व्यंजन छिपाएँ जिनमें यह हो सकता है',
	'menu.filter.allergen_note'           => 'स्वेच्छा से दी गई जानकारी पर आधारित। कृपया एलर्जी के बारे में हमेशा कर्मचारियों को बताएँ।',
	'menu.category.branch_empty'          => 'यह शाखा इस श्रेणी के व्यंजन नहीं परोसती।',
	'menu.category.all_branches_link'     => 'सभी शाखाएँ दिखाएँ',
	'menu.available_at'                   => 'यहाँ उपलब्ध',
	'menu.related'                        => 'इस श्रेणी के और व्यंजन',
	'menu.table.branch'                   => 'शाखा',
	'menu.table.price'                    => 'कीमत',
	'menu.table.availability'             => 'उपलब्धता',
	'menu.spice'                          => 'तीखापन: {label}',
	'spice.0'                             => 'तीखा नहीं',
	'spice.1'                             => 'हल्का',
	'spice.2'                             => 'मध्यम',
	'spice.3'                             => 'तीखा',
	'availability_reason.sold_out_today'  => 'आज समाप्त',
	'availability_reason.seasonal'        => 'मौसमी',
	'availability_reason.ingredient_unavailable' => 'सामग्री उपलब्ध नहीं',
	'availability_reason.advance_order_only'     => 'केवल अग्रिम ऑर्डर पर',
	'media.illustration'                  => 'चित्र केवल उदाहरण के लिए है',
	// FR-MENU-005(a) audit label (SCR-A16).
	'admin.audit.action.menu_category.reordered' => 'श्रेणी क्रम बदला गया',
];
