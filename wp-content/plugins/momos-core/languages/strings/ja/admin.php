<?php
/**
 * Admin module staff strings (ja): SCR-A02 dashboard widgets (docs/specs/access-security-privacy.md §13.1).
 * JA strings are [draft] (not from the docs) and need native review.
 */

defined( 'ABSPATH' ) || exit;

return [
	'admin.dash.needs_action.title' => '対応が必要',
	'admin.dash.needs_action.flagged' => '要対応フラグ付きの予約 {n} 件',
	'admin.dash.needs_action.not_notified' => 'お客様へ未通知の予約 {n} 件',
	'admin.dash.needs_action.none' => '対応が必要な予約はありません。',
	'admin.dash.attendance.title' => '来店記録の入力',
	'admin.dash.attendance.count' => '来店記録が未入力の予約 {n} 件',
	'admin.dash.attendance.none' => '来店記録はすべて入力済みです。',
	'admin.dash.pending.overdue' => '対応目標時間を超過 {n} 件',
	'admin.dash.pending.item' => '{ref} — {date} {time}・{party}名（{branch}）',
	'admin.dash.allergen.title' => 'アレルゲン情報未確認',
	'admin.dash.allergen.count' => 'アレルゲン未確認または要確認の料理 {n} 件',
	'admin.dash.allergen.none' => 'アレルゲン情報はすべて確認済みです。',
	'admin.dash.offers_own.title' => '承認待ちのキャンペーン（自分の申請）',
	'admin.dash.offers_own.count' => '承認待ちのキャンペーン {n} 件',
	'admin.dash.offers_own.none' => '承認待ちのキャンペーンはありません。',
	'admin.dash.offers_ending.title' => 'まもなく終了するキャンペーン',
	'admin.dash.offers_ending.item' => '{title} — {date} 終了',
	'admin.dash.offers_ending.none' => '7日以内に終了するキャンペーンはありません。',
	'admin.dash.demo.title' => '残っているデモコンテンツ',
	'admin.dash.demo.count' => 'サンプル項目 {n} 件が残っています',
	'admin.dash.demo.illustrative' => 'イメージ写真 {n} 件を使用中',
	'admin.dash.demo.none' => 'デモコンテンツは残っていません。',
	'admin.dash.tr_jobs.title' => '担当の翻訳作業',
	'admin.dash.tr_jobs.count' => '{language}：未完了 {n} 件',
	'admin.dash.tr_jobs.none' => '未完了の翻訳作業はありません。',
];
