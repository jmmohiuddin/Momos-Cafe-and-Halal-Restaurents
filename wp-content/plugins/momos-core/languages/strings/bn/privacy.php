<?php
/**
 * Privacy strings (bn). Keys: docs/specs/access-security-privacy.md §15.1/§15.2.
 * DRAFT – needs native review (DEC-027): machine-assisted Bengali draft, not approved for launch.
 * Legal text (consent.*) additionally needs counsel review; no machine translation for legal text in production (BR-PRV-04).
 * Staff admin is ja/en only (DEC-037); admin.* keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dsr.blocked_active' => 'প্রথমে সক্রিয় অনুরোধটি বাতিল করুন। ভবিষ্যতের কোনো অনুরোধ অপেক্ষমাণ বা নিশ্চিত থাকা অবস্থায় বেনামীকরণ সম্ভব নয়।',
	'admin.dsr.confirm'        => 'এই কাজটি আর ফেরানো যাবে না। এই রেকর্ডটি বেনামী করতে রেফারেন্স {ref} টাইপ করুন।',
	'admin.dsr.empty'          => 'কোনো রেকর্ড পাওয়া যায়নি।',
	'consent.special_request'  => 'আমি সম্মত যে আমার নোটে দেওয়া স্বাস্থ্য বা অ্যালার্জি সংক্রান্ত তথ্য শুধুমাত্র আমার আগমনের প্রস্তুতির জন্য ব্যবহার করা হবে।',
];
