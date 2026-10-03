@php($preview = $record->normalized_data_json ?? [])
@if($record->record_type === 'transfer' && isset($preview['source_quantity']))
<p class="help">移行元 {{ $preview['source_quantity'] }} native / 予定 {{ $preview['expected_destination_quantity'] ?? '未設定' }} native / 着弾 {{ $preview['actual_destination_quantity'] ?? '未確認' }} native<br>
状態 {{ ['planned'=>'予定','processing'=>'処理中','completed'=>'完了'][$preview['status']] }} / 申請日 {{ $preview['started_at'] ?? 'なし' }} / 完了日 {{ $preview['completed_at'] ?? 'なし' }}
@if($preview['planning_equivalent_quantity'] !== null)<br>計画用換算 {{ $preview['planning_equivalent_quantity'] }} {{ $programs->firstWhere('id', $preview['planning_equivalent_program_id'])?->name }}（残高に加算しません）@endif</p>
@elseif($record->record_type === 'conversion_rule' && isset($preview['from_quantity']))
<p class="help">交換元 {{ $preview['from_quantity'] }} native → 交換先 {{ $preview['to_quantity'] }} native / {{ $preview['active'] ? '有効な新しい版' : '無効な新しい版' }}
@if($preview['campaign_only'])<br>キャンペーン期間 {{ $preview['valid_from'] }}〜{{ $preview['valid_to'] }}@endif</p>
@elseif($record->record_type === 'premium_voucher' && isset($preview['remaining_quantity']))
<p class="help">残数量 {{ $preview['remaining_quantity'] }} {{ $preview['native_unit'] }} / 残額 {{ number_format($preview['remaining_yen']) }} 円<br>
名義 {{ $members->firstWhere('id', $preview['member_id'])?->display_name ?: '家族共通' }} / 制度 {{ $programs->firstWhere('id', $preview['program_id'])?->name }} / 口座 {{ $preview['account_id'] ? '#'.$preview['account_id'] : '新規作成予定' }}<br>
取得 {{ $preview['acquired_at'] }} / 期限 {{ $preview['expires_at'] }} / 残額確認 {{ $preview['snapshot_at'] }} / {{ ['unused'=>'未使用','partial'=>'一部使用','used'=>'使用済み'][$preview['voucher_state']] }}<br>
{{ $preview['voucher_state'] === 'used' ? '残数ゼロのロットだけ保存します。取引は追加しません。' : '残額確認日の残数量だけを1件の取引で登録します。' }}</p>
@endif
