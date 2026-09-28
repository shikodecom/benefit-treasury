@extends('layouts.app')

@section('title', '取引詳細')

@section('content')
<div class="crumb"><a href="{{ route('ledger.transactions.index') }}">取引履歴</a> / 詳細</div>
<div class="page-head"><h1>取引 #{{ $transaction->id }}</h1></div>
<div class="card"><dl class="detail-grid">
    <div><dt>口座</dt><dd><a href="{{ route('ledger.accounts.transactions', $transaction->account) }}">{{ $transaction->account->program->name }} / {{ $transaction->account->householdMember?->display_name ?? '家族共通' }}</a></dd></div>
    <div><dt>日付</dt><dd>{{ $transaction->transaction_at }}</dd></div>
    <div><dt>種別</dt><dd>{{ $transaction->transaction_type }}</dd></div>
    <div><dt>数量</dt><dd>{{ $transaction->direction === 'in' ? '+' : '−' }}{{ $transaction->quantity }} {{ $transaction->account->program->unit_name }}</dd></div>
    <div><dt>内容</dt><dd>{{ $transaction->merchant_or_purpose ?: '—' }}</dd></div>
    <div><dt>価値</dt><dd>{{ $transaction->value_yen === null ? '—' : $transaction->value_yen.'円' }}</dd></div>
    <div><dt>登録元</dt><dd>{{ $transaction->source_type }}</dd></div>
    @if($transaction->lot)<div><dt>ロット</dt><dd><a href="{{ route('ledger.lots.show', $transaction->lot) }}">{{ $transaction->lot->display_name ?: '#'.$transaction->lot->id }}</a></dd></div>@endif
</dl>@if($transaction->memo)<p>{{ $transaction->memo }}</p>@endif</div>
@if($reversal)<div class="alert">この取引は #{{ $reversal->id }} で取り消されました。</div>
@elseif($transaction->transaction_type !== 'reversal' && $transaction->listing_id === null && $transaction->transfer_step_id === null)
    <div class="card"><h2>訂正</h2><p class="help">元の取引を残し、逆方向の取引を記録します。</p><form method="post" action="{{ route('ledger.transactions.reverse', $transaction) }}" onsubmit="return confirm('この取引を取り消しますか？')">@csrf<button class="danger" type="submit">この取引を取り消す</button></form></div>
@endif
@endsection
