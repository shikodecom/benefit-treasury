@extends('layouts.app')

@section('title', $lot->display_name ?: $lot->account->program->name)

@section('content')
<div class="crumb"><a href="{{ route('ledger.lots.index') }}">期限付き特典</a> / 詳細</div>
<div class="page-head"><div><h1>{{ $lot->display_name ?: $lot->account->program->name }}</h1><p class="muted">{{ $lot->account->householdMember?->display_name ?? '家族共通／名義なし' }} · {{ $lot->account->program->name }}</p></div><a class="button secondary" href="{{ route('ledger.lots.edit', $lot) }}">編集</a></div>
@if($lot->cancelled_at)<div class="alert">このロットは登録取消済みです。</div>@endif
@if($lot->expires_at && $lot->expires_at < now('Asia/Tokyo')->toDateString() && (float)$remaining > 0)<div class="warning">期限切れ未処理です。期限延長や利用状況を確認してください。</div>@endif
<div class="card"><dl class="detail-grid">
    <div><dt>取得日</dt><dd>{{ $lot->acquired_at ?: '—' }}</dd></div>
    <div><dt>期限</dt><dd>{{ $lot->expires_at ?: 'なし' }}</dd></div>
    <div><dt>残数</dt><dd>{{ $remaining }} {{ $lot->account->program->unit_name }}</dd></div>
    <div><dt>出品引当</dt><dd>{{ $listed }}</dd></div>
    <div><dt>利用可能数</dt><dd>{{ $available }}</dd></div>
    <div><dt>運用方針</dt><dd>{{ $lot->action_policy }}</dd></div>
    @foreach(['acquisition_cost_yen'=>'取得コスト','face_value_yen'=>'額面価値','estimated_use_value_yen'=>'想定利用価値','estimated_sale_value_yen'=>'想定売却価値'] as $field=>$label)<div><dt>{{ $label }}</dt><dd>{{ $lot->$field === null ? '—' : $lot->$field.'円' }}</dd></div>@endforeach
    <div><dt>譲渡条件</dt><dd>{{ $lot->transfer_restriction ?: '—' }}</dd></div>
</dl>@if($lot->usage_conditions)<p><strong>利用条件</strong><br>{{ $lot->usage_conditions }}</p>@endif @if($lot->memo)<p><strong>メモ</strong><br>{{ $lot->memo }}</p>@endif</div>
@if(!$lot->cancelled_at && (float)$available > 0)
<div class="card"><h2>利用を記録</h2><form class="stack" method="post" action="{{ route('ledger.lots.use', $lot) }}">@csrf
    <div class="form-grid"><div class="field"><label for="quantity">数量（最大 {{ $available }}）</label><input id="quantity" type="number" min="0.0001" max="{{ $available }}" step="0.0001" name="quantity" required></div><div class="field"><label for="transaction_at">利用日</label><input id="transaction_at" type="date" name="transaction_at" value="{{ now('Asia/Tokyo')->toDateString() }}" required></div><div class="field"><label for="merchant_or_purpose">利用先・用途</label><input id="merchant_or_purpose" name="merchant_or_purpose" maxlength="255"></div><div class="field"><label for="value_yen">実際の価値（円）</label><input id="value_yen" type="number" min="0" step="1" name="value_yen"></div></div>
    <div class="field"><label for="memo">メモ</label><textarea id="memo" name="memo"></textarea></div><div><button type="submit">利用を記録</button></div>
</form></div>
@endif
@if(!$lot->cancelled_at)
<div class="card"><h2>ロットの操作</h2><div class="row">
    @if((float)$remaining > 0)<form method="post" action="{{ route('ledger.lots.expire', $lot) }}" onsubmit="return confirm('残数すべてを失効として記録しますか？')">@csrf<button class="danger" type="submit">残数を失効として処理</button></form>@endif
    @if($transactions->count() === 1 && $transactions->first()->transaction_type === 'earn')<form method="post" action="{{ route('ledger.lots.cancel', $lot) }}" onsubmit="return confirm('取得登録を取り消しますか？')">@csrf<button class="danger" type="submit">登録取消</button></form>@endif
</div></div>
@endif
<div class="card"><h2>このロットの取引</h2><div class="list">@foreach($transactions as $transaction)<div class="list-item"><div><strong>{{ $transaction->transaction_type }}</strong><small>{{ $transaction->transaction_at }} · {{ $transaction->merchant_or_purpose ?: '—' }}</small></div><div class="row"><strong>{{ $transaction->direction === 'in' ? '+' : '−' }}{{ $transaction->quantity }}</strong><a href="{{ route('ledger.transactions.show', $transaction) }}">詳細</a></div></div>@endforeach</div></div>
@endsection
