@extends('layouts.app')

@section('title', 'マスタ管理')

@section('content')
<div class="page-head">
    <div><h1>マスタ管理</h1><p class="muted">特典を記録する前に、名義・制度・口座を登録します。</p></div>
</div>
<div class="grid">
    <a class="card" href="{{ route('settings.members.index') }}"><h2>家族・名義</h2><p>誰の特典かを管理します。</p></a>
    <a class="card" href="{{ route('settings.programs.index') }}"><h2>特典制度・別名</h2><p>ポイント、マイル、優待券などの種類を管理します。</p></a>
    <a class="card" href="{{ route('settings.accounts.index') }}"><h2>保有口座</h2><p>制度と名義を組み合わせて管理します。</p></a>
</div>
@endsection
