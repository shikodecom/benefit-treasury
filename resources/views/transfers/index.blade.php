@extends('layouts.app')
@section('title', 'ポイント・マイル移行')
@section('content')
<div class="page-head"><div><h1>ポイント・マイル移行</h1><p class="muted">申請中の移行と着弾予定を管理します。</p></div><a class="button" href="{{ route('transfers.create') }}">移行計画を作成</a></div>
<div class="row">@foreach(['processing'=>'処理中','planned'=>'予定','overdue'=>'予定日超過','completed'=>'完了','error'=>'エラー','cancelled'=>'取消','all'=>'すべて'] as $value=>$label)<a class="button {{ $status === $value ? '' : 'secondary' }}" href="{{ route('transfers.index', ['status'=>$value]) }}">{{ $label }}</a>@endforeach</div>
@forelse($groups as $group)
@php($steps = $group->steps->sortBy('sequence_no'))
@php($current = $steps->first(fn ($step) => in_array($step->status, ['processing','error'])) ?? $steps->firstWhere('status', 'planned') ?? $steps->last())
<article class="card"><div class="page-head"><div><h2><a href="{{ route('transfers.show', $group) }}">{{ $group->name ?: ($group->purpose ?: '移行 #'.$group->id) }}</a></h2><p class="muted">{{ ['planned'=>'予定','processing'=>'処理中','completed'=>'完了','cancelled'=>'取消','error'=>'エラー'][$group->status] ?? $group->status }} · {{ $steps->where('status','completed')->count() }} / {{ $steps->count() }} 完了</p></div><a class="button secondary" href="{{ route('transfers.show', $group) }}">詳細</a></div>
@if($current)<p><strong>{{ $current->fromAccount->program->name }} → {{ $current->toAccount->program->name }}</strong> · {{ $current->source_quantity }} {{ $current->fromAccount->program->unit_name }} → 予定 {{ $current->expected_destination_quantity ?? '未設定' }} {{ $current->toAccount->program->unit_name }}</p>@if($current->planning_equivalent_quantity)<p class="muted">最終換算 {{ $current->planning_equivalent_quantity }} {{ $current->planningEquivalentProgram?->unit_name }}</p>@endif @if($current->status === 'processing' && $current->expected_complete_at)<p class="{{ $current->expected_complete_at < now('Asia/Tokyo')->toDateString() ? 'warning' : 'muted' }}">着弾予定 {{ $current->expected_complete_at }} · @if($current->expected_complete_at < now('Asia/Tokyo')->toDateString()){{ abs(now('Asia/Tokyo')->startOfDay()->diffInDays(\Carbon\Carbon::parse($current->expected_complete_at, 'Asia/Tokyo'))) }}日超過@else あと{{ now('Asia/Tokyo')->startOfDay()->diffInDays(\Carbon\Carbon::parse($current->expected_complete_at, 'Asia/Tokyo')) }}日@endif</p>@endif @endif
</article>
@empty<div class="card">該当する移行はありません。</div>@endforelse
{{ $groups->links() }}
@endsection
