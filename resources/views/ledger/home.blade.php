@extends('layouts.app')

@section('title', '保有特典')

@section('content')
<div class="page-head"><div><h1>保有特典</h1><p class="muted">口座残高は取引履歴から計算しています。</p></div><div class="row"><a class="button" href="{{ route('ledger.transactions.create') }}">取引を登録</a><a class="button secondary" href="{{ route('ledger.lots.create') }}">特典を取得</a></div></div>
<div class="list">
@forelse ($items as $account)
    <div class="list-item"><div><strong>{{ $account->program->name }} · {{ $account->householdMember?->display_name ?? '家族共通／名義なし' }}</strong><small>{{ $account->account_label ?: 'ラベルなし' }} · 期限付きロット {{ $expiring[$account->id] }}件 · 最短期限 {{ $nextExpiry[$account->id] ?: 'なし' }}</small></div><div class="row"><strong>{{ $balances[$account->id] }} {{ $account->program->unit_name }}</strong><a class="button secondary" href="{{ route('ledger.accounts.transactions', $account) }}">詳細</a></div></div>
@empty
    <div class="card empty">口座はまだありません。<a href="{{ route('settings.accounts.create') }}">保有口座を追加</a></div>
@endforelse
</div>
@include('settings.pager', ['paginator' => $items])
@endsection
