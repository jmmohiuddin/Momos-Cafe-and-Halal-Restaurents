<?php
declare(strict_types=1);

namespace Momos\Seed\Data;

/**
 * Demo menu (DEC-047 sample content, docs/specs/content-strings.md §10, menu-dietary.md): 6 categories,
 * 24 dishes (SKU D9101–D9124, outside the QA fixture range D9001–D9013), key ingredients and two
 * non-claim dietary tags. All names, descriptions and prices are FICTIONAL SAMPLE data. ar/ur/hi/bn
 * texts are DRAFT translations (DEC-027) for the browsable development site only.
 *
 * No halal, "allergen-free" or ordering wording in any text (WordingGuard, GATE-02, BR-DIET-03).
 */
final class Menu {
	/** @return array<string,string> */
	private static function t( string $ja, string $en, string $ar, string $ur, string $hi, string $bn ): array {
		return [ 'ja' => $ja, 'en' => $en, 'ar' => $ar, 'ur' => $ur, 'hi' => $hi, 'bn' => $bn ];
	}

	/** @return array<string, array{class:string,name:array<string,string>}> */
	public static function ingredients(): array {
		return [
			'chicken'    => [ 'class' => 'poultry', 'name' => self::t( '鶏肉', 'Chicken', 'دجاج', 'چکن', 'चिकन', 'মুরগি' ) ],
			'mutton'     => [ 'class' => 'meat', 'name' => self::t( 'マトン', 'Mutton', 'لحم الضأن', 'مٹن', 'मटन', 'মাটন' ) ],
			'beef'       => [ 'class' => 'meat', 'name' => self::t( '牛肉', 'Beef', 'لحم البقر', 'گائے کا گوشت', 'बीफ़', 'গরুর মাংস' ) ],
			'prawn'      => [ 'class' => 'seafood', 'name' => self::t( '海老', 'Prawns', 'روبيان', 'جھینگا', 'झींगा', 'চিংড়ি' ) ],
			'basmati'    => [ 'class' => 'plant', 'name' => self::t( 'バスマティライス', 'Basmati rice', 'أرز بسمتي', 'باسمتی چاول', 'बासमती चावल', 'বাসমতি চাল' ) ],
			'wheat'      => [ 'class' => 'plant', 'name' => self::t( '小麦粉', 'Wheat flour', 'دقيق القمح', 'گندم کا آٹا', 'गेहूँ का आटा', 'গমের আটা' ) ],
			'dairy'      => [ 'class' => 'dairy', 'name' => self::t( '乳製品', 'Dairy products', 'منتجات الألبان', 'دودھ سے بنی اشیاء', 'डेयरी उत्पाद', 'দুগ্ধজাত পণ্য' ) ],
			'lentils'    => [ 'class' => 'plant', 'name' => self::t( 'レンズ豆', 'Lentils', 'عدس', 'دال', 'दाल', 'ডাল' ) ],
			'vegetables' => [ 'class' => 'plant', 'name' => self::t( '野菜', 'Vegetables', 'خضار', 'سبزیاں', 'सब्ज़ियाँ', 'সবজি' ) ],
			'potato'     => [ 'class' => 'plant', 'name' => self::t( 'じゃがいも', 'Potato', 'بطاطس', 'آلو', 'आलू', 'আলু' ) ],
			'egg'        => [ 'class' => 'egg', 'name' => self::t( '卵', 'Egg', 'بيض', 'انڈا', 'अंडा', 'ডিম' ) ],
		];
	}

	/** Non-claim tags only (menu-dietary.md §2.4 candidates; claim tags need CAP-DCA and are not seeded). @return array<string, array{name:array<string,string>,description:array<string,string>}> */
	public static function tags(): array {
		return [
			'vegetarian' => [
				'name'        => self::t( 'ベジタリアン', 'Vegetarian', 'نباتي', 'سبزی خور', 'शाकाहारी', 'নিরামিষ' ),
				'description' => self::t( '肉・魚介を使用していない料理です（乳製品・卵を含む場合があります）。', 'No meat or seafood (may contain dairy or egg).', 'بدون لحوم أو مأكولات بحرية (قد يحتوي على ألبان أو بيض).', 'گوشت یا سمندری غذا کے بغیر (دودھ یا انڈا شامل ہو سکتا ہے)۔', 'मांस या समुद्री भोजन नहीं (दूध या अंडा हो सकता है)।', 'মাংস বা সামুদ্রিক খাবার নেই (দুধ বা ডিম থাকতে পারে)।' ),
			],
			'vegan'      => [
				'name'        => self::t( 'ヴィーガン', 'Vegan', 'نباتي صرف', 'ویگن', 'वीगन', 'ভেগান' ),
				'description' => self::t( '動物性の原材料を使用していない料理です。', 'No ingredients of animal origin.', 'بدون مكونات من أصل حيواني.', 'جانوروں سے حاصل شدہ اجزا کے بغیر۔', 'पशु-उत्पत्ति की कोई सामग्री नहीं।', 'প্রাণিজ কোনো উপাদান নেই।' ),
			],
		];
	}

	/**
	 * slug => order, name, intro (term description), note (appended to each dish description), image (prototype photo).
	 *
	 * @return array<string, array{order:int,name:array<string,string>,intro:array<string,string>,note:array<string,string>,image:string}>
	 */
	public static function categories(): array {
		return [
			'curry'        => [
				'order' => 10,
				'image' => 'butter-chicken-curry',
				'name'  => self::t( 'カレー', 'Curry', 'الكاري', 'سالن', 'करी', 'কারি' ),
				'intro' => self::t( 'スパイスを丁寧に炒め、じっくり煮込んだカレー。', 'Curries made by slowly cooking freshly toasted spices.', 'أطباق كاري مطهوة ببطء مع توابل محمصة طازجة.', 'تازہ بھنے مصالحوں کے ساتھ دھیمی آنچ پر پکے سالن۔', 'ताज़ा भुने मसालों के साथ धीमी आँच पर पकी करी।', 'তাজা ভাজা মসলায় ধীরে রান্না করা কারি।' ),
				'note'  => self::t( 'ライスまたはナンと一緒にお召し上がりください。辛さの調整はスタッフにご相談ください。', 'Enjoy with rice or naan. Ask our staff if you would like it milder or hotter.', 'تناوله مع الأرز أو خبز النان. اسأل فريقنا إذا رغبت في درجة حرارة أخف أو أقوى.', 'چاول یا نان کے ساتھ نوش فرمائیں۔ تیکھا پن کم یا زیادہ کرنے کے لیے عملے سے پوچھیں۔', 'चावल या नान के साथ आनंद लें। तीखापन कम या ज़्यादा करवाने के लिए स्टाफ़ से पूछें।', 'ভাত বা নানের সাথে উপভোগ করুন। ঝাল কম বা বেশি চাইলে কর্মীদের জানান।' ),
			],
			'biryani-rice' => [
				'order' => 20,
				'image' => 'chicken-biryani',
				'name'  => self::t( 'ビリヤニ・ライス', 'Biryani & rice', 'البرياني والأرز', 'بریانی اور چاول', 'बिरयानी और चावल', 'বিরিয়ানি ও ভাত' ),
				'intro' => self::t( '香り高いバスマティライスを使ったご飯もの。', 'Rice dishes made with fragrant basmati.', 'أطباق أرز محضرة بأرز البسمتي العطري.', 'خوشبودار باسمتی چاول سے بنے پکوان۔', 'खुशबूदार बासमती चावल से बने व्यंजन।', 'সুগন্ধি বাসমতি চালের পদ।' ),
				'note'  => self::t( 'ビリヤニは都度炊き上げるため、混雑時はお時間をいただくことがあります。', 'Biryani is cooked in batches, so it may take a little longer at busy times.', 'يُطهى البرياني على دفعات، لذا قد يستغرق وقتًا أطول قليلًا في أوقات الازدحام.', 'بریانی کھیپ میں پکتی ہے، اس لیے رش کے وقت تھوڑا زیادہ وقت لگ سکتا ہے۔', 'बिरयानी बैच में पकती है, इसलिए भीड़ के समय थोड़ा ज़्यादा समय लग सकता है।', 'বিরিয়ানি ব্যাচে রান্না হয়, তাই ভিড়ের সময় একটু বেশি সময় লাগতে পারে।' ),
			],
			'grill-kebab'  => [
				'order' => 30,
				'image' => 'tandoor-chicken',
				'name'  => self::t( 'グリル・ケバブ', 'Grill & kebab', 'المشاوي والكباب', 'گرل اور کباب', 'ग्रिल और कबाब', 'গ্রিল ও কাবাব' ),
				'intro' => self::t( 'タンドール窯で焼き上げる肉料理と海老料理。', 'Meat and prawn dishes roasted in the tandoor.', 'أطباق لحوم وروبيان مشوية في التنور.', 'تندور میں بھنے گوشت اور جھینگے کے پکوان۔', 'तंदूर में भुने मांस और झींगे के व्यंजन।', 'তন্দুরে ঝলসানো মাংস ও চিংড়ির পদ।' ),
				'note'  => self::t( 'タンドール窯で一品ずつ焼き上げます。', 'Each item is roasted fresh in our tandoor oven.', 'يُشوى كل طبق طازجًا في فرن التنور.', 'ہر ڈش تندور میں تازہ بھونی جاتی ہے۔', 'हर व्यंजन तंदूर में ताज़ा भूना जाता है।', 'প্রতিটি পদ তন্দুরে টাটকা ঝলসানো হয়।' ),
			],
			'naan-bread'   => [
				'order' => 40,
				'image' => 'garlic-naan',
				'name'  => self::t( 'ナン・パン', 'Naan & bread', 'النان والخبز', 'نان اور روٹی', 'नान और रोटी', 'নান ও রুটি' ),
				'intro' => self::t( '窯で焼き上げるナンの数々。', 'Naan breads baked in our oven.', 'تشكيلة من خبز النان المخبوز في الفرن.', 'تندور میں پکے طرح طرح کے نان۔', 'तंदूर में सेंके तरह-तरह के नान।', 'তন্দুরে সেঁকা নানা রকম নান।' ),
				'note'  => self::t( '焼きたてをお出しします。カレーとの相性抜群です。', 'Served straight from the oven — the perfect partner for curry.', 'يُقدَّم ساخنًا من الفرن، الرفيق المثالي للكاري.', 'تندور سے تازہ پیش کیا جاتا ہے، سالن کا بہترین ساتھی۔', 'तंदूर से ताज़ा परोसा जाता है, करी का बेहतरीन साथी।', 'তন্দুর থেকে গরম গরম পরিবেশন, কারির সেরা সঙ্গী।' ),
			],
			'sides'        => [
				'order' => 50,
				'image' => 'veg-samosa',
				'name'  => self::t( 'サイド・スープ・サラダ', 'Sides, soups & salads', 'المقبلات والشوربات والسلطات', 'سائیڈز، سوپ اور سلاد', 'साइड, सूप और सलाद', 'সাইড, স্যুপ ও সালাদ' ),
				'intro' => self::t( '前菜、スープ、サラダ。', 'Starters, soups and salads.', 'مقبلات وشوربات وسلطات.', 'اسٹارٹرز، سوپ اور سلاد۔', 'स्टार्टर, सूप और सलाद।', 'স্টার্টার, স্যুপ ও সালাদ।' ),
				'note'  => self::t( '前菜やシェアにおすすめです。', 'Great as a starter or for sharing.', 'مثالية كمقبلات أو للمشاركة.', 'اسٹارٹر یا مل بانٹ کر کھانے کے لیے بہترین۔', 'स्टार्टर या साझा करने के लिए बढ़िया।', 'স্টার্টার বা ভাগ করে খাওয়ার জন্য দারুণ।' ),
			],
			'dessert'      => [
				'order' => 60,
				'image' => 'vanilla-matcha-ice-cream',
				'name'  => self::t( 'デザート', 'Dessert', 'الحلويات', 'میٹھا', 'मिठाई', 'ডেজার্ট' ),
				'intro' => self::t( '食後の甘いひととき。', 'Something sweet to finish.', 'شيء حلو للختام.', 'آخر میں کچھ میٹھا۔', 'अंत में कुछ मीठा।', 'শেষে একটু মিষ্টি।' ),
				'note'  => self::t( '食後のひとときにどうぞ。', 'A sweet finish to your meal.', 'ختام حلو لوجبتك.', 'کھانے کا میٹھا اختتام۔', 'भोजन का मीठा समापन।', 'খাবারের মিষ্টি সমাপ্তি।' ),
			],
		];
	}

	/**
	 * Dishes. `allergens`: null = left "not verified" (incomplete data, shown as such); otherwise
	 * [contains, may_contain] and every other seeded allergen is recorded as not_contained.
	 * `branches`: branch key => price override (null = reference price); absent = not offered there.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function dishes(): array {
		$all = [ 'DKH' => null, 'DNB' => null, 'DKY' => null ];
		return [
			[
				'sku' => 'D9101', 'slug' => 'butter-chicken-curry', 'cat' => 'curry', 'image' => 'butter-chicken-curry', 'original' => 'Murgh Makhani', 'spice' => 1, 'price' => 1280, 'featured' => true, 'tags' => [], 'ingredients' => [ 'chicken', 'dairy' ], 'allergens' => [ [ 'milk', 'chicken', 'cashew_nut' ], [ 'wheat' ] ], 'branches' => [ 'DKH' => null, 'DNB' => null, 'DKY' => 1380 ],
				'name'  => self::t( 'バターチキンカレー', 'Butter chicken curry', 'كاري الدجاج بالزبدة', 'بٹر چکن سالن', 'बटर चिकन करी', 'বাটার চিকেন কারি' ),
				'short' => self::t( 'トマトとバターのまろやかなソースで煮込んだ鶏肉のカレー。', 'Chicken simmered in a mellow tomato and butter sauce.', 'دجاج مطهو في صلصة طماطم وزبدة ناعمة.', 'ٹماٹر اور مکھن کی ہلکی چٹنی میں پکا ہوا چکن۔', 'टमाटर और मक्खन की हल्की ग्रेवी में पका चिकन।', 'টমেটো ও মাখনের হালকা সসে রান্না করা মুরগি।' ),
			],
			[
				'sku' => 'D9102', 'slug' => 'mutton-curry', 'cat' => 'curry', 'image' => 'mutton-curry', 'original' => 'Mutton Korma', 'spice' => 2, 'price' => 1480, 'tags' => [], 'ingredients' => [ 'mutton', 'dairy' ], 'allergens' => [ [ 'milk' ], [] ], 'branches' => $all,
				'name'  => self::t( 'マトンカレー', 'Mutton curry', 'كاري لحم الضأن', 'مٹن سالن', 'मटन करी', 'মাটন কারি' ),
				'short' => self::t( '骨付きマトンを玉ねぎとスパイスでじっくり煮込みました。', 'Bone-in mutton slow-cooked with onions and whole spices.', 'لحم ضأن بالعظم مطهو ببطء مع البصل والتوابل الكاملة.', 'ہڈی والا مٹن پیاز اور ثابت مصالحوں کے ساتھ دھیمی آنچ پر پکایا گیا۔', 'हड्डी वाला मटन प्याज़ और साबुत मसालों के साथ धीमी आँच पर पकाया गया।', 'হাড়সহ মাটন পেঁয়াজ ও গোটা মসলায় ধীরে রান্না।' ),
			],
			[
				'sku' => 'D9103', 'slug' => 'prawn-curry', 'cat' => 'curry', 'image' => 'prawn-curry', 'original' => 'Chingri Malai', 'spice' => 2, 'price' => 1580, 'tags' => [], 'ingredients' => [ 'prawn' ], 'allergens' => [ [ 'shrimp' ], [ 'crab', 'squid' ] ], 'branches' => $all,
				'name'  => self::t( 'プラウンカレー', 'Prawn curry', 'كاري الروبيان', 'جھینگا سالن', 'झींगा करी', 'চিংড়ি কারি' ),
				'short' => self::t( 'ココナッツミルクで仕上げた海老のカレー。', 'Prawns in a coconut milk curry.', 'روبيان في كاري بحليب جوز الهند.', 'ناریل کے دودھ والے سالن میں جھینگے۔', 'नारियल के दूध की करी में झींगे।', 'নারকেলের দুধের কারিতে চিংড়ি।' ),
			],
			[
				'sku' => 'D9104', 'slug' => 'mixed-vegetable-curry', 'cat' => 'curry', 'image' => 'mix-vegetable-curry', 'original' => '', 'spice' => 1, 'price' => 1180, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'vegetables', 'potato', 'dairy' ], 'allergens' => [ [ 'milk' ], [ 'soybean' ] ], 'branches' => $all,
				'name'  => self::t( '野菜カレー', 'Mixed vegetable curry', 'كاري الخضار المشكلة', 'مکس سبزی سالن', 'मिक्स वेज करी', 'মিক্সড সবজি কারি' ),
				'short' => self::t( '季節の野菜をたっぷり使ったやさしい味わいのカレー。', 'A gentle curry with plenty of seasonal vegetables.', 'كاري خفيف بالكثير من خضار الموسم.', 'موسمی سبزیوں سے بھرپور ہلکا سالن۔', 'मौसमी सब्ज़ियों से भरपूर हल्की करी।', 'মৌসুমি সবজিতে ভরা হালকা কারি।' ),
			],
			[
				'sku' => 'D9105', 'slug' => 'dal-fry', 'cat' => 'curry', 'image' => 'dal-fry', 'original' => 'Dal Tadka', 'spice' => 1, 'price' => 980, 'tags' => [ 'vegetarian', 'vegan' ], 'ingredients' => [ 'lentils' ], 'allergens' => [ [], [] ], 'branches' => $all,
				'name'  => self::t( 'ダルフライ', 'Dal fry', 'دال مقلية', 'دال فرائی', 'दाल फ्राई', 'ডাল ফ্রাই' ),
				'short' => self::t( 'レンズ豆をクミンとにんにくで香ばしく仕上げた豆カレー。', 'Lentils finished with cumin and garlic.', 'عدس مطهو بالكمون والثوم.', 'زیرے اور لہسن کے تڑکے والی دال۔', 'जीरे और लहसुन के तड़के वाली दाल।', 'জিরা ও রসুনের ফোড়ন দেওয়া ডাল।' ),
			],
			[
				'sku' => 'D9106', 'slug' => 'chicken-biryani', 'cat' => 'biryani-rice', 'image' => 'chicken-biryani', 'original' => 'Murgh Biryani', 'spice' => 2, 'price' => 1380, 'featured' => true, 'tags' => [], 'ingredients' => [ 'chicken', 'basmati', 'dairy' ], 'allergens' => [ [ 'milk', 'chicken' ], [ 'cashew_nut', 'almond' ] ], 'branches' => [ 'DKH' => null, 'DNB' => null, 'DKY' => 1480 ],
				'name'  => self::t( 'チキンビリヤニ', 'Chicken biryani', 'برياني الدجاج', 'چکن بریانی', 'चिकन बिरयानी', 'চিকেন বিরিয়ানি' ),
				'short' => self::t( 'スパイスでマリネした鶏肉とバスマティライスを重ねて炊き上げました。', 'Spiced chicken layered with basmati rice and steamed.', 'دجاج متبل بطبقات من أرز البسمتي مطهو على البخار.', 'مصالحے دار چکن اور باسمتی چاول کی تہیں، دم پر پکائی گئیں۔', 'मसालेदार चिकन और बासमती चावल की परतें, दम पर पकाई गईं।', 'মসলাদার মুরগি ও বাসমতি চালের স্তর, দমে রান্না।' ),
			],
			[
				'sku' => 'D9107', 'slug' => 'mutton-biryani', 'cat' => 'biryani-rice', 'image' => 'mutton-biryani', 'original' => 'Gosht Biryani', 'spice' => 3, 'price' => 1580, 'tags' => [], 'ingredients' => [ 'mutton', 'basmati', 'dairy' ], 'allergens' => [ [ 'milk' ], [ 'cashew_nut', 'almond' ] ], 'branches' => $all, 'sold_out' => 'DNB',
				'name'  => self::t( 'マトンビリヤニ', 'Mutton biryani', 'برياني لحم الضأن', 'مٹن بریانی', 'मटन बिरयानी', 'মাটন বিরিয়ানি' ),
				'short' => self::t( '柔らかく煮たマトンと香り高いバスマティライスの炊き込みご飯。', 'Tender mutton and fragrant basmati rice cooked together.', 'لحم ضأن طري مع أرز بسمتي عطري.', 'نرم مٹن اور خوشبودار باسمتی چاول ایک ساتھ پکے ہوئے۔', 'नरम मटन और खुशबूदार बासमती चावल साथ पकाए गए।', 'নরম মাটন ও সুগন্ধি বাসমতি চাল একসাথে রান্না।' ),
			],
			[
				'sku' => 'D9108', 'slug' => 'vegetable-biryani', 'cat' => 'biryani-rice', 'image' => 'vegetable-biryani', 'original' => 'Sabzi Biryani', 'spice' => 2, 'price' => 1180, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'vegetables', 'basmati', 'dairy' ], 'allergens' => [ [ 'milk', 'cashew_nut', 'almond' ], [ 'pistachio' ] ], 'branches' => $all,
				'name'  => self::t( '野菜ビリヤニ', 'Vegetable biryani', 'برياني الخضار', 'سبزی بریانی', 'वेज बिरयानी', 'সবজি বিরিয়ানি' ),
				'short' => self::t( '野菜とナッツの香りを楽しむビリヤニ。', 'Biryani with vegetables and a hint of nuts.', 'برياني بالخضار ولمسة من المكسرات.', 'سبزیوں اور میووں کی خوشبو والی بریانی۔', 'सब्ज़ियों और मेवों की खुशबू वाली बिरयानी।', 'সবজি ও বাদামের সুগন্ধি বিরিয়ানি।' ),
			],
			[
				'sku' => 'D9109', 'slug' => 'saffron-rice', 'cat' => 'biryani-rice', 'image' => 'basmati-saffron-rice', 'original' => 'Zafrani Chawal', 'spice' => 0, 'price' => 480, 'tags' => [ 'vegetarian', 'vegan' ], 'ingredients' => [ 'basmati' ], 'allergens' => [ [], [] ], 'branches' => $all,
				'name'  => self::t( 'サフランライス', 'Saffron rice', 'أرز بالزعفران', 'زعفرانی چاول', 'केसर चावल', 'জাফরান ভাত' ),
				'short' => self::t( 'サフランで色づけたバスマティライス。カレーと一緒にどうぞ。', 'Basmati rice coloured with saffron, ideal with any curry.', 'أرز بسمتي ملون بالزعفران، مثالي مع أي كاري.', 'زعفران سے رنگے باسمتی چاول، ہر سالن کے ساتھ بہترین۔', 'केसर से रंगे बासमती चावल, हर करी के साथ बढ़िया।', 'জাফরানে রাঙানো বাসমতি ভাত, যেকোনো কারির সাথে চমৎকার।' ),
			],
			[
				'sku' => 'D9110', 'slug' => 'tandoori-chicken', 'cat' => 'grill-kebab', 'image' => 'tandoor-chicken', 'original' => 'Tandoori Murgh', 'spice' => 2, 'price' => 1280, 'featured' => true, 'tags' => [], 'ingredients' => [ 'chicken', 'dairy' ], 'allergens' => [ [ 'milk', 'chicken' ], [] ], 'branches' => [ 'DKH' => null, 'DNB' => 1380, 'DKY' => null ],
				'name'  => self::t( 'タンドリーチキン', 'Tandoori chicken', 'دجاج تندوري', 'تندوری چکن', 'तंदूरी चिकन', 'তন্দুরি চিকেন' ),
				'short' => self::t( 'ヨーグルトとスパイスに漬け込み、タンドール窯で焼き上げた骨付きチキン。', 'Bone-in chicken marinated in yoghurt and spices, roasted in the tandoor.', 'دجاج بالعظم متبل باللبن والتوابل ومشوي في التنور.', 'دہی اور مصالحوں میں میرینیٹ ہڈی والا چکن، تندور میں بھنا ہوا۔', 'दही और मसालों में मैरिनेट हड्डी वाला चिकन, तंदूर में भुना।', 'দই ও মসলায় মাখানো হাড়সহ মুরগি, তন্দুরে ঝলসানো।' ),
			],
			[
				'sku' => 'D9111', 'slug' => 'chicken-tikka', 'cat' => 'grill-kebab', 'image' => 'chicken-tikka', 'original' => 'Murgh Tikka', 'spice' => 1, 'price' => 980, 'tags' => [], 'ingredients' => [ 'chicken', 'dairy' ], 'allergens' => [ [ 'milk', 'chicken' ], [] ], 'branches' => $all,
				'name'  => self::t( 'チキンティッカ', 'Chicken tikka', 'تكا الدجاج', 'چکن تکہ', 'चिकन टिक्का', 'চিকেন টিক্কা' ),
				'short' => self::t( '一口大の鶏もも肉をスパイスでマリネして香ばしく焼きました。', 'Bite-sized chicken thigh, spiced and chargrilled.', 'قطع صغيرة من فخذ الدجاج متبلة ومشوية على الفحم.', 'چھوٹے ٹکڑوں میں چکن ران، مصالحے لگا کر کوئلوں پر بھنی۔', 'छोटे टुकड़ों में चिकन जांघ, मसाले लगाकर कोयले पर सेंकी।', 'ছোট টুকরো মুরগির রান, মসলা মাখিয়ে কয়লায় ঝলসানো।' ),
			],
			[
				'sku' => 'D9112', 'slug' => 'mutton-seekh-kebab', 'cat' => 'grill-kebab', 'image' => 'mutton-seekh-kebab', 'original' => 'Seekh Kebab', 'spice' => 2, 'price' => 1180, 'tags' => [], 'ingredients' => [ 'mutton' ], 'allergens' => [ [], [ 'egg' ] ], 'branches' => $all,
				'name'  => self::t( 'マトンシークケバブ', 'Mutton seekh kebab', 'كباب سيخ لحم الضأن', 'مٹن سیخ کباب', 'मटन सीख कबाब', 'মাটন সিক কাবাব' ),
				'short' => self::t( '香味野菜を練り込んだマトンのひき肉を串に巻いて焼き上げました。', 'Minced mutton with fresh herbs, shaped on skewers and grilled.', 'لحم ضأن مفروم بالأعشاب الطازجة، مشكل على أسياخ ومشوي.', 'تازہ ہری بوٹیوں کے ساتھ مٹن کا قیمہ، سیخوں پر گرل کیا گیا۔', 'ताज़ी हर्ब्स के साथ मटन कीमा, सीखों पर ग्रिल किया गया।', 'তাজা ভেষজসহ মাটনের কিমা, শিকে গ্রিল করা।' ),
			],
			[
				'sku' => 'D9113', 'slug' => 'tandoori-prawns', 'cat' => 'grill-kebab', 'image' => 'tandoor-prawns', 'original' => 'Tandoori Jhinga', 'spice' => 2, 'price' => 1680, 'tags' => [], 'ingredients' => [ 'prawn', 'dairy' ], 'allergens' => [ [ 'shrimp', 'milk' ], [ 'crab' ] ], 'branches' => [ 'DKH' => null, 'DNB' => null ],
				'name'  => self::t( 'タンドリープラウン', 'Tandoori prawns', 'روبيان تندوري', 'تندوری جھینگے', 'तंदूरी झींगे', 'তন্দুরি চিংড়ি' ),
				'short' => self::t( '大ぶりの海老をスパイスに漬け込み、タンドールで焼きました。', 'Large prawns marinated in spices and roasted in the tandoor.', 'روبيان كبير متبل بالتوابل ومشوي في التنور.', 'بڑے جھینگے مصالحوں میں میرینیٹ کر کے تندور میں بھنے۔', 'बड़े झींगे मसालों में मैरिनेट करके तंदूर में भुने।', 'বড় চিংড়ি মসলায় মাখিয়ে তন্দুরে ঝলসানো।' ),
			],
			[
				'sku' => 'D9114', 'slug' => 'mixed-grill-platter', 'cat' => 'grill-kebab', 'image' => 'grill-assorted-platter', 'original' => '', 'spice' => 2, 'price' => 3980, 'advance' => 24, 'tags' => [], 'ingredients' => [ 'chicken', 'mutton', 'prawn', 'dairy' ], 'allergens' => [ [ 'milk', 'chicken', 'shrimp' ], [ 'egg', 'crab' ] ], 'branches' => [ 'DKH' => null, 'DNB' => null ],
				'name'  => self::t( 'グリル盛り合わせ', 'Mixed grill platter', 'طبق المشاوي المشكلة', 'مکس گرل پلیٹر', 'मिक्स ग्रिल प्लैटर', 'মিক্সড গ্রিল প্ল্যাটার' ),
				'short' => self::t( 'タンドリーチキン、ティッカ、シークケバブ、海老を盛り合わせた大皿。', 'Tandoori chicken, tikka, seekh kebab and prawns on one platter.', 'دجاج تندوري وتكا وكباب سيخ وروبيان في طبق واحد.', 'تندوری چکن، تکہ، سیخ کباب اور جھینگے ایک ہی پلیٹر میں۔', 'तंदूरी चिकन, टिक्का, सीख कबाब और झींगे एक ही प्लैटर में।', 'তন্দুরি চিকেন, টিক্কা, সিক কাবাব ও চিংড়ি এক প্ল্যাটারে।' ),
			],
			[
				'sku' => 'D9115', 'slug' => 'plain-naan', 'cat' => 'naan-bread', 'image' => 'plain-naan', 'original' => '', 'spice' => 0, 'price' => 380, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'wheat', 'dairy', 'egg' ], 'allergens' => [ [ 'wheat', 'milk', 'egg' ], [ 'sesame' ] ], 'branches' => $all,
				'name'  => self::t( 'プレーンナン', 'Plain naan', 'خبز نان سادة', 'سادہ نان', 'प्लेन नान', 'প্লেইন নান' ),
				'short' => self::t( 'タンドールで焼き上げる、もっちりとしたナン。', 'Soft, chewy naan baked in the tandoor.', 'خبز نان طري مخبوز في التنور.', 'تندور میں پکا نرم نان۔', 'तंदूर में सेंका नरम नान।', 'তন্দুরে সেঁকা নরম নান।' ),
			],
			[
				'sku' => 'D9116', 'slug' => 'garlic-naan', 'cat' => 'naan-bread', 'image' => 'garlic-naan', 'original' => '', 'spice' => 0, 'price' => 480, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'wheat', 'dairy', 'egg' ], 'allergens' => [ [ 'wheat', 'milk', 'egg' ], [ 'sesame' ] ], 'branches' => $all,
				'name'  => self::t( 'ガーリックナン', 'Garlic naan', 'نان بالثوم', 'لہسن نان', 'गार्लिक नान', 'গার্লিক নান' ),
				'short' => self::t( '刻みにんにくとバターをのせて焼いた香ばしいナン。', 'Naan topped with chopped garlic and butter.', 'نان مغطى بالثوم المفروم والزبدة.', 'کٹے لہسن اور مکھن والا نان۔', 'कटे लहसुन और मक्खन वाला नान।', 'কুচানো রসুন ও মাখন দেওয়া নান।' ),
			],
			[
				'sku' => 'D9117', 'slug' => 'cheese-naan', 'cat' => 'naan-bread', 'image' => 'cheese-naan', 'original' => '', 'spice' => 0, 'price' => 680, 'featured' => true, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'wheat', 'dairy', 'egg' ], 'allergens' => [ [ 'wheat', 'milk', 'egg' ], [] ], 'branches' => $all,
				'name'  => self::t( 'チーズナン', 'Cheese naan', 'نان بالجبن', 'پنیر نان', 'चीज़ नान', 'চিজ নান' ),
				'short' => self::t( 'とろけるチーズをたっぷり包んで焼いたナン。', 'Naan filled with plenty of melting cheese.', 'نان محشو بالكثير من الجبن الذائب.', 'پگھلتے پنیر سے بھرا نان۔', 'पिघलते चीज़ से भरा नान।', 'গলানো চিজে ভরা নান।' ),
			],
			[
				'sku' => 'D9118', 'slug' => 'vegetable-samosa', 'cat' => 'sides', 'image' => 'veg-samosa', 'original' => '', 'spice' => 1, 'price' => 580, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'wheat', 'potato', 'vegetables' ], 'allergens' => [ [ 'wheat' ], [ 'soybean', 'sesame' ] ], 'branches' => $all,
				'name'  => self::t( 'ベジサモサ', 'Vegetable samosa', 'سمبوسة الخضار', 'سبزی سموسہ', 'वेज समोसा', 'সবজি সমুচা' ),
				'short' => self::t( 'スパイスで味付けしたじゃがいもとグリーンピースを包んで揚げました（2個）。', 'Crisp pastries filled with spiced potato and peas (2 pieces).', 'معجنات مقرمشة محشوة بالبطاطس والبازلاء المتبلة (قطعتان).', 'مصالحے دار آلو اور مٹر سے بھرے خستہ سموسے (2 عدد)۔', 'मसालेदार आलू और मटर से भरे कुरकुरे समोसे (2 पीस)।', 'মসলাদার আলু ও মটরশুঁটি ভরা মুচমুচে সমুচা (২টি)।' ),
			],
			[
				'sku' => 'D9119', 'slug' => 'chicken-lollipop', 'cat' => 'sides', 'image' => 'chicken-lollipop', 'original' => '', 'spice' => 2, 'price' => 880, 'tags' => [], 'ingredients' => [ 'chicken' ], 'allergens' => null, 'branches' => $all,
				'name'  => self::t( 'チキンロリポップ', 'Chicken lollipop', 'مصاصة الدجاج', 'چکن لالی پاپ', 'चिकन लॉलीपॉप', 'চিকেন ললিপপ' ),
				'short' => self::t( '手羽元をスパイシーな衣で揚げた人気の前菜。', 'Spicy battered chicken drumettes, a favourite starter.', 'أجنحة دجاج مقلية بخليط حار، مقبلات مفضلة.', 'مصالحے دار گھول میں تلے چکن ونگز، پسندیدہ اسٹارٹر۔', 'मसालेदार घोल में तले चिकन विंग्स, पसंदीदा स्टार्टर।', 'মসলাদার ব্যাটারে ভাজা চিকেন উইংস, জনপ্রিয় স্টার্টার।' ),
			],
			[
				'sku' => 'D9120', 'slug' => 'green-salad', 'cat' => 'sides', 'image' => 'green-salad', 'original' => '', 'spice' => 0, 'price' => 580, 'tags' => [ 'vegetarian', 'vegan' ], 'ingredients' => [ 'vegetables' ], 'allergens' => [ [], [ 'sesame' ] ], 'branches' => $all,
				'name'  => self::t( 'グリーンサラダ', 'Green salad', 'سلطة خضراء', 'گرین سلاد', 'ग्रीन सलाद', 'গ্রিন সালাদ' ),
				'short' => self::t( '新鮮な野菜にレモンとスパイスのドレッシング。', 'Fresh greens with a lemon and spice dressing.', 'خضار طازجة مع صلصة الليمون والتوابل.', 'تازہ سبزیاں لیموں اور مصالحے کی ڈریسنگ کے ساتھ۔', 'ताज़ी हरी सब्ज़ियाँ नींबू और मसाले की ड्रेसिंग के साथ।', 'লেবু ও মসলার ড্রেসিংসহ তাজা সবজি।' ),
			],
			[
				'sku' => 'D9121', 'slug' => 'beef-soup', 'cat' => 'sides', 'image' => 'beef-soup', 'original' => '', 'spice' => 1, 'price' => 780, 'tags' => [], 'ingredients' => [ 'beef', 'vegetables' ], 'allergens' => [ [ 'beef' ], [ 'wheat' ] ], 'branches' => [ 'DNB' => null, 'DKY' => null ],
				'name'  => self::t( 'ビーフスープ', 'Beef soup', 'شوربة اللحم البقري', 'بیف سوپ', 'बीफ़ सूप', 'বিফ স্যুপ' ),
				'short' => self::t( '牛肉と野菜を煮込んだ、スパイス香る温かいスープ。', 'A warming spiced soup of beef and vegetables.', 'شوربة دافئة متبلة باللحم البقري والخضار.', 'گائے کے گوشت اور سبزیوں کا گرم مصالحے دار سوپ۔', 'बीफ़ और सब्ज़ियों का गरम मसालेदार सूप।', 'গরুর মাংস ও সবজির গরম মসলাদার স্যুপ।' ),
			],
			[
				'sku' => 'D9122', 'slug' => 'matcha-ice-cream', 'cat' => 'dessert', 'image' => 'matcha-ice-cream', 'original' => '', 'spice' => 0, 'price' => 480, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'dairy', 'egg' ], 'allergens' => [ [ 'milk', 'egg' ], [] ], 'branches' => $all,
				'name'  => self::t( '抹茶アイスクリーム', 'Matcha ice cream', 'آيس كريم الماتشا', 'ماچا آئس کریم', 'माचा आइसक्रीम', 'মাচা আইসক্রিম' ),
				'short' => self::t( '香り高い抹茶の濃厚なアイスクリーム。', 'Rich ice cream with fragrant matcha.', 'آيس كريم غني بالماتشا العطري.', 'خوشبودار ماچا والی گاڑھی آئس کریم۔', 'खुशबूदार माचा वाली गाढ़ी आइसक्रीम।', 'সুগন্ধি মাচার ঘন আইসক্রিম।' ),
			],
			[
				'sku' => 'D9123', 'slug' => 'vanilla-ice-cream', 'cat' => 'dessert', 'image' => 'vanilla-ice-cream', 'original' => '', 'spice' => 0, 'price' => 420, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'dairy', 'egg' ], 'allergens' => [ [ 'milk', 'egg' ], [] ], 'branches' => $all,
				'name'  => self::t( 'バニラアイスクリーム', 'Vanilla ice cream', 'آيس كريم الفانيليا', 'ونیلا آئس کریم', 'वनीला आइसक्रीम', 'ভ্যানিলা আইসক্রিম' ),
				'short' => self::t( 'なめらかな口当たりのバニラアイス。辛い料理の後にどうぞ。', 'Smooth vanilla ice cream, perfect after a spicy meal.', 'آيس كريم فانيليا ناعم، مثالي بعد وجبة حارة.', 'ملائم ونیلا آئس کریم، مصالحے دار کھانے کے بعد بہترین۔', 'मुलायम वनीला आइसक्रीम, तीखे खाने के बाद बढ़िया।', 'মসৃণ ভ্যানিলা আইসক্রিম, ঝাল খাবারের পরে দারুণ।' ),
			],
			[
				'sku' => 'D9124', 'slug' => 'vanilla-matcha-ice-cream', 'cat' => 'dessert', 'image' => 'vanilla-matcha-ice-cream', 'original' => '', 'spice' => 0, 'price' => 580, 'tags' => [ 'vegetarian' ], 'ingredients' => [ 'dairy', 'egg' ], 'allergens' => [ [ 'milk', 'egg' ], [] ], 'branches' => $all, 'unavailable' => 'DKY',
				'name'  => self::t( 'バニラ＆抹茶アイス', 'Vanilla & matcha ice cream', 'آيس كريم الفانيليا والماتشا', 'ونیلا اور ماچا آئس کریم', 'वनीला और माचा आइसक्रीम', 'ভ্যানিলা ও মাচা আইসক্রিম' ),
				'short' => self::t( 'バニラと抹茶、二つの味を一皿で。', 'Vanilla and matcha, two flavours on one plate.', 'الفانيليا والماتشا، نكهتان في طبق واحد.', 'ونیلا اور ماچا، ایک پلیٹ میں دو ذائقے۔', 'वनीला और माचा, एक प्लेट में दो स्वाद।', 'ভ্যানিলা ও মাচা, এক প্লেটে দুই স্বাদ।' ),
			],
		];
	}

	/**
	 * DRAFT ar/ur/hi/bn names of the 29 seeded allergen reference terms (the Dietary migration ships ja/en
	 * only). Written by the demo seed only where a language is still missing, approved in development
	 * data only and removed again by --reset; allergen names need native food-safety review (GATE-04).
	 *
	 * @return array<string, array{ar:string,ur:string,hi:string,bn:string}>
	 */
	public static function allergen_names(): array {
		$rows = [
			'shrimp'        => [ 'الروبيان', 'جھینگا', 'झींगा', 'চিংড়ি' ],
			'cashew_nut'    => [ 'الكاجو', 'کاجو', 'काजू', 'কাজুবাদাম' ],
			'crab'          => [ 'السلطعون', 'کیکڑا', 'केकड़ा', 'কাঁকড়া' ],
			'walnut'        => [ 'الجوز', 'اخروٹ', 'अखरोट', 'আখরোট' ],
			'wheat'         => [ 'القمح', 'گندم', 'गेहूँ', 'গম' ],
			'buckwheat'     => [ 'الحنطة السوداء', 'کٹو', 'कुट्टू', 'বাকউইট' ],
			'egg'           => [ 'البيض', 'انڈا', 'अंडा', 'ডিম' ],
			'milk'          => [ 'الحليب', 'دودھ', 'दूध', 'দুধ' ],
			'peanut'        => [ 'الفول السوداني', 'مونگ پھلی', 'मूंगफली', 'চিনাবাদাম' ],
			'almond'        => [ 'اللوز', 'بادام', 'बादाम', 'কাঠবাদাম' ],
			'abalone'       => [ 'أذن البحر', 'ابالون', 'एबालोन', 'অ্যাবালোন' ],
			'squid'         => [ 'الحبار', 'اسکوئڈ', 'स्क्विड', 'স্কুইড' ],
			'salmon_roe'    => [ 'بطارخ السلمون', 'سالمن کے انڈے', 'सैल्मन के अंडे', 'স্যামন মাছের ডিম' ],
			'orange'        => [ 'البرتقال', 'مالٹا', 'संतरा', 'কমলা' ],
			'kiwifruit'     => [ 'الكيوي', 'کیوی', 'कीवी', 'কিউই' ],
			'beef'          => [ 'لحم البقر', 'گائے کا گوشت', 'बीफ़', 'গরুর মাংস' ],
			'sesame'        => [ 'السمسم', 'تل', 'तिल', 'তিল' ],
			'salmon'        => [ 'السلمون', 'سالمن', 'सैल्मन', 'স্যামন' ],
			'mackerel'      => [ 'الماكريل', 'میکریل', 'मैकेरल', 'ম্যাকারেল' ],
			'soybean'       => [ 'فول الصويا', 'سویابین', 'सोयाबीन', 'সয়াবিন' ],
			'chicken'       => [ 'الدجاج', 'مرغی', 'चिकन', 'মুরগি' ],
			'banana'        => [ 'الموز', 'کیلا', 'केला', 'কলা' ],
			'pistachio'     => [ 'الفستق', 'پستہ', 'पिस्ता', 'পেস্তা' ],
			'pork'          => [ 'لحم الخنزير', 'سور کا گوشت', 'पोर्क', 'শূকরের মাংস' ],
			'macadamia_nut' => [ 'المكاديميا', 'میکاڈیمیا', 'मैकाडामिया', 'ম্যাকাডামিয়া' ],
			'peach'         => [ 'الخوخ', 'آڑو', 'आड़ू', 'পীচ' ],
			'yam'           => [ 'اليام', 'رتالو', 'रतालू', 'মেটে আলু' ],
			'apple'         => [ 'التفاح', 'سیب', 'सेब', 'আপেল' ],
			'gelatin'       => [ 'الجيلاتين', 'جیلاٹن', 'जिलेटिन', 'জেলাটিন' ],
		];
		return array_map( static fn( array $r ) => [ 'ar' => $r[0], 'ur' => $r[1], 'hi' => $r[2], 'bn' => $r[3] ], $rows );
	}
}
