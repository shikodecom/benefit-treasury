<p class="help">残額を初期在庫として移行します。台帳・ロットのない口座を選び、残数量と円額をそれぞれ確認してください。過去の取得・利用履歴は復元しません。</p>
<div class="field"><label>既存口座（名義・制度・単位）<select name="{{ $prefix }}[account_id]"><option value="">選択してください</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(($options['account_id'] ?? '') == $account->id)>{{ $account->householdMember?->display_name ?: '家族共通' }} / {{ $account->program->name }}（{{ $account->program->unit_name }}）/ {{ $account->account_label }}</option>@endforeach</select></label></div>
<input type="hidden" name="{{ $prefix }}[create_account]" value="0"><label class="check"><input type="checkbox" name="{{ $prefix }}[create_account]" value="1" @checked(!empty($options['create_account']))>新規口座を作成予定にする（実行時に作成）</label>
<div class="field"><label>新規口座の制度<select name="{{ $prefix }}[new_program_id]"><option value="">選択してください</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($options['new_program_id'] ?? '') == $program->id)>{{ $program->name }}（{{ $program->unit_name }}）</option>@endforeach</select></label></div>
<div class="field"><label>新規口座の名義<select name="{{ $prefix }}[new_member_id]"><option value="">家族共通</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected(($options['new_member_id'] ?? '') == $member->id)>{{ $member->display_name }}</option>@endforeach</select></label></div>
<div class="field"><label>新規口座のラベル<input name="{{ $prefix }}[new_label]" maxlength="150" value="{{ $options['new_label'] ?? '' }}"></label></div>
<div class="field"><label>数量単位（口座の単位と一致）<input name="{{ $prefix }}[native_unit]" maxlength="40" value="{{ $options['native_unit'] ?? $raw['native_unit'] ?? '' }}"></label></div>
<div class="field"><label>残額確認日<input type="date" name="{{ $prefix }}[snapshot_at]" value="{{ $options['snapshot_at'] ?? $data['snapshot_at'] ?? $raw['snapshot_at'] ?? '' }}"></label></div>
<input type="hidden" name="{{ $prefix }}[confirm_snapshot]" value="0"><label class="check"><input type="checkbox" name="{{ $prefix }}[confirm_snapshot]" value="1" @checked(!empty($options['confirm_snapshot']))>残額の初期移行であり、過去履歴と重複しないことを確認した</label>
@if($record)
@foreach(['remaining_quantity'=>'残数量（円額から自動変換しない）','remaining_yen'=>'残額（整数円）'] as $field=>$label)
<div class="field"><label>{{ $label }}<input type="number" min="0" step="{{ $field === 'remaining_yen' ? '1' : '0.0001' }}" name="{{ $prefix }}[{{ $field }}]" value="{{ $options[$field] ?? $data[$field] ?? str_replace(',', '', $raw[$field] ?? '') }}"></label></div>
@endforeach
@foreach(['acquired_at'=>'取得日','expires_at'=>'有効期限'] as $field=>$label)
<div class="field"><label>{{ $label }}<input type="date" name="{{ $prefix }}[{{ $field }}]" value="{{ $options[$field] ?? $data[$field] ?? $raw[$field] ?? '' }}"></label></div>
@endforeach
@php($state = $options['voucher_state'] ?? (['未使用'=>'unused','一部使用'=>'partial','使用中'=>'partial','使用済'=>'used','使用済み'=>'used'][$raw['voucher_state'] ?? ''] ?? ($raw['voucher_state'] ?? '')))
<div class="field"><label>利用状態<select name="{{ $prefix }}[voucher_state]"><option value="">未確認</option>@foreach(['unused'=>'未使用','partial'=>'一部使用','used'=>'使用済み（残額・数量ゼロ）'] as $key=>$label)<option value="{{ $key }}" @selected($state === $key)>{{ $label }}</option>@endforeach</select></label></div>
@endif
