@extends('layouts.app')

@section('title', $member->exists ? '名義を編集' : '名義を追加')

@section('content')
<div class="crumb"><a href="{{ route('settings.members.index') }}">家族・名義</a> / {{ $member->exists ? '編集' : '追加' }}</div>
<div class="page-head"><h1>{{ $member->exists ? '名義を編集' : '名義を追加' }}</h1></div>
<form class="card stack" method="post" action="{{ $member->exists ? route('settings.members.update', $member) : route('settings.members.store') }}">
    @csrf
    @if ($member->exists) @method('PUT') @endif
    <div class="field"><label for="display_name">表示名</label><input id="display_name" name="display_name" maxlength="100" value="{{ old('display_name', $member->display_name) }}" required></div>
    <div class="field"><label for="relation_type">区分</label><select id="relation_type" name="relation_type" required>
        <option value="">選択してください</option>
        @foreach (['self'=>'本人','spouse'=>'配偶者','child'=>'子ども','family'=>'家族共通','other'=>'その他'] as $value => $label)
            <option value="{{ $value }}" @selected(old('relation_type', $member->relation_type)===$value)>{{ $label }}</option>
        @endforeach
    </select></div>
    <div class="row"><button type="submit">保存</button><a class="button secondary" href="{{ route('settings.members.index') }}">一覧へ戻る</a></div>
</form>
@if ($member->exists)
<div class="card">
    <h2>利用状態</h2>
    @if ($member->active && $member->accounts_count > 0)
        <p class="warning">この名義には {{ $member->accounts_count }} 件の口座があります。無効化しても履歴は保持されます。</p>
    @endif
    <form method="post" action="{{ route('settings.members.toggle', $member) }}" onsubmit="return confirm('利用状態を変更しますか？')">@csrf
        <button class="{{ $member->active ? 'danger' : 'secondary' }}" type="submit">{{ $member->active ? '無効化' : '有効化' }}</button>
    </form>
</div>
@endif
@endsection
