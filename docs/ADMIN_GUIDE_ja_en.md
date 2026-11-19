# 管理画面ガイド（スタッフ向け） / Admin guide for staff

対象：Momo's の管理画面（WordPress）を使うすべてのスタッフ。役割ごとに「毎日の作業」「メニューの場所」
「できないこと（とその理由）」をまとめています。各節は日本語 → English の順です。

Audience: every staff member who uses the Momo's admin (WordPress). For each role: daily tasks, exact menu
paths, and what the role cannot do and why. Each section is Japanese first, then English.

> 表記 / Notation：メニューの場所は「日本語の表示 / English label」で示します。例：
> **予約リクエスト → 受信箱 / Reservations → Inbox**。WordPress 本体のメニュー（投稿・メディア・ユーザー）は、
> サーバーに WordPress の日本語パックが入っている場合に日本語で表示されます。

---

## 1. 共通：ログイン・2段階認証・言語 / Common: log in, 2-step verification, language

### 1.1 ログイン / Log in
**日本語**
1. ブラウザーで `https://<サイトのドメイン>/wp-admin/` を開きます（ブックマーク推奨）。
2. ユーザー名とパスワードを入力して「ログイン」。パスワードは 12〜128 文字。よく使われるパスワード・
   流出済みのパスワードは使えません。定期変更は不要です。
3. 「ログイン状態を保存」はありません。ログインは最長 14 時間、操作がない状態が 8 時間続くと自動的に終了します。
4. パスワードを 15 分間に 10 回間違えると、そのアカウント（または接続元）が 60 分間ロックされます。
   画面に再試行できる時刻が表示されます。待てない場合は本部に連絡してください。
5. 共有 PC では作業後に必ず右上の名前 → 「ログアウト」。

**English**
1. Open `https://<site domain>/wp-admin/` (bookmark it).
2. Enter user name and password, then "Log In". Passwords are 12–128 characters; common or breached
   passwords are refused; no forced rotation.
3. There is no "Remember me". A login lasts at most 14 hours and ends after 8 hours without activity.
4. 10 failed attempts within 15 minutes lock the account (or the network address) for 60 minutes; the
   message says when to retry. If you cannot wait, contact Head Office.
5. On a shared PC always log out (your name, top right → "Log Out").

### 1.2 2段階認証（初回設定） / 2-step verification (first time)
**日本語**
すべてのスタッフアカウントで必須です（本番環境）。スマートフォンに認証アプリ（Google Authenticator、
Microsoft Authenticator、1Password など TOTP 対応のもの）を入れてください。
1. パスワードでログインすると「2段階認証の設定」画面になります。
2. 認証アプリで QR コードを読み取ります（読み取れない場合は「手入力用のキー」を入力）。
3. アプリに表示された 6 桁のコードを入力して「確認」。
4. **リカバリーコードが 10 個表示されます。これは一度しか表示されません。** 印刷するか、パスワード管理ツールに
   保存してください（LINE やメールで自分に送らないでください）。「これらのコードを保存しました」に
   チェックして「続ける」。
5. 次回以降は、パスワードの後に 6 桁のコードを入力します。コードの入力ミスが 5 回続くと最初からやり直しです。

スマートフォンを紛失した場合：ログイン画面の「リカバリーコードを使用する」で 1 個使ってログインできます
（各コードは 1 回限り）。コードもない場合は本部に連絡してください。本部が本人確認のうえ
「2段階認証をリセット」し、次回ログイン時に再設定します。

**English**
Required for every staff account (production). Install a TOTP authenticator app (Google Authenticator,
Microsoft Authenticator, 1Password …) on your phone.
1. After the password you see "Set up two-step verification".
2. Scan the QR code with the app (or type the "Key for manual entry").
3. Enter the 6-digit code and press "Verify".
4. **Ten recovery codes are shown once only.** Print them or store them in a password manager (do not send
   them to yourself by chat or email). Tick "I have saved these codes" → "Continue".
5. From then on, enter the 6-digit code after your password. Five wrong codes restart the login.

Lost phone: use "Use a recovery code" on the login screen (each code works once). Without codes, contact
Head Office: they verify your identity and use "Reset two-factor"; you enrol again at the next login.

### 1.3 管理画面の言語 / Admin language
**日本語** 右上の名前 → 「プロフィール」→「言語」で「日本語」または「English」を選んで「プロフィールを更新」。
自分の画面だけが切り替わり、お客様向けサイトの言語には影響しません。
**English** Your name (top right) → "Profile" → "Language": choose 日本語 or English → "Update Profile". Only
your own admin changes; the public site is not affected.

> 技術担当へ / For the technical administrator：「日本語」が選択肢に出るには、WordPress 本体の日本語パックが必要です
> （`wp language core install ja`）。Momo's のメニューの日本語訳はプラグイン同梱（`momos-core-ja.mo`）です。
> 日本語 appears in the dropdown only when the WordPress core Japanese pack is installed
> (`wp language core install ja`); the Momo's menus are translated by the bundled `momos-core-ja.mo`.

### 1.4 してはいけないこと（全員） / Never (everyone)
- お客様の氏名・電話番号・メールアドレスを、チャット・個人のメモ・スクリーンショット・URL に書き写さない。
  Do not copy guest names, phone numbers or email addresses into chats, personal notes, screenshots or URLs.
- アカウントを他人と共有しない（操作はすべて監査ログに記録されます）。
  Never share an account — every action is recorded in the audit log.
- お客様のリクエストは店舗が「承認する」まで確定していません。電話などで「予約確定」と伝えないでください。
  A request is not confirmed until the branch presses "Accept"; never tell a guest it is booked before that.

---

## 2. 本部管理者 / Head Office Administrator

**日本語 — 毎日の作業**
| 作業 | メニュー |
|---|---|
| 全店舗の未対応リクエストの確認・エスカレーション対応 | ダッシュボード → 「担当の未対応リクエスト」／ **予約リクエスト → 受信箱**（「未対応」「要対応」タブ、店舗で絞り込み） |
| 予約データの CSV 出力 | **予約リクエスト → 受信箱** → 「CSV をエクスポート」（期間・件数に上限あり） |
| 実績の確認 | **予約リクエスト → レポート** |
| 通知メールの失敗確認 | **Momo's → 通知ログ**（「送信に失敗した通知」） |
| 翻訳の承認・不足の確認 | **Momo's → 翻訳の不足**、各編集画面の「翻訳」ボックス、**Momo's → インターフェース文字列** |
| スタッフの追加・店舗の割り当て・無効化 | **ユーザー → 新規追加 / すべてのユーザー** → 編集画面の「店舗」「アカウントの状態」「2段階認証をリセット」 |
| 全体設定（予約受付の一括停止、通知、言語、プライバシー、地図） | **Momo's → 設定**（タブ：予約リクエスト／通知／言語／プライバシー／地図 …） |
| ブランド情報（ロゴ、表示文） | **Momo's → ブランド** |
| 店舗の基本情報（名称・住所・電話・受付設定） | **店舗 → 店舗** → 店舗を選択 |
| 法的ページの公開 | **固定ページ** → 該当ページ（プライバシーポリシー等）→ 「法的ページ」ボックス |
| 個人データの請求（開示・訂正・削除） | **Momo's → 個人データの請求**（メールアドレスまたは受付番号で完全一致検索） |
| リダイレクト・404 ログ | **Momo's → リダイレクト** |
| 監査ログ | **Momo's → 監査ログ** |
| 言語の公開（ネイティブ確認後） | **Momo's → 設定 → 言語**（公開に必須のコンテンツがすべて承認済み＋ネイティブレビューの記録が必要） |

**English — daily tasks**
| Task | Menu |
|---|---|
| Watch pending requests of all branches, handle escalations | Dashboard → "My pending requests" / **Reservations → Inbox** ("Pending", "Needs action" views, branch filter) |
| Export reservation data | **Reservations → Inbox** → "Export CSV" (range and row limits) |
| Results | **Reservations → Report** |
| Failed emails | **Momo's → Notification log** ("Failed notifications") |
| Approve translations, check gaps | **Momo's → Translation gaps**, "Translations" box on editors, **Momo's → Interface strings** |
| Add staff, assign branches, disable | **Users → Add User / All Users** → edit: "Branches", "Account status", "Reset two-factor" |
| Global settings (kill switch, notifications, languages, privacy, maps) | **Momo's → Settings** (tabs Reservations / Notifications / Languages / Privacy / Maps …) |
| Brand (logo, statements) | **Momo's → Brand** |
| Branch identity (name, address, phone, reservation settings) | **Branches → Branches** → open a branch |
| Publish legal pages | **Pages** → the page (Privacy Policy …) → "Legal page" box |
| Data-subject requests (export, correct, erase) | **Momo's → Data requests** (exact email or reference) |
| Redirects and 404 log | **Momo's → Redirections** |
| Audit log | **Momo's → Audit log** |
| Make a language public (after native review) | **Momo's → Settings → Languages** (needs all launch-critical content approved + review sign-off) |

**できないこと / Cannot**
- ハラール・アルコール・アレルゲン表示の変更は、本部ユーザーのうち個別に「食事表示承認者」（CAP-DCA）を
  付与された人だけ（§8）。表示はお客様の安全と信頼に直結するため（GATE-02）。
  Halal/alcohol/allergen data can be changed only by Head Office users individually granted "Dietary Claims
  Approver" (CAP-DCA, §8) — these claims are a safety and trust issue (GATE-02).
- 自分が翻訳した内容を自分で承認すること。2 人目の確認が必要なため。
  Approving your own translation — a second person must review it.
- 監査ログの編集・削除（追記専用）。 Editing or deleting audit entries (append-only).
- サーバー・プラグインの更新（技術担当のみ）。 Server/plugin updates (technical administrator only).

---

## 3. メニュー管理者 / Menu Manager

**日本語 — 毎日の作業**
| 作業 | メニュー |
|---|---|
| 料理の追加・編集（名称、説明、写真、ブランド価格） | **メニュー → すべての料理 / 料理を追加** |
| カテゴリー・並び順 | **メニュー → メニューカテゴリー**、**メニュー → カテゴリーの並び順** |
| 店舗ごとの提供・価格・売り切れ | **Momo's → 店舗別メニュー**（店舗を選んで表で編集） |
| 主な原材料・食事タグ | **メニュー → 主な原材料 / 食事タグ** |
| 料理の翻訳の入力 | 料理の編集画面 → 「翻訳」ボックス |
| ハラール登録簿の閲覧 | **Momo's → ハラール登録簿**（閲覧のみ） |

**English — daily tasks**
| Task | Menu |
|---|---|
| Add/edit dishes (name, description, photo, brand price) | **Menu → All dishes / Add dish** |
| Categories and order | **Menu → Menu categories**, **Menu → Category order** |
| Per-branch offering, price, sold out | **Momo's → Branch menus** (pick a branch, edit the grid) |
| Key ingredients, dietary tags | **Menu → Key ingredients / Dietary tags** |
| Enter dish translations | Dish editor → "Translations" box |
| View the halal register | **Momo's → Halal register** (read only) |

**できないこと / Cannot**
- 料理の公開・削除（本部が確認して公開。個別に公開権限を付与される場合あり：DEC-026）。
  Publish or delete dishes — Head Office reviews and publishes (a per-user publish grant is possible, DEC-026).
- ハラール・アルコール・アレルゲン項目、「表示に関わるタグ」の変更（食事表示承認者のみ）。原材料を変えると
  その料理は自動的に「要レビュー」になります。
  Halal/alcohol/allergen fields and claim tags (Dietary Claims Approvers only). Changing ingredients puts the
  dish back to "needs review" automatically.
- 翻訳の承認（翻訳者・本部のみ）。 Approve translations (Translators/Head Office only).
- 予約リクエストの閲覧（お客様の個人情報を扱わない役割のため）。
  See reservation requests — this role does not handle guest data.

---

## 4. コンテンツ編集者 / Content Editor

**日本語 — 毎日の作業**
| 作業 | メニュー |
|---|---|
| ニュース記事 | **投稿 → 投稿一覧 / 新規投稿** |
| 固定ページ（会社概要、FAQ など） | **固定ページ**（FAQ は「FAQ 項目」ボックスで編集） |
| お知らせ（全店舗または店舗別） | **お知らせ → お知らせ / お知らせを追加** |
| 特典（下書き作成） | **特典 → 特典を追加**（公開は本部） |
| 画像のアップロード・代替テキスト | **メディア → ライブラリ**（デモ用の「イメージ画像」はギャラリーに使えません） |
| 店舗ギャラリー（実際の写真のみ） | **店舗 → 店舗** → 店舗 → 「ギャラリー」 |
| SEO タイトル・説明文 | 各編集画面の SEO 欄 |

**English — daily tasks**
| Task | Menu |
|---|---|
| News posts | **Posts → All Posts / Add Post** |
| Pages (about, FAQ …) | **Pages** (FAQ in the "FAQ items" box) |
| Notices (brand-wide or per branch) | **Notices → Notices / Add Notice** |
| Offers (drafts) | **Offers → Add Offer** (Head Office publishes) |
| Images, alt text | **Media → Library** (demo "illustrative" images cannot be used in galleries) |
| Branch gallery (real photos only) | **Branches → Branches** → branch → "Gallery" |
| SEO title/description | SEO fields on each editor |

**できないこと / Cannot**
- 法的ページ（プライバシーポリシー、予約ポリシー等）の公開：弁護士の承認記録が必要なため本部のみ（GATE-06）。
  Publish legal pages — they need counsel approval on record, Head Office only (GATE-06).
- 特典の公開・削除（価格や条件の誤表示を防ぐため本部が確認）。
  Publish or delete offers — Head Office checks prices and conditions first.
- 店舗の名称・住所・営業時間・予約設定、料理、食事表示の変更。
  Change branch identity, address, hours, reservation settings, dishes or dietary claims.
- 予約リクエスト・ユーザー・設定。 Reservations, users, settings.

---

## 5. 翻訳者 / Translator

**日本語 — 毎日の作業**
1. **Momo's → 翻訳の不足** で、担当言語の「未翻訳」「要レビュー」を確認します（担当言語はユーザー設定の
   「担当する翻訳言語」で本部が設定）。
2. 項目を開き、編集画面の「翻訳」ボックス（または翻訳の編集画面）で訳文を入力し、状態を選んで保存：
   「下書き」→「要レビュー」→「承認済み（公開）」。**承認済みのものだけがサイトに表示されます。**
3. 画面の文言（ボタン・エラーなど）は **Momo's → インターフェース文字列**。`{name}` や `%s` などの
   プレースホルダーは原文と同じものを残してください（違うと保存されません）。
4. 食事表示・ハラール・アレルゲンの文言（レビュー対象クラス）は、自分の訳を自分で承認できません。
   別の翻訳者または本部が承認します。

**English — daily tasks**
1. **Momo's → Translation gaps** lists missing and "needs review" items for your languages (Head Office sets
   them in your user profile, "Translation languages").
2. Open the item and use the "Translations" box (or the translation editor page); choose a status and save:
   Draft → Needs review → Approved (published). **Only approved text is shown on the site.**
3. Interface text (buttons, errors) is in **Momo's → Interface strings**. Keep placeholders such as `{name}`
   or `%s` exactly as in the source (otherwise the text is not saved).
4. Dietary/halal/allergen texts (review class) cannot be approved by the person who translated them.

**できないこと / Cannot**
- 原文（日本語・英語の元の内容）、価格、店舗情報、食事表示データの変更。翻訳だけを担当するため。
  Change source content, prices, branch facts or dietary data — this role only translates.
- 言語の公開（本部のみ。公開に必須のコンテンツがすべて承認済みで、ネイティブレビューの記録が必要）。
  Make a language public — Head Office only, after all launch-critical content is approved and signed off.
- 予約リクエスト・お客様情報の閲覧。 See reservations or guest data.

---

## 6. 店長 / Branch Manager

割り当てられた店舗だけを表示・操作できます。 You see and act on your assigned branch(es) only.

**日本語 — 毎日の作業**
| 作業 | メニュー |
|---|---|
| 新しいリクエストへの対応（目標：回答時間内） | **予約リクエスト → 受信箱** →「未対応」→ リクエストを開く →「担当する」→「承認する」または「お断りする」（お客様に自動でメール） |
| 本日・今後の承認済みの確認 | 受信箱の「本日」「今後の承認済み」タブ |
| 来店・無断キャンセルの記録 | 受信箱の「来店確認待ち」→「来店済み／無断キャンセル」 |
| お客様への連絡（メール未達など） | リクエスト詳細の「要対応」表示 → 電話で連絡 →「連絡済みにする」 |
| 営業時間・臨時休業 | **店舗 → 営業時間・休業日**（既存のリクエストに影響がある場合は警告が出ます。先に対応してください） |
| 売り切れ | **Momo's → 店舗別メニュー** → 該当の料理を「売り切れ」（営業終了時に自動で戻ります） |
| お知らせ（自店舗） | **お知らせ → お知らせを追加** |
| 特典の下書き | **特典 → 特典を追加**（公開は本部） |
| 実績 | **予約リクエスト → レポート**（自店舗のみ） |

**English — daily tasks**
| Task | Menu |
|---|---|
| Answer new requests (within the response-time target) | **Reservations → Inbox** → "Pending" → open → "Claim" → "Accept" or "Decline" (the guest is emailed automatically) |
| Today / upcoming accepted | Inbox views "Today", "Upcoming confirmed" |
| Record attendance | Inbox "Attendance due" → "Attended / No-show" |
| Contact a guest (e.g. email failed) | "Needs action" on the request → call → "Mark as contacted" |
| Hours and closures | **Branches → Hours & closures** (a warning lists affected requests — handle them first) |
| Sold out | **Momo's → Branch menus** → set the dish to "Sold out" (resets at closing time) |
| Notices (own branch) | **Notices → Add Notice** |
| Offer drafts | **Offers → Add Offer** (Head Office publishes) |
| Results | **Reservations → Report** (own branch) |

**できないこと / Cannot**
- 他店舗のリクエスト・情報の閲覧（URL を直接入力しても表示されません）。お客様情報の保護と店舗間の分離のため（GATE-03）。
  See other branches (also not via a typed URL) — guest privacy and branch isolation (GATE-03).
- 店舗の名称・住所・電話・予約受付の設定、料理の価格・提供の有無：本部・メニュー管理者が管理（公式情報の一元管理）。
  Change branch name, address, phone, reservation settings, dish prices or offering — owned by Head Office /
  Menu Manager so official facts stay consistent.
- CSV 出力（個人情報の大量持ち出しを防ぐため本部のみ）。 Export CSV — Head Office only (bulk personal data).
- 食事表示の変更（CAP-DCA を付与された本部ユーザーのみ。§8）。
  Change dietary claims — only Head Office users holding CAP-DCA (§8).
- スタッフの追加・権限変更。 Add staff or change roles.

---

## 7. 予約担当スタッフ / Reservation Staff

割り当てられた店舗だけを表示・操作できます。 Assigned branch(es) only.

**日本語 — 毎日の作業**
1. **予約リクエスト → 受信箱**（ログイン後の最初の画面）で「未対応」を確認。
2. リクエストを開いて「担当する」。ほかの人が担当中の場合は「引き継ぎますか？」と表示されます。
3. 席・時間を確認して「承認する」または「お断りする」（理由を選択）。お客様にはメールが自動送信されます。
   **電話や口頭で先に「確定」と伝えないでください。**
4. 当日：「本日」タブで来店予定を確認し、来店後に「来店済み」または「無断キャンセル」を記録。
5. お客様からの変更依頼：リクエスト詳細で日時・人数を変更（お客様に更新メールが送られます）。
   キャンセル依頼は「キャンセルする」。
6. 料理の売り切れ：**Momo's → 店舗別メニュー** で「売り切れ」。

**English — daily tasks**
1. **Reservations → Inbox** (your start screen): check "Pending".
2. Open a request → "Claim". If someone else holds it you are asked "Take over?".
3. Check seats/time → "Accept" or "Decline" (choose a reason). The guest is emailed automatically.
   **Do not tell the guest it is confirmed before you press Accept.**
4. On the day: "Today" view; after the visit record "Attended" or "No-show".
5. Guest asks for a change: edit date/time/party on the request (an update email is sent). To cancel:
   "Cancel request".
6. Sold-out dishes: **Momo's → Branch menus** → "Sold out".

**できないこと / Cannot**
- 他店舗のリクエスト、レポート、CSV 出力。 Other branches, reports, CSV export.
- 店舗情報・営業時間・お知らせ・特典・価格の変更（店長・本部の業務）。
  Branch facts, hours, notices, offers or prices — Branch Manager / Head Office tasks.
- 内部メモにお客様の個人情報以外の業務情報だけを書く（アレルギーなどの健康情報はお客様の入力欄にあるものを参照し、
  メモに書き写さない）。 Internal notes are for operational information only — do not copy health or
  contact details into them.

---

## 8. 食事表示承認者（個別付与） / Dietary Claims Approver (per-user grant)

**日本語** 役割ではなく、特定の**本部ユーザー**に個別に付与する権限です（ユーザー編集画面「ハラール／食事表示と認証書を
承認できる（CAP-DCA）」。外部委託のアカウントには付与できません）。ハラール認証書の登録（**Momo's → ハラール登録簿**）、店舗の食事表示、料理のアレルゲン・
アルコール情報の承認を行います。厨房の担当者など**情報提供者と承認者は別の人**でなければなりません（2 人確認
ルール）。「100% ハラール」などの禁止表現は保存できません（**Momo's → 表示用語のチェック** で確認）。

**English** Not a role: a per-user grant for named **Head Office users** only (user edit screen, "May approve
halal/dietary claims and certificates (CAP-DCA)"; never vendor accounts). Approvers maintain the certificate register (**Momo's → Halal
register**), branch dietary statements and dish allergen/alcohol data. The **source person and the approver
must be different people** (two-person rule). Prohibited wording such as "100 % halal" cannot be saved
(check with **Momo's → Claim-term scan**).

---

## 9. 困ったとき / When something goes wrong

| 状況 / Situation | 対応 / What to do |
|---|---|
| ロックされた / Locked out | 表示された時刻まで待つ。急ぎは本部へ。 Wait until the time shown; urgent → Head Office. |
| スマートフォン紛失 / Lost phone | リカバリーコードでログイン → 本部に「2段階認証をリセット」を依頼。 Log in with a recovery code, then ask Head Office to reset 2-step verification. |
| 「権限がありません」と表示 / "You do not have permission" | 役割の範囲外の操作です。必要なら本部に依頼。試行は監査ログに記録されます。 Outside your role; ask Head Office. Attempts are logged. |
| お客様にメールが届かない / Guest did not get the email | リクエスト詳細の通知欄で状態を確認 → 「要対応」なら電話で連絡し「連絡済みにする」。 Check the notification panel on the request; if "Needs action", call and "Mark as contacted". |
| 受付を一時停止したい / Need to stop requests now | 店長→本部に連絡。本部が **Momo's → 設定 → 予約リクエスト** の一括停止、または店舗の受付設定を変更。 Branch Manager → Head Office: kill switch in **Momo's → Settings → Reservations** or the branch's setting. |

> この文書の日本語は管理画面の表示（momos-core-ja.po、ロール名は config/capabilities.php）に合わせています。
> 画面の文言が変わった場合はこのガイドも更新してください。
> Japanese labels follow the admin translation (momos-core-ja.po; role names from config/capabilities.php).
> Update this guide when screen labels change.
