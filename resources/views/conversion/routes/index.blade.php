@extends('layouts.app')
@section('title', '交換ルート')
@section('content')
<div class="page-head"><div><h1>交換ルート</h1><p class="muted">よく使う交換経路を保存します。数量はルートに固定しません。</p></div><div class="row"><a class="button secondary" href="{{ route('conversion.rules.index') }}">交換ルール</a><a class="button" href="{{ route('conversion.routes.create') }}">ルートを作成</a></div></div>
<div class="row">@foreach(['active'=>'有効','inactive'=>'無効','all'=>'すべて'] as $value=>$label)<a class="button {{ $status === $value ? '' : 'secondary' }}" href="{{ route('conversion.routes.index', ['status'=>$value]) }}">{{ $label }}</a>@endforeach</div>
@forelse($routes as $route)<article class="card"><div class="page-head"><div><h2><a href="{{ route('conversion.routes.show', $route) }}">{{ $route->name }}</a></h2><p class="muted">{{ $usable[$route->id] ? '現在利用可能' : '条件を確認' }} · {{ $route->active ? '有効設定' : '無効設定' }} · {{ $route->steps->count() }} ステップ</p></div><a class="button secondary" href="{{ route('conversion.routes.show', $route) }}">詳細</a></div><p>@foreach($route->steps->sortBy('sequence_no') as $step)@if($loop->first){{ $step->ruleGroup->fromProgram->name }} → @endif{{ $step->ruleGroup->toProgram->name }}@if(!$loop->last) → @endif @endforeach</p></article>@empty<div class="card">交換ルートがありません。</div>@endforelse
{{ $routes->links() }}
@endsection
