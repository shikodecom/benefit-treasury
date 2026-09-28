# 特典台帳

ポイント、マイル、優待券などの保有と期限を管理する Laravel 12 アプリです。ログイン付きの名義・制度・口座マスタと、手入力の取引台帳・期限付きロットを実装しています。

## 配置

- 公開URL: `https://benefit-treasury.shikode.com`
- 配置先: `/home/f-taniguchi/www/shikode/benefit-treasury`
- Webサーバーのドキュメントルート: `/home/f-taniguchi/www/shikode/benefit-treasury/public`

`.env` とアプリ本体がブラウザーから読めないよう、ドキュメントルートは必ず `public` に設定します。設定できない場合は、その環境向けの安全な配置方法を先に決めてください。

## サーバー側の準備

1. PHP 8.2以上、必要なPHP拡張、Composer、MySQL 8.0を用意します。
2. `composer install --no-dev --optimize-autoloader` を実行します。
3. `.env.example` を `.env` にコピーし、サーバー上でDB接続情報を設定します。`APP_KEY` は `php artisan key:generate` で生成します。`.env` はGit管理しません。
4. `php artisan benefit:db-check` でDB疎通を確認します。
5. `php artisan migrate --force` でスキーマを作成します。
6. `php artisan benefit:create-admin` を実行し、ログイン用アカウントを作成します。一般公開の登録画面はありません。
7. `storage/` と `bootstrap/cache/` をPHP実行ユーザーが書き込めるようにします。
8. `php artisan optimize` を実行し、Webサーバーから `public/` を公開します。

ローカルからのDB接続は禁止されているため、DB疎通とマイグレーションはデプロイ先で実行します。

## スキーマの原則

- 口座残高とロット残数は `benefit_transactions` の差分から算出します。
- 数量は正数で保持し、増減は `direction` で表現します。
- 出品中の数量は予約として扱い、売却成立までは取引残数を減らしません。
- 実データと認証情報はリポジトリに含めません。

DB仕様の正本は [Issue #2](https://github.com/shikodecom/benefit-treasury/issues/2) です。

## マスタ管理

`/settings/benefits` から名義、特典制度、保有口座を管理します。無効化しても履歴は削除しません。制度には別名を登録でき、一覧検索にも反映されます。同じ制度・名義・ラベルの口座を重複登録する際は確認が必要です。取引履歴がある制度の単位・カテゴリは変更できません。

## 手入力の台帳

`/benefits` から残高を確認し、`/transactions` で初期残高・獲得・利用・調整を記録します。初期残高は取引がない口座にだけ登録できます。取引の訂正は削除ではなく取消取引を追加します。`/lots` では期限付き特典を取得し、ロット単位で利用・失効・取得取消を記録できます。残高と残数は取引から計算します。ロット付き残高はロット詳細から利用してください。

口座の取引履歴から複数ロットの利用を期限順に配分でき、配分数量を手動で変更できます。ポイント移行、出品・売却、Excel取込は後続Issueの対象です。
