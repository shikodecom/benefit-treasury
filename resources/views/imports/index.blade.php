@extends('layouts.app')
@section('title', 'Excel取込')
@section('content')
<h1>Excel初期取込</h1>
<div class="card"><h2>XLSXを解析</h2><p class="muted">解析とプレビューでは業務データを変更しません。20MB以下のXLSXを選択してください。</p><form method="post" action="{{ route('imports.upload') }}" enctype="multipart/form-data">@csrf<div class="field"><label for="file">Excelファイル</label><input id="file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div><button>解析する</button></form></div>
<div class="card"><h2>取込履歴</h2><div class="list">@forelse($batches as $batch)<a class="list-item" href="{{ route('imports.show', $batch) }}"><span>{{ $batch->source_filename }} <small>#{{ $batch->id }} · {{ $batch->started_at }} · {{ $batch->status }}</small></span><strong>{{ $batch->imported_rows }}件</strong></a>@empty<p>取込履歴はありません。</p>@endforelse</div>{{ $batches->links() }}</div>
@endsection
