<?php
/**
 * Privacy strings (ar). Keys: docs/specs/access-security-privacy.md §15.1/§15.2.
 * DRAFT – needs native review (DEC-027): machine-assisted Arabic draft, not approved for launch.
 * Legal text (consent.*) additionally needs counsel review; no machine translation for legal text in production (BR-PRV-04).
 * Staff admin is ja/en only (DEC-037); admin.* keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dsr.blocked_active' => 'ألغِ الطلب النشط أولًا. لا يمكن إخفاء الهوية ما دام هناك طلب مستقبلي قيد الانتظار أو مؤكَّد.',
	'admin.dsr.confirm'        => 'لا يمكن التراجع عن هذا الإجراء. اكتب الرقم المرجعي {ref} لإخفاء هوية هذا السجل.',
	'admin.dsr.empty'          => 'لم يتم العثور على سجلات.',
	'consent.special_request'  => 'أوافق على أن تُستخدم المعلومات الصحية أو المتعلقة بالحساسية في ملاحظتي فقط للتحضير لزيارتي.',
];
