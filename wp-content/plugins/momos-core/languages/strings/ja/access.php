<?php
/**
 * Access/Audit staff strings (ja). Keys: docs/specs/access-security-privacy.md §15.1.
 * JA strings marked [draft] in the spec need native/legal review.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.login.err.generic' => 'ユーザー名またはパスワードが正しくありません。',
	'admin.login.err.locked' => 'ログインの失敗が多すぎます。{time}以降に再度お試しください。',
	'admin.login.err.locked_generic' => 'ログインの失敗が多すぎます。しばらくしてから再度お試しください。',
	'admin.2fa.title' => '2段階認証',
	'admin.2fa.prompt' => '認証アプリに表示されている6桁のコードを入力してください。',
	'admin.2fa.err.invalid' => 'コードが正しくありません。もう一度お試しください。',
	'admin.2fa.err.expired' => '認証の有効期限が切れました。もう一度ログインしてください。',
	'admin.2fa.err.too_many' => '誤ったコードの入力が多すぎます。もう一度ログインしてください。',
	'admin.2fa.err.confirm_saved' => 'コードを保存したことを確認するチェックを入れてください。新しいコードを発行しましたので、こちらを保存してください。',
	'admin.2fa.recovery_link' => 'リカバリーコードを使用する',
	'admin.2fa.recovery_label' => 'リカバリーコード（XXXX-XXXX）',
	'admin.2fa.verify' => '確認',
	'admin.2fa.enrol.title' => '2段階認証の設定',
	'admin.2fa.enrol.intro' => '認証アプリでQRコードを読み取るか、キーを手入力してから、表示された6桁のコードを入力してください。',
	'admin.2fa.enrol.key_label' => '手入力用のキー：',
	'admin.2fa.enrol.qr_alt' => '認証アプリ用のQRコード',
	'admin.2fa.codes.intro' => 'これらのリカバリーコードを安全な場所に保存してください。スマートフォンを紛失した場合、各コードを1回だけ使用できます。再表示はされません。',
	'admin.2fa.saved_codes' => 'これらのコードを保存しました',
	'admin.2fa.continue' => '続ける',
	'admin.user.err.username' => '別のユーザー名を選択してください。',
	'admin.user.err.branch_required' => '少なくとも1つの店舗を割り当ててください。',
	'admin.user.no_branch' => 'アカウントに店舗が割り当てられていません。本部へお問い合わせください。',
	'admin.pw.err.length' => '12〜128文字で入力してください。',
	'admin.pw.err.breached' => 'このパスワードは一般的すぎるか、流出が確認されています。別のパスワードを選択してください。',
	'admin.vendor.err.window' => 'ベンダーのアクセスは最長{hours}時間まで有効にできます。',
	'admin.audit.empty' => '該当する記録はありません',
	'admin.err.forbidden_requests' => 'この店舗のリクエストへのアクセス権限がありません。',
	'admin.err.forbidden' => 'この操作を行う権限がありません。',
	'staff.err.forbidden_branch' => 'この店舗へのアクセス権限がありません。',
	'staff.err.forbidden_field' => '次の項目を変更する権限がありません：{field}',
	'admin.lang.err.gate' => '{language}を公開できません：公開必須コンテンツのうち{n}件が未翻訳または未レビューです。',
	'admin.lang.err.signoff' => '{language}を公開する前に、ネイティブレビューの承認（氏名と日付）を記録してください。',
];
