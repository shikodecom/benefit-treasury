@extends('layouts.app')
@section('title', '通知設定')
@section('content')
<div class="crumb"><a href="{{ route('notifications.index') }}">通知</a> / 設定</div><h1>通知設定</h1>
<form method="post" action="{{ route('notifications.preferences.update') }}" class="card">@csrf<p class="muted">現在はアプリ内通知のみです。</p>@foreach($types as $type)<div class="field"><label><input type="checkbox" name="enabled[{{ $type }}]" value="1" @checked($preferences[$type]->enabled ?? true)> {{ $type }}</label></div>@endforeach<button>設定を保存</button></form>
@endsection
