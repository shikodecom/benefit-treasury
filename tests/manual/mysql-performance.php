<?php

// Run only against a disposable, migrated MySQL database ending in _test.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\BenefitNotificationService;
use App\Services\BenefitSearchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (app()->environment() !== 'testing' || DB::getDriverName() !== 'mysql'
    || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
    throw new RuntimeException('Refusing to run outside a disposable MySQL test database.');
}
if (DB::table('benefit_lots')->exists() || DB::table('benefit_transactions')->exists()) {
    throw new RuntimeException('Performance database must start without lots or transactions.');
}

$now = now();
$today = now('Asia/Tokyo')->toDateString();
$programId = DB::table('benefit_programs')->insertGetId([
    'name' => 'Synthetic performance program', 'category' => 'shareholder_benefit',
    'unit_name' => 'point', 'active' => 1, 'sellable' => 1,
    'created_at' => $now, 'updated_at' => $now,
]);
$accountId = DB::table('benefit_accounts')->insertGetId([
    'program_id' => $programId, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
]);

function addLots(int $start, int $end, int $accountId, string $today, $now, int $transactionsPerLot): void
{
    for ($i = $start; $i < $end; $i += 100) {
        $lotRows = [];
        for ($j = $i; $j < min($i + 100, $end); $j++) {
            $lotRows[] = [
                'account_id' => $accountId, 'display_name' => 'Synthetic lot '.str_pad((string) $j, 4, '0', STR_PAD_LEFT),
                'acquired_at' => $today, 'expires_at' => now('Asia/Tokyo')->addDays($j % 2 ? 3 : 7)->toDateString(),
                'action_policy' => 'sell_now', 'source' => 'performance_test',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('benefit_lots')->insert($lotRows);
    }
    $ids = DB::table('benefit_lots')->where('account_id', $accountId)->orderBy('id')->pluck('id')->all();
    $rows = [];
    foreach (array_slice($ids, $start, $end - $start) as $id) {
        for ($n = 0; $n < $transactionsPerLot; $n++) {
            $in = $n === 0 || $n === 2;
            $rows[] = [
                'account_id' => $accountId, 'lot_id' => $id,
                'transaction_type' => $n === 0 ? 'opening_balance' : ($in ? 'earn' : 'use'),
                'quantity' => $n === 0 ? 10 : 1, 'direction' => $in ? 'in' : 'out',
                'transaction_at' => $today, 'source_type' => 'manual',
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($rows) >= 250) {
                DB::table('benefit_transactions')->insert($rows);
                $rows = [];
            }
        }
    }
    if ($rows) {
        DB::table('benefit_transactions')->insert($rows);
    }
}

addLots(0, 500, $accountId, $today, $now, 4);
if (DB::table('benefit_lots')->count() !== 500 || DB::table('benefit_transactions')->count() !== 2000) {
    throw new RuntimeException('Search fixture size mismatch.');
}
$search = app(BenefitSearchService::class);
foreach ([['policy' => 'sell_now', 'listing' => 'unlisted'], ['q' => 'Synthetic lot', 'expires_within' => '7'], ['sort' => 'name', 'per_page' => 25, 'lots_page' => 2]] as $filters) {
    $started = hrtime(true);
    $result = $search->search($filters);
    $ms = (hrtime(true) - $started) / 1e6;
    if ($result['lots']->total() !== 500 || $result['lots']->count() !== 25) {
        throw new RuntimeException('Search results or pagination mismatch.');
    }
    echo 'search '.json_encode($filters).' '.round($ms, 1)." ms\n";
}

addLots(500, 1000, $accountId, $today, $now, 1);
$notifications = app(BenefitNotificationService::class);
foreach ([1, 2] as $run) {
    $started = hrtime(true);
    $created = $notifications->generate();
    $ms = (hrtime(true) - $started) / 1e6;
    if ($created !== ($run === 1 ? 1000 : 0) || DB::table('notifications')->count() !== 1000) {
        throw new RuntimeException('Notification creation or deduplication mismatch.');
    }
    echo "notifications run {$run}: {$created} created, ".round($ms, 1)." ms\n";
}
echo 'peak memory '.round(memory_get_peak_usage(true) / 1024 / 1024, 1)." MiB\n";
