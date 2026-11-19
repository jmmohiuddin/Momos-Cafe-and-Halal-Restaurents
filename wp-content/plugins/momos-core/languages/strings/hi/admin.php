<?php
/**
 * Admin module staff strings (hi): SCR-A02 dashboard widgets (docs/specs/access-security-privacy.md §13.1).
 * DRAFT – needs native review (DEC-027). Staff admin is ja/en only (DEC-037); these keys exist for catalog completeness.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dash.needs_action.title' => 'कार्रवाई आवश्यक',
	'admin.dash.needs_action.flagged' => '{n} अनुरोधों पर कार्रवाई आवश्यक',
	'admin.dash.needs_action.not_notified' => '{n} मेहमानों को अभी सूचना नहीं दी गई',
	'admin.dash.needs_action.none' => 'किसी कार्रवाई की आवश्यकता नहीं।',
	'admin.dash.attendance.title' => 'दर्ज करने हेतु उपस्थिति',
	'admin.dash.attendance.count' => '{n} आरक्षणों की उपस्थिति दर्ज नहीं',
	'admin.dash.attendance.none' => 'सभी उपस्थिति दर्ज है।',
	'admin.dash.pending.overdue' => '{n} प्रतिक्रिया लक्ष्य से अधिक',
	'admin.dash.pending.item' => '{ref} — {date} {time}, {party} मेहमान ({branch})',
	'admin.dash.allergen.title' => 'एलर्जेन डेटा असत्यापित',
	'admin.dash.allergen.count' => '{n} व्यंजनों का एलर्जेन डेटा असत्यापित या समीक्षा हेतु',
	'admin.dash.allergen.none' => 'सभी एलर्जेन डेटा सत्यापित है।',
	'admin.dash.offers_own.title' => 'मेरे ऑफ़र अनुमोदन की प्रतीक्षा में',
	'admin.dash.offers_own.count' => 'आपके {n} ऑफ़र अनुमोदन की प्रतीक्षा में हैं',
	'admin.dash.offers_own.none' => 'आपका कोई ऑफ़र अनुमोदन की प्रतीक्षा में नहीं है।',
	'admin.dash.offers_ending.title' => 'जल्द समाप्त होने वाले ऑफ़र',
	'admin.dash.offers_ending.item' => '{title} — {date} को समाप्त',
	'admin.dash.offers_ending.none' => 'अगले 7 दिनों में कोई ऑफ़र समाप्त नहीं हो रहा।',
	'admin.dash.demo.title' => 'शेष डेमो सामग्री',
	'admin.dash.demo.count' => '{n} नमूना आइटम अभी साइट पर हैं',
	'admin.dash.demo.illustrative' => '{n} उदाहरण चित्र उपयोग में',
	'admin.dash.demo.none' => 'कोई डेमो सामग्री शेष नहीं।',
	'admin.dash.tr_jobs.title' => 'मेरे अनुवाद कार्य',
	'admin.dash.tr_jobs.count' => '{language}: {n} लंबित आइटम',
	'admin.dash.tr_jobs.none' => 'कोई लंबित अनुवाद कार्य नहीं।',
	// FR-ADM-001(b)/(c)/(d), AC-ADM-01-02 widgets; FR-CORP-010(c) offer list filters.
	'admin.dash.offers_pending.title' => 'स्वीकृति की प्रतीक्षा में ऑफ़र',
	'admin.dash.offers_pending.count' => 'स्वीकृति की प्रतीक्षा में ऑफ़र: {n}',
	'admin.dash.pages_pending.title' => 'समीक्षा की प्रतीक्षा में पेज',
	'admin.dash.pages_pending.count' => 'समीक्षा की प्रतीक्षा में पेज: {n}',
	'admin.dash.pages_pending.none' => 'कोई पेज समीक्षा की प्रतीक्षा में नहीं है।',
	'admin.dash.tr_gaps.menu_title' => 'मेनू अनुवाद की कमियाँ',
	'admin.dash.tr_gaps.pages_title' => 'पेज अनुवाद की कमियाँ',
	'admin.dash.tr_gaps.language' => 'भाषा',
	'admin.dash.tr_gaps.items' => 'अभी तक स्वीकृत नहीं आइटम',
	'admin.dash.tr_gaps.lccs' => 'LCCS पूर्णता',
	'admin.offers.filter.state' => 'स्थिति के अनुसार फ़िल्टर',
	'admin.offers.filter.all_states' => 'सभी स्थितियाँ',
	'admin.offers.filter.scope' => 'दायरे के अनुसार फ़िल्टर',
	'admin.offers.filter.all_scopes' => 'सभी दायरे',
	'admin.offers.scope.all_branches' => 'सभी शाखाएँ (पूरी साइट)',
	'admin.offers.scope.branches' => 'चयनित शाखाएँ',
	'admin.offers.filter.branch' => 'शाखा के अनुसार फ़िल्टर',
	'admin.offers.filter.all_branches' => 'सभी शाखाएँ',
];
