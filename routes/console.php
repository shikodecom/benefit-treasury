<?php

use App\Models\User;
use App\Services\BenefitNotificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

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

Artisan::command('benefit:notifications', function (): int {
    $run = (string) Str::uuid();
    $started = hrtime(true);
    $this->line(json_encode(['event' => 'notifications.started', 'run_id' => $run,
        'at' => now('Asia/Tokyo')->toIso8601String()]));
    try {
        $count = app(BenefitNotificationService::class)->generate();
        $this->info(json_encode(['event' => 'notifications.completed', 'run_id' => $run,
            'at' => now('Asia/Tokyo')->toIso8601String(), 'created' => $count,
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 1)]));

        return 0;
    } catch (Throwable) {
        // Never emit row contents, SQL bindings or exception messages to scheduler output.
        $this->error(json_encode(['event' => 'notifications.failed', 'run_id' => $run,
            'at' => now('Asia/Tokyo')->toIso8601String(), 'error_code' => 'generation_failed',
            'duration_ms' => round((hrtime(true) - $started) / 1e6, 1)]));

        return 1;
    }
})->purpose('Generate due benefit and transfer notifications');

Schedule::command('benefit:notifications')->dailyAt('08:00')->timezone('Asia/Tokyo')
    ->withoutOverlapping()->appendOutputTo(storage_path('logs/notifications-scheduler.log'));
