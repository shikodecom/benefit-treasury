@php($options = $record?->normalized_data_json['_mapping'] ?? [])
@php($raw = $record?->raw_data_json ?? [])
@php($data = $record?->normalized_data_json ?? [])
<div class="stack import-mapping">
@if($type === 'transfer')
    @foreach(['from_account_id'=>'移行元口座（名義・制度）', 'to_account_id'=>'移行先口座（名義・制度）'] as $field => $label)
    <div class="field"><label>{{ $label }}<select name="{{ $prefix }}[{{ $field }}]"><option value="">選択してください</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(($options[$field] ?? '') == $account->id)>{{ $account->householdMember?->display_name ?: '家族共通' }} / {{ $account->program->name }} / {{ $account->account_label }}</option>@endforeach</select></label></div>
    @endforeach
    <div class="field"><label>換算数量の制度<select name="{{ $prefix }}[planning_equivalent_program_id]"><option value="">換算なし</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($options['planning_equivalent_program_id'] ?? '') == $program->id)>{{ $program->name }}（{{ $program->unit_name }}）</option>@endforeach</select></label></div>
    <input type="hidden" name="{{ $prefix }}[confirm_status]" value="0"><label class="check"><input type="checkbox" name="{{ $prefix }}[confirm_status]" value="1" @checked(!empty($options['confirm_status']))>各行の移行状態を確認した（空欄は自動確定しない）</label>
    @if($record)
    @php($status = $options['status'] ?? (['予定'=>'planned','手続中'=>'processing','処理中'=>'processing','移行中'=>'processing','完了'=>'completed','済'=>'completed'][$raw['status'] ?? ''] ?? ($raw['status'] ?? '')))
    <div class="field"><label>移行状態<select name="{{ $prefix }}[status]"><option value="">未確認</option>@foreach(['planned'=>'予定','processing'=>'処理中','completed'=>'完了'] as $key => $label)<option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>@endforeach</select></label></div>
    @foreach(['source_quantity'=>'移行元のnative数量（ポイント相当から推測しない）','expected_destination_quantity'=>'移行先のnative予定数量（任意）','actual_destination_quantity'=>'着弾したnative数量（完了時）','planning_equivalent_quantity'=>'計画用の換算数量（残高には入れない）'] as $field => $label)
    <div class="field"><label>{{ $label }}<input name="{{ $prefix }}[{{ $field }}]" type="number" min="0" step="0.0001" value="{{ $options[$field] ?? $data[$field] ?? str_replace(',', '', $raw[$field] ?? '') }}"></label></div>
    @endforeach
    @foreach(['started_at'=>'申請日（予定は空欄）','expected_complete_at'=>'完了予定日','completed_at'=>'完了日（完了時）'] as $field => $label)
    <div class="field"><label>{{ $label }}<input name="{{ $prefix }}[{{ $field }}]" type="date" value="{{ $options[$field] ?? $data[$field] ?? $raw[$field] ?? '' }}"></label></div>
    @endforeach
    <p class="help">処理中・完了済みは登録済み台帳取引へ紐づけます。取引は追加せず、数量・口座・日付が一致する未紐づけの取引だけ使えます。台帳を先に取り込んでください。</p>
    @foreach(['out_transaction_id'=>'既存の出金取引ID（処理中・完了時）','in_transaction_id'=>'既存の入金取引ID（完了・着弾数量が正の時）'] as $field => $label)
    <div class="field"><label>{{ $label }}<input name="{{ $prefix }}[{{ $field }}]" type="number" min="1" value="{{ $options[$field] ?? '' }}"></label></div>
    @endforeach
    @endif
@elseif($type === 'premium_voucher')
    @include('imports.voucher-fields')
@else
    @foreach(['from_program_id'=>'交換元制度', 'to_program_id'=>'交換先制度'] as $field => $label)
    <div class="field"><label>{{ $label }}<select name="{{ $prefix }}[{{ $field }}]"><option value="">選択してください</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected(($options[$field] ?? '') == $program->id)>{{ $program->name }}（{{ $program->unit_name }}）</option>@endforeach</select></label></div>
    @endforeach
    <div class="field"><label>交換ルール系列<select name="{{ $prefix }}[rule_group_id]"><option value="">新しい系列を作る</option>@foreach($ruleGroups as $group)<option value="{{ $group->id }}" @selected(($options['rule_group_id'] ?? '') == $group->id)>#{{ $group->id }} {{ $group->fromProgram->name }} → {{ $group->toProgram->name }} / {{ $group->name }}</option>@endforeach</select></label></div>
    <input type="hidden" name="{{ $prefix }}[activate_rule]" value="0"><label class="check"><input type="checkbox" name="{{ $prefix }}[activate_rule]" value="1" @checked(!empty($options['activate_rule']))>新しい版を有効にする（既存版は変更しない）</label>
    @if($record)
    @foreach(['from_quantity'=>'交換元のnative数量','to_quantity'=>'交換先のnative数量'] as $field => $label)
    <div class="field"><label>{{ $label }}<input name="{{ $prefix }}[{{ $field }}]" type="number" min="0" step="0.0001" value="{{ $options[$field] ?? $data[$field] ?? str_replace(',', '', $raw[$field] ?? '') }}"></label></div>
    @endforeach
    @php($campaign = $options['campaign_only'] ?? (in_array($raw['campaign_only'] ?? '', ['1','はい','限定'], true) || preg_match('/キャンペーン(?:時のみ|限定)|campaign.only/i', $raw['conditions_text'] ?? '')))
    <input type="hidden" name="{{ $prefix }}[campaign_only]" value="0"><label class="check"><input type="checkbox" name="{{ $prefix }}[campaign_only]" value="1" @checked($campaign)>キャンペーン限定</label>
    @foreach(['valid_from'=>'有効開始日','valid_to'=>'有効終了日'] as $field => $label)
    <div class="field"><label>{{ $label }}<input name="{{ $prefix }}[{{ $field }}]" type="date" value="{{ $options[$field] ?? $data[$field] ?? $raw[$field] ?? '' }}"></label></div>
    @endforeach
    <p class="help">実質レートだけでは交換数量を確定しません。キャンペーン限定は開始・終了日が必要です。</p>
    @endif
@endif
<input type="hidden" name="{{ $prefix }}[confirm_native]" value="0"><label class="check"><input type="checkbox" name="{{ $prefix }}[confirm_native]" value="1" @checked(!empty($options['confirm_native']))>{{ $type === 'premium_voucher' ? '残数量が口座の単位と一致し、円額とは別に確認した' : '元・先の数量が各制度のnative単位で、換算数量と別であることを確認した' }}</label>
</div>
