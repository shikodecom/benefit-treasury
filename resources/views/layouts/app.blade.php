<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '特典台帳') · 特典台帳</title>
    <link rel="stylesheet" href="{{ asset('app.css') }}">
</head>
<body>
<header>
    <div class="topbar">
        <a class="brand" href="{{ route('ledger.home') }}">特典台帳</a>
        @auth
            <nav class="nav" aria-label="メインナビゲーション">
                <a href="{{ route('ledger.home') }}">保有</a>
                <a href="{{ route('ledger.transactions.index') }}">取引</a>
                <a href="{{ route('ledger.lots.index') }}">ロット</a>
                <a href="{{ route('settings.members.index') }}">名義</a>
                <a href="{{ route('settings.programs.index') }}">制度</a>
                <a href="{{ route('settings.accounts.index') }}">口座</a>
                <form method="post" action="{{ route('logout') }}">@csrf <button class="link-button" type="submit">ログアウト</button></form>
            </nav>
        @endauth
    </div>
</header>
<main class="container">
    @if (session('status'))
        <div class="alert" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert error-box" role="alert">
            <strong>入力内容を確認してください。</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>
