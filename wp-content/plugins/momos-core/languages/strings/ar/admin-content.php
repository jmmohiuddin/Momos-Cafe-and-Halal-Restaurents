<?php
/**
 * Admin content strings (content-strings.md §3.4 `admin.legal.publish_denied`, §12 `admin.translation.gaps_none`, `admin.media.alt_required` FR-ADM-005(d)) — Arabic.
 * DRAFT – needs native review (DEC-027, IMPL-003). Not for production until GATE-04 sign-off.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.legal.publish_denied' => 'يمكن للمكتب الرئيسي فقط نشر الصفحات القانونية أو تحديثها. احفظ مسودة وأرسلها للمراجعة.',
	'admin.translation.gaps_none' => 'لا توجد ترجمات ناقصة للغة {language}',
	'admin.media.alt_required' => 'أضف نصًا بديلًا قبل النشر',
	'admin.audit.action.translation.publish_blocked' => 'تم حظر نشر الترجمة (النص البديل مفقود)',
	'admin.audit.action.content.illustrative_refused' => 'رُفضت صورة توضيحية',
];
