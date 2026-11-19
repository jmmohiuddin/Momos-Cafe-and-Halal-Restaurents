<?php
declare(strict_types=1);

namespace Momos\Seed\Data;

/**
 * Demo branches (DEC-047, branch.md, qa.md §3.1 spirit): three published FICTIONAL branches in
 * Osaka/Kyoto style plus the hidden TEST branch (DEC-052/055). Addresses are invented ("Sample"
 * street names), phones follow +81 6-0000-000x, every email is on example.com. Real branch facts
 * arrive only through GATE-01 data entry. ar/ur/hi/bn texts are DRAFT translations (DEC-027).
 *
 * Hours: weekday 1 = Monday … 7 = Sunday; periods [label, opens, closes, last order].
 */
final class Branches {
	/** @return array<string,string> */
	private static function t( string $ja, string $en, string $ar, string $ur, string $hi, string $bn ): array {
		return [ 'ja' => $ja, 'en' => $en, 'ar' => $ar, 'ur' => $ur, 'hi' => $hi, 'bn' => $bn ];
	}

	/** @return list<array<string,mixed>> */
	public static function all(): array {
		$split_daily = [];
		for ( $w = 1; $w <= 6; $w++ ) {
			$split_daily[ $w ] = [ [ 'lunch', '11:00', '15:00', '14:30' ], [ 'dinner', '17:00', '22:00', '21:30' ] ];
		}
		$split_daily[7] = [ [ 'all_day', '11:00', '21:00', '20:30' ] ];

		$namba = [];
		foreach ( [ 1, 2, 3, 4, 7 ] as $w ) {
			$namba[ $w ] = [ [ 'all_day', '11:30', '23:00', '22:30' ] ];
		}
		foreach ( [ 5, 6 ] as $w ) {
			// Friday/Saturday dinner runs past midnight (closes_next_day).
			$namba[ $w ] = [ [ 'lunch', '11:30', '15:00', '14:30' ], [ 'dinner', '17:00', '02:00', '01:30' ] ];
		}

		$kyoto = [];
		foreach ( [ 1, 3, 4, 5, 6, 7 ] as $w ) {
			$kyoto[ $w ] = [ [ 'lunch', '11:00', '14:30', '14:00' ], [ 'dinner', '17:30', '21:30', '21:00' ] ];
		}
		$kyoto[2] = []; // Closed on Tuesdays.

		$test = [];
		for ( $w = 1; $w <= 7; $w++ ) {
			$test[ $w ] = [ [ 'all_day', '10:00', '22:00', '21:00' ] ];
		}

		$settings = [
			'momo_res_party_min'                      => '1',
			'momo_res_party_max'                      => '8',
			'momo_res_lead_time_minutes'              => '120',
			'momo_res_booking_window_days'            => '60',
			'momo_res_slot_interval_minutes'          => '30',
			'momo_res_decision_cutoff_minutes'        => '60',
			'momo_res_response_sla_minutes'           => '120',
			'momo_res_sla_clock'                      => 'wall_clock',
			'momo_res_show_response_promise'          => '0',
			'momo_res_max_pending_per_email'          => '3',
			'momo_res_customer_cancel_cutoff_minutes' => '0',
		];

		return [
			[
				'code'          => 'DKH',
				'slug'          => 'demo-kitahama',
				'order'         => 10,
				'test'          => false,
				'title'         => self::t( 'デモ北浜店', 'Demo Kitahama', 'فرع كيتاهاما التجريبي', 'ڈیمو کیتاہاما برانچ', 'डेमो कितहामा शाखा', 'ডেমো কিতাহামা শাখা' ),
				'content'       => self::t(
					'オフィス街・北浜にあるデモ店舗です。昼はカレーとビリヤニのランチ、夜はタンドール料理をゆっくりお楽しみいただけます。',
					'A demo branch in the Kitahama business district: curry and biryani at lunchtime, tandoor dishes in the evening.',
					'فرع تجريبي في حي كيتاهاما التجاري: كاري وبرياني وقت الغداء، وأطباق التنور في المساء.',
					'کیتاہاما کے کاروباری علاقے میں ایک ڈیمو برانچ: دوپہر میں سالن اور بریانی، شام کو تندوری پکوان۔',
					'कितहामा के व्यावसायिक इलाके में एक डेमो शाखा: दोपहर में करी और बिरयानी, शाम को तंदूरी व्यंजन।',
					'কিতাহামার ব্যবসায়িক এলাকায় একটি ডেমো শাখা: দুপুরে কারি ও বিরিয়ানি, সন্ধ্যায় তন্দুরি পদ।'
				),
				'concept'       => self::t( 'カフェ＆カレー', 'Cafe & curry', 'مقهى وكاري', 'کیفے اور سالن', 'कैफ़े और करी', 'ক্যাফে ও কারি' ),
				'area'          => self::t( '北浜駅 徒歩3分', '3 min from Kitahama Sta.', '3 دقائق من محطة كيتاهاما', 'کیتاہاما اسٹیشن سے 3 منٹ', 'कितहामा स्टेशन से 3 मिनट', 'কিতাহামা স্টেশন থেকে 3 মিনিট' ),
				'postal'        => '540-0000',
				'pref'          => self::t( '大阪府', 'Osaka', 'أوساكا', 'اوساکا', 'ओसाका', 'ওসাকা' ),
				'city'          => self::t( '大阪市中央区', 'Chuo-ku, Osaka City', 'تشو-كو، مدينة أوساكا', 'چو-کو، اوساکا شہر', 'चुओ-कु, ओसाका शहर', 'চুও-কু, ওসাকা শহর' ),
				'line1'         => self::t( '北浜サンプル1-2-3', '1-2-3 Kitahama Sample', '1-2-3 كيتاهاما سامبل', '1-2-3 کیتاہاما سیمپل', '1-2-3 कितहामा सैंपल', '1-2-3 কিতাহামা স্যাম্পল' ),
				'line2'         => self::t( 'デモビル2階', 'Demo Bldg. 2F', 'مبنى ديمو، الطابق 2', 'ڈیمو بلڈنگ، دوسری منزل', 'डेमो बिल्डिंग, दूसरी मंज़िल', 'ডেমো বিল্ডিং, 2য় তলা' ),
				'address_ja'    => '〒540-0000 大阪府大阪市中央区北浜サンプル1-2-3 デモビル2階',
				'lat'           => '34.69201',
				'lng'           => '135.50672',
				'phone'         => '+81600000001',
				'phone_display' => '06-0000-0001',
				'email'         => 'kitahama@example.com',
				'directions'    => [ [ 'station' => self::t( '北浜駅', 'Kitahama Station', 'محطة كيتاهاما', 'کیتاہاما اسٹیشن', 'कितहामा स्टेशन', 'কিতাহামা স্টেশন' ), 'exit' => self::t( '2番', '2', '2', '2', '2', '2' ), 'walk' => 3 ] ],
				'access_notes'  => self::t( '1階のコンビニエンスストア横の階段から2階へお上がりください。エレベーターもございます。', 'Take the stairs next to the convenience store on the ground floor; a lift is also available.', 'اصعد الدرج المجاور للمتجر في الطابق الأرضي؛ يتوفر مصعد أيضًا.', 'گراؤنڈ فلور پر سہولت اسٹور کے ساتھ والی سیڑھیوں سے اوپر آئیں؛ لفٹ بھی موجود ہے۔', 'भूतल पर सुविधा स्टोर के पास वाली सीढ़ियों से ऊपर आएँ; लिफ़्ट भी उपलब्ध है।', 'নিচতলার সুবিধা দোকানের পাশের সিঁড়ি দিয়ে উপরে আসুন; লিফটও আছে।' ),
				'prayer'        => 'nearby',
				'prayer_note'   => self::t( '近隣の礼拝スペースについてはスタッフにお尋ねください。', 'Ask our staff about prayer spaces nearby.', 'اسأل فريقنا عن أماكن الصلاة القريبة.', 'قریبی نماز کی جگہ کے بارے میں عملے سے پوچھیں۔', 'पास के प्रार्थना स्थल के बारे में स्टाफ़ से पूछें।', 'কাছাকাছি নামাজের জায়গা সম্পর্কে কর্মীদের জিজ্ঞাসা করুন।' ),
				'smoking'       => 'non_smoking',
				'step_free'     => 'yes',
				'seating'       => [ 'table', 'counter' ],
				'payment'       => [ 'cash', 'credit_card', 'ic_card' ],
				'wifi'          => 'yes',
				'alcohol'       => 'no',
				'hours'         => $split_daily,
				'settings'      => $settings,
				'recipients'    => [ [ 'email' => 'kitahama-inbox@example.com', 'language' => 'ja', 'active' => '1' ], [ 'email' => 'kitahama-manager@example.com', 'language' => 'en', 'active' => '1' ] ],
				// Day offsets from today (JST): one closure, no special hours.
				'closures'      => [ [ 'offset' => 10, 'type' => 'closed_all_day', 'reason' => 'maintenance' ] ],
			],
			[
				'code'          => 'DNB',
				'slug'          => 'demo-namba-minami',
				'order'         => 20,
				'test'          => false,
				'title'         => self::t( 'デモなんば南店', 'Demo Namba-minami', 'فرع نامبا الجنوبي التجريبي', 'ڈیمو نامبا مینامی برانچ', 'डेमो नंबा-मिनामी शाखा', 'ডেমো নাম্বা-মিনামি শাখা' ),
				'content'       => self::t(
					'なんば南のにぎやかな通りにあるデモ店舗です。金曜日・土曜日は深夜2時まで営業しています。',
					'A demo branch on a lively street in Namba-minami, open until 2 a.m. on Fridays and Saturdays.',
					'فرع تجريبي في شارع نابض بالحياة في نامبا الجنوبية، يفتح حتى الثانية صباحًا يومي الجمعة والسبت.',
					'نامبا مینامی کی رونق بھری گلی میں ایک ڈیمو برانچ، جمعہ اور ہفتہ کو رات 2 بجے تک کھلی۔',
					'नंबा-मिनामी की चहल-पहल वाली गली में एक डेमो शाखा, शुक्रवार और शनिवार को रात 2 बजे तक खुली।',
					'নাম্বা-মিনামির প্রাণবন্ত রাস্তায় একটি ডেমো শাখা, শুক্র ও শনিবার রাত 2টা পর্যন্ত খোলা।'
				),
				'concept'       => self::t( 'グリル＆ケバブ', 'Grill & kebab', 'مشاوي وكباب', 'گرل اور کباب', 'ग्रिल और कबाब', 'গ্রিল ও কাবাব' ),
				'area'          => self::t( 'なんば駅 徒歩5分', '5 min from Namba Sta.', '5 دقائق من محطة نامبا', 'نامبا اسٹیشن سے 5 منٹ', 'नंबा स्टेशन से 5 मिनट', 'নাম্বা স্টেশন থেকে 5 মিনিট' ),
				'postal'        => '556-0000',
				'pref'          => self::t( '大阪府', 'Osaka', 'أوساكا', 'اوساکا', 'ओसाका', 'ওসাকা' ),
				'city'          => self::t( '大阪市浪速区', 'Naniwa-ku, Osaka City', 'نانيوا-كو، مدينة أوساكا', 'نانیوا-کو، اوساکا شہر', 'नानिवा-कु, ओसाका शहर', 'নানিওয়া-কু, ওসাকা শহর' ),
				'line1'         => self::t( 'なんば南サンプル4-5-6', '4-5-6 Namba-minami Sample', '4-5-6 نامبا مينامي سامبل', '4-5-6 نامبا مینامی سیمپل', '4-5-6 नंबा-मिनामी सैंपल', '4-5-6 নাম্বা-মিনামি স্যাম্পল' ),
				'line2'         => self::t( '', '', '', '', '', '' ),
				'address_ja'    => '〒556-0000 大阪府大阪市浪速区なんば南サンプル4-5-6',
				'lat'           => '34.66094',
				'lng'           => '135.50121',
				'phone'         => '+81600000002',
				'phone_display' => '06-0000-0002',
				'email'         => 'namba@example.com',
				'directions'    => [ [ 'station' => self::t( 'なんば駅', 'Namba Station', 'محطة نامبا', 'نامبا اسٹیشن', 'नंबा स्टेशन', 'নাম্বা স্টেশন' ), 'exit' => self::t( 'E5', 'E5', 'E5', 'E5', 'E5', 'E5' ), 'walk' => 5 ] ],
				'access_notes'  => self::t( '', '', '', '', '', '' ),
				'prayer'        => 'unknown',
				'prayer_note'   => self::t( '', '', '', '', '', '' ),
				'smoking'       => 'non_smoking',
				'step_free'     => 'partial',
				'seating'       => [ 'table', 'private_room' ],
				'payment'       => [ 'cash', 'credit_card', 'qr_payment' ],
				'wifi'          => 'yes',
				'alcohol'       => 'no',
				'hours'         => $namba,
				'settings'      => array_merge( $settings, [ 'momo_res_party_max' => '10' ] ),
				'recipients'    => [ [ 'email' => 'namba-inbox@example.com', 'language' => 'ja', 'active' => '1' ] ],
				'closures'      => [ [ 'offset' => 12, 'type' => 'special_hours', 'reason' => 'private_event', 'periods' => [ [ 'label' => 'dinner', 'opens' => '18:00', 'closes' => '21:00', 'last_order' => '20:30' ] ] ] ],
			],
			[
				'code'          => 'DKY',
				'slug'          => 'demo-kyoto-kawaramachi',
				'order'         => 30,
				'test'          => false,
				'title'         => self::t( 'デモ京都河原町店', 'Demo Kyoto Kawaramachi', 'فرع كيوتو كاواراماتشي التجريبي', 'ڈیمو کیوٹو کاواراماچی برانچ', 'डेमो क्योटो कावारामाची शाखा', 'ডেমো কিয়োটো কাওয়ারামাচি শাখা' ),
				'content'       => self::t(
					'京都・河原町の路地にあるデモ店舗です。町家風の落ち着いた店内でお食事をお楽しみください（火曜定休）。',
					'A demo branch in a quiet Kawaramachi lane in Kyoto with a calm townhouse-style interior (closed on Tuesdays).',
					'فرع تجريبي في زقاق هادئ في كاواراماتشي بكيوتو بتصميم داخلي هادئ على طراز البيوت التقليدية (مغلق أيام الثلاثاء).',
					'کیوٹو کے کاواراماچی کی پرسکون گلی میں ایک ڈیمو برانچ، روایتی طرز کا پرسکون ماحول (منگل کو بند)۔',
					'क्योटो के कावारामाची की शांत गली में एक डेमो शाखा, पारंपरिक टाउनहाउस शैली का शांत माहौल (मंगलवार बंद)।',
					'কিয়োটোর কাওয়ারামাচির শান্ত গলিতে একটি ডেমো শাখা, ঐতিহ্যবাহী টাউনহাউস ধাঁচের শান্ত পরিবেশ (মঙ্গলবার বন্ধ)।'
				),
				'concept'       => self::t( '町家ダイニング', 'Townhouse dining', 'مطعم بطراز البيوت التقليدية', 'روایتی گھر میں ڈائننگ', 'टाउनहाउस डाइनिंग', 'টাউনহাউস ডাইনিং' ),
				'area'          => self::t( '京都河原町駅 徒歩4分', '4 min from Kyoto-Kawaramachi Sta.', '4 دقائق من محطة كيوتو-كاواراماتشي', 'کیوٹو کاواراماچی اسٹیشن سے 4 منٹ', 'क्योटो-कावारामाची स्टेशन से 4 मिनट', 'কিয়োটো-কাওয়ারামাচি স্টেশন থেকে 4 মিনিট' ),
				'postal'        => '604-0000',
				'pref'          => self::t( '京都府', 'Kyoto', 'كيوتو', 'کیوٹو', 'क्योटो', 'কিয়োটো' ),
				'city'          => self::t( '京都市中京区', 'Nakagyo-ku, Kyoto City', 'ناكاغيو-كو، مدينة كيوتو', 'ناکاگیو-کو، کیوٹو شہر', 'नाकाग्यो-कु, क्योटो शहर', 'নাকাগিয়ো-কু, কিয়োটো শহর' ),
				'line1'         => self::t( '河原町サンプル7-8', '7-8 Kawaramachi Sample', '7-8 كاواراماتشي سامبل', '7-8 کاواراماچی سیمپل', '7-8 कावारामाची सैंपल', '7-8 কাওয়ারামাচি স্যাম্পল' ),
				'line2'         => self::t( '1階', '1F', 'الطابق الأرضي', 'گراؤنڈ فلور', 'भूतल', 'নিচতলা' ),
				'address_ja'    => '〒604-0000 京都府京都市中京区河原町サンプル7-8 1階',
				'lat'           => '35.00415',
				'lng'           => '135.76925',
				'phone'         => '+81600000003',
				'phone_display' => '06-0000-0003',
				'email'         => 'kyoto@example.com',
				'directions'    => [ [ 'station' => self::t( '京都河原町駅', 'Kyoto-Kawaramachi Station', 'محطة كيوتو-كاواراماتشي', 'کیوٹو کاواراماچی اسٹیشن', 'क्योटो-कावारामाची स्टेशन', 'কিয়োটো-কাওয়ারামাচি স্টেশন' ), 'exit' => self::t( '3A', '3A', '3A', '3A', '3A', '3A' ), 'walk' => 4 ] ],
				'access_notes'  => self::t( '入口ののれんが目印です。', 'Look for the noren curtain at the entrance.', 'ابحث عن ستارة النورين عند المدخل.', 'داخلی دروازے پر نورین پردہ تلاش کریں۔', 'प्रवेश द्वार पर नोरेन पर्दा देखें।', 'প্রবেশপথে নোরেন পর্দা খুঁজুন।' ),
				'prayer'        => 'no',
				'prayer_note'   => self::t( '', '', '', '', '', '' ),
				'smoking'       => 'non_smoking',
				'step_free'     => 'no',
				'seating'       => [ 'table', 'tatami' ],
				'payment'       => [ 'cash', 'credit_card' ],
				'wifi'          => 'unknown',
				'alcohol'       => 'unknown',
				'hours'         => $kyoto,
				'settings'      => array_merge( $settings, [ 'momo_res_party_max' => '6', 'momo_res_customer_cancel_cutoff_minutes' => '180' ] ),
				'recipients'    => [ [ 'email' => 'kyoto-inbox@example.com', 'language' => 'ja', 'active' => '1' ], [ 'email' => 'kyoto-backup@example.com', 'language' => 'en', 'active' => '0' ] ],
				'closures'      => [],
			],
			[
				'code'          => 'TST',
				'slug'          => 'test-branch',
				'order'         => 99,
				'test'          => true,
				'title'         => self::t( 'テスト店舗（非公開）', 'Test branch (hidden)', 'فرع الاختبار (مخفي)', 'ٹیسٹ برانچ (پوشیدہ)', 'परीक्षण शाखा (छिपी)', 'পরীক্ষামূলক শাখা (লুকানো)' ),
				'content'       => self::t( '本番環境の動作確認用の非公開テスト店舗です。', 'Hidden test branch used for smoke tests. Never public.', 'فرع اختبار مخفي لاختبارات التشغيل. غير معروض للعامة.', 'اسموک ٹیسٹ کے لیے پوشیدہ ٹیسٹ برانچ۔ کبھی عوامی نہیں۔', 'स्मोक टेस्ट के लिए छिपी परीक्षण शाखा। कभी सार्वजनिक नहीं।', 'স্মোক টেস্টের জন্য লুকানো পরীক্ষামূলক শাখা। কখনো প্রকাশ্য নয়।' ),
				'concept'       => self::t( '', '', '', '', '', '' ),
				'area'          => self::t( '', '', '', '', '', '' ),
				'postal'        => '530-0000',
				'pref'          => self::t( '大阪府', 'Osaka', 'أوساكا', 'اوساکا', 'ओसाका', 'ওসাকা' ),
				'city'          => self::t( '大阪市北区', 'Kita-ku, Osaka City', 'كيتا-كو، مدينة أوساكا', 'کیتا-کو، اوساکا شہر', 'किता-कु, ओसाका शहर', 'কিতা-কু, ওসাকা শহর' ),
				'line1'         => self::t( 'テスト1-1', '1-1 Test', '1-1 تست', '1-1 ٹیسٹ', '1-1 टेस्ट', '1-1 টেস্ট' ),
				'line2'         => self::t( '', '', '', '', '', '' ),
				'address_ja'    => '〒530-0000 大阪府大阪市北区テスト1-1',
				'lat'           => '34.70248',
				'lng'           => '135.49595',
				'phone'         => '+81600000009',
				'phone_display' => '06-0000-0009',
				'email'         => 'test-branch@example.com',
				'directions'    => [],
				'access_notes'  => self::t( '', '', '', '', '', '' ),
				'prayer'        => 'unknown',
				'prayer_note'   => self::t( '', '', '', '', '', '' ),
				'smoking'       => 'unknown',
				'step_free'     => 'unknown',
				'seating'       => [],
				'payment'       => [],
				'wifi'          => 'unknown',
				'alcohol'       => 'unknown',
				'hours'         => $test,
				'settings'      => $settings,
				'recipients'    => [ [ 'email' => 'qa-inbox@example.com', 'language' => 'en', 'active' => '1' ] ],
				'closures'      => [],
			],
		];
	}
}
