<?php
declare(strict_types=1);

namespace Momos\Seed\Data;

/**
 * Demo editorial content (DEC-047, content-strings.md §8/§10): notices, offers, FAQ, Home/About/Contact
 * copy (marked as sample text), legal page placeholders and brand/notification settings. FICTIONAL;
 * free text carries no halal, allergen-safety or ordering wording (GATE-02, BR-DIET-03, DEC-042).
 * ar/ur/hi/bn texts are DRAFT translations (DEC-027).
 */
final class Content {
	public const LEGAL_PLACEHOLDER = '[PLACEHOLDER – requires legal review]';

	/** @return array<string,string> */
	private static function t( string $ja, string $en, string $ar, string $ur, string $hi, string $bn ): array {
		return [ 'ja' => $ja, 'en' => $en, 'ar' => $ar, 'ur' => $ur, 'hi' => $hi, 'bn' => $bn ];
	}

	/**
	 * `start`/`end`: day offsets from now (JST). `branches`: branch codes (scope "branches").
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function notices(): array {
		return [
			[
				'key'      => 'holiday-hours',
				'scope'    => 'site_wide',
				'branches' => [],
				'severity' => 'info',
				'start'    => -1,
				'end'      => 30,
				'link'     => '/branches/',
				'title'    => self::t( '年末年始の営業時間について（サンプル）', 'Holiday opening hours (sample)', 'ساعات العمل في العطلات (نموذج)', 'تعطیلات کے اوقات کار (نمونہ)', 'छुट्टियों का समय (नमूना)', 'ছুটির দিনের সময়সূচি (নমুনা)' ),
				'body'     => self::t(
					'年末年始は店舗ごとに営業時間が変わります。各店舗のページでご確認ください。（デモ用のお知らせです）',
					'Opening hours change over the New Year holidays. Please check each branch page. (Demo notice)',
					'تتغير ساعات العمل خلال عطلة رأس السنة. يرجى مراجعة صفحة كل فرع. (إشعار تجريبي)',
					'نئے سال کی تعطیلات میں اوقات کار بدل جاتے ہیں۔ براہ کرم ہر برانچ کا صفحہ دیکھیں۔ (ڈیمو اطلاع)',
					'नववर्ष की छुट्टियों में समय बदलता है। कृपया हर शाखा का पेज देखें। (डेमो सूचना)',
					'নববর্ষের ছুটিতে সময় বদলায়। অনুগ্রহ করে প্রতিটি শাখার পৃষ্ঠা দেখুন। (ডেমো নোটিশ)'
				),
				'label'    => self::t( '店舗一覧', 'All branches', 'جميع الفروع', 'تمام برانچیں', 'सभी शाखाएँ', 'সব শাখা' ),
			],
			[
				'key'      => 'kitahama-maintenance',
				'scope'    => 'branches',
				'branches' => [ 'DKH' ],
				'severity' => 'warning',
				'start'    => -1,
				'end'      => 20,
				'link'     => '',
				'title'    => self::t( '設備点検による臨時休業（サンプル）', 'Closed for maintenance (sample)', 'إغلاق للصيانة (نموذج)', 'دیکھ بھال کے لیے بند (نمونہ)', 'रखरखाव के लिए बंद (नमूना)', 'রক্ষণাবেক্ষণের জন্য বন্ধ (নমুনা)' ),
				'body'     => self::t(
					'設備点検のため、1日臨時休業します。日付は店舗の営業時間でご確認ください。（デモ用のお知らせです）',
					'We will close for one day for equipment maintenance. Please check the branch opening hours for the date. (Demo notice)',
					'سنغلق يومًا واحدًا لصيانة المعدات. يرجى مراجعة ساعات عمل الفرع لمعرفة التاريخ. (إشعار تجريبي)',
					'آلات کی دیکھ بھال کے لیے ہم ایک دن بند رہیں گے۔ تاریخ کے لیے برانچ کے اوقات کار دیکھیں۔ (ڈیمو اطلاع)',
					'उपकरणों के रखरखाव के लिए हम एक दिन बंद रहेंगे। तारीख के लिए शाखा का समय देखें। (डेमो सूचना)',
					'যন্ত্রপাতি রক্ষণাবেক্ষণের জন্য আমরা একদিন বন্ধ থাকব। তারিখ জানতে শাখার সময়সূচি দেখুন। (ডেমো নোটিশ)'
				),
				'label'    => self::t( '', '', '', '', '', '' ),
			],
		];
	}

	/** @return list<array<string,mixed>> */
	public static function offers(): array {
		return [
			[
				'key'       => 'weekday-lunch',
				'scope'     => 'all_branches',
				'branches'  => [],
				'featured'  => true,
				'order'     => 1,
				'price'     => '1200',
				'cta'       => 'request_table',
				'image'     => 'gallery:curry-naan-spread',
				'start'     => -1,
				'end'       => 60,
				'title'     => self::t( '平日ランチセット（サンプル）', 'Weekday lunch set (sample)', 'وجبة غداء أيام الأسبوع (نموذج)', 'ہفتے کے دنوں کا لنچ سیٹ (نمونہ)', 'सप्ताह के दिनों का लंच सेट (नमूना)', 'সপ্তাহের দিনের লাঞ্চ সেট (নমুনা)' ),
				'summary'   => self::t( 'カレー、ナン、サラダ、ドリンクのランチセット。', 'Curry, naan, salad and a drink.', 'كاري وخبز نان وسلطة ومشروب.', 'سالن، نان، سلاد اور ایک مشروب۔', 'करी, नान, सलाद और एक पेय।', 'কারি, নান, সালাদ ও একটি পানীয়।' ),
				'conditions' => self::t(
					"平日 11:00〜15:00。\nご来店時にスタッフへお伝えください。\n他の特典との併用はできません。\nデモ用の特典です。",
					"Weekdays 11:00–15:00.\nMention the offer to our staff when you arrive.\nCannot be combined with other offers.\nDemo offer.",
					"أيام الأسبوع 11:00–15:00.\nاذكر العرض لفريقنا عند وصولك.\nلا يمكن دمجه مع عروض أخرى.\nعرض تجريبي.",
					"ہفتے کے دنوں میں 11:00–15:00۔\nآمد پر عملے کو آفر کا بتائیں۔\nدیگر آفرز کے ساتھ نہیں۔\nڈیمو آفر۔",
					"सप्ताह के दिन 11:00–15:00।\nपहुँचने पर स्टाफ़ को ऑफ़र बताएँ।\nअन्य ऑफ़र के साथ नहीं।\nडेमो ऑफ़र।",
					"সপ্তাহের দিনে 11:00–15:00।\nপৌঁছে কর্মীদের অফারটি জানান।\nঅন্য অফারের সাথে নয়।\nডেমো অফার।"
				),
			],
			[
				'key'       => 'group-platter',
				'scope'     => 'branches',
				'branches'  => [ 'DNB', 'DKY' ],
				'featured'  => false,
				'order'     => 2,
				'price'     => '',
				'cta'       => 'menu',
				'cta_category' => 'grill-kebab',
				'image'     => 'gallery:grill-mixed-platter',
				'start'     => -1,
				'end'       => 45,
				'title'     => self::t( 'グループ来店特典（サンプル）', 'Group visit offer (sample)', 'عرض الزيارات الجماعية (نموذج)', 'گروپ وزٹ آفر (نمونہ)', 'समूह विज़िट ऑफ़र (नमूना)', 'গ্রুপ ভিজিট অফার (নমুনা)' ),
				'summary'   => self::t( '6名様以上のご来店で、グリル盛り合わせを1皿ご用意します。', 'Parties of six or more receive one mixed grill platter to share.', 'تحصل المجموعات المكونة من ستة أشخاص أو أكثر على طبق مشاوي مشكلة للمشاركة.', 'چھ یا زیادہ افراد کے گروپ کو بانٹنے کے لیے ایک مکس گرل پلیٹر۔', 'छह या अधिक लोगों के समूह को साझा करने के लिए एक मिक्स ग्रिल प्लैटर।', 'ছয় বা তার বেশি জনের দলের জন্য ভাগ করে খাওয়ার একটি মিক্সড গ্রিল প্ল্যাটার।' ),
				'conditions' => self::t(
					"6名様以上のグループが対象です。\n事前のテーブルリクエストが必要です。\n1グループ1回限り。\nデモ用の特典です。",
					"For groups of 6 or more.\nA table request in advance is required.\nOnce per group.\nDemo offer.",
					"للمجموعات المكونة من 6 أشخاص أو أكثر.\nيلزم إرسال طلب طاولة مسبقًا.\nمرة واحدة لكل مجموعة.\nعرض تجريبي.",
					"6 یا زیادہ افراد کے گروپ کے لیے۔\nپیشگی ٹیبل کی درخواست ضروری ہے۔\nہر گروپ کے لیے ایک بار۔\nڈیمو آفر۔",
					"6 या अधिक लोगों के समूह के लिए।\nपहले से टेबल अनुरोध ज़रूरी है।\nहर समूह के लिए एक बार।\nडेमो ऑफ़र।",
					"6 বা তার বেশি জনের দলের জন্য।\nআগে থেকে টেবিল অনুরোধ প্রয়োজন।\nপ্রতি দলে একবার।\nডেমো অফার।"
				),
			],
		];
	}

	/**
	 * FAQ rows for page meta `momo_faq_items` (groups per Content::FAQ_GROUPS).
	 *
	 * @return list<array{id:string, group:string, order:int, question:array<string,string>, answer:array<string,string>}>
	 */
	public static function faq(): array {
		return [
			[
				'id' => 'table-request', 'group' => 'reservations', 'order' => 10,
				'question' => self::t( '予約はできますか？', 'Can I book a table?', 'هل يمكنني حجز طاولة؟', 'کیا میں ٹیبل بک کر سکتا ہوں؟', 'क्या मैं टेबल बुक कर सकता हूँ?', 'আমি কি টেবিল বুক করতে পারি?' ),
				'answer'   => self::t(
					'各店舗のページからテーブルのリクエストを送信できます。店舗が確認したあと、結果をメールでお知らせします。リクエストの送信だけでは予約は確定しません。',
					'You can send a table request from each branch page. The branch reviews it and emails you the result. Sending a request does not confirm a booking.',
					'يمكنك إرسال طلب طاولة من صفحة كل فرع. يراجع الفرع الطلب ويرسل إليك النتيجة بالبريد الإلكتروني. إرسال الطلب لا يعني تأكيد الحجز.',
					'آپ ہر برانچ کے صفحے سے ٹیبل کی درخواست بھیج سکتے ہیں۔ برانچ اس کا جائزہ لے کر آپ کو ای میل سے نتیجہ بتائے گی۔ صرف درخواست بھیجنے سے بکنگ کنفرم نہیں ہوتی۔',
					'आप हर शाखा के पेज से टेबल का अनुरोध भेज सकते हैं। शाखा उसकी समीक्षा करके आपको ईमेल से परिणाम बताएगी। केवल अनुरोध भेजने से बुकिंग पक्की नहीं होती।',
					'প্রতিটি শাখার পৃষ্ঠা থেকে আপনি টেবিলের অনুরোধ পাঠাতে পারেন। শাখা সেটি দেখে আপনাকে ইমেইলে ফলাফল জানাবে। শুধু অনুরোধ পাঠালে বুকিং নিশ্চিত হয় না।'
				),
			],
			[
				'id' => 'change-or-cancel', 'group' => 'reservations', 'order' => 20,
				'question' => self::t( 'リクエストを変更・キャンセルするには？', 'How do I change or cancel my request?', 'كيف أغير طلبي أو ألغيه؟', 'میں اپنی درخواست کیسے تبدیل یا منسوخ کروں؟', 'मैं अपना अनुरोध कैसे बदलूँ या रद्द करूँ?', 'আমি কীভাবে আমার অনুরোধ পরিবর্তন বা বাতিল করব?' ),
				'answer'   => self::t(
					'確認メールのリンクから状況の確認とキャンセルができます。内容の変更は店舗へお電話ください。',
					'Use the link in your confirmation email to check the status or cancel. To change the details, please call the branch.',
					'استخدم الرابط في بريد التأكيد للتحقق من الحالة أو الإلغاء. لتغيير التفاصيل، يرجى الاتصال بالفرع.',
					'حالت دیکھنے یا منسوخ کرنے کے لیے تصدیقی ای میل کا لنک استعمال کریں۔ تفصیلات بدلنے کے لیے برانچ کو فون کریں۔',
					'स्थिति देखने या रद्द करने के लिए पुष्टि ईमेल का लिंक इस्तेमाल करें। विवरण बदलने के लिए शाखा को फ़ोन करें।',
					'অবস্থা দেখতে বা বাতিল করতে নিশ্চিতকরণ ইমেইলের লিংক ব্যবহার করুন। বিবরণ বদলাতে শাখায় ফোন করুন।'
				),
			],
			[
				'id' => 'pork-and-alcohol', 'group' => 'halal_dietary', 'order' => 30,
				'question' => self::t( '豚肉やアルコールの使用について知りたいです。', 'Where can I find information about pork and alcohol?', 'أين أجد معلومات عن لحم الخنزير والكحول؟', 'سور کے گوشت اور الکحل کے بارے میں معلومات کہاں ملیں گی؟', 'पोर्क और शराब के बारे में जानकारी कहाँ मिलेगी?', 'শূকরের মাংস ও অ্যালকোহল সম্পর্কে তথ্য কোথায় পাব?' ),
				'answer'   => self::t(
					'食材やアルコールに関する情報は店舗ごとに異なります。店舗ページと各料理のページをご確認のうえ、ご不明な点はご来店前に店舗へお問い合わせください。',
					'Information on ingredients and alcohol differs by branch. Please check the branch page and each dish page, and contact the branch before your visit if anything is unclear.',
					'تختلف المعلومات المتعلقة بالمكونات والكحول من فرع لآخر. يرجى مراجعة صفحة الفرع وصفحة كل طبق، والتواصل مع الفرع قبل زيارتك إذا كان هناك ما هو غير واضح.',
					'اجزا اور الکحل سے متعلق معلومات ہر برانچ میں مختلف ہیں۔ برانچ اور ہر ڈش کا صفحہ دیکھیں، اور کچھ واضح نہ ہو تو آنے سے پہلے برانچ سے رابطہ کریں۔',
					'सामग्री और शराब की जानकारी हर शाखा में अलग है। शाखा और हर व्यंजन का पेज देखें, और कुछ स्पष्ट न हो तो आने से पहले शाखा से संपर्क करें।',
					'উপকরণ ও অ্যালকোহল সংক্রান্ত তথ্য শাখাভেদে আলাদা। শাখা ও প্রতিটি পদের পৃষ্ঠা দেখুন, কিছু অস্পষ্ট হলে আসার আগে শাখায় যোগাযোগ করুন।'
				),
			],
			[
				'id' => 'food-allergy', 'group' => 'allergens', 'order' => 40,
				'question' => self::t( '食物アレルギーがあります。', 'I have a food allergy.', 'لدي حساسية من بعض الأطعمة.', 'مجھے کھانے سے الرجی ہے۔', 'मुझे खाने से एलर्जी है।', 'আমার খাবারে অ্যালার্জি আছে।' ),
				'answer'   => self::t(
					'各料理のページにアレルゲン情報を掲載していますが、同じ厨房で多くの食材を扱っています。ご来店時に必ずスタッフへお知らせください。',
					'Each dish page shows allergen information, but many ingredients are handled in the same kitchen. Please always tell our staff when you arrive.',
					'تعرض صفحة كل طبق معلومات مسببات الحساسية، لكن العديد من المكونات تُستخدم في المطبخ نفسه. يرجى دائمًا إبلاغ فريقنا عند وصولك.',
					'ہر ڈش کے صفحے پر الرجی کی معلومات موجود ہیں، لیکن ایک ہی کچن میں کئی اجزا استعمال ہوتے ہیں۔ آمد پر ہمیشہ عملے کو بتائیں۔',
					'हर व्यंजन के पेज पर एलर्जन जानकारी है, लेकिन एक ही रसोई में कई सामग्रियाँ इस्तेमाल होती हैं। आने पर हमेशा स्टाफ़ को बताएँ।',
					'প্রতিটি পদের পৃষ্ঠায় অ্যালার্জেন তথ্য আছে, তবে একই রান্নাঘরে অনেক উপকরণ ব্যবহৃত হয়। আসার পর সবসময় কর্মীদের জানান।'
				),
			],
			[
				'id' => 'parking', 'group' => 'branches_access', 'order' => 50,
				'question' => self::t( '駐車場はありますか？', 'Is there parking?', 'هل يوجد موقف سيارات؟', 'کیا پارکنگ ہے؟', 'क्या पार्किंग है?', 'পার্কিং আছে কি?' ),
				'answer'   => self::t(
					'専用の駐車場はありません。近隣のコインパーキングまたは公共交通機関をご利用ください。',
					'There is no dedicated parking. Please use nearby coin parking or public transport.',
					'لا يوجد موقف سيارات مخصص. يرجى استخدام مواقف السيارات القريبة أو وسائل النقل العام.',
					'مخصوص پارکنگ نہیں ہے۔ قریبی کوائن پارکنگ یا پبلک ٹرانسپورٹ استعمال کریں۔',
					'कोई अलग पार्किंग नहीं है। पास की कॉइन पार्किंग या सार्वजनिक परिवहन का उपयोग करें।',
					'আলাদা পার্কিং নেই। কাছাকাছি কয়েন পার্কিং বা গণপরিবহন ব্যবহার করুন।'
				),
			],
			[
				'id' => 'children', 'group' => 'branches_access', 'order' => 60,
				'question' => self::t( '子ども連れでも大丈夫ですか？', 'Can I bring children?', 'هل يمكنني إحضار الأطفال؟', 'کیا میں بچوں کو ساتھ لا سکتا ہوں؟', 'क्या मैं बच्चों को ला सकता हूँ?', 'আমি কি বাচ্চাদের আনতে পারি?' ),
				'answer'   => self::t(
					'はい、歓迎します。キッズチェアをご希望の場合は、テーブルリクエストの備考欄にご記入ください。',
					'Yes, children are welcome. If you need a high chair, add it to the notes of your table request.',
					'نعم، الأطفال مرحب بهم. إذا كنت بحاجة إلى كرسي أطفال، أضفه إلى ملاحظات طلب الطاولة.',
					'جی ہاں، بچوں کا خیرمقدم ہے۔ اگر ہائی چیئر چاہیے تو ٹیبل کی درخواست کے نوٹس میں لکھیں۔',
					'हाँ, बच्चों का स्वागत है। अगर हाई चेयर चाहिए तो टेबल अनुरोध के नोट में लिखें।',
					'হ্যাঁ, শিশুদের স্বাগত। হাই চেয়ার লাগলে টেবিল অনুরোধের নোটে লিখুন।'
				),
			],
		];
	}

	/**
	 * Copy for existing system pages (role => fields). Pages are restored by `--reset`.
	 * `publish` = publish an optional (draft) system page in the demo.
	 *
	 * @return array<string, array{title:array<string,string>, excerpt?:array<string,string>, content?:array<string,string>, publish?:bool, faq?:bool}>
	 */
	public static function pages(): array {
		$sample = self::t( '※ このページの文章はデモ用のサンプルです。', 'SAMPLE TEXT — this page copy is demo content.', 'نص تجريبي — محتوى هذه الصفحة للعرض فقط.', 'نمونہ متن — اس صفحے کا مواد ڈیمو کے لیے ہے۔', 'नमूना पाठ — इस पेज की सामग्री डेमो के लिए है।', 'নমুনা লেখা — এই পৃষ্ঠার বিষয়বস্তু ডেমোর জন্য।' );
		$about  = [];
		foreach ( $sample as $lang => $s ) {
			$about[ $lang ] = '<p>' . self::about_p1()[ $lang ] . '</p><p>' . self::about_p2()[ $lang ] . '</p><p><small>' . $s . '</small></p>';
		}
		return [
			'home'     => [
				'title'   => self::t( 'ホーム', 'Home', 'الرئيسية', 'ہوم', 'होम', 'হোম' ),
				'excerpt' => self::t(
					'大阪と京都のデモ店舗で、カレー、ビリヤニ、タンドール料理をお楽しみください。（サンプル文章）',
					'Curry, biryani and tandoor dishes at our demo branches in Osaka and Kyoto. (Sample text)',
					'كاري وبرياني وأطباق التنور في فروعنا التجريبية في أوساكا وكيوتو. (نص تجريبي)',
					'اوساکا اور کیوٹو کی ہماری ڈیمو برانچوں میں سالن، بریانی اور تندوری پکوان۔ (نمونہ متن)',
					'ओसाका और क्योटो की हमारी डेमो शाखाओं में करी, बिरयानी और तंदूरी व्यंजन। (नमूना पाठ)',
					'ওসাকা ও কিয়োটোর আমাদের ডেমো শাখায় কারি, বিরিয়ানি ও তন্দুরি পদ। (নমুনা লেখা)'
				),
			],
			'about'    => [
				'title'   => self::t( '私たちについて', 'About us', 'من نحن', 'ہمارے بارے میں', 'हमारे बारे में', 'আমাদের সম্পর্কে' ),
				'excerpt' => self::t(
					'スパイス料理を気軽に楽しめる場所をつくりたい――そんな思いから生まれたデモブランドです。',
					'A demo brand built around one idea: a relaxed place to enjoy spiced dishes.',
					'علامة تجريبية قائمة على فكرة واحدة: مكان مريح للاستمتاع بالأطباق المتبلة.',
					'ایک ڈیمو برانڈ جس کا خیال ہے: مصالحے دار کھانوں سے لطف اٹھانے کی آرام دہ جگہ۔',
					'एक डेमो ब्रांड जिसका विचार है: मसालेदार व्यंजनों का आनंद लेने की आरामदायक जगह।',
					'একটি ডেমো ব্র্যান্ড যার ভাবনা: মসলাদার খাবার উপভোগের একটি আরামদায়ক জায়গা।'
				),
				'content' => $about,
			],
			'faq'      => [
				'title'   => self::t( 'よくある質問', 'Frequently asked questions', 'الأسئلة الشائعة', 'اکثر پوچھے جانے والے سوالات', 'अक्सर पूछे जाने वाले प्रश्न', 'সচরাচর জিজ্ঞাসা' ),
				'excerpt' => self::t( 'テーブルのリクエスト、食事、店舗についてのよくある質問です。', 'Common questions about table requests, food and our branches.', 'أسئلة شائعة حول طلبات الطاولات والطعام وفروعنا.', 'ٹیبل کی درخواست، کھانے اور برانچوں کے بارے میں عام سوالات۔', 'टेबल अनुरोध, भोजन और शाखाओं के बारे में आम प्रश्न।', 'টেবিল অনুরোধ, খাবার ও শাখা সম্পর্কে সাধারণ প্রশ্ন।' ),
				'publish' => true,
				'faq'     => true,
			],
			'contact'  => [
				'title'   => self::t( 'お問い合わせ', 'Contact', 'اتصل بنا', 'رابطہ', 'संपर्क', 'যোগাযোগ' ),
				'excerpt' => self::t( 'ご質問やご意見はこちらからお送りください。テーブルのリクエストは各店舗のページからお願いします。', 'Send us your questions or feedback here. For table requests, please use each branch page.', 'أرسل إلينا أسئلتك أو ملاحظاتك من هنا. لطلبات الطاولات، يرجى استخدام صفحة كل فرع.', 'اپنے سوالات یا رائے یہاں بھیجیں۔ ٹیبل کی درخواست کے لیے ہر برانچ کا صفحہ استعمال کریں۔', 'अपने प्रश्न या सुझाव यहाँ भेजें। टेबल अनुरोध के लिए हर शाखा का पेज इस्तेमाल करें।', 'আপনার প্রশ্ন বা মতামত এখানে পাঠান। টেবিল অনুরোধের জন্য প্রতিটি শাখার পৃষ্ঠা ব্যবহার করুন।' ),
				'publish' => true,
			],
			'branches'    => [ 'title' => self::t( '店舗一覧', 'Branches', 'الفروع', 'برانچیں', 'शाखाएँ', 'শাখাসমূহ' ) ],
			'menu'        => [ 'title' => self::t( 'メニュー', 'Menu', 'القائمة', 'مینو', 'मेन्यू', 'মেনু' ) ],
			'halal'       => [ 'title' => self::t( 'ハラール情報', 'Halal information', 'معلومات الحلال', 'حلال معلومات', 'हलाल जानकारी', 'হালাল তথ্য' ) ],
			'reservation' => [ 'title' => self::t( '予約リクエスト', 'Table request', 'طلب حجز طاولة', 'ٹیبل کی درخواست', 'टेबल अनुरोध', 'টেবিল অনুরোধ' ) ],
			'submitted'   => [ 'title' => self::t( 'リクエストを送信しました', 'Request sent', 'تم إرسال الطلب', 'درخواست بھیج دی گئی', 'अनुरोध भेजा गया', 'অনুরোধ পাঠানো হয়েছে' ) ],
			'status'      => [ 'title' => self::t( '予約リクエストの状況', 'Request status', 'حالة الطلب', 'درخواست کی صورتحال', 'अनुरोध की स्थिति', 'অনুরোধের অবস্থা' ) ],
			'offers'      => [ 'title' => self::t( 'お得な情報', 'Offers', 'العروض', 'آفرز', 'ऑफ़र', 'অফার' ) ],
		];
	}

	/** @return array<string,string> */
	private static function about_p1(): array {
		return self::t(
			'私たちは、南アジアのスパイス料理を毎日の食事として気軽に楽しんでいただくことを目指すデモブランドです。大阪と京都のデモ店舗で、カレー、ビリヤニ、タンドール料理をご用意しています。',
			'We are a demo brand that wants South Asian spiced dishes to feel like an everyday meal. Our demo branches in Osaka and Kyoto serve curry, biryani and tandoor dishes.',
			'نحن علامة تجريبية نريد أن تصبح الأطباق المتبلة من جنوب آسيا وجبة يومية. تقدم فروعنا التجريبية في أوساكا وكيوتو الكاري والبرياني وأطباق التنور.',
			'ہم ایک ڈیمو برانڈ ہیں جو چاہتا ہے کہ جنوبی ایشیائی مصالحے دار کھانے روزمرہ کا کھانا بنیں۔ اوساکا اور کیوٹو میں ہماری ڈیمو برانچیں سالن، بریانی اور تندوری پکوان پیش کرتی ہیں۔',
			'हम एक डेमो ब्रांड हैं जो चाहता है कि दक्षिण एशियाई मसालेदार व्यंजन रोज़ का भोजन बनें। ओसाका और क्योटो में हमारी डेमो शाखाएँ करी, बिरयानी और तंदूरी व्यंजन परोसती हैं।',
			'আমরা একটি ডেমো ব্র্যান্ড, যারা চাই দক্ষিণ এশীয় মসলাদার খাবার প্রতিদিনের খাবার হয়ে উঠুক। ওসাকা ও কিয়োটোতে আমাদের ডেমো শাখাগুলো কারি, বিরিয়ানি ও তন্দুরি পদ পরিবেশন করে।'
		);
	}

	/** @return array<string,string> */
	private static function about_p2(): array {
		return self::t(
			'スパイスはお店で炒って挽き、料理ごとに配合を変えています。辛さはスタッフにご相談ください。',
			'We toast and grind our spices in-house and blend them differently for each dish. Ask our staff about the spice level.',
			'نحمص توابلنا ونطحنها في المطعم ونمزجها بشكل مختلف لكل طبق. اسأل فريقنا عن درجة الحرارة.',
			'ہم اپنے مصالحے یہیں بھونتے اور پیستے ہیں اور ہر ڈش کے لیے الگ ملاتے ہیں۔ تیکھے پن کے بارے میں عملے سے پوچھیں۔',
			'हम अपने मसाले यहीं भूनते और पीसते हैं और हर व्यंजन के लिए अलग मिलाते हैं। तीखेपन के बारे में स्टाफ़ से पूछें।',
			'আমরা নিজেরাই মসলা ভেজে গুঁড়ো করি এবং প্রতিটি পদের জন্য আলাদাভাবে মেশাই। ঝালের মাত্রা নিয়ে কর্মীদের জিজ্ঞাসা করুন।'
		);
	}

	/** Translated titles of the five legal pages (SystemPages::LEGAL keys). @return array<string, array<string,string>> */
	public static function legal_titles(): array {
		return [
			'privacy'       => self::t( 'プライバシーポリシー', 'Privacy Policy', 'سياسة الخصوصية', 'رازداری کی پالیسی', 'गोपनीयता नीति', 'গোপনীয়তা নীতি' ),
			'terms'         => self::t( '利用規約', 'Terms of Use', 'شروط الاستخدام', 'استعمال کی شرائط', 'उपयोग की शर्तें', 'ব্যবহারের শর্তাবলি' ),
			'res_policy'    => self::t( '予約ポリシー', 'Reservation Policy', 'سياسة الحجز', 'ریزرویشن پالیسی', 'आरक्षण नीति', 'সংরক্ষণ নীতি' ),
			'ext_trans'     => self::t( '外部送信・Cookieについて', 'External transmission & cookies', 'الإرسال الخارجي وملفات تعريف الارتباط', 'بیرونی ترسیل اور کوکیز', 'बाहरी प्रेषण और कुकीज़', 'বাহ্যিক প্রেরণ ও কুকিজ' ),
			'accessibility' => self::t( 'アクセシビリティ', 'Accessibility', 'إمكانية الوصول', 'رسائی', 'सुलभता', 'প্রবেশযোগ্যতা' ),
		];
	}

	/**
	 * G2 statement templates the DEMO approves (QA-006, GATE-02 demo): only neutral, non-claim templates —
	 * `brand.neutral` (home/halal page), `no_claim` (branch), `branch_default` (dish halal basis line) and
	 * the alcohol lines. Claim templates (`certified.*`, `halal_ingredients`) are never approved by the seed.
	 * ja/en are the Doc 11 §8.1/§8.7 master texts already stored by M0003 (kept as stored); ar/ur/hi/bn are
	 * DRAFT translations used only when a row has no translation yet (DEC-027; production approval needs
	 * native review + CAP-DCA + counsel, BR-DIET-05). Reverted by `--reset`.
	 *
	 * @return array<string, array<string,string>> key => lang => text
	 */
	public static function statements(): array {
		return [
			'brand.neutral'      => self::t(
				'ハラールに関する情報は店舗ごとに表示しています。',
				'Halal information is shown for each branch.',
				'تُعرض معلومات الحلال لكل فرع على حدة.',
				'حلال سے متعلق معلومات ہر برانچ کے لیے الگ دکھائی جاتی ہیں۔',
				'हलाल से संबंधित जानकारी हर शाखा के लिए अलग से दिखाई जाती है।',
				'হালাল সম্পর্কিত তথ্য প্রতিটি শাখার জন্য আলাদাভাবে দেখানো হয়।'
			),
			'no_claim'           => self::t(
				'食材や調理方法については、この店舗のスタッフにお尋ねください。',
				'Please ask our staff at this branch about ingredients and preparation.',
				'يُرجى سؤال فريق العمل في هذا الفرع عن المكونات وطريقة التحضير.',
				'اجزاء اور تیاری کے طریقے کے بارے میں براہ کرم اس برانچ کے عملے سے پوچھیں۔',
				'सामग्री और बनाने के तरीके के बारे में कृपया इस शाखा के स्टाफ़ से पूछें।',
				'উপকরণ ও প্রস্তুত প্রণালী সম্পর্কে অনুগ্রহ করে এই শাখার কর্মীদের জিজ্ঞাসা করুন।'
			),
			'branch_default'     => self::t(
				'ハラールに関する情報は店舗ごとに表示しています。この料理を提供している店舗：{branch_links}',
				'Halal information is shown for each branch. Branches offering this dish: {branch_links}',
				'تُعرض معلومات الحلال لكل فرع على حدة. الفروع التي تقدّم هذا الطبق: {branch_links}',
				'حلال سے متعلق معلومات ہر برانچ کے لیے الگ دکھائی جاتی ہیں۔ یہ ڈش پیش کرنے والی برانچیں: {branch_links}',
				'हलाल से संबंधित जानकारी हर शाखा के लिए अलग से दिखाई जाती है। यह व्यंजन उपलब्ध कराने वाली शाखाएँ: {branch_links}',
				'হালাল সম্পর্কিত তথ্য প্রতিটি শাখার জন্য আলাদাভাবে দেখানো হয়। যে শাখাগুলোতে এই খাবারটি পাওয়া যায়: {branch_links}'
			),
			'alcohol.served.yes' => self::t(
				'この店舗ではアルコール飲料を提供しています。',
				'Alcoholic drinks are served at this branch.',
				'يقدّم هذا الفرع مشروبات كحولية.',
				'اس برانچ میں الکوحل والے مشروبات پیش کیے جاتے ہیں۔',
				'इस शाखा में मादक पेय परोसे जाते हैं।',
				'এই শাখায় অ্যালকোহলযুক্ত পানীয় পরিবেশন করা হয়।'
			),
			'alcohol.served.no'  => self::t(
				'この店舗ではアルコール飲料を提供していません。',
				'Alcoholic drinks are not served at this branch.',
				'لا يقدّم هذا الفرع مشروبات كحولية.',
				'اس برانچ میں الکوحل والے مشروبات پیش نہیں کیے جاتے۔',
				'इस शाखा में मादक पेय नहीं परोसे जाते।',
				'এই শাখায় অ্যালকোহলযুক্ত পানীয় পরিবেশন করা হয় না।'
			),
		];
	}

	/** Brand settings (G1) written by the seed; trading name is left as installed. @return array<string,mixed> */
	public static function brand(): array {
		return [
			'momo_tagline'               => self::t( '大阪・京都のスパイスダイニング', 'Spice dining in Osaka and Kyoto', 'مطاعم التوابل في أوساكا وكيوتو', 'اوساکا اور کیوٹو میں مصالحوں کا ذائقہ', 'ओसाका और क्योटो में मसालों का स्वाद', 'ওসাকা ও কিয়োটোতে মসলার স্বাদ' ),
			'momo_contact_email'         => 'info@example.com',
			'momo_contact_phone_e164'    => '+81600000000',
			'momo_contact_phone_display' => '06-0000-0000',
		];
	}

	/** Notification settings (example.com recipients). @return array<string,mixed> */
	public static function notification_settings(): array {
		return [
			'momo_ho_escalation_recipients' => [ [ 'email' => 'ho-desk@example.com', 'language' => 'ja', 'active' => '1' ] ],
			'momo_digest_recipients'        => [ [ 'email' => 'ops-summary@example.com', 'language' => 'en', 'active' => '1' ] ],
		];
	}
}
