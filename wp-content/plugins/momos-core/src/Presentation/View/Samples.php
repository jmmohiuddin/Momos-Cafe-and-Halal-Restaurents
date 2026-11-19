<?php
declare(strict_types=1);

namespace Momos\Presentation\View;

use Momos\Presentation\Format\DateFormatter;
use Momos\Presentation\Format\PriceFormatter;
use Momos\Presentation\Helpers\Bdi;

/**
 * SAMPLE data for the living style guide (staff-only, noindex; DEC-047/UI-CHG-012). Never used on
 * customer templates. Kept in momos-core so the theme template scan (no prices/phones/times/claims in
 * theme files, Doc 09 §2.9.4) stays clean. Names are neutral placeholders, not client data.
 */
final class Samples {
	private const TEXT = [
		'dish'          => [ 'ja' => 'チキン・ビリヤニ', 'en' => 'Chicken biryani', 'ar' => 'برياني الدجاج', 'ur' => 'چکن بریانی', 'hi' => 'चिकन बिरयानी', 'bn' => 'চিকেন বিরিয়ানি' ],
		'dish_desc'     => [ 'ja' => 'スパイスで炊き上げたバスマティライスと鶏肉。サンプルの説明文です。', 'en' => 'Basmati rice slow-cooked with spiced chicken. Sample description text.', 'ar' => 'أرز بسمتي مطهو ببطء مع الدجاج المتبل. نص وصف تجريبي.', 'ur' => 'مصالحے دار چکن کے ساتھ آہستہ پکے باسمتی چاول۔ نمونہ متن۔', 'hi' => 'मसालेदार चिकन के साथ धीमी आँच पर पके बासमती चावल। नमूना विवरण।', 'bn' => 'মসলাদার মুরগির সাথে ধীরে রান্না বাসমতি চাল। নমুনা বিবরণ।' ],
		'category'      => [ 'ja' => 'ビリヤニ・ライス', 'en' => 'Biryani & rice', 'ar' => 'برياني وأرز', 'ur' => 'بریانی اور چاول', 'hi' => 'बिरयानी और चावल', 'bn' => 'বিরিয়ানি ও ভাত' ],
		'branch'        => [ 'ja' => 'サンプル店', 'en' => 'Sample branch', 'ar' => 'فرع تجريبي', 'ur' => 'نمونہ برانچ', 'hi' => 'नमूना शाखा', 'bn' => 'নমুনা শাখা' ],
		'area'          => [ 'ja' => '最寄り駅から徒歩3分', 'en' => '3 min walk from the station', 'ar' => '3 دقائق سيرًا من المحطة', 'ur' => 'اسٹیشن سے 3 منٹ پیدل', 'hi' => 'स्टेशन से 3 मिनट पैदल', 'bn' => 'স্টেশন থেকে ৩ মিনিট হাঁটা' ],
		'offer'         => [ 'ja' => '平日ランチセット', 'en' => 'Weekday lunch set', 'ar' => 'وجبة غداء أيام الأسبوع', 'ur' => 'ہفتے کے دنوں کا لنچ سیٹ', 'hi' => 'सप्ताह के दिनों का लंच सेट', 'bn' => 'সপ্তাহের দিনের লাঞ্চ সেট' ],
		'offer_sum'     => [ 'ja' => 'カレーとナン、ドリンクのセット（サンプル）。', 'en' => 'Curry, naan and a drink (sample).', 'ar' => 'كاري وخبز نان ومشروب (نموذج).', 'ur' => 'سالن، نان اور ایک مشروب (نمونہ)۔', 'hi' => 'करी, नान और एक पेय (नमूना)।', 'bn' => 'কারি, নান ও একটি পানীয় (নমুনা)।' ],
		'offer_cond'    => [ 'ja' => "平日のみ。他の特典との併用不可。\nご来店時にお伝えください。\n一部店舗のみ。\n数量限定。", 'en' => "Weekdays only. Cannot be combined with other offers.\nMention the offer when you arrive.\nParticipating branches only.\nWhile stocks last.", 'ar' => "أيام الأسبوع فقط. لا يمكن دمجه مع عروض أخرى.\nاذكر العرض عند وصولك.\nفي الفروع المشاركة فقط.\nحتى نفاد الكمية.", 'ur' => "صرف ہفتے کے دنوں میں۔ دیگر آفرز کے ساتھ نہیں۔\nآمد پر آفر کا ذکر کریں۔\nصرف شریک برانچوں میں۔\nاسٹاک ختم ہونے تک۔", 'hi' => "केवल सप्ताह के दिन। अन्य ऑफ़र के साथ नहीं।\nपहुँचने पर ऑफ़र बताएँ।\nकेवल भाग लेने वाली शाखाएँ।\nस्टॉक रहने तक।", 'bn' => "শুধু সপ্তাহের দিনে। অন্য অফারের সাথে নয়।\nপৌঁছে অফারটি জানান।\nশুধু অংশগ্রহণকারী শাখায়।\nস্টক থাকা পর্যন্ত।" ],
		'notice'        => [ 'ja' => '年末年始は営業時間が変わります。各店舗のページでご確認ください。', 'en' => 'Opening hours change over the New Year holidays. Please check each branch page.', 'ar' => 'تتغير ساعات العمل خلال عطلة رأس السنة. يرجى مراجعة صفحة كل فرع.', 'ur' => 'نئے سال کی تعطیلات میں اوقات کار بدل جاتے ہیں۔ براہ کرم ہر برانچ کا صفحہ دیکھیں۔', 'hi' => 'नववर्ष की छुट्टियों में समय बदलता है। कृपया हर शाखा का पेज देखें।', 'bn' => 'নববর্ষের ছুটিতে সময় বদলায়। অনুগ্রহ করে প্রতিটি শাখার পৃষ্ঠা দেখুন।' ],
		'label_name'    => [ 'ja' => 'お名前', 'en' => 'Your name', 'ar' => 'الاسم', 'ur' => 'آپ کا نام', 'hi' => 'आपका नाम', 'bn' => 'আপনার নাম' ],
		'label_email'   => [ 'ja' => 'メールアドレス', 'en' => 'Email', 'ar' => 'البريد الإلكتروني', 'ur' => 'ای میل', 'hi' => 'ईमेल', 'bn' => 'ইমেইল' ],
		'label_phone'   => [ 'ja' => '電話番号', 'en' => 'Phone', 'ar' => 'الهاتف', 'ur' => 'فون', 'hi' => 'फ़ोन', 'bn' => 'ফোন' ],
		'label_message' => [ 'ja' => 'メッセージ', 'en' => 'Message', 'ar' => 'الرسالة', 'ur' => 'پیغام', 'hi' => 'संदेश', 'bn' => 'বার্তা' ],
		'statement'     => [ 'ja' => 'ハラールに関する情報は店舗ごとに表示しています。', 'en' => 'Halal information is shown for each branch.', 'ar' => 'تُعرض معلومات الحلال لكل فرع على حدة.', 'ur' => 'حلال سے متعلق معلومات ہر برانچ کے لیے الگ دکھائی جاتی ہیں۔', 'hi' => 'हलाल जानकारी हर शाखा के लिए अलग दिखाई जाती है।', 'bn' => 'হালাল তথ্য প্রতিটি শাখার জন্য আলাদাভাবে দেখানো হয়।' ],
	];

	public static function text( string $key, string $lang ): string {
		return self::TEXT[ $key ][ $lang ] ?? self::TEXT[ $key ]['en'] ?? '';
	}

	/** @return array<string,mixed> MenuQueryService::card() shape */
	public static function dish( string $lang, int $spice = 2, string $availability = 'sold_out' ): array {
		return [
			'id'                  => 0,
			'url'                 => '#sample-dish',
			'name'                => self::text( 'dish', $lang ),
			'original_name'       => 'ja' === $lang ? '' : 'Chicken Biryani',
			'short_description'   => self::text( 'dish_desc', $lang ),
			'image_id'            => 0,
			'image_illustrative'  => false,
			'price'               => 1400,
			'price_html'          => PriceFormatter::html( 1400, $lang ),
			'spice'               => $spice,
			'spice_label'         => momos_t( 'spice.' . $spice, [], $lang ),
			'tags'                => [],
			'availability'        => 'sold_out' === $availability ? [ 'code' => 'sold_out', 'label' => momos_t( 'menu.sold_out', [], $lang ), 'reason' => null, 'reason_label' => null ] : null,
			'advance_order_label' => momos_t( 'menu.advance_order', [ 'hours' => 24 ], $lang ),
			'data'                => [],
		];
	}

	/** @return array<string,mixed> Cards::branch() shape */
	public static function branch( string $lang, bool $temp_closed = false ): array {
		$open = $temp_closed
			? [ 'state' => 'temp_closed', 'html' => esc_html( momos_t( 'branch.temp_closed_nodate', [], $lang ) ) ]
			: [ 'state' => 'open', 'html' => Bdi::t( 'branch.open_now', [ 'time' => '22:00', 'lo' => '21:30' ], [ 'time', 'lo' ], $lang ) ];
		return [
			'id'          => 0,
			'code'        => 'SMP',
			'name'        => self::text( 'branch', $lang ),
			'concept'     => '',
			'area'        => self::text( 'area', $lang ),
			'address'     => 'ja' === $lang ? '' : '1-2-3 Sample-cho, Osaka',
			'address_ja'  => '大阪府大阪市サンプル町1-2-3',
			'phone'       => 'ja' === $lang ? '06-0000-0000' : '+81 6-0000-0000',
			'tel'         => 'tel:+81600000000',
			'url'         => '#sample-branch',
			'request_url' => $temp_closed ? '' : '#sample-request',
			'directions'  => 'https://www.google.com/maps/dir/?api=1&destination=34.6937,135.5023',
			'temp_closed' => $temp_closed,
			'open'        => $open,
		];
	}

	/** @return array<string,mixed> Cards::offer() shape */
	public static function offer( string $lang ): array {
		$start = new \DateTimeImmutable( '2026-12-01 00:00', new \DateTimeZone( 'Asia/Tokyo' ) );
		$end   = new \DateTimeImmutable( '2026-12-31 00:00', new \DateTimeZone( 'Asia/Tokyo' ) );
		return [
			'id'           => 0,
			'title'        => self::text( 'offer', $lang ),
			'summary'      => self::text( 'offer_sum', $lang ),
			'conditions'   => self::text( 'offer_cond', $lang ),
			'valid_html'   => Bdi::t( 'offers.valid', [ 'start' => DateFormatter::long( $start, $lang ), 'end' => DateFormatter::long( $end, $lang ) ], [ 'start', 'end' ], $lang ),
			'scope'        => [ momos_t( 'offers.scope.all', [], $lang ) ],
			'price_html'   => PriceFormatter::html( 1200, $lang ),
			'image_id'     => 0,
			'illustrative' => false,
			'cta'          => [ 'type' => 'request_table', 'url' => '#sample-request', 'label' => momos_t( 'cta.request_table', [], $lang ) ],
		];
	}

	/** @return array<string,mixed> ClaimResolver::branch_claim() shape (layout preview only). */
	public static function claim( string $lang, bool $certified = false ): array {
		return [
			'type'                => $certified ? 'certified' : 'no_claim',
			'statement'           => self::text( 'statement', $lang ),
			'certifier'           => $certified ? 'SAMPLE Certifier' : null,
			'scope_label'         => $certified ? momos_t( 'halal.scope.whole_kitchen', [], $lang ) : null,
			'number'              => $certified ? 'SAMPLE-0000' : null,
			'valid_until_display' => $certified ? DateFormatter::long( '2027-03-31', $lang ) : null,
			'mark_url'            => null,
			'alcohol_lines'       => [],
			'last_verified'       => momos_t( 'last_verified', [ 'date' => DateFormatter::long( '2026-11-01', $lang ) ], $lang ),
		];
	}

	/** @return array<string,mixed> AllergenPresenter::for_dish() shape */
	public static function allergens( string $lang ): array {
		$names = [
			'ja' => [ '小麦', '乳', '卵', 'えび', 'カシューナッツ' ],
			'en' => [ 'Wheat', 'Milk', 'Egg', 'Shrimp', 'Cashew nut' ],
			'ar' => [ 'القمح', 'الحليب', 'البيض', 'الروبيان', 'الكاجو' ],
			'ur' => [ 'گندم', 'دودھ', 'انڈا', 'جھینگا', 'کاجو' ],
			'hi' => [ 'गेहूँ', 'दूध', 'अंडा', 'झींगा', 'काजू' ],
			'bn' => [ 'গম', 'দুধ', 'ডিম', 'চিংড়ি', 'কাজু' ],
		][ $lang ] ?? [ 'Wheat', 'Milk', 'Egg', 'Shrimp', 'Cashew nut' ];
		$row   = static fn( string $name, string $state ) => [ 'code' => sanitize_key( $name ), 'term_id' => 0, 'name' => $name, 'state' => $state, 'state_label' => momos_t( 'allergen.state.' . $state, [], $lang ), 'icon_id' => 0 ];
		$sep   = momos_t( 'diet.list.separator', [], $lang );
		return [
			'complete'      => false,
			'summary'       => [ 'contains' => momos_t( 'allergen.summary.contains', [ 'list' => $names[0] . $sep . $names[1] ], $lang ), 'may_contain' => momos_t( 'allergen.summary.may_contain', [ 'list' => $names[2] ], $lang ) ],
			'banner'        => momos_t( 'allergen.banner.not_verified', [], $lang ),
			'legend'        => [ 'contains' => momos_t( 'allergen.state.contains', [], $lang ), 'may_contain' => momos_t( 'allergen.state.may_contain', [], $lang ), 'not_contained' => momos_t( 'allergen.state.not_contained', [], $lang ), 'not_verified' => momos_t( 'allergen.state.not_verified', [], $lang ) ],
			'groups'        => [
				[ 'key' => 'mandatory', 'label' => momos_t( 'allergen.group.mandatory', [], $lang ), 'rows' => [ $row( $names[0], 'contains' ), $row( $names[1], 'contains' ), $row( $names[2], 'may_contain' ), $row( $names[3], 'not_contained' ) ] ],
				[ 'key' => 'recommended', 'label' => momos_t( 'allergen.group.recommended', [], $lang ), 'rows' => [ $row( $names[4], 'not_verified' ) ] ],
			],
			'last_verified' => momos_t( 'allergen.last_verified', [ 'date' => DateFormatter::long( '2026-11-01', $lang ) ], $lang ),
			'disclaimers'   => [ momos_t( 'allergen.disclaimer.voluntary', [], $lang ), momos_t( 'allergen.tell_staff', [], $lang ) ],
			'labels'        => [ 'caption' => momos_t( 'allergen.table.caption', [], $lang ), 'allergen' => momos_t( 'allergen.table.allergen', [], $lang ), 'status' => momos_t( 'allergen.table.status', [], $lang ), 'toggle' => momos_t( 'allergen.toggle.relevant', [], $lang ) ],
		];
	}
}
