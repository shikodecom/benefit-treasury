@extends('layouts.app')
@section('title', $listing->exists ? '出品を編集' : '出品を作成')
@section('content')
<div class="crumb"><a href="{{ route('listings.index') }}">出品・売却</a> / {{ $listing->exists ? '編集' : '作成' }}</div>
<h1>{{ $listing->exists ? '出品を編集' : '出品の下書きを作成' }}</h1>
<form class="stack" method="post" action="{{ $listing->exists ? route('listings.update', $listing) : route('listings.store') }}">@csrf @if($listing->exists) @method('PUT') @endif
<div class="card"><h2>特典と数量</h2><p class="muted">複数ロットを選ぶとセット出品できます。異なる期限を含む場合は出品開始時に確認します。</p>
@foreach($lots as $lot)
@php($current = $listing->items->firstWhere('lot_id', $lot->id)?->quantity)
@if((float)$available[$lot->id] > 0 || $current !== null)
<div class="list-item"><label for="item-{{ $lot->id }}"><strong>{{ $lot->display_name ?: $lot->account->program->name }}</strong><small>{{ $lot->account->householdMember?->display_name ?? '家族共通' }} · 期限 {{ $lot->expires_at ?: 'なし' }} · 出品可能 {{ $available[$lot->id] }} {{ $lot->account->program->unit_name }}@if($lot->transfer_restriction === 'non_transferable') · 譲渡不可@endif</small></label><input id="item-{{ $lot->id }}" name="items[{{ $lot->id }}]" type="number" step="0.0001" min="0.0001" value="{{ old('items.'.$lot->id, $current ?? ($selectedLotId == $lot->id ? '1' : '')) }}" aria-label="{{ $lot->display_name ?: $lot->account->program->name }} の出品数量"></div>
@endif
@endforeach</div>
<div class="card"><h2>出品情報</h2><div class="form-grid">
<div class="field"><label for="marketplace">出品先</label><input id="marketplace" name="marketplace" value="{{ old('marketplace', $listing->marketplace) }}" list="marketplaces" maxlength="100" @readonly($listing->status === 'listed') required><datalist id="marketplaces"><option value="ラクマ"><option value="Yahoo!オークション"><option value="Yahoo!フリマ"><option value="メルカリ"><option value="その他"></datalist></div>
<div class="field"><label for="title">出品名</label><input id="title" name="title" value="{{ old('title', $listing->title) }}" maxlength="255"></div>
<div class="field"><label for="listing_price_yen">現在価格（円）</label><input id="listing_price_yen" name="listing_price_yen" type="number" min="0" step="1" value="{{ old('listing_price_yen', $listing->listing_price_yen) }}"></div>
<div class="field"><label for="delivery_type">受け渡し</label><select id="delivery_type" name="delivery_type" @disabled($listing->status === 'listed')><option value="">未設定</option>@foreach(['digital'=>'電子コード','shipping'=>'郵送','handoff'=>'手渡し','other'=>'その他'] as $value=>$label)<option value="{{ $value }}" @selected(old('delivery_type', $listing->delivery_type) === $value)>{{ $label }}</option>@endforeach</select>@if($listing->status === 'listed')<input type="hidden" name="delivery_type" value="{{ $listing->delivery_type }}">@endif</div>
<div class="field"><label for="listing_url">出品 URL</label><input id="listing_url" name="listing_url" type="url" value="{{ old('listing_url', $listing->listing_url) }}" placeholder="https://"></div>
@if($listing->exists)<div class="field"><label for="price_reason">価格変更理由</label><input id="price_reason" name="price_reason" maxlength="255"></div>@endif
</div><div class="field"><label for="memo">メモ（優待コードや PIN は入力しないでください）</label><textarea id="memo" name="memo">{{ old('memo', $listing->memo) }}</textarea></div></div>
@if($listing->status === 'listed')<div class="card"><p class="muted">対象ロットや数量を変更する場合は、方針と期限を再確認してください。</p><label><input type="checkbox" name="confirm_policy" value="1"> 「今売る／セット販売」以外の方針も確認した</label><br><label><input type="checkbox" name="confirm_mixed_expiry" value="1"> 異なる期限を含む場合、購入者への表示を確認した</label></div>@endif
<div><button type="submit">{{ $listing->exists ? '更新' : '下書きを保存' }}</button></div></form>
@endsection
