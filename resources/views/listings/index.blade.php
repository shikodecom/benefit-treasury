@extends('layouts.app')
@section('title', '出品・売却')
@section('content')
<div class="page-head"><div><h1>出品・売却</h1><p class="muted">出品中の数量は在庫引当、売却時に残数を減らします。</p></div><a class="button" href="{{ route('listings.create') }}">出品を作成</a></div>
<div class="row">@foreach(['listed'=>'出品中','draft'=>'下書き','sold'=>'売却済み','ended_unsold'=>'売れず終了','cancelled'=>'取消','all'=>'すべて'] as $value=>$label)<a class="button {{ $status === $value ? '' : 'secondary' }}" href="{{ route('listings.index', ['status'=>$value]) }}">{{ $label }}</a>@endforeach</div>
<div class="card"><h2>売却実績</h2><p>{{ $summary['count'] }} 件 · 売却総額 {{ number_format($summary['sold_price_yen']) }} 円 · 手取り {{ number_format($summary['net_proceeds_yen']) }} 円</p></div>
@forelse($listings as $listing)
<article class="card"><div class="page-head"><div><h2><a href="{{ route('listings.show', $listing) }}">{{ $listing->title ?: $listing->items->pluck('lot.display_name')->filter()->join(' / ') ?: '出品 #'.$listing->id }}</a></h2><p class="muted">{{ $listing->marketplace }} · {{ ['draft'=>'下書き','listed'=>'出品中','sold'=>'売却済み','ended_unsold'=>'売れず終了','cancelled'=>'取消'][$listing->status] ?? $listing->status }} @if($listing->sale_reversed_at) · 売却取消済み @endif</p></div><strong>{{ $listing->listing_price_yen === null ? '価格未設定' : number_format($listing->listing_price_yen).' 円' }}</strong></div>
<p>@foreach($listing->items as $item){{ $item->lot->display_name ?: $item->lot->account->program->name }} × {{ $item->quantity }}@if($item->lot->expires_at) · 期限 {{ $item->lot->expires_at }}@endif @if(!$loop->last) / @endif @endforeach</p>
@if($listing->status === 'listed')<p class="muted">出品から {{ \Carbon\Carbon::parse($listing->listed_at)->startOfDay()->diffInDays(now('Asia/Tokyo')->startOfDay()) }} 日 · 手数料未確定のため手取りは未確定</p>@endif
@if($listing->status === 'sold')<p>実売価格 {{ number_format($listing->sold_price_yen) }} 円 · 手取り {{ number_format($listing->net_proceeds_yen) }} 円</p>@endif
<div class="row"><a class="button secondary" href="{{ route('listings.show', $listing) }}">詳細</a>@if($listing->status === 'listed')<a class="button" href="{{ route('listings.sell.form', $listing) }}">売れた</a>@endif</div></article>
@empty<div class="card">該当する出品はありません。</div>@endforelse
{{ $listings->links() }}
@endsection
