# Issue #14 ケース別照合（2026-10-03）

対象コード: main `6d64c7c`（PR #27まで）＋本PR。全P0 73件・P1 40件を列挙する。`pass`は合成データの自動assertionが対応するケースで、本番動作の証明ではない。専用assertionや操作証跡が足りないケースは、近いテストが通っていても`未検証`に残す。失敗・適用外への置換やMVP範囲変更は行っていない。

判定はFeature実行結果と以下のテストメソッドを照合する。競合ケースはCIの各ステップの実ログも確認する。

| ケース | 内容 | 状態 | 証跡／残確認 |
| --- | --- | --- | --- |
| P0-01 | 残高基本 | pass | [LedgerTest::test_manual_transactions_preserve_balance_and_prevent_overdraft](../tests/Feature/LedgerTest.php) |
| P0-02 | 負数入力禁止 | pass | [ReleaseGateBoundaryTest::test_negative_quantity_and_forged_direction_cannot_create_transactions](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-03 | direction不整合 | pass | [ReleaseGateBoundaryTest::test_negative_quantity_and_forged_direction_cannot_create_transactions](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-04 | opening balance二重登録 | pass | [LedgerTest::test_manual_transactions_preserve_balance_and_prevent_overdraft](../tests/Feature/LedgerTest.php) |
| P0-05 | reversal | pass | [LedgerTest::test_reversal_is_single_use_and_lot_cancel_is_atomic](../tests/Feature/LedgerTest.php) |
| P0-06 | 二重reversal | pass | [LedgerTest::test_reversal_is_single_use_and_lot_cancel_is_atomic](../tests/Feature/LedgerTest.php) |
| P0-07 | reversalのreversal | pass | [ReleaseGateBoundaryTest::test_reversal_of_reversal_rejects_and_guides_to_adjustment](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-08 | account overdraw | pass | [LedgerTest::test_manual_transactions_preserve_balance_and_prevent_overdraft](../tests/Feature/LedgerTest.php) |
| P0-09 | concurrent account use | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php) account_use、[CI](https://github.com/shikodecom/benefit-treasury/actions/runs/37084982930)の競合ステップ全5反復成功（後続テスト起動のsuite指定失敗は別途修正）。 |
| P0-10 | lot basic | pass | [LedgerTest::test_lot_acquisition_use_expiry_and_cancel_are_history_based](../tests/Feature/LedgerTest.php) |
| P0-11 | lot acquire rollback | pass | [ReleaseGateBoundaryTest::test_acquisition_insert_failure_rolls_back_the_inserted_lot](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-12 | partial use | pass | [LedgerTest::test_lot_acquisition_use_expiry_and_cancel_are_history_based](../tests/Feature/LedgerTest.php) |
| P0-13 | lot overdraw | pass | [LedgerTest::test_lot_acquisition_use_expiry_and_cancel_are_history_based](../tests/Feature/LedgerTest.php) |
| P0-14 | FEFO | pass | 375pxで期限順に配分→Aへ2・Bへ1→保存後合計9。 [配分画面](evidence/mobile-fefo-2026-10-03.jpg) / [保存後台帳](evidence/mobile-fefo-result-2026-10-03.jpg)。期限日は合成実行日の当日／6か月後。 |
| P0-15 | FEFO同時利用 | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php)のlot_use: 同じロットに2件同時利用、1件成功・残数非負を5反復。合成値は10から7を2件。 |
| P0-16 | expired does not auto decrement | pass | [ReleaseGateBoundaryTest::test_expired_inventory_is_derived_and_account_change_is_rejected](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-17 | expire remaining | pass | [LedgerTest::test_lot_acquisition_use_expiry_and_cancel_are_history_based](../tests/Feature/LedgerTest.php) |
| P0-18 | lot account lock | pass | [ReleaseGateBoundaryTest::test_expired_inventory_is_derived_and_account_change_is_rejected](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-19 | cancelled lot | pass | [LedgerTest::test_reversal_is_single_use_and_lot_cancel_is_atomic](../tests/Feature/LedgerTest.php) |
| P0-20 | listing partial reserve | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-21 | draft does not reserve | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-22 | listing over-reserve | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P0-23 | concurrent listing reserve | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php) publish、[CI](https://github.com/shikodecom/benefit-treasury/actions/runs/37084982930)の競合ステップ全5反復成功（後続テスト起動のsuite指定失敗は別途修正）。 |
| P0-24 | use while listed | pass | [ReleaseGateBoundaryTest::test_using_listed_inventory_keeps_reservation_and_rejects_overuse](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-25 | sell listing | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-26 | double sell | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php) sell、[CI](https://github.com/shikodecom/benefit-treasury/actions/runs/37084982930)の競合ステップ全5反復成功（後続テスト起動のsuite指定失敗は別途修正）。 |
| P0-27 | sell rollback | pass | [ReleaseGateBoundaryTest::test_second_sale_insert_failure_rolls_back_both_lots_and_listing](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-28 | listing cancel | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P0-29 | ended unsold | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P0-30 | sale reversal | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-31 | listing double reverse | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P0-32 | non-transferable listing | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P0-33 | transfer planned | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-34 | transfer start | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-35 | transfer source insufficient | pass | [ReleaseGateBoundaryTest::test_insufficient_transfer_rolls_back_and_planning_equivalent_never_becomes_native](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-36 | concurrent transfer start | pass | [mysql-concurrency.php](../tests/manual/mysql-concurrency.php)の同一stepを2プロセスで同時start。開始前14・申請7で、残高7・transfer_out1件・processingを5反復。別stepの残高競合／同一step着弾も検証。 |
| P0-37 | transfer complete | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-38 | double complete | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-39 | actual differs | pass | [TransferTest::test_planned_start_complete_and_actual_difference_are_history_based](../tests/Feature/TransferTest.php) |
| P0-40 | transfer cancel planned | pass | [TransferTest::test_cancel_refund_error_and_overdue_do_not_invent_balance](../tests/Feature/TransferTest.php) |
| P0-41 | processing cancel with refund | pass | [TransferTest::test_cancel_refund_error_and_overdue_do_not_invent_balance](../tests/Feature/TransferTest.php) |
| P0-42 | error does not auto refund | pass | [TransferTest::test_cancel_refund_error_and_overdue_do_not_invent_balance](../tests/Feature/TransferTest.php) |
| P0-43 | multi-step transfer | pass | [ReleaseGateTest::test_e2e_03_route_preview_commit_three_steps_and_all_ledger_views](../tests/Feature/ReleaseGateTest.php) |
| P0-44 | intermediate pool | pass | [TransferTest::test_multi_step_accepts_pooled_intermediate_balance](../tests/Feature/TransferTest.php) |
| P0-45 | native vs planning equivalent | pass | [ReleaseGateBoundaryTest::test_insufficient_transfer_rolls_back_and_planning_equivalent_never_becomes_native](../tests/Feature/ReleaseGateBoundaryTest.php) |
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
| P0-66 | expiry JST boundary | pass | [ReleaseGateBoundaryTest::test_jst_today_and_dashboard_exact_boundaries_keep_hold_expiry_visible](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-67 | notification duplicate | pass | [ReleaseGateTest::test_e2e_05_notice_preferences_rerun_action_read_and_dismiss](../tests/Feature/ReleaseGateTest.php) |
| P0-68 | notification milestone date | pass | [MvpIntegrationTest::test_alias_search_and_revised_expiry_and_transfer_overdue_notifications](../tests/Feature/MvpIntegrationTest.php) |
| P0-69 | notification remaining zero | pass | [ReleaseGateTest::test_e2e_05_notice_preferences_rerun_action_read_and_dismiss](../tests/Feature/ReleaseGateTest.php) |
| P0-70 | notification sell_now unlisted | pass | [ReleaseGateBoundaryTest::test_notification_text_deduplicates_listed_unlisted_and_never_copies_private_attributes](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-71 | notification listed | pass | [ReleaseGateBoundaryTest::test_notification_text_deduplicates_listed_unlisted_and_never_copies_private_attributes](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P0-72 | transfer overdue notification | pass | [MvpIntegrationTest::test_alias_search_and_revised_expiry_and_transfer_overdue_notifications](../tests/Feature/MvpIntegrationTest.php) |
| P0-73 | notification secret leak | pass | [ReleaseGateBoundaryTest::test_notification_text_deduplicates_listed_unlisted_and_never_copies_private_attributes](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-01 | member inactive | pass | [MasterManagementTest::test_invalid_url_and_inactive_master_cannot_be_used_for_new_account](../tests/Feature/MasterManagementTest.php) |
| P1-02 | program inactive | pass | [MasterManagementTest::test_invalid_url_and_inactive_master_cannot_be_used_for_new_account](../tests/Feature/MasterManagementTest.php) |
| P1-03 | inactive account with balance | pass | [DashboardTest::test_filters_include_inactive_accounts_and_policy_update_is_validated](../tests/Feature/DashboardTest.php) |
| P1-04 | duplicate account warning | pass | [MasterManagementTest::test_duplicate_account_requires_explicit_confirmation](../tests/Feature/MasterManagementTest.php) |
| P1-05 | program unit immutable | pass | [MasterManagementTest::test_program_unit_is_locked_after_first_transaction](../tests/Feature/MasterManagementTest.php) |
| P1-06 | official URL scheme | pass | [MasterManagementTest::test_invalid_url_and_inactive_master_cannot_be_used_for_new_account](../tests/Feature/MasterManagementTest.php) |
| P1-07 | alias search | pass | [SearchTest::test_alias_matches_accounts_transactions_listings_transfers_and_rules](../tests/Feature/SearchTest.php) |
| P1-08 | alias scope | pass | [ReleaseGateBoundaryTest::test_same_alias_resolves_by_scope_and_injection_search_keeps_database_intact](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-09 | dashboard 31-day boundary | pass | [ReleaseGateBoundaryTest::test_jst_today_and_dashboard_exact_boundaries_keep_hold_expiry_visible](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-10 | dashboard 30-day boundary | pass | [ReleaseGateBoundaryTest::test_jst_today_and_dashboard_exact_boundaries_keep_hold_expiry_visible](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-11 | dashboard 7-day boundary | pass | [ReleaseGateBoundaryTest::test_jst_today_and_dashboard_exact_boundaries_keep_hold_expiry_visible](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-12 | dashboard expired | pass | [DashboardTest::test_dashboard_uses_japan_dates_balances_and_active_listing_reservations](../tests/Feature/DashboardTest.php) |
| P1-13 | dashboard partial listing | pass | [DashboardTest::test_priority_distinguishes_unlisted_partial_and_fully_listed_lots](../tests/Feature/DashboardTest.php) |
| P1-14 | dashboard hold does not suppress expiry | pass | [ReleaseGateBoundaryTest::test_jst_today_and_dashboard_exact_boundaries_keep_hold_expiry_visible](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-15 | dashboard no expiry | pass | [DashboardTest::test_dashboard_uses_japan_dates_balances_and_active_listing_reservations](../tests/Feature/DashboardTest.php) |
| P1-16 | priority order | pass | [DashboardTest::test_priority_distinguishes_unlisted_partial_and_fully_listed_lots](../tests/Feature/DashboardTest.php) |
| P1-17 | listing price history | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P1-18 | relist | pass | [ListingTest::test_reservation_is_rechecked_and_restricted_lots_cannot_publish](../tests/Feature/ListingTest.php) |
| P1-19 | multi-lot listing | pass | [ListingTest::test_multi_lot_sale_and_negative_proceeds_and_policy_confirmation](../tests/Feature/ListingTest.php) |
| P1-20 | sale net proceeds | pass | [ListingTest::test_draft_publish_price_sale_and_reversal_keep_inventory_and_history](../tests/Feature/ListingTest.php) |
| P1-21 | negative proceeds warning | pass | [ReleaseGateBoundaryTest::test_second_sale_insert_failure_rolls_back_both_lots_and_listing](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-22 | transfer overdue derived | pass | [TransferTest::test_cancel_refund_error_and_overdue_do_not_invent_balance](../tests/Feature/TransferTest.php) |
| P1-23 | completed not overdue | pass | [ReleaseGateTest::test_e2e_03_route_preview_commit_three_steps_and_all_ledger_views](../tests/Feature/ReleaseGateTest.php) |
| P1-24 | rule validity boundary | pass | [ConversionTest::test_current_rules_use_jst_and_keep_normal_and_campaign_versions](../tests/Feature/ConversionTest.php) |
| P1-25 | campaign overlap | pass | [ConversionTest::test_current_rules_use_jst_and_keep_normal_and_campaign_versions](../tests/Feature/ConversionTest.php) |
| P1-26 | route simulation rounding | pass | [ConversionTest::test_route_continuity_loop_simulation_and_invalid_preferred_rule](../tests/Feature/ConversionTest.php) |
| P1-27 | search keyword | pass | [SearchTest::test_keyword_search_keeps_drafts_without_linked_inventory_or_transfer_steps](../tests/Feature/SearchTest.php) |
| P1-28 | search filters combined | pass | [SearchTest::test_transfer_filters_apply_member_program_category_and_status_together](../tests/Feature/SearchTest.php) |
| P1-29 | search URL persistence | pass | [SearchTest::test_pagination_details_and_reset_keep_or_clear_search_conditions](../tests/Feature/SearchTest.php) |
| P1-30 | pagination persistence | pass | [SearchTest::test_pagination_details_and_reset_keep_or_clear_search_conditions](../tests/Feature/SearchTest.php) |
| P1-31 | search SQL injection | pass | [ReleaseGateBoundaryTest::test_same_alias_resolves_by_scope_and_injection_search_keeps_database_intact](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-32 | search XSS | pass | [MvpIntegrationTest::test_search_filters_and_notifications_are_deduplicated_in_jst](../tests/Feature/MvpIntegrationTest.php) |
| P1-33 | notification read | pass | [ReleaseGateTest::test_e2e_05_notice_preferences_rerun_action_read_and_dismiss](../tests/Feature/ReleaseGateTest.php) |
| P1-34 | notification dismiss | pass | [ReleaseGateTest::test_e2e_05_notice_preferences_rerun_action_read_and_dismiss](../tests/Feature/ReleaseGateTest.php) |
| P1-35 | notification catch-up | pass | [ReleaseGateTest::test_notification_catch_up_only_creates_current_stage_and_command_has_safe_timestamps](../tests/Feature/ReleaseGateTest.php) |
| P1-36 | import unknown alias | pass | [MvpIntegrationTest::test_unknown_program_alias_stays_unresolved_until_scoped_alias_is_registered](../tests/Feature/MvpIntegrationTest.php) |
| P1-37 | import alias mapping | pass | [MvpIntegrationTest::test_unknown_program_alias_stays_unresolved_until_scoped_alias_is_registered](../tests/Feature/MvpIntegrationTest.php) |
| P1-38 | import member mapping | pass | [MvpIntegrationTest::test_excel_identical_history_is_scoped_to_the_selected_member_account](../tests/Feature/MvpIntegrationTest.php) |
| P1-39 | importer version | pass | [ReleaseGateBoundaryTest::test_import_version_and_missing_headers_never_guess_column_positions](../tests/Feature/ReleaseGateBoundaryTest.php) |
| P1-40 | unsupported headers | pass | [ReleaseGateBoundaryTest::test_import_version_and_missing_headers_never_guess_column_positions](../tests/Feature/ReleaseGateBoundaryTest.php) |

ローカル合成データの集計: pass 113件（P0 73件／P1 40件）／未検証0件／失敗0件／適用外0件。全ケースに自動assertionまたは操作証跡を対応付けた。本番稼働ゲートは別途未検証。

本番配置commit・migration・cronの08:00実行・認証後操作が未検証。残確認は#22/#14に集約する。

P0-03はServiceがdirection引数を受け付けず、HTTPもdirection指定を明示拒否する。P0-18は口座変更をHTTP/Serviceで明示拒否する。P1-40はsheet単位のunsupported_headersをneeds_reviewとして保存し、位置を推測せず取込0件を維持する安全なエラー扱いを確認した。
