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

## 継続する検証

- #9: 実ファイルの値を使った業務上の検算は行わない。移行、交換ルール、商品券シートは安全上 `needs_review` とし、自動投入対象ではない。2,000 行の合成履歴は SQLite の feature test で取込済み。MySQL での取込メモリ・処理時間を追加検証する。
- #11: JST 08:00 cronの実行証跡・本番での通知生成と再実行重複を次回実行時に確認する。長期停止からの復旧、本番ログイン後の一括既読と設定の操作は別途確認する。
- 全体: E2E-03〜05の専用通しテストと、本番ブラウザ・375px実機相当での操作確認を追加する。375pxは合成データのブラウザで確認済み。

これらが未実施の間は、Issue #14 の release gate を通過した扱いにしない。
