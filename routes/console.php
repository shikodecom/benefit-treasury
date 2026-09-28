<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('benefit:db-check', function (): int {
    try {
        DB::select('SELECT 1 AS ok');
        $this->info('DB connection OK.');

        return 0;
    } catch (Throwable) {
        $this->error('DB connection failed. Check server-side .env and database access.');

        return 1;
    }
})->purpose('Check the configured database connection without changing data');

Artisan::command('benefit:create-admin', function (): int {
    $name = trim((string) $this->ask('管理者の表示名'));
    $email = trim((string) $this->ask('ログイン用メールアドレス'));
    $password = (string) $this->secret('パスワード（12文字以上）');

    if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 12) {
        $this->error('表示名、メールアドレス、12文字以上のパスワードを入力してください。');

        return 1;
    }

    User::query()->updateOrCreate(['email' => $email], [
        'name' => $name,
        'password' => $password,
    ]);

    $this->info('管理者アカウントを保存しました。');

    return 0;
})->purpose('Create or update the administrator login');
