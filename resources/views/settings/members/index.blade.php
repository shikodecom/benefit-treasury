@extends('layouts.app')

@section('title', '家族・名義')

@section('content')
<div class="crumb"><a href="{{ route('settings.home') }}">マスタ管理</a> / 家族・名義</div>
<div class="page-head">
    <div><h1>家族・名義</h1><p class="muted">保有者を登録します。無効化しても履歴は残ります。</p></div>
    <a class="button" href="{{ route('settings.members.create') }}">名義を追加</a>
</div>
<form class="card filters" method="get">
    <label class="check"><input type="checkbox" name="include_inactive" value="1" @checked(request()->boolean('include_inactive'))> 無効を含める</label>
    <div class="field"><label for="per_page">表示件数</label><select id="per_page" name="per_page">@foreach ([25,50,100] as $size)<option value="{{ $size }}" @selected((int)request('per_page',25)===$size)>{{ $size }}件</option>@endforeach</select></div>
    <button class="secondary" type="submit">表示</button>
</form>
<div class="list">
@forelse ($members as $member)
    <div class="list-item">
        <div><strong>{{ $member->display_name }}</strong><small>{{ ['self'=>'本人','spouse'=>'配偶者','child'=>'子ども','family'=>'家族共通','other'=>'その他'][$member->relation_type] ?? $member->relation_type }} · 口座 {{ $member->accounts_count }}件 · 更新 {{ $member->updated_at?->format('Y/m/d') }}</small></div>
        <div class="row"><span class="badge {{ $member->active ? '' : 'inactive' }}">{{ $member->active ? '利用中' : '無効' }}</span><a class="button secondary" href="{{ route('settings.members.edit', $member) }}">編集</a></div>
    </div>
@empty
    <div class="card empty">名義はまだありません。</div>
@endforelse
</div>
@include('settings.pager', ['paginator' => $members])
@endsection
