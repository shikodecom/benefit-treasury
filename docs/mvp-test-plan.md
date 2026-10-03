# MVP総合テスト (#14)

## 実行ゲート

PRごとに `vendor/bin/pint --test`、`php artisan test --testsuite=Feature`、`php artisan view:cache`、`npm run build` を実行する。CI は `.github/workflows/tests.yml` に定義し、隔離MySQL 8.0での並行実行も行う。

## 対応表

| Issue | ケース | 自動検証 |
| --- | --- | --- |
| #2〜#4 | MIG-01、P0-01〜19、INV-01〜06 | `BenefitSchemaTest`、`LedgerTest`、`MasterManagementTest` |
| #5〜#6 | P0-20〜32、P1-09〜21 | `DashboardTest`、`ListingTest` |
| #7〜#8 | P0-33〜50 | `TransferTest`、`ConversionTest` |
| #13 | campaign選択、rule固定参照、予定数量不変 | `MvpIntegrationTest::test_route_draft_selects_campaign_and_freezes_rule_quantity` |
| #9 | P0-51〜65（一部）、P1-36/37/39/40、PERF-03 | 匿名化した実シート構成の fixture、悪意あるXLSX、重複・検算・2,000 行取込 |
| #10 | P1-27/28/31/32、alias検索 | `MvpIntegrationTest` の検索ケース |
| #11 | P0-66/67/68/69/70/72/73、P1-33/34、PERF-04 | `MvpIntegrationTest` の通知ケース、100 ロット時の SQL 件数 |

## 2026-09-29 に追加した検証

- MySQL 8.0.46の独立DBで、2プロセスを同じ行ロックへ同時に待たせる競合を5回反復。口座・ロット同時利用、二重出品、二重売却、二重移行申請・着弾を確認した。`REPEATABLE READ` では二重出品を再現したため、MySQL接続を `READ COMMITTED` に変更して全反復で残高・件数不変条件を確認した。
- 375pxブラウザで主要10画面の横スクロールなし、横断検索の複合条件と詳細からの戻る操作を確認。検索条件を折りたたみ、通知設定のチェック欄を修正した。
- 実運用ファイルのシート名・列配置だけに基づく合成値の匿名化fixture 2件を追加。JAL/ANA残高、重複、要確認、ポイント利用の減算、外部リンクと展開サイズ超過の拒否を確認した。実数値・実名は含めない。
- 隔離MySQLで全migration→1件rollback→再適用→全reset→全再適用が成功（MIG-02/03）。
- 隔離MySQLの合成データで2,000取引・500ロットの検索3条件が633.5〜682.4ms、ページネーション各25件。1,000ロットの通知は初回2,539.5msで1,000件、再実行878.2msで0件、ピーク30MiB。再現手順は `tests/manual/mysql-performance.php`。これはローカルMySQLの値であり本番環境の応答時間ではない。
- 追跡ファイル191件のSEC-07検索で秘密鍵・代表的なAPI鍵形式は0件。`@example`以外のメールは依存パッケージの公開メタデータ `composer.lock` のみ。電話形式は合成値を使ったテストコードのみ。追跡XLSXは匿名化fixture 2件のみ。
- E2E-01/02のライフサイクル通しテストを追加。マイル残高をダッシュボードでも確認できるよう、口座残高欄を追加。E2E-03は3段移行テスト、E2E-04は匿名化fixtureの取込・再取込テスト、E2E-05は通知の重複防止・対応済み表示テストで主要経路を検証する。
- 375pxの合成データ環境で通知設定の無効化・再有効化、一括既読の操作を確認。実機と本番ログイン後の操作は別途確認する。
- 通知cronの標準出力を `storage/logs/notifications-scheduler.log` に追記する設定を追加。翌日08:00 JSTの実行証跡を確認できるようにした。

## 2026-10-03 release gate (#22)

#18〜#21はPR #24〜#27としてmainへマージ済み。移行・交換ルール・プレ商品券のImporterは明示マッピングと行単位レビューを含めて実装済み。

- [release gateの実行証跡・本番確認手順](release-gate.md)
- [P0 73件／P1 40件のケース別対応表](mvp-case-evidence.md)。専用assertionが足りない項目は未検証とし、Feature全成功から全ケース成功を推測しない。
- E2E-03〜05と通知catch-upの専用通しテストをSQLite／MySQLで追加。375pxの合成画面でも主要操作を確認したが、全操作の375px証跡・本番操作は残る。
- 隔離MySQLで2,000／10,000／20,000行×1／4口座の6ケースがpass。残高・件数一致、改名再取込0件。phase別の時間・peak memory・SQL・ロック指標をJSONLに保存した。
- 通知コマンドにJST開始・終了時刻、run_id、件数、所要時間、安全な失敗コードを追加した。

**release gateは未通過。** 本番配置commit、migration、実際の08:00 cron、認証後の通知操作、およびケース表の未検証項目が残る。#22/#11/#14/#1はOpenを維持する。

## 口座別Excel重複判定 (#18)

- Featureで別名義・別口座の同一履歴、改名再取込、シート／行単位の再マッピング、作成予定口座の再利用、重複行を含む追加履歴の残高検算を確認する。
- 既存キーの移行はcommit済み取引の口座を正本とし、移行→rollback→再移行、未解決履歴での全更新中止、別口座の同一元行を登録した後のrollback拒否を確認する。
- `tests/manual/mysql-concurrency.php` は2プロセスで別batchの同一口座取込、同じbatchの二重実行、同じ作成予定口座の競合を各5回検証する。既存キーのMySQL移行と再取込も同スクリプトで確認する。
- 2026-10-02: ローカルの隔離MySQL 8.0.46で上記と既存の台帳・出品・移行競合を全5回pass。Featureは68件/655 assertions、Pint、Blade cache、Vite buildもpass。
- 実データの移行とMySQL取込性能の最終評価は #22 に残る。
