@extends('layouts.app')

@section('title', $program->name)

@section('content')
<div class="crumb"><a href="{{ route('settings.programs.index') }}">特典制度</a> / 詳細</div>
<div class="page-head"><div><h1>{{ $program->name }}</h1><span class="badge {{ $program->active ? '' : 'inactive' }}">{{ $program->active ? '利用中' : '無効' }}</span></div><a class="button secondary" href="{{ route('settings.programs.edit', $program) }}">編集</a></div>
<div class="card"><dl class="detail-grid">
    <div><dt>カテゴリ</dt><dd>{{ $program->category }}</dd></div><div><dt>提供元</dt><dd>{{ $program->provider ?: '—' }}</dd></div>
    <div><dt>単位</dt><dd>{{ $program->unit_name }}</dd></div><div><dt>参考円換算</dt><dd>{{ $program->default_unit_value_yen ?? '—' }}</dd></div>
    <div><dt>移行</dt><dd>{{ $program->transferable ? '可' : '不可' }}</dd></div><div><dt>売却</dt><dd>{{ $program->sellable ? '可' : '不可' }}</dd></div>
    <div><dt>保有口座</dt><dd>{{ $program->accounts_count }}件</dd></div>
</dl>
@if ($program->official_url)<p><a href="{{ $program->official_url }}" target="_blank" rel="noopener noreferrer">公式ページを開く</a></p>@endif
@if ($program->notes)<p>{{ $program->notes }}</p>@endif
</div>
<div class="card">
    <h2>別名</h2>
    <div class="list">@forelse ($program->aliases as $alias)<div class="list-item"><div><strong>{{ $alias->alias }}</strong><small>{{ $alias->source_scope ?: 'global' }}</small></div><form method="post" action="{{ route('settings.programs.aliases.destroy', [$program, $alias]) }}" onsubmit="return confirm('別名を削除しますか？')">@csrf @method('DELETE')<button class="danger" type="submit">削除</button></form></div>@empty<p class="muted">別名はまだありません。</p>@endforelse</div>
    <form class="form-grid" method="post" action="{{ route('settings.programs.aliases.store', $program) }}">@csrf
        <div class="field"><label for="alias">新しい別名</label><input id="alias" name="alias" maxlength="150" required></div>
        <div class="field"><label for="source_scope">適用範囲（空欄でglobal）</label><input id="source_scope" name="source_scope" maxlength="100"></div>
        <button type="submit">別名を追加</button>
    </form>
</div>
<div class="card"><h2>利用状態</h2><p class="help">無効化しても口座と取引履歴は残ります。</p><form method="post" action="{{ route('settings.programs.toggle', $program) }}" onsubmit="return confirm('利用状態を変更しますか？')">@csrf<button class="{{ $program->active ? 'danger' : 'secondary' }}" type="submit">{{ $program->active ? '無効化' : '有効化' }}</button></form></div>
@endsection
