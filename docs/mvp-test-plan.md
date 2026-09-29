# MVP総合テスト (#14)

## 実行ゲート

PRごとに `vendor/bin/pint --test`、`php artisan test`、`php artisan view:cache`、`npm run build` を実行する。CI は `.github/workflows/tests.yml` に定義した。リリース候補では、匿名化した実運用形式の XLSX、MySQL でのマイグレーションと並行実行、主要画面の 375px 確認を別途実施する。

## 対応表

| Issue | ケース | 自動検証 |
| --- | --- | --- |
| #2〜#4 | MIG-01、P0-01〜19、INV-01〜06 | `BenefitSchemaTest`、`LedgerTest`、`MasterManagementTest` |
| #5〜#6 | P0-20〜32、P1-09〜21 | `DashboardTest`、`ListingTest` |
| #7〜#8 | P0-33〜50 | `TransferTest`、`ConversionTest` |
| #13 | campaign選択、rule固定参照、予定数量不変 | `MvpIntegrationTest::test_route_draft_selects_campaign_and_freezes_rule_quantity` |
| #9 | P0-51〜62（一部）/64/65、P1-36/37/39/40、PERF-03 | `MvpIntegrationTest` の Excel ケース、2,000 行取込 |
| #10 | P1-27/28/31/32、alias検索 | `MvpIntegrationTest` の検索ケース |
| #11 | P0-66/67/68/69/70/72/73、P1-33/34、PERF-04 | `MvpIntegrationTest` の通知ケース、100 ロット時の SQL 件数 |

## リリース前に必要な検証

- #9: 実ファイル由来の匿名化 fixture で列・シート判定を確認する。現時点でリポジトリに対象 XLSX は含まれない。移行、交換ルール、商品券シートは安全上 `needs_review` とし、自動投入対象ではない。
- #9: 匿名化した実ファイル形式で検算する。2,000 行の合成履歴は SQLite の feature test で取込済み。外部リンク・ZIP bomb を含む悪意ある XLSX と MySQL でのメモリ・処理時間を追加検証する。
- #10: 2,000 取引・500 ロットで応答時間とページネーションを測る。スマホで複合フィルタと戻る操作を確認する。
- #11: スケジューラを JST で稼働させ、長期停止からの復旧と一括既読、設定の操作をブラウザで確認する。1,000 ロットでの実時間も測る。
- 全体: Issue #14 の E2E-01〜05、MySQL での競合操作、MIG-02/03 のロールバック、SEC-07 の実データ・秘密情報検査、375px 画面の確認を行う。

これらが未実施の間は、Issue #14 の release gate を通過した扱いにしない。
