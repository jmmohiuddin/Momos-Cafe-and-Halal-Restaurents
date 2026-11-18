<?php
/**
 * Access/Audit staff strings (bn). Keys: docs/specs/access-security-privacy.md §15.1.
 * DRAFT – needs native review (DEC-027): machine-assisted Bengali draft, not approved for launch.
 * Staff admin is ja/en only (DEC-037); these keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.login.err.generic' => 'ব্যবহারকারীর নাম বা পাসওয়ার্ড ভুল।',
	'admin.login.err.locked' => 'অনেকবার ব্যর্থ প্রচেষ্টা। অনুগ্রহ করে {time}-এর পরে আবার চেষ্টা করুন।',
	'admin.login.err.locked_generic' => 'অনেকবার ব্যর্থ প্রচেষ্টা। অনুগ্রহ করে পরে আবার চেষ্টা করুন।',
	'admin.2fa.title' => 'দুই-ধাপ যাচাই',
	'admin.2fa.prompt' => 'আপনার অথেন্টিকেটর অ্যাপ থেকে ৬-অঙ্কের কোড লিখুন।',
	'admin.2fa.err.invalid' => 'এই কোডটি সঠিক নয়। অনুগ্রহ করে আবার চেষ্টা করুন।',
	'admin.2fa.err.expired' => 'আপনার যাচাইয়ের ধাপের মেয়াদ শেষ হয়েছে। অনুগ্রহ করে আবার লগ ইন করুন।',
	'admin.2fa.err.too_many' => 'অনেকবার ভুল কোড। অনুগ্রহ করে আবার লগ ইন করুন।',
	'admin.2fa.err.confirm_saved' => 'কোডগুলো সংরক্ষণ করেছেন তা নিশ্চিত করতে টিক দিন। নতুন কোড তৈরি করা হয়েছে — এগুলো সংরক্ষণ করুন।',
	'admin.2fa.recovery_link' => 'রিকভারি কোড ব্যবহার করুন',
	'admin.2fa.recovery_label' => 'রিকভারি কোড (XXXX-XXXX)',
	'admin.2fa.verify' => 'যাচাই করুন',
	'admin.2fa.enrol.title' => 'দুই-ধাপ যাচাই সেট আপ করুন',
	'admin.2fa.enrol.intro' => 'অথেন্টিকেটর অ্যাপ দিয়ে QR কোড স্ক্যান করুন (অথবা কী নিজে লিখুন), তারপর দেখানো ৬-অঙ্কের কোড লিখুন।',
	'admin.2fa.enrol.key_label' => 'নিজে লেখার জন্য কী:',
	'admin.2fa.enrol.qr_alt' => 'অথেন্টিকেটর অ্যাপের জন্য QR কোড',
	'admin.2fa.codes.intro' => 'এই রিকভারি কোডগুলো নিরাপদ স্থানে সংরক্ষণ করুন। ফোন হারালে প্রতিটি কোড একবার কাজ করে। এগুলো আর দেখানো হবে না।',
	'admin.2fa.saved_codes' => 'আমি এই কোডগুলো সংরক্ষণ করেছি',
	'admin.2fa.continue' => 'চালিয়ে যান',
	'admin.user.err.username' => 'অন্য একটি ব্যবহারকারীর নাম বেছে নিন।',
	'admin.user.err.branch_required' => 'অন্তত একটি শাখা নির্ধারণ করুন।',
	'admin.user.no_branch' => 'আপনার অ্যাকাউন্টে কোনো শাখা নির্ধারিত নেই। অনুগ্রহ করে হেড অফিসে যোগাযোগ করুন।',
	'admin.pw.err.length' => '১২ থেকে ১২৮ অক্ষর ব্যবহার করুন।',
	'admin.pw.err.breached' => 'এই পাসওয়ার্ডটি খুব সাধারণ বা ডেটা ফাঁসে পাওয়া গেছে। অন্যটি বেছে নিন।',
	'admin.vendor.err.window' => 'ভেন্ডর অ্যাক্সেস সর্বোচ্চ {hours} ঘণ্টার জন্য চালু করা যায়।',
	'admin.audit.empty' => 'এই ফিল্টারগুলোর জন্য কোনো এন্ট্রি নেই',
	'admin.err.forbidden_requests' => 'এই শাখার অনুরোধগুলোতে আপনার অ্যাক্সেস নেই।',
	'admin.err.forbidden' => 'এটি করার অনুমতি আপনার নেই।',
	'staff.err.forbidden_branch' => 'এই শাখায় আপনার অ্যাক্সেস নেই।',
	'staff.err.forbidden_field' => 'আপনি এটি পরিবর্তন করতে পারবেন না: {field}।',
	'admin.lang.err.gate' => '{language} চালু করা যাচ্ছে না: লঞ্চের জন্য প্রয়োজনীয় {n}টি আইটেম অনুপস্থিত বা পর্যালোচনা হয়নি।',
	'admin.lang.err.signoff' => '{language} চালু করার আগে স্থানীয় পর্যালোচনার অনুমোদন (নাম ও তারিখ) লিখুন।',
];
