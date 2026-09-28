@extends('layouts.app')

@section('title', '保有口座')

@section('content')
<div class="crumb"><a href="{{ route('settings.home') }}">マスタ管理</a> / 保有口座</div>
<div class="page-head"><div><h1>保有口座</h1><p class="muted">制度と名義を組み合わせて管理します。</p></div><a class="button" href="{{ route('settings.accounts.create') }}">口座を追加</a></div>
<form class="card filters" method="get">
    <div class="field"><label for="q">制度・提供元・ラベル・ヒント</label><input id="q" type="search" name="q" value="{{ request('q') }}"></div>
    <div class="field"><label for="program_id">特典制度</label><select id="program_id" name="program_id"><option value="">すべて</option>@foreach ($programs as $program)<option value="{{ $program->id }}" @selected((string)request('program_id')===(string)$program->id)>{{ $program->name }}</option>@endforeach</select></div>
    <div class="field"><label for="household_member_id">名義</label><select id="household_member_id" name="household_member_id"><option value="">すべて</option>@foreach ($members as $member)<option value="{{ $member->id }}" @selected((string)request('household_member_id')===(string)$member->id)>{{ $member->display_name }}</option>@endforeach</select></div>
    <div class="field"><label for="category">カテゴリ</label><select id="category" name="category"><option value="">すべて</option>@foreach (['point'=>'ポイント','mile'=>'マイル','e_money'=>'電子マネー','gift'=>'商品券・ギフト','shareholder_benefit'=>'株主優待','coupon'=>'クーポン','discount'=>'割引','campaign'=>'キャンペーン特典','other'=>'その他'] as $value => $label)<option value="{{ $value }}" @selected(request('category')===$value)>{{ $label }}</option>@endforeach</select></div>
    <div class="field"><label for="status">状態</label><select id="status" name="status"><option value="active" @selected(request('status','active')==='active')>利用中</option><option value="inactive" @selected(request('status')==='inactive')>無効</option><option value="all" @selected(request('status')==='all')>すべて</option></select></div>
    <div class="field"><label for="per_page">表示件数</label><select id="per_page" name="per_page">@foreach ([25,50,100] as $size)<option value="{{ $size }}" @selected((int)request('per_page',25)===$size)>{{ $size }}件</option>@endforeach</select></div>
    <button class="secondary" type="submit">検索</button>
</form>
<div class="list">
@forelse ($accounts as $account)
    <div class="list-item"><div><strong>{{ $account->program->name }} · {{ $account->householdMember?->display_name ?? '家族共通／名義なし' }}</strong><small>{{ $account->account_label ?: 'ラベルなし' }} · 残高 {{ $balances[$account->id] }} {{ $account->program->unit_name }} · 更新 {{ $account->updated_at?->format('Y/m/d') }}</small></div><div class="row"><span class="badge {{ $account->active ? '' : 'inactive' }}">{{ $account->active ? '利用中' : '無効' }}</span><a class="button secondary" href="{{ route('settings.accounts.show', $account) }}">詳細</a></div></div>
@empty
    <div class="card empty">該当する口座はありません。</div>
@endforelse
</div>
@include('settings.pager', ['paginator' => $accounts])
@endsection
