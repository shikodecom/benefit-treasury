@extends('layouts.app')

@section('title', '取引履歴')

@section('content')
<div class="page-head"><div><h1>取引履歴</h1><p class="muted">初期残高は獲得集計に含めません。</p></div><a class="button" href="{{ route('ledger.transactions.create') }}">取引を登録</a></div>
<form class="card filters" method="get">
    <div class="field"><label for="q">キーワード</label><input id="q" type="search" name="q" value="{{ request('q') }}"></div>
    <div class="field"><label for="from">開始日</label><input id="from" type="date" name="from" value="{{ request('from') }}"></div>
    <div class="field"><label for="to">終了日</label><input id="to" type="date" name="to" value="{{ request('to') }}"></div>
    <div class="field"><label for="program_id">制度</label><select id="program_id" name="program_id"><option value="">すべて</option>@foreach($programs as $program)<option value="{{ $program->id }}" @selected((string)request('program_id')===(string)$program->id)>{{ $program->name }}</option>@endforeach</select></div>
    <div class="field"><label for="household_member_id">名義</label><select id="household_member_id" name="household_member_id"><option value="">すべて</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((string)request('household_member_id')===(string)$member->id)>{{ $member->display_name }}</option>@endforeach</select></div>
    <div class="field"><label for="account_id">口座</label><select id="account_id" name="account_id"><option value="">すべて</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string)request('account_id')===(string)$account->id)>{{ $account->program->name }} / {{ $account->account_label ?: $account->id }}</option>@endforeach</select></div>
    <div class="field"><label for="transaction_type">種別</label><select id="transaction_type" name="transaction_type"><option value="">すべて</option>@foreach(\App\Domain\BenefitValues::TRANSACTION_TYPES as $type)<option value="{{ $type }}" @selected(request('transaction_type')===$type)>{{ $type }}</option>@endforeach</select></div>
    <div class="field"><label for="direction">増減</label><select id="direction" name="direction"><option value="">すべて</option><option value="in" @selected(request('direction')==='in')>増</option><option value="out" @selected(request('direction')==='out')>減</option></select></div>
    <div class="field"><label for="has_lot">ロット</label><select id="has_lot" name="has_lot"><option value="">すべて</option><option value="1" @selected(request('has_lot')==='1')>あり</option><option value="0" @selected(request('has_lot')==='0')>なし</option></select></div>
    <div class="field"><label for="source_type">登録元</label><select id="source_type" name="source_type"><option value="">すべて</option><option value="manual" @selected(request('source_type')==='manual')>手入力</option><option value="import" @selected(request('source_type')==='import')>取込</option></select></div>
    <button class="secondary" type="submit">絞り込む</button>
</form>
<div class="card"><strong>表示条件の数量合計</strong><p class="muted">獲得 {{ $summary['earn'] ?? '0' }} ・ 利用 {{ $summary['use'] ?? '0' }} ・ 失効 {{ $summary['expire'] ?? '0' }} ・ 売却 {{ $summary['sell'] ?? '0' }} ・ 移行出 {{ $summary['transfer_out'] ?? '0' }} ・ 移行入 {{ $summary['transfer_in'] ?? '0' }}</p><small class="help">制度ごとに単位が異なるため、複数制度を含む合計は参考値です。</small></div>
<div class="list">@forelse($transactions as $transaction)
    <div class="list-item"><div><strong>{{ $transaction->account->program->name }} · {{ $transaction->account->householdMember?->display_name ?? '家族共通' }}</strong><small>{{ $transaction->transaction_at }} · {{ $transaction->merchant_or_purpose ?: $transaction->transaction_type }} · {{ $transaction->source_type }} @if($transaction->lot?->expires_at) · 期限 {{ $transaction->lot->expires_at }} @endif</small></div><div class="row"><strong>{{ $transaction->direction === 'in' ? '+' : '−' }}{{ $transaction->quantity }} {{ $transaction->account->program->unit_name }}</strong><a class="button secondary" href="{{ route('ledger.transactions.show', $transaction) }}">詳細</a></div></div>
@empty<div class="card empty">該当する取引はありません。</div>@endforelse</div>
@include('settings.pager', ['paginator' => $transactions])
@endsection
