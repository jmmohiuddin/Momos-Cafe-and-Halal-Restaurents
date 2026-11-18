<?php
/**
 * Access/Audit staff strings (ur). Keys: docs/specs/access-security-privacy.md §15.1.
 * DRAFT – needs native review (DEC-027): machine-assisted Urdu draft, not approved for launch.
 * Staff admin is ja/en only (DEC-037); these keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.login.err.generic' => 'صارف نام یا پاس ورڈ درست نہیں ہے۔',
	'admin.login.err.locked' => 'بہت زیادہ ناکام کوششیں۔ براہ کرم {time} کے بعد دوبارہ کوشش کریں۔',
	'admin.login.err.locked_generic' => 'بہت زیادہ ناکام کوششیں۔ براہ کرم بعد میں دوبارہ کوشش کریں۔',
	'admin.2fa.title' => 'دو مرحلہ تصدیق',
	'admin.2fa.prompt' => 'اپنی تصدیقی ایپ سے 6 ہندسوں کا کوڈ درج کریں۔',
	'admin.2fa.err.invalid' => 'یہ کوڈ درست نہیں ہے۔ براہ کرم دوبارہ کوشش کریں۔',
	'admin.2fa.err.expired' => 'تصدیق کا مرحلہ ختم ہو گیا۔ براہ کرم دوبارہ لاگ ان کریں۔',
	'admin.2fa.err.too_many' => 'بہت زیادہ غلط کوڈ۔ براہ کرم دوبارہ لاگ ان کریں۔',
	'admin.2fa.err.confirm_saved' => 'تصدیق کے لیے نشان لگائیں کہ آپ نے کوڈ محفوظ کر لیے ہیں۔ نئے کوڈ بنائے گئے ہیں — انہیں محفوظ کریں۔',
	'admin.2fa.recovery_link' => 'ریکوری کوڈ استعمال کریں',
	'admin.2fa.recovery_label' => 'ریکوری کوڈ (XXXX-XXXX)',
	'admin.2fa.verify' => 'تصدیق کریں',
	'admin.2fa.enrol.title' => 'دو مرحلہ تصدیق ترتیب دیں',
	'admin.2fa.enrol.intro' => 'تصدیقی ایپ سے QR کوڈ اسکین کریں (یا کلید دستی طور پر درج کریں)، پھر دکھایا گیا 6 ہندسوں کا کوڈ درج کریں۔',
	'admin.2fa.enrol.key_label' => 'دستی اندراج کے لیے کلید:',
	'admin.2fa.enrol.qr_alt' => 'تصدیقی ایپ کے لیے QR کوڈ',
	'admin.2fa.codes.intro' => 'یہ ریکوری کوڈ کسی محفوظ جگہ رکھیں۔ فون گم ہونے پر ہر کوڈ ایک بار کام کرتا ہے۔ یہ دوبارہ نہیں دکھائے جائیں گے۔',
	'admin.2fa.saved_codes' => 'میں نے یہ کوڈ محفوظ کر لیے ہیں',
	'admin.2fa.continue' => 'جاری رکھیں',
	'admin.user.err.username' => 'کوئی مختلف صارف نام منتخب کریں۔',
	'admin.user.err.branch_required' => 'کم از کم ایک برانچ تفویض کریں۔',
	'admin.user.no_branch' => 'آپ کے اکاؤنٹ کو کوئی برانچ تفویض نہیں کی گئی۔ براہ کرم ہیڈ آفس سے رابطہ کریں۔',
	'admin.pw.err.length' => '12 سے 128 حروف استعمال کریں۔',
	'admin.pw.err.breached' => 'یہ پاس ورڈ بہت عام ہے یا ڈیٹا لیک میں سامنے آ چکا ہے۔ کوئی اور منتخب کریں۔',
	'admin.vendor.err.window' => 'وینڈر رسائی زیادہ سے زیادہ {hours} گھنٹوں کے لیے فعال کی جا سکتی ہے۔',
	'admin.audit.empty' => 'ان فلٹرز کے لیے کوئی اندراج نہیں',
	'admin.err.forbidden_requests' => 'آپ کو اس برانچ کی درخواستوں تک رسائی حاصل نہیں ہے۔',
	'admin.err.forbidden' => 'آپ کو یہ کرنے کی اجازت نہیں ہے۔',
	'staff.err.forbidden_branch' => 'آپ کو اس برانچ تک رسائی حاصل نہیں ہے۔',
	'staff.err.forbidden_field' => 'آپ کو یہ تبدیل کرنے کی اجازت نہیں ہے: {field}۔',
	'admin.lang.err.gate' => '{language} فعال نہیں کی جا سکتی: لانچ کے لیے ضروری {n} اشیاء غائب یا غیر جائزہ شدہ ہیں۔',
	'admin.lang.err.signoff' => '{language} فعال کرنے سے پہلے مقامی جائزے کی منظوری (نام اور تاریخ) درج کریں۔',
];
