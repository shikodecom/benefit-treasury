@extends('layouts.app')

@section('title', $account->program->name.'の口座')

@section('content')
<div class="crumb"><a href="{{ route('settings.accounts.index') }}">保有口座</a> / 詳細</div>
<div class="page-head"><div><h1>{{ $account->program->name }}</h1><p class="muted">{{ $account->householdMember?->display_name ?? '家族共通／名義なし' }} · {{ $account->account_label ?: 'ラベルなし' }}</p></div><a class="button secondary" href="{{ route('settings.accounts.edit', $account) }}">編集</a></div>
<div class="row" style="margin-bottom:16px"><a class="button" href="{{ route('ledger.accounts.transactions', $account) }}">取引履歴</a><a class="button secondary" href="{{ route('ledger.lots.create', ['account_id' => $account->id]) }}">特典を取得</a></div>
<div class="card"><dl class="detail-grid">
    <div><dt>現在残高</dt><dd>{{ $balance }} {{ $account->program->unit_name }}</dd></div>
    <div><dt>状態</dt><dd>{{ $account->active ? '利用中' : '無効' }}</dd></div>
    <div><dt>識別ヒント</dt><dd>{{ $account->external_account_hint ?: '—' }}</dd></div>
    <div><dt>取引件数</dt><dd>{{ $account->transactions_count }}件</dd></div>
    <div><dt>最終取引日</dt><dd>{{ $lastTransaction ?: '—' }}</dd></div>
    <div><dt>ロット数</dt><dd>{{ $account->lots_count }}件</dd></div>
    <div><dt>移行中件数</dt><dd>{{ $processingTransfers }}件</dd></div>
</dl></div>
<div class="card"><h2>利用状態</h2>
    @if ($account->active && $balance !== '0.0000')<p class="warning">残高が {{ $balance }} {{ $account->program->unit_name }} あります。無効化しても残高と履歴は保持されます。</p>@endif
    <form method="post" action="{{ route('settings.accounts.toggle', $account) }}" onsubmit="return confirm('利用状態を変更しますか？')">@csrf<button class="{{ $account->active ? 'danger' : 'secondary' }}" type="submit">{{ $account->active ? '無効化' : '有効化' }}</button></form>
</div>
@endsection
