@php($days = $service->daysUntilExpiry($lot))
<article class="card dashboard-lot">
    <div class="dashboard-lot-main">
        <div><strong><a href="{{ route('ledger.lots.show', $lot) }}">{{ $lot->display_name ?: $lot->account->program->name }}</a></strong><div class="muted">{{ $lot->account->householdMember?->display_name ?? '家族共通' }} · {{ $lot->account->program->name }}</div></div>
        <span class="badge {{ $days !== null && $days < 0 ? 'urgent-badge' : '' }}">@if($days === null)期限なし@elseif($days < 0){{ abs($days) }}日超過@elseif($days === 0)本日期限@elseif($days === 1)明日期限@else あと{{ $days }}日@endif</span>
    </div>
    <div class="dashboard-lot-meta"><span>残数 <strong>{{ $lot->remaining_quantity }} {{ $lot->account->program->unit_name }}</strong></span><span>出品引当 {{ $lot->listed_quantity }}</span><span>概算価値 <strong>{{ $service->estimatedValue($lot) === null ? '未設定' : number_format($service->estimatedValue($lot)).'円' }}</strong></span><span>期限 {{ $lot->expires_at ?: 'なし' }}</span></div>
    <p class="dashboard-recommendation">推奨: {{ $service->recommendedAction($lot) }}</p>
    <div class="dashboard-lot-actions">
        <form method="post" action="{{ route('dashboard.lots.policy', $lot) }}" class="policy-form">@csrf<label for="policy-{{ $section }}-{{ $lot->id }}">方針</label><select id="policy-{{ $section }}-{{ $lot->id }}" name="action_policy">@foreach(['self_use'=>'自分で使う','sell_now'=>'すぐ売る','hold'=>'保留','bundle'=>'セット販売','do_not_sell'=>'売らない','transfer_to_points'=>'ポイント移行','undecided'=>'未設定'] as $value=>$label)<option value="{{ $value }}" @selected($lot->action_policy === $value)>{{ $label }}</option>@endforeach</select><button class="secondary" type="submit">保存</button></form>
        <a class="button secondary" href="{{ route('ledger.lots.show', $lot) }}">詳細・利用</a>
        @if($days !== null && $days < 0)<form method="post" action="{{ route('ledger.lots.expire', $lot) }}" onsubmit="return confirm('このロットの残数すべてを失効として記録しますか？')">@csrf<button class="danger" type="submit">失効処理</button></form>@endif
    </div>
</article>
