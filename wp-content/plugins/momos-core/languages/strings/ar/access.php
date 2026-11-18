<?php
/**
 * Access/Audit staff strings (ar). Keys: docs/specs/access-security-privacy.md §15.1.
 * DRAFT – needs native review (DEC-027): machine-assisted Arabic draft, not approved for launch.
 * Staff admin is ja/en only (DEC-037); these keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.login.err.generic' => 'اسم المستخدم أو كلمة المرور غير صحيحة.',
	'admin.login.err.locked' => 'محاولات فاشلة كثيرة. يُرجى المحاولة مرة أخرى بعد {time}.',
	'admin.login.err.locked_generic' => 'محاولات فاشلة كثيرة. يُرجى المحاولة لاحقًا.',
	'admin.2fa.title' => 'التحقق بخطوتين',
	'admin.2fa.prompt' => 'أدخل الرمز المكوّن من 6 أرقام من تطبيق المصادقة.',
	'admin.2fa.err.invalid' => 'هذا الرمز غير صالح. يُرجى المحاولة مرة أخرى.',
	'admin.2fa.err.expired' => 'انتهت صلاحية خطوة التحقق. يُرجى تسجيل الدخول مرة أخرى.',
	'admin.2fa.err.too_many' => 'رموز خاطئة كثيرة. يُرجى تسجيل الدخول مرة أخرى.',
	'admin.2fa.err.confirm_saved' => 'ضع علامة لتأكيد أنك حفظت الرموز. تم إنشاء مجموعة جديدة من الرموز — احفظها بدلًا من السابقة.',
	'admin.2fa.recovery_link' => 'استخدام رمز استرداد',
	'admin.2fa.recovery_label' => 'رمز الاسترداد (XXXX-XXXX)',
	'admin.2fa.verify' => 'تحقق',
	'admin.2fa.enrol.title' => 'إعداد التحقق بخطوتين',
	'admin.2fa.enrol.intro' => 'امسح رمز QR بتطبيق المصادقة (أو أدخل المفتاح يدويًا)، ثم أدخل الرمز المكوّن من 6 أرقام.',
	'admin.2fa.enrol.key_label' => 'المفتاح للإدخال اليدوي:',
	'admin.2fa.enrol.qr_alt' => 'رمز QR لتطبيق المصادقة',
	'admin.2fa.codes.intro' => 'احفظ رموز الاسترداد هذه في مكان آمن. يعمل كل رمز مرة واحدة إذا فقدت هاتفك. لن تُعرض مرة أخرى.',
	'admin.2fa.saved_codes' => 'لقد حفظت هذه الرموز',
	'admin.2fa.continue' => 'متابعة',
	'admin.user.err.username' => 'اختر اسم مستخدم مختلفًا.',
	'admin.user.err.branch_required' => 'عيّن فرعًا واحدًا على الأقل.',
	'admin.user.no_branch' => 'لا يوجد فرع مخصص لحسابك. يُرجى التواصل مع المكتب الرئيسي.',
	'admin.pw.err.length' => 'استخدم من 12 إلى 128 حرفًا.',
	'admin.pw.err.breached' => 'كلمة المرور هذه شائعة جدًا أو ظهرت في تسريب بيانات. اختر كلمة أخرى.',
	'admin.vendor.err.window' => 'يمكن تفعيل وصول المورّد لمدة أقصاها {hours} ساعة.',
	'admin.audit.empty' => 'لا توجد سجلات لهذه المرشحات',
	'admin.err.forbidden_requests' => 'ليس لديك صلاحية الوصول إلى طلبات هذا الفرع.',
	'admin.err.forbidden' => 'ليس لديك إذن للقيام بذلك.',
	'staff.err.forbidden_branch' => 'ليس لديك صلاحية الوصول إلى هذا الفرع.',
	'staff.err.forbidden_field' => 'لا يُسمح لك بتغيير: {field}.',
	'admin.lang.err.gate' => 'لا يمكن تفعيل {language}: هناك {n} عناصر أساسية للإطلاق مفقودة أو غير مراجَعة.',
	'admin.lang.err.signoff' => 'سجّل اعتماد المراجعة الأصلية (الاسم والتاريخ) قبل تفعيل {language}.',
];
