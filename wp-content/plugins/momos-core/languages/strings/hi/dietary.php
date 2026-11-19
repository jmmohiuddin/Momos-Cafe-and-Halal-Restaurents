<?php
/**
 * Dietary module customer strings — Hindi.
 * DRAFT – needs native review (DEC-027, BR-DIET-05: claim-related wording must be human-reviewed before
 * the language is enabled). Not for production until GATE-04 sign-off.
 */

defined( 'ABSPATH' ) || exit;

return [
	'allergen.heading'               => 'एलर्जेन',
	'allergen.state.contains'        => 'शामिल है',
	'allergen.state.may_contain'     => 'शामिल हो सकता है (क्रॉस-संपर्क)',
	'allergen.state.not_contained'   => 'शामिल नहीं (जाँचा गया)',
	'allergen.state.not_verified'    => 'सत्यापित नहीं',
	'allergen.summary.contains'      => 'शामिल है: {list}',
	'allergen.summary.may_contain'   => 'शामिल हो सकता है (क्रॉस-संपर्क): {list}',
	'allergen.banner.not_verified'   => 'एलर्जेन जानकारी सत्यापित नहीं है — कृपया कर्मचारियों से पूछें।',
	'allergen.disclaimer.voluntary'  => 'एलर्जेन जानकारी संदर्भ के लिए स्वेच्छा से दी जाती है। आपूर्तिकर्ता या रेसिपी बदलने पर यह बदल सकती है, और शाखाओं के बीच सामग्री अलग हो सकती है।',
	'allergen.cross_contact'         => 'हमारी रसोई में अन्य सामग्री भी उपयोग होती है और बर्तन व तलने का तेल साझा हो सकता है, इसलिए हम सूक्ष्म मात्रा की अनुपस्थिति की गारंटी नहीं दे सकते।',
	'allergen.tell_staff'            => 'यदि आपको गंभीर एलर्जी है, तो कृपया पहुँचने पर और ऑर्डर करते समय हमारे कर्मचारियों को बताएँ। यदि हम आपके लिए कोई व्यंजन नहीं बना सकते, तो हम आपको ईमानदारी से बताएँगे।',
	'allergen.last_verified'         => 'जानकारी अंतिम बार {date} को सत्यापित की गई।',
	'allergen.group.mandatory'       => 'निर्दिष्ट सामग्री (9 — पैकेज्ड भोजन के लिए अनिवार्य)',
	'allergen.group.recommended'     => 'अनुशंसित आइटम (20)',
	'allergen.group.other'           => 'अन्य सामग्री',
	'allergen.legend'                => 'संकेत',
	'allergen.table.caption'         => 'एलर्जेन जानकारी',
	'allergen.table.allergen'        => 'एलर्जेन',
	'allergen.table.status'          => 'स्थिति',
	'allergen.toggle.relevant'       => 'केवल «शामिल है» और «शामिल हो सकता है» दिखाएँ',
	'ingredients.heading'            => 'मुख्य सामग्री (पूरी सूची नहीं)',
	'last_verified'                  => 'अंतिम सत्यापन: {date}',
	'halal.heading'                  => 'हलाल जानकारी',
	'halal.label.certified'          => 'हलाल प्रमाणित',
	'halal.label.ingredients'        => 'हलाल सामग्री',
	'halal.not_applicable'           => 'लागू नहीं',
	'halal.mark_alt'                 => '{certifier} का हलाल प्रमाणन चिह्न',
	'halal.verify_link'              => 'प्रमाणक से सत्यापित करें',
	'halal.certifier'                => 'प्रमाणक',
	'halal.scope'                    => 'दायरा',
	'halal.cert_no'                  => 'प्रमाणपत्र संख्या',
	'halal.valid_until'              => 'तक मान्य',
	'halal.scope.whole_kitchen'      => 'पूरी रसोई',
	'halal.scope.specific_menu'      => 'चिह्नित व्यंजन',
	'halal.scope.specific_ingredients' => 'विशिष्ट सामग्री',
	'alcohol.heading'                => 'शराब',
	'diet.list.separator'            => ', ',
	// FR-DIET-002(f) audit label (SCR-A16).
	'admin.audit.action.dietary.prohibited_terms_updated' => 'निषिद्ध शब्द सूची अपडेट',
];
