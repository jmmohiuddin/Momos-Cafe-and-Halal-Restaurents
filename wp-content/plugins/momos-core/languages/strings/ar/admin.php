<?php
/**
 * Admin module staff strings (ar): SCR-A02 dashboard widgets (docs/specs/access-security-privacy.md §13.1).
 * DRAFT – needs native review (DEC-027). Staff admin is ja/en only (DEC-037); these keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dash.needs_action.title' => 'يتطلب إجراء',
	'admin.dash.needs_action.flagged' => '{n} طلبات تحتاج إلى إجراء',
	'admin.dash.needs_action.not_notified' => '{n} ضيوف لم يتم إخطارهم بعد',
	'admin.dash.needs_action.none' => 'لا يوجد ما يتطلب إجراء.',
	'admin.dash.attendance.title' => 'الحضور المطلوب تسجيله',
	'admin.dash.attendance.count' => '{n} حجوزات بدون تسجيل حضور',
	'admin.dash.attendance.none' => 'تم تسجيل كل الحضور.',
	'admin.dash.pending.overdue' => '{n} تجاوزت مهلة الرد',
	'admin.dash.pending.item' => '{ref} — {date} {time}، {party} ضيوف ({branch})',
	'admin.dash.allergen.title' => 'بيانات مسببات الحساسية غير موثقة',
	'admin.dash.allergen.count' => '{n} أطباق ببيانات حساسية غير موثقة أو تحتاج مراجعة',
	'admin.dash.allergen.none' => 'كل بيانات مسببات الحساسية موثقة.',
	'admin.dash.offers_own.title' => 'عروضي بانتظار الاعتماد',
	'admin.dash.offers_own.count' => '{n} من عروضك بانتظار الاعتماد',
	'admin.dash.offers_own.none' => 'لا توجد عروض لك بانتظار الاعتماد.',
	'admin.dash.offers_ending.title' => 'عروض تنتهي قريبًا',
	'admin.dash.offers_ending.item' => '{title} — ينتهي {date}',
	'admin.dash.offers_ending.none' => 'لا ينتهي أي عرض خلال الأيام السبعة القادمة.',
	'admin.dash.demo.title' => 'المحتوى التجريبي المتبقي',
	'admin.dash.demo.count' => 'لا يزال {n} عناصر تجريبية على الموقع',
	'admin.dash.demo.illustrative' => '{n} صور توضيحية قيد الاستخدام',
	'admin.dash.demo.none' => 'لم يتبق أي محتوى تجريبي.',
	'admin.dash.tr_jobs.title' => 'مهام الترجمة الخاصة بي',
	'admin.dash.tr_jobs.count' => '{language}: {n} عناصر مفتوحة',
	'admin.dash.tr_jobs.none' => 'لا توجد مهام ترجمة مفتوحة.',
	// FR-ADM-001(b)/(c)/(d), AC-ADM-01-02 widgets; FR-CORP-010(c) offer list filters.
	'admin.dash.offers_pending.title' => 'عروض بانتظار الموافقة',
	'admin.dash.offers_pending.count' => 'عروض بانتظار الموافقة: {n}',
	'admin.dash.pages_pending.title' => 'صفحات بانتظار المراجعة',
	'admin.dash.pages_pending.count' => 'صفحات بانتظار المراجعة: {n}',
	'admin.dash.pages_pending.none' => 'لا توجد صفحات بانتظار المراجعة.',
	'admin.dash.tr_gaps.menu_title' => 'فجوات ترجمة القائمة',
	'admin.dash.tr_gaps.pages_title' => 'فجوات ترجمة الصفحات',
	'admin.dash.tr_gaps.language' => 'اللغة',
	'admin.dash.tr_gaps.items' => 'عناصر لم تتم الموافقة عليها بعد',
	'admin.dash.tr_gaps.lccs' => 'اكتمال LCCS',
	'admin.offers.filter.state' => 'تصفية حسب الحالة',
	'admin.offers.filter.all_states' => 'كل الحالات',
	'admin.offers.filter.scope' => 'تصفية حسب النطاق',
	'admin.offers.filter.all_scopes' => 'كل النطاقات',
	'admin.offers.scope.all_branches' => 'كل الفروع (الموقع بالكامل)',
	'admin.offers.scope.branches' => 'فروع محددة',
	'admin.offers.filter.branch' => 'تصفية حسب الفرع',
	'admin.offers.filter.all_branches' => 'كل الفروع',
];
