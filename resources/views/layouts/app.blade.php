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
        <a class="brand" href="{{ route('dashboard.index') }}">特典台帳</a>
        @auth
            <nav class="nav" aria-label="メインナビゲーション">
                <a href="{{ route('dashboard.index') }}">ダッシュボード</a>
                <a href="{{ route('ledger.home') }}">保有</a>
                <a href="{{ route('search.index') }}">横断検索</a>
                <a href="{{ route('ledger.transactions.index') }}">取引</a>
                <a href="{{ route('ledger.lots.index') }}">ロット</a>
                <a href="{{ route('listings.index') }}">出品</a>
                <a href="{{ route('transfers.index') }}">移行</a>
                <a href="{{ route('conversion.rules.index') }}">交換ルール</a>
                <a href="{{ route('conversion.routes.index') }}">交換ルート</a>
                <a href="{{ route('imports.index') }}">Excel取込</a>
                <a href="{{ route('notifications.index') }}">通知 @php($unreadCount = \App\Models\Notification::query()->whereNull('read_at')->whereNull('dismissed_at')->count())@if($unreadCount)({{ $unreadCount }})@endif</a>
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
    @if(is_array(request()->query('search')))
        <div class="crumb"><a href="{{ route('search.index', request()->query('search')) }}">検索結果へ戻る</a></div>
    @endif
    @yield('content')
</main>
</body>
</html>
