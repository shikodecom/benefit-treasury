@extends('layouts.app')
@section('title', '通知')
@section('content')
<div class="page-head"><h1>通知</h1><a class="button secondary" href="{{ route('notifications.preferences') }}">通知設定</a></div>
<p><a href="{{ route('notifications.index', ['scope'=>'unread']) }}">未読</a> · <a href="{{ route('notifications.index', ['scope'=>'all']) }}">すべて</a></p>
<form method="post" action="{{ route('notifications.read-all') }}">@csrf<button class="secondary">すべて既読</button></form>
<div class="list">@forelse($notifications as $notification)<article class="card"><p><strong>{{ $notification->title }}</strong> <small>{{ $notification->priority }} · {{ $notification->created_at }}</small></p><p>{{ $notification->body }}</p>@if($stale[$notification->id])<p class="muted">対応済み、または現在の状態と異なる過去の通知です。</p>@endif<div class="row"><form method="post" action="{{ route('notifications.open', $notification) }}">@csrf<button>{{ $notification->read_at ? '詳細を開く' : '開いて既読' }}</button></form><form method="post" action="{{ route('notifications.dismiss', $notification) }}">@csrf<button class="secondary">非表示</button></form></div></article>@empty<p class="card">{{ $scope === 'unread' ? '未読通知はありません。' : '通知はありません。' }}</p>@endforelse</div>
{{ $notifications->links() }}
@endsection
