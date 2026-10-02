@php($preview = $record->normalized_data_json ?? [])
@if($record->record_type === 'transfer' && isset($preview['source_quantity']))
<p class="help">移行元 {{ $preview['source_quantity'] }} native / 予定 {{ $preview['expected_destination_quantity'] ?? '未設定' }} native / 着弾 {{ $preview['actual_destination_quantity'] ?? '未確認' }} native<br>
状態 {{ ['planned'=>'予定','processing'=>'処理中','completed'=>'完了'][$preview['status']] }} / 申請日 {{ $preview['started_at'] ?? 'なし' }} / 完了日 {{ $preview['completed_at'] ?? 'なし' }}
@if($preview['planning_equivalent_quantity'] !== null)<br>計画用換算 {{ $preview['planning_equivalent_quantity'] }} {{ $programs->firstWhere('id', $preview['planning_equivalent_program_id'])?->name }}（残高に加算しません）@endif</p>
@elseif($record->record_type === 'conversion_rule' && isset($preview['from_quantity']))
<p class="help">交換元 {{ $preview['from_quantity'] }} native → 交換先 {{ $preview['to_quantity'] }} native / {{ $preview['active'] ? '有効な新しい版' : '無効な新しい版' }}
@if($preview['campaign_only'])<br>キャンペーン期間 {{ $preview['valid_from'] }}〜{{ $preview['valid_to'] }}@endif</p>
@endif
