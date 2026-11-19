<?php
/**
 * Admin content strings (content-strings.md §3.4 `admin.legal.publish_denied`, §12 `admin.translation.gaps_none`, `admin.media.alt_required` FR-ADM-005(d)) — Japanese.
 * Japanese admin copy [Prop JA] — native review required.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.legal.publish_denied' => '法的ページの公開・更新は本部のみが行えます。下書きを保存してレビューに提出してください。',
	'admin.translation.gaps_none' => '{language}の未翻訳はありません',
	'admin.media.alt_required' => '公開前に代替テキストを入力してください',
	'admin.audit.action.translation.publish_blocked' => '翻訳の公開をブロック（代替テキスト未入力）',
	'admin.audit.action.content.illustrative_refused' => 'デモ画像の使用を拒否',
];
