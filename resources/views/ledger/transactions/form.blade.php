@extends('layouts.app')

@section('title', '取引を登録')

@section('content')
<div class="crumb"><a href="{{ route('ledger.transactions.index') }}">取引履歴</a> / 登録</div>
<div class="page-head"><h1>取引を登録</h1></div>
<form class="card stack" method="post" action="{{ route('ledger.transactions.store') }}">@csrf
    <div class="form-grid">
        <div class="field"><label for="account_search">口座を絞り込み</label><input id="account_search" type="search" oninput="for(const o of document.querySelectorAll('#account_id option')){if(o.value)o.hidden=!o.textContent.toLowerCase().includes(this.value.toLowerCase())}" placeholder="制度・名義で検索"></div>
        <div class="field"><label for="account_id">口座</label><select id="account_id" name="account_id" required><option value="">選択してください</option>@foreach ($accounts as $account)<option value="{{ $account->id }}" @selected((string)old('account_id', $selectedAccountId)===(string)$account->id)>{{ $account->program->name }} / {{ $account->householdMember?->display_name ?? '家族共通' }} / {{ $account->account_label ?: 'ラベルなし' }} · {{ $balances[$account->id] }} {{ $account->program->unit_name }}</option>@endforeach</select></div>
        <div class="field"><label for="transaction_type">取引種別</label><select id="transaction_type" name="transaction_type" required>@foreach (['opening_balance'=>'初期残高','earn'=>'獲得','use'=>'利用','adjustment_in'=>'調整（増）','adjustment_out'=>'調整（減）'] as $value=>$label)<option value="{{ $value }}" @selected(old('transaction_type', $selectedType)===$value)>{{ $label }}</option>@endforeach</select></div>
        <div class="field"><label for="transaction_at">日付</label><input id="transaction_at" type="date" name="transaction_at" value="{{ old('transaction_at', now('Asia/Tokyo')->toDateString()) }}" required></div>
        <div class="field"><label for="quantity">数量</label><input id="quantity" type="number" min="0.0001" step="0.0001" name="quantity" value="{{ old('quantity') }}" required></div>
        <div class="field"><label for="merchant_or_purpose">内容・用途</label><input id="merchant_or_purpose" name="merchant_or_purpose" maxlength="255" value="{{ old('merchant_or_purpose') }}"></div>
        <div class="field"><label for="value_yen">実際の価値（円・任意）</label><input id="value_yen" type="number" min="0" step="1" name="value_yen" value="{{ old('value_yen') }}"></div>
    </div>
    <div class="field"><label for="memo">メモ・調整理由</label><textarea id="memo" name="memo">{{ old('memo') }}</textarea><small class="help">調整では理由が必須です。初期残高は取引がない口座にのみ登録できます。ロット付きの特典はロット詳細から利用してください。</small></div>
    <div class="row"><button type="submit">登録</button><a class="button secondary" href="{{ route('ledger.transactions.index') }}">戻る</a></div>
</form>
@endsection
