# Issue #14 ケース別照合（2026-10-03）

対象コード: main `6d64c7c`（PR #27まで）＋本PR。全P0 73件・P1 40件を列挙する。`pass`は合成データの自動assertionが対応するケースで、本番動作の証明ではない。専用assertionや操作証跡が足りないケースは、近いテストが通っていても`未検証`に残す。失敗・適用外への置換やMVP範囲変更は行っていない。

判定はFeature実行結果と以下のテストメソッドを照合する。競合ケースはCIの各ステップの実ログも確認する。

| ケース | 内容 | 状態 | 証跡／残確認 |
| --- | --- | --- | --- |
| P0-01 | 残高基本 | pass | [LedgerTest::test_manual_transactions_preserve_balance_and_prevent_overdraft](../tests/Feature/LedgerTest.php) |
| P0-02 | 負数入力禁止 | 未検証 | 負数を各入力経路へ送信する専用assertionがない。 |
| P0-03 | direction不整合 | 未検証 | direction不整合を直接試すassertionがない。 |
| P0-04 | opening balance二重登録 | pass | [LedgerTest::test_manual_transactions_preserve_balance_and_prevent_overdraft](../tests/Feature/LedgerTest.php) |
| P0-05 | reversal | pass | [LedgerTest::test_reversal_is_single_use_and_lot_cancel_is_atomic](../tests/Feature/LedgerTest.php) |
| P0-06 | 二重reversal | pass | [LedgerTest::test_reversal_is_single_use_and_lot_cancel_is_atomic](../tests/Feature/LedgerTest.php) |
| P0-07 | reversalのreversal | 未検証 | 取消取引そのものの取消を拒否する専用assertionがない。 |
| P0-08 | account overdraw | pass | [LedgerTest::test_manual_transactions_preserve_balance_and_prevent_overdraft](../tests/Feature/LedgerTest.php) |
| P0-09 | concurrent account use | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php) account_use、[CI](https://github.com/shikodecom/benefit-treasury/actions/runs/37084982930)の競合ステップ全5反復成功（後続テスト起動のsuite指定失敗は別途修正）。 |
| P0-10 | lot basic | pass | [LedgerTest::test_lot_acquisition_use_expiry_and_cancel_are_history_based](../tests/Feature/LedgerTest.php) |
| P0-11 | lot acquire rollback | 未検証 | 既存テストは非active口座拒否。取得取引保存途中の故障注入ではない。 |
| P0-12 | partial use | pass | [LedgerTest::test_lot_acquisition_use_expiry_and_cancel_are_history_based](../tests/Feature/LedgerTest.php) |
| P0-13 | lot overdraw | pass | [LedgerTest::test_lot_acquisition_use_expiry_and_cancel_are_history_based](../tests/Feature/LedgerTest.php) |
| P0-14 | FEFO | 未検証 | 手動配分は検証済み。FEFO自動配分ボタンの操作結果は未確認。 |
| P0-15 | FEFO同時利用 | 未検証 | mysql-concurrency.phpのlot_useが対象。FEFO複数ロット自動配分の競合は別途必要。 |
| P0-16 | expired does not auto decrement | 未検証 | 期限後・失効前の残高不変を明示したassertionがない。 |
| P0-17 | expire remaining | pass | [LedgerTest::test_lot_acquisition_use_expiry_and_cancel_are_history_based](../tests/Feature/LedgerTest.php) |
| P0-18 | lot account lock | 未検証 | 既存ロットの口座変更をHTTPで試す専用assertionがない。 |
| P0-19 | cancelled lot | pass | [LedgerTest::test_reversal_is_single_use_and_lot_cancel_is_atomic](../tests/Feature/LedgerTest.php) |
| P0-20 | listing partial reserve | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-21 | draft does not reserve | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-22 | listing over-reserve | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P0-23 | concurrent listing reserve | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php) publish、[CI](https://github.com/shikodecom/benefit-treasury/actions/runs/37084982930)の競合ステップ全5反復成功（後続テスト起動のsuite指定失敗は別途修正）。 |
| P0-24 | use while listed | 未検証 | 出品中ロットを直接利用し、予約数を保持する専用assertionがない。 |
| P0-25 | sell listing | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-26 | double sell | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php) sell、[CI](https://github.com/shikodecom/benefit-treasury/actions/runs/37084982930)の競合ステップ全5反復成功（後続テスト起動のsuite指定失敗は別途修正）。 |
| P0-27 | sell rollback | 未検証 | 売却途中で故障させ全rollbackする専用assertionがない。 |
| P0-28 | listing cancel | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P0-29 | ended unsold | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P0-30 | sale reversal | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-31 | listing double reverse | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-32 | non-transferable listing | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P0-33 | transfer planned | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-34 | transfer start | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-35 | transfer source insufficient | 未検証 | 予約による不足はテスト済み。単純残高不足の移行申請を専用確認する。 |
| P0-36 | concurrent transfer start | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php) transfer_start / transfer_complete、[CI](https://github.com/shikodecom/benefit-treasury/actions/runs/37084982930)の競合ステップ全5反復成功（後続テスト起動のsuite指定失敗は別途修正）。 |
| P0-37 | transfer complete | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-38 | double complete | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-39 | actual differs | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-40 | transfer cancel planned | pass | [TransferTest::test_cancel_refund_error_and_overdue_do_not_invent_balance](../tests/Feature/TransferTest.php) |
| P0-41 | processing cancel with refund | pass | [TransferTest::test_cancel_refund_error_and_overdue_do_not_invent_balance](../tests/Feature/TransferTest.php) |
| P0-42 | error does not auto refund | pass | [TransferTest::test_cancel_refund_error_and_overdue_do_not_invent_balance](../tests/Feature/TransferTest.php) |
| P0-43 | multi-step transfer | pass | [ReleaseGateTest::test_e2e_03_route_preview_commit_three_steps_and_all_ledger_views](../tests/Feature/ReleaseGateTest.php) |
| P0-44 | intermediate pool | pass | [TransferTest::test_multi_step_accepts_pooled_intermediate_balance](../tests/Feature/TransferTest.php) |
| P0-45 | native vs planning equivalent | 未検証 | nativeと計画換算値の入力組合せをIssue記載どおり全照合していない。 |
| P0-46 | conversion rule immutable after use | pass | [ConversionTest::test_rule_versions_protect_history_and_validate_exchange_quantities](../tests/Feature/ConversionTest.php) |
| P0-47 | rule version | pass | [ConversionTest::test_rule_versions_protect_history_and_validate_exchange_quantities](../tests/Feature/ConversionTest.php) |
| P0-48 | minimum / increment | pass | [ConversionTest::test_rule_versions_protect_history_and_validate_exchange_quantities](../tests/Feature/ConversionTest.php) |
| P0-49 | route continuity | pass | [ConversionTest::test_route_continuity_loop_simulation_and_invalid_preferred_rule](../tests/Feature/ConversionTest.php) |
| P0-50 | route loop | pass | [ConversionTest::test_route_continuity_loop_simulation_and_invalid_preferred_rule](../tests/Feature/ConversionTest.php) |
| P0-51 | Excel duplicate exact file | pass | [ReleaseGateTest::test_e2e_04_upload_resolve_alias_verify_commit_and_reimport_two_filenames](../tests/Feature/ReleaseGateTest.php) |
| P0-52 | Excel duplicate renamed file | pass | [ReleaseGateTest::test_e2e_04_upload_resolve_alias_verify_commit_and_reimport_two_filenames](../tests/Feature/ReleaseGateTest.php) |
| P0-53 | legitimate duplicate transactions | pass | [MvpIntegrationTest::test_excel_identical_legitimate_rows_keep_distinct_occurrence_fingerprints](../tests/Feature/MvpIntegrationTest.php) |
| P0-54 | import opening balance | pass | [MvpIntegrationTest::test_jal_layout_keeps_undated_opening_row_for_explicit_date_override](../tests/Feature/MvpIntegrationTest.php) |
| P0-55 | import negative JAL | pass | [MvpIntegrationTest::test_anonymous_workbook_layouts_preview_reconcile_and_dedupe](../tests/Feature/MvpIntegrationTest.php) |
| P0-56 | import balance verification | pass | [MvpIntegrationTest::test_anonymous_workbook_layouts_preview_reconcile_and_dedupe](../tests/Feature/MvpIntegrationTest.php) |
| P0-57 | import balance mismatch | pass | [MvpIntegrationTest::test_excel_balance_mismatch_requires_explicit_confirmation](../tests/Feature/MvpIntegrationTest.php) |
| P0-58 | Excel aggregate area exclusion | pass | [MvpIntegrationTest::test_excel_right_side_aggregate_columns_are_not_transactions](../tests/Feature/MvpIntegrationTest.php) |
| P0-59 | PII removal | pass | [MvpIntegrationTest::test_excel_free_text_private_data_is_redacted_and_formula_cell_is_ignored](../tests/Feature/MvpIntegrationTest.php) |
| P0-60 | raw JSON sanitized | pass | [MvpIntegrationTest::test_excel_free_text_private_data_is_redacted_and_formula_cell_is_ignored](../tests/Feature/MvpIntegrationTest.php) |
| P0-61 | malicious workbook formula | pass | [MvpIntegrationTest::test_excel_free_text_private_data_is_redacted_and_formula_cell_is_ignored](../tests/Feature/MvpIntegrationTest.php) |
| P0-62 | malformed xlsx | pass | [MvpIntegrationTest::test_excel_abandoned_preview_can_be_reuploaded_and_malformed_file_is_rejected](../tests/Feature/MvpIntegrationTest.php) |
| P0-63 | xlsx bomb protection | pass | [MvpIntegrationTest::test_excel_external_link_and_expanded_size_bomb_are_rejected](../tests/Feature/MvpIntegrationTest.php) |
| P0-64 | import partial failure | pass | [MvpIntegrationTest::test_excel_failed_row_can_be_retried_without_reimporting_successful_rows](../tests/Feature/MvpIntegrationTest.php) |
| P0-65 | retry idempotency | pass | [MvpIntegrationTest::test_excel_failed_row_can_be_retried_without_reimporting_successful_rows](../tests/Feature/MvpIntegrationTest.php) |
| P0-66 | expiry JST boundary | 未検証 | JST 00:30の他ケースはあるが「本日期限」専用assertionがない。 |
| P0-67 | notification duplicate | pass | [ReleaseGateTest::test_e2e_05_notice_preferences_rerun_action_read_and_dismiss](../tests/Feature/ReleaseGateTest.php) |
| P0-68 | notification milestone date | pass | [MvpIntegrationTest::test_alias_search_and_revised_expiry_and_transfer_overdue_notifications](../tests/Feature/MvpIntegrationTest.php) |
| P0-69 | notification remaining zero | pass | [ReleaseGateTest::test_e2e_05_notice_preferences_rerun_action_read_and_dismiss](../tests/Feature/ReleaseGateTest.php) |
| P0-70 | notification sell_now unlisted | 未検証 | sell_now_unlisted型と1件生成はテスト済み。文面の「未出品」は未assert。 |
| P0-71 | notification listed | 未検証 | 出品済みの価格見直し文面と通知1件を専用確認していない。 |
| P0-72 | transfer overdue notification | pass | [MvpIntegrationTest::test_alias_search_and_revised_expiry_and_transfer_overdue_notifications](../tests/Feature/MvpIntegrationTest.php) |
| P0-73 | notification secret leak | 未検証 | Excel/コマンドの非漏洩はテスト済み。通知本文に全秘密属性がないことは未assert。 |
| P1-01 | member inactive | pass | [MasterManagementTest::test_invalid_url_and_inactive_master_cannot_be_used_for_new_account](../tests/Feature/MasterManagementTest.php) |
| P1-02 | program inactive | pass | [MasterManagementTest::test_invalid_url_and_inactive_master_cannot_be_used_for_new_account](../tests/Feature/MasterManagementTest.php) |
| P1-03 | inactive account with balance | pass | [DashboardTest::test_filters_include_inactive_accounts_and_policy_update_is_validated](../tests/Feature/DashboardTest.php) |
| P1-04 | duplicate account warning | pass | [MasterManagementTest::test_duplicate_account_requires_explicit_confirmation](../tests/Feature/MasterManagementTest.php) |
| P1-05 | program unit immutable | pass | [MasterManagementTest::test_program_unit_is_locked_after_first_transaction](../tests/Feature/MasterManagementTest.php) |
| P1-06 | official URL scheme | pass | [MasterManagementTest::test_invalid_url_and_inactive_master_cannot_be_used_for_new_account](../tests/Feature/MasterManagementTest.php) |
| P1-07 | alias search | pass | [SearchTest::test_alias_matches_accounts_transactions_listings_transfers_and_rules](../tests/Feature/SearchTest.php) |
| P1-08 | alias scope | 未検証 | scoped alias解決はテスト済み。同alias・別scopeの競合組合せは未assert。 |
| P1-09 | dashboard 31-day boundary | 未検証 | 31日ちょうどの境界を未assert。 |
| P1-10 | dashboard 30-day boundary | 未検証 | 30日ちょうどの境界を未assert。 |
| P1-11 | dashboard 7-day boundary | 未検証 | 7日ちょうどのDashboard境界を未assert。 |
| P1-12 | dashboard expired | pass | [DashboardTest::test_dashboard_uses_japan_dates_balances_and_active_listing_reservations](../tests/Feature/DashboardTest.php) |
| P1-13 | dashboard partial listing | pass | [DashboardTest::test_priority_distinguishes_unlisted_partial_and_fully_listed_lots](../tests/Feature/DashboardTest.php) |
| P1-14 | dashboard hold does not suppress expiry | 未検証 | hold指定の期限警告を専用確認していない。 |
| P1-15 | dashboard no expiry | pass | [DashboardTest::test_dashboard_uses_japan_dates_balances_and_active_listing_reservations](../tests/Feature/DashboardTest.php) |
| P1-16 | priority order | pass | [DashboardTest::test_priority_distinguishes_unlisted_partial_and_fully_listed_lots](../tests/Feature/DashboardTest.php) |
| P1-17 | listing price history | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P1-18 | relist | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P1-19 | multi-lot listing | pass | [ListingTest::test_multi_lot_sale_and_negative_proceeds_and_policy_confirmation](../tests/Feature/ListingTest.php) |
| P1-20 | sale net proceeds | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P1-21 | negative proceeds warning | 未検証 | 負の手取り計算はテスト済み。警告表示は未assert。 |
| P1-22 | transfer overdue derived | pass | [TransferTest::test_cancel_refund_error_and_overdue_do_not_invent_balance](../tests/Feature/TransferTest.php) |
| P1-23 | completed not overdue | pass | [ReleaseGateTest::test_e2e_03_route_preview_commit_three_steps_and_all_ledger_views](../tests/Feature/ReleaseGateTest.php) |
| P1-24 | rule validity boundary | pass | [ConversionTest::test_current_rules_use_jst_and_keep_normal_and_campaign_versions](../tests/Feature/ConversionTest.php) |
| P1-25 | campaign overlap | pass | [ConversionTest::test_current_rules_use_jst_and_keep_normal_and_campaign_versions](../tests/Feature/ConversionTest.php) |
| P1-26 | route simulation rounding | pass | [ConversionTest::test_route_continuity_loop_simulation_and_invalid_preferred_rule](../tests/Feature/ConversionTest.php) |
| P1-27 | search keyword | pass | [SearchTest::test_keyword_search_keeps_drafts_without_linked_inventory_or_transfer_steps](../tests/Feature/SearchTest.php) |
| P1-28 | search filters combined | pass | [SearchTest::test_transfer_filters_apply_member_program_category_and_status_together](../tests/Feature/SearchTest.php) |
| P1-29 | search URL persistence | pass | [SearchTest::test_pagination_details_and_reset_keep_or_clear_search_conditions](../tests/Feature/SearchTest.php) |
| P1-30 | pagination persistence | pass | [SearchTest::test_pagination_details_and_reset_keep_or_clear_search_conditions](../tests/Feature/SearchTest.php) |
| P1-31 | search SQL injection | 未検証 | SQL injection文字列で結果・件数不変を専用確認していない。 |
| P1-32 | search XSS | pass | [MvpIntegrationTest::test_search_filters_and_notifications_are_deduplicated_in_jst](../tests/Feature/MvpIntegrationTest.php) |
| P1-33 | notification read | pass | [ReleaseGateTest::test_e2e_05_notice_preferences_rerun_action_read_and_dismiss](../tests/Feature/ReleaseGateTest.php) |
| P1-34 | notification dismiss | pass | [ReleaseGateTest::test_e2e_05_notice_preferences_rerun_action_read_and_dismiss](../tests/Feature/ReleaseGateTest.php) |
| P1-35 | notification catch-up | pass | [ReleaseGateTest::test_notification_catch_up_only_creates_current_stage_and_command_has_safe_timestamps](../tests/Feature/ReleaseGateTest.php) |
| P1-36 | import unknown alias | pass | [MvpIntegrationTest::test_unknown_program_alias_stays_unresolved_until_scoped_alias_is_registered](../tests/Feature/MvpIntegrationTest.php) |
| P1-37 | import alias mapping | pass | [MvpIntegrationTest::test_unknown_program_alias_stays_unresolved_until_scoped_alias_is_registered](../tests/Feature/MvpIntegrationTest.php) |
| P1-38 | import member mapping | pass | [MvpIntegrationTest::test_excel_identical_history_is_scoped_to_the_selected_member_account](../tests/Feature/MvpIntegrationTest.php) |
| P1-39 | importer version | 未検証 | batchのimporter_version値を専用assertしていない。 |
| P1-40 | unsupported headers | 未検証 | 各必須header欠落時のsheet errorを専用assertしていない。 |

暫定集計: pass 88件／未検証 25件／失敗0件／適用外0件。未検証P0があるため、全P0成功とは判定しない。

ケースに加え、本番配置commit・migration・cronの08:00実行・認証後操作が未検証。残確認は#22/#14に集約する。
