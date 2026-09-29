@extends('layouts.app')
@section('title', '移行計画の確認')
@section('content')
<div class="crumb"><a href="{{ route('conversion.routes.show', $route) }}">{{ $route->name }}</a> / 確認</div>
<h1>移行計画の確認</h1>
<p class="warning">数量は各制度の単位です。実際の着弾数量は完了時に記録します。</p>
@foreach($rows as $row)<article class="card"><h2>Step {{ $loop->iteration }}: {{ $row['account']->program->name }} → {{ $row['to']->program->name }}</h2><p>{{ $row['account']->account_label }} → {{ $row['to']->account_label }}</p><p>投入 {{ $row['used'] }} / 受取予定 {{ $row['received'] }} / 着弾予定 {{ $row['expectedDate'] ?: '手入力待ち' }}</p><p>ルール v{{ $row['rule']->version_no }} {{ $row['rule']->campaign_only ? 'キャンペーン' : '通常' }}</p>@foreach($row['warnings'] as $warning)<p class="warning">{{ $warning }}</p>@endforeach</article>@endforeach
<form method="post" action="{{ route('conversion.routes.draft.store', $route) }}">@csrf
 <input type="hidden" name="source_account_id" value="{{ $input['source_account_id'] }}"><input type="hidden" name="source_quantity" value="{{ $input['source_quantity'] }}"><input type="hidden" name="started_at" value="{{ $input['started_at'] ?? '' }}"><input type="hidden" name="purpose" value="{{ $input['purpose'] ?? '' }}">
 @foreach($input['steps'] as $index => $step)@foreach($step as $key => $value)<input type="hidden" name="steps[{{ $index }}][{{ $key }}]" value="{{ $value }}">@endforeach @endforeach
 <button>移行計画を保存</button> <a class="button secondary" href="{{ route('conversion.routes.draft', $route) }}">戻る</a>
</form>
@endsection
