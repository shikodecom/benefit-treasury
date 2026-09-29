@extends('layouts.app')
@section('title', '通知設定')
@section('content')
<div class="crumb"><a href="{{ route('notifications.index') }}">通知</a> / 設定</div><h1>通知設定</h1>
@php($labels = ['lot_expiry'=>'特典の期限','lot_expired_pending'=>'期限切れ未処理','sell_now_unlisted'=>'売る予定・未出品','listing_expiry'=>'出品中の期限','listing_ended_unsold'=>'売れず終了','transfer_due'=>'移行の着弾予定','transfer_overdue'=>'移行の予定日超過','milestone:expire_30d'=>'期限30日前','milestone:expire_14d'=>'期限14日前','milestone:expire_7d'=>'期限7日前','milestone:expire_3d'=>'期限3日前','milestone:expire_today'=>'期限当日','milestone:expired_1d'=>'期限切れ1日後','milestone:expired_7d'=>'期限切れ7日後','milestone:expired_30d'=>'期限切れ30日後','milestone:due_3d'=>'着弾予定3日前','milestone:due_today'=>'着弾予定当日','milestone:overdue_1d'=>'予定日超過1日','milestone:overdue_7d'=>'予定日超過7日','milestone:overdue_30d'=>'予定日超過30日'])
<form method="post" action="{{ route('notifications.preferences.update') }}" class="card">@csrf<p class="muted">現在はアプリ内通知のみです。</p><div class="notification-options">@foreach($types as $type)<label class="notification-option"><input type="checkbox" name="enabled[{{ $type }}]" value="1" @checked($preferences[$type]->enabled ?? true)> <span>{{ $labels[$type] ?? $type }}</span></label>@endforeach</div><button>設定を保存</button></form>
@endsection
