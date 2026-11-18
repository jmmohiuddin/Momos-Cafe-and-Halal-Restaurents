<?php
/**
 * Access/Audit staff strings (hi). Keys: docs/specs/access-security-privacy.md §15.1.
 * DRAFT – needs native review (DEC-027): machine-assisted Hindi draft, not approved for launch.
 * Staff admin is ja/en only (DEC-037); these keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.login.err.generic' => 'उपयोगकर्ता नाम या पासवर्ड गलत है।',
	'admin.login.err.locked' => 'बहुत अधिक असफल प्रयास। कृपया {time} के बाद फिर से प्रयास करें।',
	'admin.login.err.locked_generic' => 'बहुत अधिक असफल प्रयास। कृपया बाद में फिर से प्रयास करें।',
	'admin.2fa.title' => 'दो-चरणीय सत्यापन',
	'admin.2fa.prompt' => 'अपने ऑथेंटिकेटर ऐप से 6 अंकों का कोड दर्ज करें।',
	'admin.2fa.err.invalid' => 'यह कोड मान्य नहीं है। कृपया फिर से प्रयास करें।',
	'admin.2fa.err.expired' => 'आपके सत्यापन चरण की समय-सीमा समाप्त हो गई। कृपया फिर से लॉग इन करें।',
	'admin.2fa.err.too_many' => 'बहुत अधिक गलत कोड। कृपया फिर से लॉग इन करें।',
	'admin.2fa.err.confirm_saved' => 'कोड सहेजने की पुष्टि के लिए बॉक्स पर टिक करें। कोड का नया सेट बनाया गया है — इसके बजाय इन्हें सहेजें।',
	'admin.2fa.recovery_link' => 'रिकवरी कोड का उपयोग करें',
	'admin.2fa.recovery_label' => 'रिकवरी कोड (XXXX-XXXX)',
	'admin.2fa.verify' => 'सत्यापित करें',
	'admin.2fa.enrol.title' => 'दो-चरणीय सत्यापन सेट करें',
	'admin.2fa.enrol.intro' => 'अपने ऑथेंटिकेटर ऐप से QR कोड स्कैन करें (या कुंजी मैन्युअल रूप से दर्ज करें), फिर दिखाया गया 6 अंकों का कोड दर्ज करें।',
	'admin.2fa.enrol.key_label' => 'मैन्युअल प्रविष्टि के लिए कुंजी:',
	'admin.2fa.enrol.qr_alt' => 'ऑथेंटिकेटर ऐप के लिए QR कोड',
	'admin.2fa.codes.intro' => 'इन रिकवरी कोड को सुरक्षित स्थान पर सहेजें। फ़ोन खो जाने पर प्रत्येक कोड एक बार काम करता है। ये दोबारा नहीं दिखाए जाएंगे।',
	'admin.2fa.saved_codes' => 'मैंने ये कोड सहेज लिए हैं',
	'admin.2fa.continue' => 'जारी रखें',
	'admin.user.err.username' => 'कोई अन्य उपयोगकर्ता नाम चुनें।',
	'admin.user.err.branch_required' => 'कम से कम एक शाखा असाइन करें।',
	'admin.user.no_branch' => 'आपके खाते को कोई शाखा असाइन नहीं है। कृपया हेड ऑफ़िस से संपर्क करें।',
	'admin.pw.err.length' => '12 से 128 वर्णों का उपयोग करें।',
	'admin.pw.err.breached' => 'यह पासवर्ड बहुत सामान्य है या डेटा उल्लंघन में सामने आया है। कोई अन्य चुनें।',
	'admin.vendor.err.window' => 'वेंडर पहुँच अधिकतम {hours} घंटों के लिए सक्षम की जा सकती है।',
	'admin.audit.empty' => 'इन फ़िल्टरों के लिए कोई प्रविष्टि नहीं',
	'admin.err.forbidden_requests' => 'आपके पास इस शाखा के अनुरोधों तक पहुँच नहीं है।',
	'admin.err.forbidden' => 'आपको यह करने की अनुमति नहीं है।',
	'staff.err.forbidden_branch' => 'आपके पास इस शाखा तक पहुँच नहीं है।',
	'staff.err.forbidden_field' => 'आपको इसे बदलने की अनुमति नहीं है: {field}।',
	'admin.lang.err.gate' => '{language} सक्षम नहीं किया जा सकता: लॉन्च के लिए आवश्यक {n} आइटम गायब या असमीक्षित हैं।',
	'admin.lang.err.signoff' => '{language} सक्षम करने से पहले मूल-भाषी समीक्षा की स्वीकृति (नाम और तारीख) दर्ज करें।',
];
