@extends('layouts.app')

@section('title', $account->exists ? '口座を編集' : '口座を追加')

@section('content')
<div class="crumb"><a href="{{ route('settings.accounts.index') }}">保有口座</a> / {{ $account->exists ? '編集' : '追加' }}</div>
<div class="page-head"><h1>{{ $account->exists ? '口座を編集' : '口座を追加' }}</h1></div>
<form class="card stack" method="post" action="{{ $account->exists ? route('settings.accounts.update', $account) : route('settings.accounts.store') }}">
    @csrf
    @if ($account->exists) @method('PUT') @endif
    <div class="form-grid">
        <div class="field"><label for="program_search">制度を絞り込み</label><input id="program_search" type="search" placeholder="名前を入力" oninput="for(const o of document.querySelectorAll('#program_id option')){if(o.value)o.hidden=!o.textContent.toLowerCase().includes(this.value.toLowerCase())}"></div>
        <div class="field"><label for="program_id">特典制度</label><select id="program_id" name="program_id" required><option value="">選択してください</option>
        @foreach ($programs as $program)<option value="{{ $program->id }}" @selected((string)old('program_id', $account->program_id)===(string)$program->id)>{{ $program->name }} · {{ $program->category }}{{ $program->active ? '' : '（無効）' }}</option>@endforeach
        </select></div>
        <div class="field"><label for="household_member_id">名義</label><select id="household_member_id" name="household_member_id"><option value="">家族共通／名義なし</option>
        @foreach ($members as $member)<option value="{{ $member->id }}" @selected((string)old('household_member_id', $account->household_member_id)===(string)$member->id)>{{ $member->display_name }}{{ $member->active ? '' : '（無効）' }}</option>@endforeach
        </select></div>
        <div class="field"><label for="account_label">口座ラベル</label><input id="account_label" name="account_label" maxlength="150" placeholder="メイン、サブなど" value="{{ old('account_label', $account->account_label) }}"></div>
        <div class="field"><label for="external_account_hint">識別ヒント</label><input id="external_account_hint" name="external_account_hint" maxlength="100" placeholder="下4桁など" value="{{ old('external_account_hint', $account->external_account_hint) }}"><small class="help">完全な会員番号・ログインID・パスワードは登録しないでください。</small></div>
    </div>
    @unless ($account->exists)
        <label class="check"><input type="checkbox" name="force_duplicate" value="1" @checked(old('force_duplicate'))> 同じ制度・名義・ラベルの口座があることを確認したうえで登録する</label>
    @endunless
    <div class="row"><button type="submit">保存</button><a class="button secondary" href="{{ $account->exists ? route('settings.accounts.show', $account) : route('settings.accounts.index') }}">戻る</a></div>
</form>
@endsection
