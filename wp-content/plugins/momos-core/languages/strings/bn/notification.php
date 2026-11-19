<?php
/**
 * Notification module customer email strings (bn) — E01–E06 (docs/specs/notifications.md §5.1–5.2).
 * DRAFT – needs native review (DEC-027, GATE-04): machine-assisted Bengali draft, not approved for
 * launch. Pending-context keys avoid booking-confirmation wording (BR-RES-01).
 */

defined( 'ABSPATH' ) || exit;

return [
	'email.subject.e01'           => '{brand}: অনুরোধ পাওয়া গেছে — এখনও নিশ্চিত নয় [{ref}]',
	'email.subject.e02'           => '{brand}: শাখা আপনার রিজার্ভেশন নিশ্চিত করেছে [{ref}]',
	'email.subject.e03'           => '{brand}: আমরা আপনার অনুরোধ গ্রহণ করতে পারিনি [{ref}]',
	'email.subject.e04.pending'   => '{brand}: আপনার অনুরোধ বাতিল হয়েছে [{ref}]',
	'email.subject.e04.confirmed' => '{brand}: রিজার্ভেশন বাতিল হয়েছে [{ref}]',
	'email.subject.e05'           => '{brand}: আমরা সময়মতো আপনার অনুরোধ নিশ্চিত করতে পারিনি [{ref}]',
	'email.subject.e06.pending'   => '{brand}: আপনার অনুরোধের বিবরণ পরিবর্তন করা হয়েছে [{ref}]',
	'email.subject.e06.confirmed' => '{brand}: আপনার রিজার্ভেশনের বিবরণ পরিবর্তন করা হয়েছে [{ref}]',



	'email.confirmed.allergy'     => 'আপনার দলের কারও খাবারে অ্যালার্জি থাকলে, পৌঁছানোর পর আমাদের কর্মীদের জানান।',
	'cta.request_other_time'      => 'অন্য সময়ের অনুরোধ করুন',

	'email.details.branch'        => 'শাখা',
	'email.details.date'          => 'তারিখ',
	'email.details.time'          => 'সময়',
	'email.details.party'         => 'অতিথির সংখ্যা',
	'email.details.party_one'     => '{n} জন',
	'email.details.party_other'   => '{n} জন',
	'email.jst'                   => 'জাপান সময়',
	'email.duration.minutes'      => '{n} মিনিট',
	'email.duration.hour'         => '{n} ঘণ্টা',
	'email.duration.hours'        => '{n} ঘণ্টা',
	'email.branch.address_ja'     => 'জাপানি ভাষায় ঠিকানা (ট্যাক্সি চালককে দেখাতে পারেন)',
	'email.branch.call'           => 'শাখায় ফোন করুন: {phone}',
	'email.common.note_received'  => 'আমরা আপনার নোট পেয়েছি।',
	'email.cta.view_reservation'  => 'রিজার্ভেশন দেখুন',
	'email.policy.heading'        => 'শাখার নীতি',
	'email.e01.cancel_hint'       => 'পরিকল্পনা বদলালে, অবস্থা পৃষ্ঠা থেকে এই অনুরোধ বাতিল করতে পারেন।',
	'email.e02.message_heading'   => 'শাখার বার্তা',
	'email.e02.cancel_hint'       => 'আসতে না পারলে, অনুগ্রহ করে রিজার্ভেশন পৃষ্ঠা থেকে বাতিল করুন বা শাখায় ফোন করুন।',
	'email.e03.reason_heading'    => 'কারণ',
	'email.e04.by_customer.pending'   => 'আপনি এই অনুরোধ বাতিল করেছেন।',
	'email.e04.by_customer.confirmed' => 'আপনি এই রিজার্ভেশন বাতিল করেছেন।',
	'email.e04.by_branch.pending'     => 'শাখা এই অনুরোধ বাতিল করেছে।',
	'email.e04.by_branch.confirmed'   => 'শাখা এই রিজার্ভেশন বাতিল করেছে।',
	'email.e04.unexpected'        => 'এটি অপ্রত্যাশিত হলে, অনুগ্রহ করে শাখার সাথে যোগাযোগ করুন: {phone}',
	'cancel.reason.customer_request_by_phone' => 'ফোনে আপনার অনুরোধে বাতিল করা হয়েছে।',
	'cancel.reason.branch_unable_to_serve'    => 'সেই সময়ে শাখা আপনাকে সেবা দিতে পারছে না।',
	'cancel.reason.duplicate'     => 'এটি অন্য একটি অনুরোধের পুনরাবৃত্তি ছিল।',
	'cancel.reason.other'         => 'শাখাকে বাতিল করতে হয়েছে।',
	'email.e05.apology'           => 'দুঃখিত — শাখা নির্ধারিত সময়ের আগে উত্তর দিতে পারেনি, তাই আপনার অনুরোধের মেয়াদ শেষ হয়েছে। আপনার জন্য কোনো টেবিল রাখা হয়নি।',
	'email.e05.walk_in'           => 'আপনি এখনও শাখায় ফোন করতে পারেন বা অনুরোধ ছাড়াই আসতে পারেন।',
	'email.e06.changed'           => '(পরিবর্তিত)',
	'email.e06.reason'            => 'আপনার সম্মতিতে এই বিবরণ পরিবর্তন করা হয়েছে।',
	'email.e06.new_link'          => 'এই অনুরোধ দেখার জন্য আপনার নতুন লিংক এটি। আগের লিংকগুলো আর কাজ করে না।',
	'email.footer.auto'           => 'এটি আপনার টেবিল অনুরোধ সম্পর্কে একটি স্বয়ংক্রিয় ইমেল। উত্তর শাখায় যায়।',
	'email.footer.copyright'      => '© {year} {operator}',
];
