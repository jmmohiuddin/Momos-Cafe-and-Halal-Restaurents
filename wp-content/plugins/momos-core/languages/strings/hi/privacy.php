<?php
/**
 * Privacy strings (hi). Keys: docs/specs/access-security-privacy.md §15.1/§15.2.
 * DRAFT – needs native review (DEC-027): machine-assisted Hindi draft, not approved for launch.
 * Legal text (consent.*) additionally needs counsel review; no machine translation for legal text in production (BR-PRV-04).
 * Staff admin is ja/en only (DEC-037); admin.* keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dsr.blocked_active' => 'पहले सक्रिय अनुरोध रद्द करें। जब तक कोई भविष्य का अनुरोध लंबित या पुष्ट है, तब तक गुमनामीकरण संभव नहीं है।',
	'admin.dsr.confirm'        => 'यह कार्रवाई वापस नहीं ली जा सकती। इस रिकॉर्ड को गुमनाम करने के लिए संदर्भ संख्या {ref} टाइप करें।',
	'admin.dsr.empty'          => 'कोई रिकॉर्ड नहीं मिला।',
	'consent.special_request'  => 'मैं सहमत हूँ कि मेरे नोट में दी गई स्वास्थ्य या एलर्जी संबंधी जानकारी का उपयोग केवल मेरी विज़िट की तैयारी के लिए किया जाएगा।',
];
