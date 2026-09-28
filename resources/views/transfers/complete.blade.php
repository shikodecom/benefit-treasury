@extends('layouts.app')
@section('title', '着弾を確認')
@section('content')
<div class="crumb"><a href="{{ route('transfers.show', $step->transfer_group_id) }}">移行詳細</a> / 着弾確認</div><h1>着弾を確認</h1>
<div class="card"><h2>{{ $step->fromAccount->program->name }} → {{ $step->toAccount->program->name }}</h2><p>移行元 {{ $step->source_quantity }} {{ $step->fromAccount->program->unit_name }} · 受取予定 {{ $step->expected_destination_quantity ?? '未設定' }} {{ $step->toAccount->program->unit_name }}</p><p class="muted">申請日 {{ $step->started_at }} · 予定日 {{ $step->expected_complete_at ?: '未設定' }}</p></div>
<form class="stack" method="post" action="{{ route('transfers.steps.complete', $step) }}">@csrf<div class="card"><p>実際に着弾した数量を確認して確定してください。確定すると移行先残高へ加算します。</p><div class="form-grid"><div class="field"><label for="completed_at">着弾日</label><input id="completed_at" name="completed_at" type="date" value="{{ old('completed_at', now('Asia/Tokyo')->toDateString()) }}" required></div><div class="field"><label for="actual_destination_quantity">実際の受取数量（{{ $step->toAccount->program->unit_name }}）</label><input id="actual_destination_quantity" name="actual_destination_quantity" type="number" min="0" step="0.0001" value="{{ old('actual_destination_quantity', $step->expected_destination_quantity) }}" required></div></div></div><button>着弾と残高加算を確定</button></form>
@endsection
