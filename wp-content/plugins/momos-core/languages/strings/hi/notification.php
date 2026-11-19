<?php
/**
 * Notification module customer email strings (hi) — E01–E06 (docs/specs/notifications.md §5.1–5.2).
 * DRAFT – needs native review (DEC-027, GATE-04): machine-assisted Hindi draft, not approved for
 * launch. Pending-context keys avoid booking-confirmation wording (BR-RES-01).
 */

defined( 'ABSPATH' ) || exit;

return [
	'email.subject.e01'           => '{brand}: अनुरोध प्राप्त हुआ — अभी पुष्टि नहीं हुई [{ref}]',
	'email.subject.e02'           => '{brand}: शाखा ने आपके आरक्षण की पुष्टि कर दी है [{ref}]',
	'email.subject.e03'           => '{brand}: हम आपका अनुरोध स्वीकार नहीं कर सके [{ref}]',
	'email.subject.e04.pending'   => '{brand}: आपका अनुरोध रद्द कर दिया गया [{ref}]',
	'email.subject.e04.confirmed' => '{brand}: आरक्षण रद्द कर दिया गया [{ref}]',
	'email.subject.e05'           => '{brand}: हम समय पर आपके अनुरोध की पुष्टि नहीं कर सके [{ref}]',
	'email.subject.e06.pending'   => '{brand}: आपके अनुरोध का विवरण बदला गया [{ref}]',
	'email.subject.e06.confirmed' => '{brand}: आपके आरक्षण का विवरण बदला गया [{ref}]',



	'email.confirmed.allergy'     => 'यदि आपके समूह में किसी को भोजन से एलर्जी है, तो कृपया पहुँचने पर हमारे कर्मचारियों को बताएँ।',
	'cta.request_other_time'      => 'दूसरे समय का अनुरोध करें',

	'email.details.branch'        => 'शाखा',
	'email.details.date'          => 'तारीख',
	'email.details.time'          => 'समय',
	'email.details.party'         => 'लोगों की संख्या',
	'email.details.party_one'     => '{n} व्यक्ति',
	'email.details.party_other'   => '{n} लोग',
	'email.jst'                   => 'जापान समय',
	'email.duration.minutes'      => '{n} मिनट',
	'email.duration.hour'         => '{n} घंटा',
	'email.duration.hours'        => '{n} घंटे',
	'email.branch.address_ja'     => 'जापानी में पता (आप इसे टैक्सी चालक को दिखा सकते हैं)',
	'email.branch.call'           => 'शाखा को कॉल करें: {phone}',
	'email.common.note_received'  => 'हमें आपका नोट मिल गया है।',
	'email.cta.view_reservation'  => 'आरक्षण देखें',
	'email.policy.heading'        => 'शाखा की नीति',
	'email.e01.cancel_hint'       => 'यदि आपकी योजना बदलती है, तो आप स्थिति पृष्ठ पर यह अनुरोध रद्द कर सकते हैं।',
	'email.e02.message_heading'   => 'शाखा का संदेश',
	'email.e02.cancel_hint'       => 'यदि आप अब नहीं आ सकते, तो कृपया आरक्षण पृष्ठ पर रद्द करें या शाखा को कॉल करें।',
	'email.e03.reason_heading'    => 'कारण',
	'email.e04.by_customer.pending'   => 'आपने यह अनुरोध रद्द कर दिया।',
	'email.e04.by_customer.confirmed' => 'आपने यह आरक्षण रद्द कर दिया।',
	'email.e04.by_branch.pending'     => 'शाखा ने यह अनुरोध रद्द कर दिया।',
	'email.e04.by_branch.confirmed'   => 'शाखा ने यह आरक्षण रद्द कर दिया।',
	'email.e04.unexpected'        => 'यदि यह अप्रत्याशित है, तो कृपया शाखा से संपर्क करें: {phone}',
	'cancel.reason.customer_request_by_phone' => 'फ़ोन पर आपके अनुरोध पर रद्द किया गया।',
	'cancel.reason.branch_unable_to_serve'    => 'उस समय शाखा आपकी सेवा करने में असमर्थ है।',
	'cancel.reason.duplicate'     => 'यह किसी अन्य अनुरोध की प्रतिलिपि थी।',
	'cancel.reason.other'         => 'शाखा को रद्द करना पड़ा।',
	'email.e05.apology'           => 'क्षमा करें — शाखा समय सीमा से पहले उत्तर नहीं दे सकी, इसलिए आपका अनुरोध समाप्त हो गया है। आपके लिए कोई टेबल नहीं रखी गई है।',
	'email.e05.walk_in'           => 'आप अब भी शाखा को कॉल कर सकते हैं या बिना अनुरोध के आ सकते हैं।',
	'email.e06.changed'           => '(बदला गया)',
	'email.e06.reason'            => 'ये विवरण आपकी सहमति से बदले गए।',
	'email.e06.new_link'          => 'इस अनुरोध को देखने के लिए आपका नया लिंक यह है। पुराने लिंक अब काम नहीं करते।',
	'email.footer.auto'           => 'यह आपके टेबल अनुरोध के बारे में एक स्वचालित ईमेल है। उत्तर शाखा को जाते हैं।',
	'email.footer.copyright'      => '© {year} {operator}',
];
