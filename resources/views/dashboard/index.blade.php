@extends('layouts.app')

@section('title', '期限管理ダッシュボード')

@section('content')
<div class="page-head"><div><h1>期限管理ダッシュボード</h1><p class="muted">{{ now('Asia/Tokyo')->format('Y年n月j日') }} 現在の対応事項です。期限切れは自動で失効処理されません。</p></div><a class="button secondary" href="{{ route('ledger.lots.create') }}">特典を取得</a></div>

<div class="dashboard-summary" aria-label="期限と出品の集計">
    @foreach(['expired'=>'期限切れ未処理','within7'=>'7日以内','within30'=>'30日以内','listed'=>'出品中'] as $key=>$label)
        <div class="card summary-card"><small>{{ $label }}</small><strong>{{ $summary[$key]['count'] }}件</strong><span>設定済み概算 {{ number_format($summary[$key]['value']) }}円</span></div>
    @endforeach
</div>
<div class="dashboard-summary" aria-label="移行中の集計"><div class="card summary-card"><small>移行中</small><strong>{{ $pendingTransfers->count() }}件</strong><a href="{{ route('transfers.index') }}">移行一覧</a></div><div class="card summary-card"><small>着弾予定日超過</small><strong>{{ $overdueTransfers->count() }}件</strong><a href="{{ route('transfers.index', ['status'=>'overdue']) }}">確認する</a></div>@foreach($equivalents as $equivalent)<div class="card summary-card"><small>{{ $equivalent['program']?->name }} 換算</small><strong>{{ $equivalent['quantity'] }} {{ $equivalent['program']?->unit_name }}</strong></div>@endforeach</div>
@if($overdueTransfers->isNotEmpty())<section class="dashboard-section"><div class="section-head"><h2>着弾確認が必要な移行 <span class="count">{{ $overdueTransfers->count() }}</span></h2></div><div class="dashboard-items">@foreach($overdueTransfers as $step)<article class="card"><strong>{{ $step->fromAccount->program->name }} → {{ $step->toAccount->program->name }}</strong><p>着弾予定 {{ $step->expected_complete_at }} · 受取予定 {{ $step->expected_destination_quantity ?? '未設定' }} {{ $step->toAccount->program->unit_name }}</p><p class="dashboard-recommendation">推奨: 着弾確認</p><a class="button secondary" href="{{ route('transfers.show', $step->transfer_group_id) }}">詳細</a></article>@endforeach</div></section>@endif
<p class="help">30日以内には7日以内を含みます。出品中は出品件数と設定済み出品価格の合計、他はロット件数と方針別の概算価値です。価値未設定は合計に含めません。</p>

@if($balanceAccounts->isNotEmpty())
<section class="dashboard-section" aria-labelledby="heading-balances">
    <div class="section-head"><h2 id="heading-balances">口座残高 <span class="count">{{ $balanceAccounts->count() }}</span></h2><a href="{{ route('ledger.home') }}">保有一覧</a></div>
    <div class="dashboard-items">
        @foreach($balanceAccounts as $account)
            <article class="card"><strong>{{ $account->program->name }}{{ $account->account_label ? ' · '.$account->account_label : '' }}</strong><p>{{ number_format((float) $account->current_balance, 4, '.', '') }} {{ $account->program->unit_name }}</p><a class="button secondary" href="{{ route('ledger.accounts.transactions', $account) }}">取引履歴</a></article>
        @endforeach
    </div>
</section>
@endif

<form class="card filters" method="get" action="{{ route('dashboard.index') }}">
    <div class="field"><label for="member">名義</label><select id="member" name="member"><option value="">すべて</option><option value="shared" @selected(($filters['member'] ?? '') === 'shared')>家族共通</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected((string)($filters['member'] ?? '') === (string)$member->id)>{{ $member->display_name }}</option>@endforeach</select></div>
    <div class="field"><label for="category">カテゴリ</label><select id="category" name="category"><option value="">すべて</option>@foreach(['point'=>'ポイント','mile'=>'マイル','e_money'=>'電子マネー','gift'=>'商品券・ギフト','shareholder_benefit'=>'株主優待','coupon'=>'クーポン','discount'=>'割引','campaign'=>'キャンペーン特典','other'=>'その他'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="field"><label for="policy">運用方針</label><select id="policy" name="policy"><option value="">すべて</option>@foreach(['self_use'=>'自分で使う','sell_now'=>'すぐ売る','hold'=>'保留','bundle'=>'セット販売','do_not_sell'=>'売らない','transfer_to_points'=>'ポイント移行','undecided'=>'未設定'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['policy'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
    <div class="field"><label for="expiry">期限</label><select id="expiry" name="expiry"><option value="">すべて</option><option value="expired" @selected(($filters['expiry'] ?? '') === 'expired')>期限切れ</option><option value="7" @selected(($filters['expiry'] ?? '') === '7')>7日以内</option><option value="30" @selected(($filters['expiry'] ?? '') === '30')>30日以内</option><option value="none" @selected(($filters['expiry'] ?? '') === 'none')>期限なし</option></select></div>
    <button class="secondary" type="submit">絞り込む</button><a href="{{ route('dashboard.index') }}">解除</a>
</form>

@foreach(['urgent'=>['今すぐ対応','期限切れ・3日以内・未出品の売却対象'], 'week'=>['今週の期限','今日から7日以内'], 'month'=>['30日以内','8〜30日後'], 'listed'=>['出品中','現在出品中のロット'], 'undecided'=>['方針未設定','期限なしも含む']] as $section=>$heading)
<section class="dashboard-section" aria-labelledby="heading-{{ $section }}">
    <div class="section-head"><h2 id="heading-{{ $section }}">{{ $heading[0] }} <span class="count">{{ $$section->count() }}</span></h2><span class="muted">{{ $heading[1] }}</span></div>
    <div class="dashboard-items">
        @forelse($$section as $lot)
            @include('dashboard.lot-card', ['lot' => $lot])
        @empty
            <div class="card empty">該当する特典はありません。</div>
        @endforelse
    </div>
</section>
@endforeach
@endsection
