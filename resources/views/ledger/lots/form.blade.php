@extends('layouts.app')

@section('title', $lot->exists ? 'ロットを編集' : '特典を取得')

@section('content')
<div class="crumb"><a href="{{ route('ledger.lots.index') }}">期限付き特典</a> / {{ $lot->exists ? '編集' : '取得' }}</div>
<div class="page-head"><h1>{{ $lot->exists ? 'ロットを編集' : '特典を取得' }}</h1></div>
<form class="card stack" method="post" action="{{ $lot->exists ? route('ledger.lots.update', $lot) : route('ledger.lots.store') }}">@csrf @if($lot->exists) @method('PUT') @endif
    <div class="form-grid">
        @if($lot->exists)<div class="field"><label>口座</label><p>{{ $lot->account->program->name }}</p></div>
        @else
            <div class="field"><label for="account_search">口座を絞り込み</label><input id="account_search" type="search" oninput="for(const o of document.querySelectorAll('#account_id option')){if(o.value)o.hidden=!o.textContent.toLowerCase().includes(this.value.toLowerCase())}"></div>
            <div class="field"><label for="account_id">口座</label><select id="account_id" name="account_id" required><option value="">選択してください</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string)old('account_id', $selectedAccountId)===(string)$account->id)>{{ $account->program->name }} / {{ $account->householdMember?->display_name ?? '家族共通' }} / {{ $account->account_label ?: 'ラベルなし' }}</option>@endforeach</select></div>
            <div class="field"><label for="quantity">取得数量</label><input id="quantity" type="number" min="0.0001" step="0.0001" name="quantity" value="{{ old('quantity') }}" required></div>
        @endif
        <div class="field"><label for="display_name">ロット名</label><input id="display_name" name="display_name" maxlength="200" value="{{ old('display_name', $lot->display_name) }}"></div>
        <div class="field"><label for="acquired_at">取得日</label><input id="acquired_at" type="date" name="acquired_at" value="{{ old('acquired_at', $lot->acquired_at ?? now('Asia/Tokyo')->toDateString()) }}"></div>
        <div class="field"><label for="expires_at">有効期限</label><input id="expires_at" type="date" name="expires_at" value="{{ old('expires_at', $lot->expires_at) }}"></div>
        <div class="field"><label for="action_policy">運用方針</label><select id="action_policy" name="action_policy">@foreach(['undecided'=>'未定','self_use'=>'自家利用','sell_now'=>'売却','hold'=>'保有','bundle'=>'まとめる','do_not_sell'=>'売らない','transfer_to_points'=>'ポイント移行'] as $value=>$label)<option value="{{ $value }}" @selected(old('action_policy', $lot->action_policy ?? 'undecided')===$value)>{{ $label }}</option>@endforeach</select></div>
        @foreach(['acquisition_cost_yen'=>'取得コスト','face_value_yen'=>'額面価値','estimated_use_value_yen'=>'想定利用価値','estimated_sale_value_yen'=>'想定売却価値'] as $field=>$label)
            <div class="field"><label for="{{ $field }}">{{ $label }}（円）</label><input id="{{ $field }}" type="number" min="0" step="1" name="{{ $field }}" value="{{ old($field, $lot->$field) }}"></div>
        @endforeach
        <div class="field"><label for="transfer_restriction">譲渡条件</label><input id="transfer_restriction" name="transfer_restriction" maxlength="40" value="{{ old('transfer_restriction', $lot->transfer_restriction) }}"></div>
    </div>
    <div class="field"><label for="usage_conditions">利用条件</label><textarea id="usage_conditions" name="usage_conditions">{{ old('usage_conditions', $lot->usage_conditions) }}</textarea></div>
    <div class="field"><label for="memo">メモ</label><textarea id="memo" name="memo">{{ old('memo', $lot->memo) }}</textarea></div>
    <div class="row"><button type="submit">保存</button><a class="button secondary" href="{{ $lot->exists ? route('ledger.lots.show', $lot) : route('ledger.lots.index') }}">戻る</a></div>
</form>
@endsection
