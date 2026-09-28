@extends('layouts.app')

@section('title', '特典制度')

@section('content')
<div class="crumb"><a href="{{ route('settings.home') }}">マスタ管理</a> / 特典制度</div>
<div class="page-head"><div><h1>特典制度</h1><p class="muted">ポイント、マイル、優待券などの種類と別名を管理します。</p></div><a class="button" href="{{ route('settings.programs.create') }}">制度を追加</a></div>
<form class="card filters" method="get">
    <div class="field"><label for="q">名前・提供元・別名</label><input id="q" type="search" name="q" value="{{ request('q') }}"></div>
    <div class="field"><label for="category">カテゴリ</label><select id="category" name="category"><option value="">すべて</option>
    @foreach (['point'=>'ポイント','mile'=>'マイル','e_money'=>'電子マネー','gift'=>'商品券・ギフト','shareholder_benefit'=>'株主優待','coupon'=>'クーポン','discount'=>'割引','campaign'=>'キャンペーン特典','other'=>'その他'] as $value => $label)
        <option value="{{ $value }}" @selected(request('category')===$value)>{{ $label }}</option>
    @endforeach
    </select></div>
    <div class="field"><label for="status">状態</label><select id="status" name="status"><option value="active" @selected(request('status','active')==='active')>利用中</option><option value="inactive" @selected(request('status')==='inactive')>無効</option><option value="all" @selected(request('status')==='all')>すべて</option></select></div>
    <div class="field"><label for="transferable">移行</label><select id="transferable" name="transferable"><option value="">すべて</option><option value="1" @selected(request('transferable')==='1')>可</option><option value="0" @selected(request('transferable')==='0')>不可</option></select></div>
    <div class="field"><label for="sellable">売却</label><select id="sellable" name="sellable"><option value="">すべて</option><option value="1" @selected(request('sellable')==='1')>可</option><option value="0" @selected(request('sellable')==='0')>不可</option></select></div>
    <div class="field"><label for="per_page">表示件数</label><select id="per_page" name="per_page">@foreach ([25,50,100] as $size)<option value="{{ $size }}" @selected((int)request('per_page',25)===$size)>{{ $size }}件</option>@endforeach</select></div>
    <button class="secondary" type="submit">検索</button>
</form>
<div class="list">
@forelse ($programs as $program)
    <div class="list-item"><div><strong>{{ $program->name }}</strong><small>{{ $program->provider ?: '提供元未設定' }} · {{ $program->category }} · {{ $program->unit_name }} · 口座 {{ $program->accounts_count }}件 · 移行 {{ $program->transferable ? '可' : '不可' }} · 売却 {{ $program->sellable ? '可' : '不可' }}</small></div><div class="row"><span class="badge {{ $program->active ? '' : 'inactive' }}">{{ $program->active ? '利用中' : '無効' }}</span><a class="button secondary" href="{{ route('settings.programs.show', $program) }}">詳細</a></div></div>
@empty
    <div class="card empty">該当する制度はありません。</div>
@endforelse
</div>
@include('settings.pager', ['paginator' => $programs])
@endsection
