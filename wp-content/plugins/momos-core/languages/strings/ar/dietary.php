<?php
/**
 * Dietary module customer strings — Arabic.
 * DRAFT – needs native review (DEC-027, BR-DIET-05: claim-related wording must be human-reviewed before
 * the language is enabled). Not for production until GATE-04 sign-off.
 */

defined( 'ABSPATH' ) || exit;

return [
	'allergen.heading'               => 'مسببات الحساسية',
	'allergen.state.contains'        => 'يحتوي على',
	'allergen.state.may_contain'     => 'قد يحتوي على (تلامس عرضي)',
	'allergen.state.not_contained'   => 'لا يحتوي (تم التحقق)',
	'allergen.state.not_verified'    => 'لم يتم التحقق',
	'allergen.summary.contains'      => 'يحتوي على: {list}',
	'allergen.summary.may_contain'   => 'قد يحتوي على (تلامس عرضي): {list}',
	'allergen.banner.not_verified'   => 'لم يتم التحقق من معلومات مسببات الحساسية — يُرجى سؤال الموظفين.',
	'allergen.disclaimer.voluntary'  => 'تُقدَّم معلومات مسببات الحساسية طوعًا للاسترشاد. قد تتغير عند تغيّر الموردين أو الوصفات، وقد تختلف المكونات بين الفروع.',
	'allergen.cross_contact'         => 'يتعامل مطبخنا أيضًا مع مكونات أخرى وقد يشارك الأدوات وزيت القلي، لذا لا يمكننا ضمان خلو الأطباق من الكميات الضئيلة.',
	'allergen.tell_staff'            => 'إذا كانت لديك حساسية شديدة، يُرجى إبلاغ موظفينا عند وصولك وعند الطلب. سنخبرك بصراحة إذا لم نتمكن من تحضير طبق لك.',
	'allergen.last_verified'         => 'آخر تحقق من المعلومات في {date}.',
	'allergen.group.mandatory'       => 'المكونات المحددة (9 — إلزامية للأغذية المعبأة)',
	'allergen.group.recommended'     => 'العناصر الموصى بها (20)',
	'allergen.group.other'           => 'مكونات أخرى',
	'allergen.legend'                => 'دليل الرموز',
	'allergen.table.caption'         => 'معلومات مسببات الحساسية',
	'allergen.table.allergen'        => 'مسبب الحساسية',
	'allergen.table.status'          => 'الحالة',
	'allergen.toggle.relevant'       => 'عرض «يحتوي» و«قد يحتوي» فقط',
	'ingredients.heading'            => 'المكونات الرئيسية (ليست قائمة كاملة)',
	'last_verified'                  => 'آخر تحقق: {date}',
	'halal.heading'                  => 'معلومات الحلال',
	'halal.label.certified'          => 'حاصل على شهادة حلال',
	'halal.label.ingredients'        => 'مكونات حلال',
	'halal.not_applicable'           => 'لا ينطبق',
	'halal.mark_alt'                 => 'علامة شهادة الحلال الصادرة عن {certifier}',
	'halal.verify_link'              => 'التحقق لدى جهة الإصدار',
	'halal.certifier'                => 'جهة الإصدار',
	'halal.scope'                    => 'النطاق',
	'halal.cert_no'                  => 'رقم الشهادة',
	'halal.valid_until'              => 'صالحة حتى',
	'halal.scope.whole_kitchen'      => 'المطبخ بالكامل',
	'halal.scope.specific_menu'      => 'الأطباق المشار إليها',
	'halal.scope.specific_ingredients' => 'مكونات محددة',
	'alcohol.heading'                => 'الكحول',
	'diet.list.separator'            => '، ',
];
