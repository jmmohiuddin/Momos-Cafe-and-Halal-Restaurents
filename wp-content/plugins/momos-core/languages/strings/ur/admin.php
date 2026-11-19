<?php
/**
 * Admin module staff strings (ur): SCR-A02 dashboard widgets (docs/specs/access-security-privacy.md §13.1).
 * DRAFT – needs native review (DEC-027). Staff admin is ja/en only (DEC-037); these keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dash.needs_action.title' => 'کارروائی درکار',
	'admin.dash.needs_action.flagged' => '{n} درخواستوں پر کارروائی درکار',
	'admin.dash.needs_action.not_notified' => '{n} مہمانوں کو ابھی اطلاع نہیں دی گئی',
	'admin.dash.needs_action.none' => 'کسی کارروائی کی ضرورت نہیں۔',
	'admin.dash.attendance.title' => 'حاضری درج کرنا باقی',
	'admin.dash.attendance.count' => '{n} ریزرویشنز کی حاضری درج نہیں',
	'admin.dash.attendance.none' => 'تمام حاضری درج ہے۔',
	'admin.dash.pending.overdue' => '{n} جواب کے ہدف سے تجاوز',
	'admin.dash.pending.item' => '{ref} — {date} {time}، {party} مہمان ({branch})',
	'admin.dash.allergen.title' => 'الرجی ڈیٹا غیر تصدیق شدہ',
	'admin.dash.allergen.count' => '{n} ڈشز کا الرجی ڈیٹا غیر تصدیق شدہ یا جائزہ طلب',
	'admin.dash.allergen.none' => 'تمام الرجی ڈیٹا تصدیق شدہ ہے۔',
	'admin.dash.offers_own.title' => 'میری پیشکشیں منظوری کی منتظر',
	'admin.dash.offers_own.count' => 'آپ کی {n} پیشکشیں منظوری کی منتظر ہیں',
	'admin.dash.offers_own.none' => 'آپ کی کوئی پیشکش منظوری کی منتظر نہیں۔',
	'admin.dash.offers_ending.title' => 'جلد ختم ہونے والی پیشکشیں',
	'admin.dash.offers_ending.item' => '{title} — {date} کو ختم',
	'admin.dash.offers_ending.none' => 'اگلے 7 دنوں میں کوئی پیشکش ختم نہیں ہو رہی۔',
	'admin.dash.demo.title' => 'باقی ڈیمو مواد',
	'admin.dash.demo.count' => '{n} نمونہ آئٹمز ابھی سائٹ پر ہیں',
	'admin.dash.demo.illustrative' => '{n} تمثیلی تصاویر استعمال میں',
	'admin.dash.demo.none' => 'کوئی ڈیمو مواد باقی نہیں۔',
	'admin.dash.tr_jobs.title' => 'میرے ترجمے کے کام',
	'admin.dash.tr_jobs.count' => '{language}: {n} زیر التوا آئٹمز',
	'admin.dash.tr_jobs.none' => 'ترجمے کا کوئی زیر التوا کام نہیں۔',
	// FR-ADM-001(b)/(c)/(d), AC-ADM-01-02 widgets; FR-CORP-010(c) offer list filters.
	'admin.dash.offers_pending.title' => 'منظوری کے منتظر آفرز',
	'admin.dash.offers_pending.count' => 'منظوری کے منتظر آفرز: {n}',
	'admin.dash.pages_pending.title' => 'جائزے کے منتظر صفحات',
	'admin.dash.pages_pending.count' => 'جائزے کے منتظر صفحات: {n}',
	'admin.dash.pages_pending.none' => 'کوئی صفحہ جائزے کا منتظر نہیں۔',
	'admin.dash.tr_gaps.menu_title' => 'مینو کے ترجمے میں کمی',
	'admin.dash.tr_gaps.pages_title' => 'صفحات کے ترجمے میں کمی',
	'admin.dash.tr_gaps.language' => 'زبان',
	'admin.dash.tr_gaps.items' => 'ابھی منظور نہ ہونے والے آئٹمز',
	'admin.dash.tr_gaps.lccs' => 'LCCS تکمیل',
	'admin.offers.filter.state' => 'حالت کے لحاظ سے فلٹر',
	'admin.offers.filter.all_states' => 'تمام حالتیں',
	'admin.offers.filter.scope' => 'دائرہ کار کے لحاظ سے فلٹر',
	'admin.offers.filter.all_scopes' => 'تمام دائرہ کار',
	'admin.offers.scope.all_branches' => 'تمام برانچز (پوری سائٹ)',
	'admin.offers.scope.branches' => 'منتخب برانچز',
	'admin.offers.filter.branch' => 'برانچ کے لحاظ سے فلٹر',
	'admin.offers.filter.all_branches' => 'تمام برانچز',
];
