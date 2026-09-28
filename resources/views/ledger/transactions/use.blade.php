@extends('layouts.app')

@section('title', '利用を登録')

@section('content')
<div class="crumb"><a href="{{ route('ledger.accounts.transactions', $account) }}">{{ $account->program->name }}の取引</a> / 利用</div>
<div class="page-head"><div><h1>利用を登録</h1><p class="muted">{{ $account->program->name }} · {{ $account->householdMember?->display_name ?? '家族共通' }}。期限の近いロットから配分を提案します。</p></div></div>
<form class="card stack" method="post" action="{{ route('ledger.accounts.use.store', $account) }}">@csrf
    <div class="form-grid">
        <div class="field"><label for="quantity">利用数量（{{ $account->program->unit_name }}）</label><input id="quantity" type="number" min="0.0001" step="0.0001" name="quantity" value="{{ old('quantity') }}" required></div>
        <div class="field"><label for="transaction_at">利用日</label><input id="transaction_at" type="date" name="transaction_at" value="{{ old('transaction_at', now('Asia/Tokyo')->toDateString()) }}" required></div>
        <div class="field"><label for="merchant_or_purpose">利用先・用途</label><input id="merchant_or_purpose" name="merchant_or_purpose" maxlength="255" value="{{ old('merchant_or_purpose') }}"></div>
        <div class="field"><label for="value_yen">実際の価値（円・任意）</label><input id="value_yen" type="number" min="0" step="1" name="value_yen" value="{{ old('value_yen') }}"></div>
    </div>
    <div class="field"><label for="memo">メモ</label><textarea id="memo" name="memo">{{ old('memo') }}</textarea></div>
    <div><button type="button" class="secondary" id="suggest">期限順に配分</button><p class="help">提案後、各ロットへの配分を変更できます。合計を利用数量と一致させてください。</p></div>
    <div class="list">
        @foreach($availableLots as $lot)
            <div class="list-item"><div><strong>{{ $lot->display_name ?: $account->program->name }}</strong><small>期限 {{ $lot->expires_at ?: 'なし' }} · 利用可能 {{ $available[$lot->id] }} {{ $account->program->unit_name }}</small></div><div class="field"><label for="lot_{{ $lot->id }}">このロットから</label><input id="lot_{{ $lot->id }}" class="allocation" type="number" min="0" max="{{ $available[$lot->id] }}" step="0.0001" name="allocations[{{ $lot->id }}]" data-cap="{{ $available[$lot->id] }}" value="{{ old('allocations.'.$lot->id, '0') }}"></div></div>
        @endforeach
        <div class="list-item"><div><strong>ロットなし残高</strong><small>利用可能 {{ $unallocated }} {{ $account->program->unit_name }}</small></div><div class="field"><label for="unallocated_quantity">ロットなしから</label><input id="unallocated_quantity" class="allocation" type="number" min="0" max="{{ $unallocated }}" step="0.0001" name="unallocated_quantity" data-cap="{{ $unallocated }}" value="{{ old('unallocated_quantity', '0') }}"></div></div>
    </div>
    <div class="row"><button type="submit">利用を記録</button><a class="button secondary" href="{{ route('ledger.accounts.transactions', $account) }}">戻る</a></div>
</form>
<script>
document.getElementById('suggest').addEventListener('click', () => {
    const units = value => {
        const [whole, fraction = ''] = String(value || '0').split('.');
        return BigInt(whole || '0') * 10000n + BigInt((fraction + '0000').slice(0, 4));
    };
    const decimal = value => `${value / 10000n}.${String(value % 10000n).padStart(4, '0')}`;
    let remaining = units(document.getElementById('quantity').value);
    for (const input of document.querySelectorAll('.allocation')) {
        const cap = units(input.dataset.cap);
        const used = remaining < cap ? remaining : cap;
        input.value = decimal(used);
        remaining -= used;
    }
});
</script>
@endsection
