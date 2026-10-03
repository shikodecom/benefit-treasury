# Release gate #22（2026-10-03）

判定: **未通過**。#18〜#21はPR #24〜#27としてmainへマージ済み。今回の合成データ検証は成功したが、[ケース別照合](mvp-case-evidence.md)の未検証項目と、本番証跡が残る。#22/#11/#14/#1はOpenを維持する。

ローカル全Feature 97件/1,225 assertions、Pint、Blade cache、Vite buildは成功。

## 専用E2E

`tests/Feature/ReleaseGateTest.php`はSQLiteと隔離MySQLで4件/132 assertions成功。既存の個別サービス検証に加え、HTTP経由のプレビュー、確定、遷移、残高、検索、通知操作を一連のケースとして検証する。

- E2E-03: ルート→プレビュー（未保存）→計画保存→3段申請・着弾→全口座台帳・Dashboard・検索・遅延除外。
- E2E-04: upload→未知alias停止→alias登録→再マッピング→プレビュー→確定→残高110・3取引→同名／改名再取込0件。秘密列の非表示も確認。
- E2E-05: 設定保存→生成→再実行0件→通知からロットへ遷移・既読→全量利用→次回生成0件→対応済み表示→一括既読・dismiss。
- catch-up: 期限後41日で過去段階を増やさず`expired_30d`を1件、同日再実行0件。成功／失敗ログ、JST時刻、run_id、終了コード、例外内容の非漏洩を検証。

375×812pxのローカル合成データ画面では、3段申請・着弾、通知設定の無効化／再有効化、既読・全量利用・対応済み・dismiss、Excel upload→マッピング→3件確定を操作した。横幅375px、document幅360px、取込後のJavaScript error 0件。ルート計画作成の初回操作は通常幅であり、alias補正と同名／改名再取込はHTTPテストで検証した。これら全操作を375pxで行った証跡、本番認証後操作、実機確認は未検証。

画面証跡は合成データのみ: [移行](evidence/mobile-transfer-2026-10-03.jpg)、[通知](evidence/mobile-notification-2026-10-03.jpg)、[Excel](evidence/mobile-excel-2026-10-03.jpg)。

## 隔離MySQL Excel性能

PHP 8.5.9 / MySQL 8.0.46 / READ COMMITTED、同一PHPプロセスで6ケースを順次実行。入力は全行+1の合成mile履歴。口座別件数・残高、初回取込N件、改名再取込0件・重複N件を全ケースでassertした。ローカル測定値であり、本番のSLAや上限を保証しない。

| 行数 | 口座数 | analyze ms | configure ms | verify単独 ms | execute ms | 最大peak MiB |
| --- | --- | --- | --- | --- | --- | --- |
| 2,000 | 1 | 310.5 | 1,325.0 | 254.6 | 1,933.8 | 37 |
| 2,000 | 4 | 240.8 | 1,352.4 | 255.3 | 1,982.3 | 37 |
| 10,000 | 1 | 1,214.4 | 6,549.4 | 1,456.5 | 9,699.5 | 49 |
| 10,000 | 4 | 1,186.6 | 6,519.5 | 1,327.6 | 9,703.9 | 49 |
| 20,000 | 1 | 2,424.7 | 13,316.7 | 2,709.2 | 19,936.3 | 59 |
| 20,000 | 4 | 2,437.7 | 13,495.3 | 2,790.7 | 19,650.3 | 59 |

configure/executeは内部verifyを含む。単独verifyは計測用の追加呼出しで、通常操作の総時間へ二重加算しない。最大peakは再取込を含む各phaseの最大値。各phaseでpeakをresetするが、既存プロセスの確保済みメモリを含む。

[JSONL原測定値](evidence/mysql-excel-2026-10-03.jsonl)に再取込、SQL件数・合計ms、FOR UPDATE件数、InnoDBロック待ち差分を保存した。SQL件数はLaravel QueryExecutedのstatement数で、transaction制御文を含まない。ロック待ちは専用daemonのGLOBAL `Innodb_row_lock_time`差分で、全phase 0ms。同時競合の待ち時間の証拠にはならない。

SQL件数はconfigure `6N+7`、execute `9N+9`、verify `N+1`、再configure `7N+7`。初回analyze→configure→executeは2,000行で約3.6秒、20,000行で約35.7秒とほぼ行数比例。単一／複数口座に大差はなかった。安全性を保ったまま設定・検算のSQL削減余地はあるが、受入性能閾値と本番測定がないため、最適化の必要性は未確定。今回ロックは削除しない。

再現には**空の使い捨てMySQL DB**を使用する。`APP_ENV=testing`、DB名末尾`_test`、既存取引／batchなしをスクリプトが確認する。

```sh
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=benefit_excel_test php artisan migrate --force
APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=benefit_excel_test php tests/manual/mysql-excel-performance.php
```

接続設定は隔離DB用に指定する。20,000行・1口座はheader込み20,000行のreader制限を維持して2シートを同じ口座へ割り当てる。4口座は4シート。CIは既存5反復のMySQL競合に専用ReleaseGateTestを追加し、性能測定は手動で再現する。

## 本番で残る証跡

公開URLのログイン画面到達のみ確認した。配置commit・migration・cron・ログイン後操作は未確認であり、公開URLが最新mainとは推測しない。接続先または既存証跡の提供を待つ。ローカルから本番DBには接続しない。

サーバー上の配置ディレクトリで以下を読み取り、結果だけ記録する。`.env`、credential、実名、ログ本文をIssue/PRへ貼らない。

```sh
git rev-parse HEAD
git status --short
php artisan migrate:status
php artisan schedule:list
crontab -l
```

- 配置commitと承認・マージ済みcommitを比較する。未適用migration、dirty状態、schedule/cron有効性を個別記録する。
- 実際の08:00 JST実行後に`storage/logs/notifications-scheduler.log`をサーバー上で確認する。本PRのコマンドは開始・完了／失敗をrun_id付きJSONで出力する。開始と完了の同run_id、`at`の+09:00、件数、duration、failedなしをサーバーのcron実行時刻／終了状態と照合する。ログ形式変更前の配置ではサーバー側開始・終了証跡も必要。
- 同milestone/date再実行0件は合成データで検証済み。本番で再実行する際は、新しく対象となる通知がないか確認し、生成による本番への影響を記録する。本番へテスト用ロットを投入しない。
- 認証済み本番セッションで設定保存・一括既読・dismiss・対応済み表示を確認し、操作対象と影響を記録する。
- ケース表の未検証を専用assertion／操作で埋め、P0の未検証・失敗が残らないことを確認してから#14/#1のリリース可否を判定する。

本PRは検証基盤とローカル証跡を提供する。マージだけで#22やMVPを完了にしない。
