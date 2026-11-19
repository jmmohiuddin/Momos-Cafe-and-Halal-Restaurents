<?php
/**
 * Role → capability grant table (TRD §2.7.2, authoritative). Consumed by Access\Roles\RoleInstaller.
 *
 * Values: role slugs that receive the capability. "Y(b)" grants are branch-limited at object level
 * by Access\MetaCapMapper (TRD §2.7.3); the branch-scoped roles are listed in 'scoped_roles'.
 * CAP-DCA (`momo_edit_dietary_claims`) is granted per user, never to a role.
 *
 * Additions made during implementation (IMPL register):
 *  - momos_translate / momo_approve_translations / momo_preview_languages replace the WPML
 *    translator designation (IMPL-002). Translators are the native-speaker reviewers (DEC-027).
 *  - momos_view_notification_log: notification log view (F-NOT-03).
 *  - momo_manage_redirects / momo_edit_seo_meta: SEO module (no TRD row; role sets match SEO spec).
 */

defined( 'ABSPATH' ) || exit;

$sa  = 'administrator';
$ho  = 'momo_head_office';
$mm  = 'momo_menu_manager';
$ce  = 'momo_content_editor';
$tr  = 'momo_translator';
$bm  = 'momo_branch_manager';
$rs  = 'momo_reservation_staff';
$all = [ $sa, $ho, $mm, $ce, $tr, $bm, $rs ];

$caps = [
	'read'         => $all,
	'upload_files' => [ $sa, $ho, $mm, $ce, $bm ],
];

foreach ( [ 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'manage_categories' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho, $ce ];
}
foreach ( [ 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho, $ce ];
}
$caps['momo_publish_legal_pages'] = [ $sa, $ho ];
$caps['edit_theme_options']       = [ $sa, $ho ];
foreach ( [ 'list_users', 'create_users', 'edit_users', 'promote_users' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho ];
}

// Branches.
foreach ( [ 'create_momo_branches', 'publish_momo_branches', 'delete_momo_branches', 'delete_published_momo_branches', 'delete_others_momo_branches', 'read_private_momo_branches', 'edit_private_momo_branches', 'delete_private_momo_branches' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho ];
}
foreach ( [ 'edit_momo_branches', 'edit_others_momo_branches', 'edit_published_momo_branches' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho, $ce, $bm ];
}
$caps['momo_edit_branch_identity'] = [ $sa, $ho ];
$caps['momo_edit_branch_settings'] = [ $sa, $ho ];
$caps['momo_edit_branch_hours']    = [ $sa, $ho, $bm ];

// Notices.
foreach ( [ 'create_momo_notices', 'edit_momo_notices', 'edit_others_momo_notices', 'edit_published_momo_notices', 'publish_momo_notices', 'delete_momo_notices', 'delete_others_momo_notices', 'delete_published_momo_notices' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho, $ce, $bm ];
}

// Offers.
foreach ( [ 'create_momo_offers', 'edit_momo_offers', 'edit_others_momo_offers' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho, $ce, $bm ];
}
foreach ( [ 'edit_published_momo_offers', 'publish_momo_offers', 'delete_momo_offers', 'delete_others_momo_offers', 'delete_published_momo_offers', 'read_private_momo_offers' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho ];
}

// Dishes and menu.
foreach ( [ 'create_momo_dishes', 'edit_momo_dishes', 'edit_others_momo_dishes', 'edit_published_momo_dishes', 'read_private_momo_dishes' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho, $mm ];
}
$caps['publish_momo_dishes'] = [ $sa, $ho ]; // MM: optional per-user grant (DEC-026).
foreach ( [ 'delete_momo_dishes', 'delete_others_momo_dishes', 'delete_published_momo_dishes' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho ];
}
$caps['momo_manage_menu_categories']   = [ $sa, $ho, $mm ];
$caps['momo_edit_branch_menu']         = [ $sa, $ho, $mm ];
$caps['momo_edit_branch_prices']       = [ $sa, $ho, $mm ];
$caps['momo_edit_branch_availability'] = [ $sa, $ho, $mm, $bm, $rs ];

// Dietary claims / certificates (effective only with per-user CAP-DCA, M4).
foreach ( [ 'create_momo_certificates', 'edit_momo_certificates', 'edit_others_momo_certificates', 'edit_published_momo_certificates', 'publish_momo_certificates' ] as $c ) {
	$caps[ $c ] = [ $sa, $ho ];
}
$caps['read_private_momo_certificates'] = [ $sa, $ho, $mm, $bm ];
$caps['momo_manage_certificates']       = [ $sa, $ho ];

// Brand, reservations, staff, audit, privacy.
$caps['momo_manage_brand']             = [ $sa, $ho ];
$caps['momo_view_reservations']        = [ $sa, $ho, $bm, $rs ];
$caps['momo_process_reservations']     = [ $sa, $ho, $bm, $rs ];
$caps['momo_view_customer_pii']        = [ $sa, $ho, $bm, $rs ];
$caps['momo_export_reservations']      = [ $sa, $ho ];
$caps['momo_view_reservation_reports'] = [ $sa, $ho, $bm ];
$caps['momo_manage_staff']             = [ $sa, $ho ];
$caps['momo_view_audit_log']           = [ $sa, $ho ];
$caps['momo_manage_dsr']               = [ $sa, $ho ];
$caps['momo_manage_languages']         = [ $sa, $ho ];
$caps['momo_access_all_branches']      = [ $sa, $ho, $mm, $ce ];
$caps['momo_view_notification_log']    = [ $sa, $ho ];
$caps['momo_manage_redirects']         = [ $sa, $ho ];            // SEO redirects + 404 log (i18n-seo-analytics §7).
$caps['momo_edit_seo_meta']            = [ $sa, $ho, $mm, $ce ];  // Per-item SEO title/description.

// Translation (replaces WPML designations, IMPL-002). TRD name `momo_translate` kept as alias.
$caps['momo_translate']             = [ $sa, $ho, $mm, $ce, $tr ];
$caps['momo_approve_translations'] = [ $sa, $ho, $tr ];
$caps['momo_preview_languages']    = [ $sa, $ho, $tr ];

return [
	'roles'        => [
		$ho => [ 'Head Office Administrator', '本部管理者' ],
		$mm => [ 'Menu Manager', 'メニュー管理者' ],
		$ce => [ 'Content Editor', 'コンテンツ編集者' ],
		$tr => [ 'Translator', '翻訳者' ],
		$bm => [ 'Branch Manager', '店長' ],
		$rs => [ 'Reservation Staff', '予約担当スタッフ' ],
	],
	'scoped_roles' => [ $bm, $rs ],
	'caps'         => $caps,
	// Per-user only (never role-granted): CAP-DCA. Grantable only to SA/HO users.
	'per_user'     => [ 'momo_edit_dietary_claims' ],
	// Explicit denies for vendor accounts (DEC-035).
	'vendor_denies' => [ 'momo_view_customer_pii', 'momo_view_reservations', 'momo_export_reservations', 'momo_manage_dsr' ],
];
