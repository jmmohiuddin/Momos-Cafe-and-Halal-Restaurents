<?php
/**
 * Menu module customer strings — Bengali.
 * DRAFT – needs native review (DEC-027, IMPL-003). Not for production until GATE-04 sign-off.
 */

defined( 'ABSPATH' ) || exit;

return [
	'price.tax_incl'                      => '(কর সহ)',
	'menu.title'                          => 'মেনু',
	'menu.branch_select.label'            => 'এই শাখার দাম দেখান',
	'menu.branch_select.all'              => 'সব শাখা — রেফারেন্স দাম',
	'menu.prices_may_differ'              => 'শাখাভেদে দাম ভিন্ন হতে পারে',
	'menu.price_all_branches'             => 'সব শাখায় {price}',
	'menu.price_at_branch'                => '{branch}-এ {price}',
	'menu.change_branch'                  => 'পরিবর্তন করুন',
	'menu.sold_out'                       => 'আজ শেষ হয়ে গেছে',
	'menu.unavailable'                    => 'এই শাখায় এখন পাওয়া যাচ্ছে না',
	'menu.available_today'                => 'আজ পাওয়া যাচ্ছে',
	'menu.not_available'                  => 'বর্তমানে পাওয়া যাচ্ছে না',
	'menu.advance_order'                  => 'কমপক্ষে {hours} ঘণ্টা আগে অর্ডার করুন',
	'menu.advance_order.notice'           => 'এই খাবারটি কমপক্ষে {hours} ঘণ্টা আগে অর্ডার করতে হয়। টেবিলের অনুরোধ করার সময় বিশেষ অনুরোধে এটি উল্লেখ করুন।',
	'menu.advance_order.mention'          => 'আপনার টেবিলের অনুরোধে এটি উল্লেখ করুন',
	'menu.filters.heading'                => 'ফিল্টার',
	'menu.filters.toggle'                 => 'ফিল্টার ({n})',
	'menu.filters.empty'                  => 'এই ফিল্টারগুলোর সাথে কোনো খাবার মেলে না।',
	'menu.filters.clear'                  => 'ফিল্টার মুছুন',
	'menu.filters.count.one'              => '{n}টি খাবার দেখানো হচ্ছে',
	'menu.filters.count.other'            => '{n}টি খাবার দেখানো হচ্ছে',
	'menu.filter.diet'                    => 'খাদ্যাভ্যাসের পছন্দ',
	'menu.filter.max_spice'               => 'সর্বোচ্চ ঝাল',
	'menu.filter.max_spice.any'           => 'যেকোনো',
	'menu.filter.exclude'                 => 'যেসব খাবারে এটি থাকতে পারে সেগুলো লুকান',
	'menu.filter.allergen_note'           => 'স্বেচ্ছায় দেওয়া তথ্যের ভিত্তিতে। অ্যালার্জির বিষয়ে সবসময় কর্মীদের জানান।',
	'menu.category.branch_empty'          => 'এই শাখা এই বিভাগের খাবার পরিবেশন করে না।',
	'menu.category.all_branches_link'     => 'সব শাখা দেখান',
	'menu.available_at'                   => 'যেখানে পাওয়া যায়',
	'menu.related'                        => 'এই বিভাগের আরও খাবার',
	'menu.table.branch'                   => 'শাখা',
	'menu.table.price'                    => 'দাম',
	'menu.table.availability'             => 'প্রাপ্যতা',
	'menu.spice'                          => 'ঝাল: {label}',
	'spice.0'                             => 'ঝাল নেই',
	'spice.1'                             => 'হালকা',
	'spice.2'                             => 'মাঝারি',
	'spice.3'                             => 'বেশি ঝাল',
	'availability_reason.sold_out_today'  => 'আজ শেষ হয়ে গেছে',
	'availability_reason.seasonal'        => 'মৌসুমি',
	'availability_reason.ingredient_unavailable' => 'উপকরণ পাওয়া যাচ্ছে না',
	'availability_reason.advance_order_only'     => 'শুধু অগ্রিম অর্ডারে',
	'media.illustration'                  => 'ছবিটি শুধু উদাহরণের জন্য',
	// FR-MENU-005(a) audit label (SCR-A16).
	'admin.audit.action.menu_category.reordered' => 'বিভাগের ক্রম পরিবর্তন',
];
