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

## 継続する検証

- #9: 実ファイルの値を使った業務上の検算は行わない。移行、交換ルール、商品券シートは安全上 `needs_review` とし、自動投入対象ではない。2,000 行の合成履歴は SQLite の feature test で取込済み。MySQL での取込メモリ・処理時間を追加検証する。
- #10: 2,000 取引・500 ロットで応答時間とページネーションを測る。スマホで複合フィルタと戻る操作を確認する。
- #11: スケジューラを JST で稼働させ、長期停止からの復旧と一括既読、設定の操作をブラウザで確認する。1,000 ロットでの実時間も測る。
- 全体: Issue #14 の E2E-01〜05、MIG-02/03 のロールバック、SEC-07 の実データ・秘密情報検査を行う。375pxは合成データで確認済みで、本番ブラウザでの操作確認は別途行う。

これらが未実施の間は、Issue #14 の release gate を通過した扱いにしない。
