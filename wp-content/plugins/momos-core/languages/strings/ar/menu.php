<?php
/**
 * Menu module customer strings — Arabic.
 * DRAFT – needs native review (DEC-027, IMPL-003). Not for production until GATE-04 sign-off.
 */

defined( 'ABSPATH' ) || exit;

return [
	'price.tax_incl'                      => '(شامل الضريبة)',
	'menu.title'                          => 'قائمة الطعام',
	'menu.branch_select.label'            => 'عرض الأسعار في',
	'menu.branch_select.all'              => 'جميع الفروع — الأسعار المرجعية',
	'menu.prices_may_differ'              => 'قد تختلف الأسعار حسب الفرع',
	'menu.price_all_branches'             => '{price} في جميع الفروع',
	'menu.price_at_branch'                => '{price} في {branch}',
	'menu.change_branch'                  => 'تغيير',
	'menu.sold_out'                       => 'نفدت الكمية اليوم',
	'menu.unavailable'                    => 'غير متوفر في هذا الفرع حاليًا',
	'menu.available_today'                => 'متوفر اليوم',
	'menu.not_available'                  => 'غير متوفر حاليًا',
	'menu.advance_order'                  => 'يُطلب قبل {hours} ساعة على الأقل',
	'menu.advance_order.notice'           => 'يجب طلب هذا الطبق قبل {hours} ساعة على الأقل. يُرجى ذكره في الطلبات الخاصة عند طلب حجز طاولة.',
	'menu.advance_order.mention'          => 'اذكره في طلب حجز الطاولة',
	'menu.filters.heading'                => 'التصفية',
	'menu.filters.toggle'                 => 'التصفية ({n})',
	'menu.filters.empty'                  => 'لا توجد أطباق تطابق هذه الخيارات.',
	'menu.filters.clear'                  => 'مسح التصفية',
	'menu.filters.count.one'              => 'يُعرض طبق واحد ({n})',
	'menu.filters.count.other'            => 'يُعرض {n} من الأطباق',
	'menu.filter.diet'                    => 'التفضيلات الغذائية',
	'menu.filter.max_spice'               => 'الحد الأقصى لدرجة الحدة',
	'menu.filter.max_spice.any'           => 'أي درجة',
	'menu.filter.exclude'                 => 'إخفاء الأطباق التي قد تحتوي على',
	'menu.filter.allergen_note'           => 'استنادًا إلى معلومات مقدمة طوعًا. يُرجى دائمًا إبلاغ الموظفين بأي حساسية.',
	'menu.category.branch_empty'          => 'لا يقدّم هذا الفرع أطباقًا من هذه الفئة.',
	'menu.category.all_branches_link'     => 'عرض جميع الفروع',
	'menu.available_at'                   => 'متوفر في',
	'menu.related'                        => 'أطباق أخرى من هذه الفئة',
	'menu.table.branch'                   => 'الفرع',
	'menu.table.price'                    => 'السعر',
	'menu.table.availability'             => 'التوفر',
	'menu.spice'                          => 'درجة الحدة: {label}',
	'spice.0'                             => 'غير حار',
	'spice.1'                             => 'خفيف',
	'spice.2'                             => 'متوسط',
	'spice.3'                             => 'حار',
	'availability_reason.sold_out_today'  => 'نفدت الكمية اليوم',
	'availability_reason.seasonal'        => 'موسمي',
	'availability_reason.ingredient_unavailable' => 'أحد المكونات غير متوفر',
	'availability_reason.advance_order_only'     => 'بالطلب المسبق فقط',
	'media.illustration'                  => 'الصورة للتوضيح فقط',
	// FR-MENU-005(a) audit label (SCR-A16).
	'admin.audit.action.menu_category.reordered' => 'تغيير ترتيب الفئات',
];
