<?php
/**
 * Notification module customer email strings (ur) — E01–E06 (docs/specs/notifications.md §5.1–5.2).
 * DRAFT – needs native review (DEC-027, GATE-04): machine-assisted Urdu draft, not approved for
 * launch. Pending-context keys avoid booking-confirmation wording (BR-RES-01).
 */

defined( 'ABSPATH' ) || exit;

return [
	'email.subject.e01'           => '{brand}: درخواست موصول ہوئی — ابھی تصدیق نہیں ہوئی [{ref}]',
	'email.subject.e02'           => '{brand}: برانچ نے آپ کی ریزرویشن کی تصدیق کر دی [{ref}]',
	'email.subject.e03'           => '{brand}: ہم آپ کی درخواست قبول نہیں کر سکے [{ref}]',
	'email.subject.e04.pending'   => '{brand}: آپ کی درخواست منسوخ ہو گئی [{ref}]',
	'email.subject.e04.confirmed' => '{brand}: ریزرویشن منسوخ ہو گئی [{ref}]',
	'email.subject.e05'           => '{brand}: ہم وقت پر آپ کی درخواست کی تصدیق نہیں کر سکے [{ref}]',
	'email.subject.e06.pending'   => '{brand}: آپ کی درخواست کی تفصیلات تبدیل کر دی گئیں [{ref}]',
	'email.subject.e06.confirmed' => '{brand}: آپ کی ریزرویشن کی تفصیلات تبدیل کر دی گئیں [{ref}]',



	'email.confirmed.allergy'     => 'اگر آپ کے گروپ میں کسی کو کھانے سے الرجی ہے تو براہِ کرم آمد پر ہمارے عملے کو بتائیں۔',
	'cta.request_other_time'      => 'کسی اور وقت کی درخواست کریں',

	'email.details.branch'        => 'برانچ',
	'email.details.date'          => 'تاریخ',
	'email.details.time'          => 'وقت',
	'email.details.party'         => 'افراد کی تعداد',
	'email.details.party_one'     => '{n} فرد',
	'email.details.party_other'   => '{n} افراد',
	'email.jst'                   => 'جاپان کا وقت',
	'email.duration.minutes'      => '{n} منٹ',
	'email.duration.hour'         => '{n} گھنٹہ',
	'email.duration.hours'        => '{n} گھنٹے',
	'email.branch.address_ja'     => 'جاپانی زبان میں پتہ (آپ یہ ٹیکسی ڈرائیور کو دکھا سکتے ہیں)',
	'email.branch.call'           => 'برانچ کو کال کریں: {phone}',
	'email.common.note_received'  => 'ہمیں آپ کا نوٹ موصول ہو گیا ہے۔',
	'email.cta.view_reservation'  => 'ریزرویشن دیکھیں',
	'email.policy.heading'        => 'برانچ کی پالیسی',
	'email.e01.cancel_hint'       => 'اگر آپ کا پروگرام بدل جائے تو آپ صورتحال کے صفحے پر یہ درخواست منسوخ کر سکتے ہیں۔',
	'email.e02.message_heading'   => 'برانچ کی جانب سے پیغام',
	'email.e02.cancel_hint'       => 'اگر آپ نہیں آ سکتے تو براہِ کرم ریزرویشن کے صفحے پر منسوخ کریں یا برانچ کو کال کریں۔',
	'email.e03.reason_heading'    => 'وجہ',
	'email.e04.by_customer.pending'   => 'آپ نے یہ درخواست منسوخ کر دی۔',
	'email.e04.by_customer.confirmed' => 'آپ نے یہ ریزرویشن منسوخ کر دی۔',
	'email.e04.by_branch.pending'     => 'برانچ نے یہ درخواست منسوخ کر دی۔',
	'email.e04.by_branch.confirmed'   => 'برانچ نے یہ ریزرویشن منسوخ کر دی۔',
	'email.e04.unexpected'        => 'اگر یہ غیر متوقع ہے تو براہِ کرم برانچ سے رابطہ کریں: {phone}',
	'cancel.reason.customer_request_by_phone' => 'آپ کی فون پر درخواست پر منسوخ کیا گیا۔',
	'cancel.reason.branch_unable_to_serve'    => 'اس وقت برانچ آپ کی خدمت سے قاصر ہے۔',
	'cancel.reason.duplicate'     => 'یہ کسی دوسری درخواست کی نقل تھی۔',
	'cancel.reason.other'         => 'برانچ کو منسوخ کرنا پڑا۔',
	'email.e05.apology'           => 'معذرت — برانچ مقررہ وقت سے پہلے جواب نہیں دے سکی، اس لیے آپ کی درخواست کی میعاد ختم ہو گئی۔ آپ کے لیے کوئی میز مخصوص نہیں ہے۔',
	'email.e05.walk_in'           => 'آپ اب بھی برانچ کو کال کر سکتے ہیں یا بغیر درخواست کے تشریف لا سکتے ہیں۔',
	'email.e06.changed'           => '(تبدیل شدہ)',
	'email.e06.reason'            => 'یہ تفصیلات آپ کی رضامندی سے تبدیل کی گئیں۔',
	'email.e06.new_link'          => 'اس درخواست کو دیکھنے کے لیے آپ کا نیا لنک یہ ہے۔ پرانے لنکس اب کام نہیں کرتے۔',
	'email.footer.auto'           => 'یہ آپ کی میز کی درخواست کے بارے میں خودکار ای میل ہے۔ جوابات برانچ کو جاتے ہیں۔',
	'email.footer.copyright'      => '© {year} {operator}',
];
