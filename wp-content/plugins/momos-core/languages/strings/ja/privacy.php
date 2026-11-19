<?php
/**
 * Privacy strings (ja). Keys: docs/specs/access-security-privacy.md §15.1/§15.2.
 * admin.dsr.* are [draft] in the spec (need native review); consent.special_request is a counsel draft (JA master governs).
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dsr.blocked_active' => '先に有効なリクエストをキャンセルしてください。未来の受付中・確定済みリクエストがある間は匿名化できません。',
	'admin.dsr.confirm'        => 'この操作は取り消せません。匿名化するには参照番号 {ref} を入力してください。',
	'admin.dsr.empty'          => '該当するデータはありません。',
	'consent.special_request'  => '特記事項に記載した健康・アレルギーに関する情報が、ご来店の準備のためにのみ利用されることに同意します。',
];
