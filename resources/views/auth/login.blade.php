@extends('layouts.app')

@section('title', 'ログイン')

@section('content')
<div class="card auth-card">
    <h1>ログイン</h1>
    <p class="muted">特典台帳の管理画面へ進みます。</p>
    <form class="stack" method="post" action="{{ route('login.submit') }}">
        @csrf
        <div class="field">
            <label for="email">メールアドレス</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
        </div>
        <div class="field">
            <label for="password">パスワード</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button type="submit">ログイン</button>
    </form>
</div>
@endsection
