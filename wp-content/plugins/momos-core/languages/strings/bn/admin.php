<?php
/**
 * Admin module staff strings (bn): SCR-A02 dashboard widgets (docs/specs/access-security-privacy.md §13.1).
 * DRAFT – needs native review (DEC-027). Staff admin is ja/en only (DEC-037); these keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dash.needs_action.title' => 'পদক্ষেপ প্রয়োজন',
	'admin.dash.needs_action.flagged' => '{n}টি অনুরোধে পদক্ষেপ প্রয়োজন',
	'admin.dash.needs_action.not_notified' => '{n} জন অতিথিকে এখনো জানানো হয়নি',
	'admin.dash.needs_action.none' => 'কোনো পদক্ষেপের প্রয়োজন নেই।',
	'admin.dash.attendance.title' => 'লিপিবদ্ধ করার উপস্থিতি',
	'admin.dash.attendance.count' => '{n}টি রিজার্ভেশনের উপস্থিতি লিপিবদ্ধ হয়নি',
	'admin.dash.attendance.none' => 'সব উপস্থিতি লিপিবদ্ধ।',
	'admin.dash.pending.overdue' => '{n}টি উত্তরের লক্ষ্য সময় পেরিয়েছে',
	'admin.dash.pending.item' => '{ref} — {date} {time}, {party} জন অতিথি ({branch})',
	'admin.dash.allergen.title' => 'অ্যালার্জেন তথ্য যাচাই হয়নি',
	'admin.dash.allergen.count' => '{n}টি খাবারের অ্যালার্জেন তথ্য যাচাই হয়নি বা পর্যালোচনা প্রয়োজন',
	'admin.dash.allergen.none' => 'সব অ্যালার্জেন তথ্য যাচাই করা হয়েছে।',
	'admin.dash.offers_own.title' => 'আমার অফার অনুমোদনের অপেক্ষায়',
	'admin.dash.offers_own.count' => 'আপনার {n}টি অফার অনুমোদনের অপেক্ষায়',
	'admin.dash.offers_own.none' => 'আপনার কোনো অফার অনুমোদনের অপেক্ষায় নেই।',
	'admin.dash.offers_ending.title' => 'শিগগির শেষ হওয়া অফার',
	'admin.dash.offers_ending.item' => '{title} — {date} শেষ',
	'admin.dash.offers_ending.none' => 'আগামী 7 দিনে কোনো অফার শেষ হচ্ছে না।',
	'admin.dash.demo.title' => 'অবশিষ্ট ডেমো বিষয়বস্তু',
	'admin.dash.demo.count' => '{n}টি নমুনা আইটেম এখনো সাইটে আছে',
	'admin.dash.demo.illustrative' => '{n}টি উদাহরণ ছবি ব্যবহৃত হচ্ছে',
	'admin.dash.demo.none' => 'কোনো ডেমো বিষয়বস্তু অবশিষ্ট নেই।',
	'admin.dash.tr_jobs.title' => 'আমার অনুবাদের কাজ',
	'admin.dash.tr_jobs.count' => '{language}: {n}টি অসম্পূর্ণ আইটেম',
	'admin.dash.tr_jobs.none' => 'কোনো অসম্পূর্ণ অনুবাদের কাজ নেই।',
];
