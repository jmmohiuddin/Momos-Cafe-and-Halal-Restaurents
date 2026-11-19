<?php
/**
 * Admin content strings (content-strings.md §3.4 `admin.legal.publish_denied`, §12 `admin.translation.gaps_none`, `admin.media.alt_required` FR-ADM-005(d)) — Hindi.
 * DRAFT – needs native review (DEC-027, IMPL-003). Not for production until GATE-04 sign-off.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.legal.publish_denied' => 'कानूनी पृष्ठ केवल हेड ऑफ़िस प्रकाशित या अपडेट कर सकता है। ड्राफ़्ट सहेजें और समीक्षा के लिए भेजें।',
	'admin.translation.gaps_none' => '{language} के लिए कोई अनुवाद बाकी नहीं है',
	'admin.media.alt_required' => 'प्रकाशित करने से पहले वैकल्पिक टेक्स्ट जोड़ें',
	'admin.audit.action.translation.publish_blocked' => 'अनुवाद का प्रकाशन रोका गया (वैकल्पिक टेक्स्ट नहीं)',
	'admin.audit.action.content.illustrative_refused' => 'उदाहरण चित्र अस्वीकार',
];
