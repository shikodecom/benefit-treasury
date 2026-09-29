@extends('layouts.app')
@section('title', '移行計画の作成')
@section('content')
<div class="crumb"><a href="{{ route('conversion.routes.show', $route) }}">{{ $route->name }}</a> / 移行計画</div>
<h1>このルートで移行を作成</h1>
<p class="muted">口座と各ステップのルールを選び、保存前に数量を確認します。途中口座の既存残高を使う場合は、そのステップの投入数量を指定してください。</p>
<form method="post" action="{{ route('conversion.routes.draft.preview', $route) }}" class="card">@csrf
 <div class="field"><label for="source_account_id">移行元口座</label><select id="source_account_id" name="source_account_id" required><option value="">選択</option>@foreach($accounts->where('program_id', $route->steps->sortBy('sequence_no')->first()?->ruleGroup->from_program_id) as $account)<option value="{{ $account->id }}" @selected(old('source_account_id') == $account->id)>{{ $account->householdMember?->display_name }} / {{ $account->program->name }} / {{ $account->account_label }}</option>@endforeach</select></div>
 <div class="field"><label for="source_quantity">最初の投入数量</label><input id="source_quantity" name="source_quantity" type="number" min="0.0001" step="0.0001" inputmode="decimal" value="{{ old('source_quantity') }}" required></div>
 <div class="field"><label for="started_at">開始予定日</label><input id="started_at" name="started_at" type="date" value="{{ old('started_at', now('Asia/Tokyo')->toDateString()) }}"></div>
 <div class="field"><label for="purpose">目的（任意）</label><input id="purpose" name="purpose" value="{{ old('purpose') }}"></div>
 @foreach($route->steps->sortBy('sequence_no')->values() as $index => $step)
 <fieldset class="card"><legend>Step {{ $index + 1 }}: {{ $step->ruleGroup->fromProgram->name }} → {{ $step->ruleGroup->toProgram->name }}</legend>
 <div class="field"><label for="rule_{{ $index }}">利用ルール</label><select id="rule_{{ $index }}" name="steps[{{ $index }}][rule_id]" required><option value="">選択</option>@foreach($candidates[$step->id] as $rule)<option value="{{ $rule->id }}" @selected(old("steps.$index.rule_id") == $rule->id)>{{ $rule->campaign_only ? 'キャンペーン: '.($rule->campaign_name ?: '期間限定') : '通常' }} / v{{ $rule->version_no }} / {{ $rule->from_quantity }} → {{ $rule->to_quantity }}</option>@endforeach</select></div>
 <div class="field"><label for="to_{{ $index }}">移行先口座</label><select id="to_{{ $index }}" name="steps[{{ $index }}][to_account_id]" required><option value="">選択</option>@foreach($accounts->where('program_id', $step->ruleGroup->to_program_id) as $account)<option value="{{ $account->id }}" @selected(old("steps.$index.to_account_id") == $account->id)>{{ $account->householdMember?->display_name }} / {{ $account->account_label }}</option>@endforeach</select></div>
 @if($index > 0)<div class="field"><label for="quantity_{{ $index }}">このステップの投入数量（空欄なら前ステップ受取数量）</label><input id="quantity_{{ $index }}" name="steps[{{ $index }}][source_quantity]" type="number" min="0.0001" step="0.0001" inputmode="decimal" value="{{ old("steps.$index.source_quantity") }}"></div>@endif
 <div class="field"><label for="date_{{ $index }}">着弾予定日（空欄ならルールの最大日数から提案）</label><input id="date_{{ $index }}" name="steps[{{ $index }}][expected_complete_at]" type="date" value="{{ old("steps.$index.expected_complete_at") }}"></div>
 </fieldset>
 @endforeach
 <button>内容を確認</button>
</form>
@endsection
