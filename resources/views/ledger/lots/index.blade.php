@extends('layouts.app')

@section('title', '期限付き特典')

@section('content')
<div class="page-head"><div><h1>期限付き特典</h1><p class="muted">期限だけでは自動失効しません。実際の状況を確認して処理します。</p></div><a class="button" href="{{ route('ledger.lots.create') }}">特典を取得</a></div>
<div class="list">@forelse($lots as $lot)
    @php($remaining = $quantities[$lot->id]['remaining'])
    @php($listed = $quantities[$lot->id]['listed'])
    @php($available = $quantities[$lot->id]['available'])
    <div class="list-item"><div><strong>{{ $lot->display_name ?: $lot->account->program->name }} · {{ $lot->account->householdMember?->display_name ?? '家族共通' }}</strong><small>期限 {{ $lot->expires_at ?: 'なし' }} · 残 {{ $remaining }} · 出品引当 {{ $listed }} · 利用可 {{ $quantities[$lot->id]['available'] }} {{ $lot->account->program->unit_name }} · {{ $lot->action_policy }}</small></div><div class="row">
        @if((float)$remaining <= 0)<span class="badge inactive">残数なし</span>
        @elseif($lot->expires_at && $lot->expires_at < now('Asia/Tokyo')->toDateString())<span class="badge inactive">期限切れ未処理</span>
        @elseif((float)$available <= 0)<span class="badge">全数出品中</span>
        @elseif((float)$listed > 0)<span class="badge">一部出品中</span>
        @elseif($lot->expires_at === now('Asia/Tokyo')->toDateString())<span class="badge">本日期限</span>
        @elseif($lot->expires_at && $lot->expires_at <= now('Asia/Tokyo')->addDays(7)->toDateString())<span class="badge">期限間近</span>
        @elseif($lot->expires_at && $lot->expires_at <= now('Asia/Tokyo')->addDays(30)->toDateString())<span class="badge">30日以内</span>
        @else<span class="badge">利用可能</span>@endif
        <a class="button secondary" href="{{ route('ledger.lots.show', $lot) }}">詳細</a></div></div>
@empty<div class="card empty">ロットはまだありません。</div>@endforelse</div>
@include('settings.pager', ['paginator' => $lots])
@endsection
