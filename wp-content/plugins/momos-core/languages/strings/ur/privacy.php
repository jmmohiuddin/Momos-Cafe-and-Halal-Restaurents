<?php
/**
 * Privacy strings (ur). Keys: docs/specs/access-security-privacy.md §15.1/§15.2.
 * DRAFT – needs native review (DEC-027): machine-assisted Urdu draft, not approved for launch.
 * Legal text (consent.*) additionally needs counsel review; no machine translation for legal text in production (BR-PRV-04).
 * Staff admin is ja/en only (DEC-037); admin.* keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dsr.blocked_active' => 'پہلے فعال درخواست منسوخ کریں۔ جب تک کوئی آئندہ درخواست زیرِ التوا یا تصدیق شدہ ہو، گمنامی ممکن نہیں۔',
	'admin.dsr.confirm'        => 'یہ عمل واپس نہیں ہو سکتا۔ اس ریکارڈ کو گمنام کرنے کے لیے حوالہ نمبر {ref} لکھیں۔',
	'admin.dsr.empty'          => 'کوئی ریکارڈ نہیں ملا۔',
	'consent.special_request'  => 'میں اتفاق کرتا/کرتی ہوں کہ میرے نوٹ میں صحت یا الرجی سے متعلق معلومات صرف میری آمد کی تیاری کے لیے استعمال ہوں گی۔',
];
