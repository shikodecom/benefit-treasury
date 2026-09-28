@extends('layouts.app')

@section('title', $account->program->name.'の取引')

@section('content')
<div class="crumb"><a href="{{ route('ledger.home') }}">保有特典</a> / 取引履歴</div>
<div class="page-head"><div><h1>{{ $account->program->name }}</h1><p class="muted">{{ $account->householdMember?->display_name ?? '家族共通／名義なし' }} · {{ $account->account_label ?: 'ラベルなし' }}</p></div><a class="button" href="{{ route('ledger.transactions.create', ['account_id' => $account->id, 'type' => $transactions->total() === 0 ? 'opening_balance' : 'earn']) }}">{{ $transactions->total() === 0 ? '初期残高を登録' : '取引を登録' }}</a></div>
<div class="card"><strong>現在残高　{{ $balance }} {{ $account->program->unit_name }}</strong></div>
<div class="row" style="margin-bottom:16px"><a class="button secondary" href="{{ route('ledger.accounts.use', $account) }}">ロットを配分して利用</a><a class="button secondary" href="{{ route('ledger.lots.create', ['account_id' => $account->id]) }}">期限付き特典を取得</a><a class="button secondary" href="{{ route('settings.accounts.show', $account) }}">口座設定</a></div>
<div class="list">@forelse ($transactions as $transaction)
    <div class="list-item"><div><strong>{{ $transaction->merchant_or_purpose ?: $transaction->transaction_type }}</strong><small>{{ $transaction->transaction_at }} · {{ $transaction->transaction_type }} @if($transaction->lot_id) · ロット #{{ $transaction->lot_id }} @endif</small></div><div class="row"><strong>{{ $transaction->direction === 'in' ? '+' : '−' }}{{ $transaction->quantity }} {{ $account->program->unit_name }}</strong><a class="button secondary" href="{{ route('ledger.transactions.show', $transaction) }}">詳細</a></div></div>
@empty<div class="card empty">取引はまだありません。</div>@endforelse</div>
@include('settings.pager', ['paginator' => $transactions])
@endsection
