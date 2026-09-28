@extends('layouts.app')

@section('title', $program->exists ? '制度を編集' : '制度を追加')

@section('content')
<div class="crumb"><a href="{{ route('settings.programs.index') }}">特典制度</a> / {{ $program->exists ? '編集' : '追加' }}</div>
<div class="page-head"><h1>{{ $program->exists ? '制度を編集' : '制度を追加' }}</h1></div>
<form class="card stack" method="post" action="{{ $program->exists ? route('settings.programs.update', $program) : route('settings.programs.store') }}">
    @csrf
    @if ($program->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label for="name">制度名</label><input id="name" name="name" maxlength="150" value="{{ old('name', $program->name) }}" required></div>
        <div class="field"><label for="provider">提供元</label><input id="provider" name="provider" maxlength="150" value="{{ old('provider', $program->provider) }}"></div>
        <div class="field"><label for="category">カテゴリ</label><select id="category" name="category" required><option value="">選択してください</option>
        @foreach (['point'=>'ポイント','mile'=>'マイル','e_money'=>'電子マネー','gift'=>'商品券・ギフト','shareholder_benefit'=>'株主優待','coupon'=>'クーポン','discount'=>'割引','campaign'=>'キャンペーン特典','other'=>'その他'] as $value => $label)
            <option value="{{ $value }}" @selected(old('category', $program->category)===$value)>{{ $label }}</option>
        @endforeach
        </select></div>
        <div class="field"><label for="unit_name">単位</label><input id="unit_name" name="unit_name" maxlength="30" placeholder="pt、mile、枚など" value="{{ old('unit_name', $program->unit_name) }}" required></div>
        <div class="field"><label for="default_unit_value_yen">参考円換算（1単位）</label><input id="default_unit_value_yen" name="default_unit_value_yen" type="number" min="0" step="0.0001" value="{{ old('default_unit_value_yen', $program->default_unit_value_yen) }}"></div>
        <div class="field"><label for="official_url">公式URL</label><input id="official_url" name="official_url" type="url" value="{{ old('official_url', $program->official_url) }}" placeholder="https://"></div>
    </div>
    <div class="row"><label class="check"><input type="checkbox" name="transferable" value="1" @checked(old('transferable', $program->transferable))> 移行可能</label><label class="check"><input type="checkbox" name="sellable" value="1" @checked(old('sellable', $program->sellable))> 売却可能</label></div>
    <div class="field"><label for="notes">メモ</label><textarea id="notes" name="notes">{{ old('notes', $program->notes) }}</textarea></div>
    <label class="check"><input type="checkbox" name="force_duplicate_name" value="1" @checked(old('force_duplicate_name'))> 同じ名前の制度があることを確認したうえで保存する</label>
    <p class="help">取引履歴がある制度の単位・カテゴリは変更できません。</p>
    <div class="row"><button type="submit">保存</button><a class="button secondary" href="{{ $program->exists ? route('settings.programs.show', $program) : route('settings.programs.index') }}">戻る</a></div>
</form>
@endsection
