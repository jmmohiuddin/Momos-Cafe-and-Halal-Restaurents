<?php
/**
 * Dietary module customer strings — Bengali.
 * DRAFT – needs native review (DEC-027, BR-DIET-05: claim-related wording must be human-reviewed before
 * the language is enabled). Not for production until GATE-04 sign-off.
 */

defined( 'ABSPATH' ) || exit;

return [
	'allergen.heading'               => 'অ্যালার্জেন',
	'allergen.state.contains'        => 'রয়েছে',
	'allergen.state.may_contain'     => 'থাকতে পারে (ক্রস-কন্টাক্ট)',
	'allergen.state.not_contained'   => 'নেই (যাচাইকৃত)',
	'allergen.state.not_verified'    => 'যাচাই করা হয়নি',
	'allergen.summary.contains'      => 'রয়েছে: {list}',
	'allergen.summary.may_contain'   => 'থাকতে পারে (ক্রস-কন্টাক্ট): {list}',
	'allergen.banner.not_verified'   => 'অ্যালার্জেন তথ্য যাচাই করা হয়নি — অনুগ্রহ করে কর্মীদের জিজ্ঞাসা করুন।',
	'allergen.disclaimer.voluntary'  => 'অ্যালার্জেন তথ্য রেফারেন্সের জন্য স্বেচ্ছায় দেওয়া হয়। সরবরাহকারী বা রেসিপি বদলালে এটি বদলাতে পারে, এবং শাখাভেদে উপকরণ ভিন্ন হতে পারে।',
	'allergen.cross_contact'         => 'আমাদের রান্নাঘরে অন্যান্য উপকরণও ব্যবহৃত হয় এবং সরঞ্জাম ও ভাজার তেল ভাগাভাগি হতে পারে, তাই আমরা সামান্য পরিমাণের অনুপস্থিতির নিশ্চয়তা দিতে পারি না।',
	'allergen.tell_staff'            => 'আপনার গুরুতর অ্যালার্জি থাকলে, পৌঁছানোর সময় এবং অর্ডার করার সময় আমাদের কর্মীদের জানান। আমরা কোনো খাবার আপনার জন্য প্রস্তুত করতে না পারলে সততার সাথে জানাব।',
	'allergen.last_verified'         => 'তথ্য সর্বশেষ যাচাই করা হয়েছে {date}।',
	'allergen.group.mandatory'       => 'নির্দিষ্ট উপকরণ (9টি — প্যাকেটজাত খাবারে বাধ্যতামূলক)',
	'allergen.group.recommended'     => 'সুপারিশকৃত আইটেম (20টি)',
	'allergen.group.other'           => 'অন্যান্য উপকরণ',
	'allergen.legend'                => 'সংকেত',
	'allergen.table.caption'         => 'অ্যালার্জেন তথ্য',
	'allergen.table.allergen'        => 'অ্যালার্জেন',
	'allergen.table.status'          => 'অবস্থা',
	'allergen.toggle.relevant'       => 'শুধু «রয়েছে» ও «থাকতে পারে» দেখান',
	'ingredients.heading'            => 'প্রধান উপকরণ (সম্পূর্ণ তালিকা নয়)',
	'last_verified'                  => 'সর্বশেষ যাচাই: {date}',
	'halal.heading'                  => 'হালাল তথ্য',
	'halal.label.certified'          => 'হালাল সার্টিফাইড',
	'halal.label.ingredients'        => 'হালাল উপকরণ',
	'halal.not_applicable'           => 'প্রযোজ্য নয়',
	'halal.mark_alt'                 => '{certifier}-এর হালাল সার্টিফিকেশন চিহ্ন',
	'halal.verify_link'              => 'সার্টিফায়ারের কাছে যাচাই করুন',
	'halal.certifier'                => 'সার্টিফায়ার',
	'halal.scope'                    => 'পরিধি',
	'halal.cert_no'                  => 'সার্টিফিকেট নম্বর',
	'halal.valid_until'              => 'মেয়াদ',
	'halal.scope.whole_kitchen'      => 'সম্পূর্ণ রান্নাঘর',
	'halal.scope.specific_menu'      => 'চিহ্নিত খাবার',
	'halal.scope.specific_ingredients' => 'নির্দিষ্ট উপকরণ',
	'alcohol.heading'                => 'অ্যালকোহল',
	'diet.list.separator'            => ', ',
	// FR-DIET-002(f) audit label (SCR-A16).
	'admin.audit.action.dietary.prohibited_terms_updated' => 'নিষিদ্ধ শব্দের তালিকা হালনাগাদ',
];
