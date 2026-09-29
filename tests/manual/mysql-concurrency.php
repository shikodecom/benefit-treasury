<?php

/**
 * Run against an isolated MySQL database, never the production database:
 * APP_ENV=testing DB_CONNECTION=mysql DB_DATABASE=benefit_treasury_concurrency_test \
 * DB_SOCKET=/tmp/benefit-mysql80.sock DB_USERNAME=root php tests/manual/mysql-concurrency.php
 */

use App\Models\BenefitAccount;
use App\Models\BenefitListing;
use App\Models\BenefitLot;
use App\Models\BenefitProgram;
use App\Models\BenefitTransferStep;
use App\Services\BenefitListingService;
use App\Services\BenefitLotService;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use App\Services\BenefitTransferService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->environment() !== 'testing' || DB::getDefaultConnection() !== 'mysql'
    || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
    fwrite(STDERR, "Refusing to run outside an isolated MySQL test database.\n");
    exit(2);
}

function runWorker(string $operation, int $id): void
{
    echo "READY\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'GO') {
        exit(3);
    }
    try {
        match ($operation) {
            'account_use' => app(BenefitTransactionService::class)->use(BenefitAccount::findOrFail($id), '7', '2026-09-29'),
            'lot_use' => app(BenefitLotService::class)->use(BenefitLot::findOrFail($id), '7', '2026-09-29'),
            'publish' => app(BenefitListingService::class)->publish(BenefitListing::findOrFail($id)),
            'sell' => app(BenefitListingService::class)->sell(BenefitListing::findOrFail($id), 1000, 0, 0, '2026-09-29'),
            'transfer_start' => app(BenefitTransferService::class)->startStep(BenefitTransferStep::findOrFail($id), '2026-09-29'),
            'transfer_complete' => app(BenefitTransferService::class)->completeStep(BenefitTransferStep::findOrFail($id), '7', '2026-09-29'),
        };
        echo "RESULT:ok\n";
    } catch (ValidationException) {
        echo "RESULT:validation\n";
    } catch (Throwable $error) {
        echo 'RESULT:'.get_class($error).':'.str_replace("\n", ' ', $error->getMessage())."\n";
    }
    flush();
}

function assertEqual(mixed $expected, mixed $actual, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException("{$label}: expected ".json_encode($expected).' got '.json_encode($actual));
    }
}

function race(string $operation, array $ids, string $lockedTable, int $lockedId): void
{
    $children = [];
    DB::beginTransaction();
    try {
        DB::table($lockedTable)->where('id', $lockedId)->lockForUpdate()->first();
        foreach ($ids as $id) {
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $operation, (string) $id],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start worker');
            }
            stream_set_timeout($pipes[1], 15);
            assertEqual("READY\n", fgets($pipes[1]), 'worker readiness');
            $children[] = [$process, $pipes];
        }
        foreach ($children as [, $pipes]) {
            fwrite($pipes[0], "GO\n");
            fflush($pipes[0]);
        }
        // Both independent MySQL sessions queue behind the same InnoDB row lock.
        usleep(300000);
        DB::commit();
        $results = [];
        foreach ($children as [$process, $pipes]) {
            $line = fgets($pipes[1]);
            $results[] = trim(str_replace('RESULT:', '', (string) $line));
            fclose($pipes[0]);
            fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0) {
                throw new RuntimeException("Worker exited {$exit}: {$errors}");
            }
        }
        sort($results);
        assertEqual(['ok', 'validation'], $results, $operation.' outcomes');
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
    }
}

function account(string $name): BenefitAccount
{
    $program = new BenefitProgram;
    $program->name = $name;
    $program->category = 'point';
    $program->unit_name = 'pt';
    $program->active = true;
    $program->sellable = true;
    $program->transferable = true;
    $program->save();
    $account = new BenefitAccount;
    $account->program_id = $program->id;
    $account->active = true;
    $account->save();

    return $account;
}

if (($argv[1] ?? null) === '--worker') {
    runWorker($argv[2], (int) $argv[3]);
    exit;
}

$read = app(BenefitReadService::class);
assertEqual('READ-COMMITTED', DB::selectOne('SELECT @@transaction_isolation AS isolation')->isolation, 'MySQL isolation level');
$transactions = app(BenefitTransactionService::class);
$lots = app(BenefitLotService::class);
$listings = app(BenefitListingService::class);
$transfers = app(BenefitTransferService::class);

for ($round = 1; $round <= 5; $round++) {
    $plain = account("Concurrent plain {$round}");
    $transactions->createOpeningBalance($plain, '2026-09-29', '10', null);
    race('account_use', [$plain->id, $plain->id], 'benefit_accounts', $plain->id);
    assertEqual('3.0000', $read->unallocatedBalance($plain->id), 'plain balance');
    assertEqual(1, $plain->transactions()->where('transaction_type', 'use')->count(), 'plain use count');

    $lotAccount = account("Concurrent lot {$round}");
    $lot = $lots->acquire($lotAccount, ['display_name' => 'Test lot', 'quantity' => '10',
        'acquired_at' => '2026-09-29', 'action_policy' => 'sell_now']);
    race('lot_use', [$lot->id, $lot->id], 'benefit_accounts', $lotAccount->id);
    assertEqual('3.0000', $read->lotRemainingQuantity($lot->id), 'lot remaining');
    assertEqual(1, $lot->transactions()->where('transaction_type', 'use')->count(), 'lot use count');

    $listingAccount = account("Concurrent listing {$round}");
    $saleLot = $lots->acquire($listingAccount, ['display_name' => 'Test listing lot', 'quantity' => '10',
        'acquired_at' => '2026-09-29', 'action_policy' => 'sell_now']);
    $first = $listings->createDraft(['marketplace' => 'test'], [$saleLot->id => '7']);
    $second = $listings->createDraft(['marketplace' => 'test'], [$saleLot->id => '7']);
    race('publish', [$first->id, $second->id], 'benefit_accounts', $listingAccount->id);
    assertEqual('7.0000', $read->lotListedQuantity($saleLot->id), 'listed quantity');
    $listed = BenefitListing::query()->whereIn('id', [$first->id, $second->id])->where('status', 'listed')->firstOrFail();
    race('sell', [$listed->id, $listed->id], 'benefit_listings', $listed->id);
    assertEqual('3.0000', $read->lotRemainingQuantity($saleLot->id), 'remaining after sale');
    assertEqual(1, $listed->transactions()->where('transaction_type', 'sell')->count(), 'sell count');

    $source = account("Concurrent source {$round}");
    $destination = account("Concurrent destination {$round}");
    $transactions->createOpeningBalance($source, '2026-09-29', '10', null);
    $group1 = $transfers->createGroup([]);
    $group2 = $transfers->createGroup([]);
    $step1 = $transfers->addStep($group1, ['from_account_id' => $source->id,
        'to_account_id' => $destination->id, 'source_quantity' => '7']);
    $step2 = $transfers->addStep($group2, ['from_account_id' => $source->id,
        'to_account_id' => $destination->id, 'source_quantity' => '7']);
    race('transfer_start', [$step1->id, $step2->id], 'benefit_accounts', $source->id);
    assertEqual('3.0000', $read->unallocatedBalance($source->id), 'remaining after transfer start');
    $processing = BenefitTransferStep::query()->whereIn('id', [$step1->id, $step2->id])
        ->where('status', 'processing')->firstOrFail();
    assertEqual(1, $source->transactions()->where('transaction_type', 'transfer_out')->count(), 'transfer out count');
    race('transfer_complete', [$processing->id, $processing->id], 'benefit_transfer_steps', $processing->id);
    assertEqual('7.0000', $read->unallocatedBalance($destination->id), 'destination balance');
    assertEqual(1, $destination->transactions()->where('transaction_type', 'transfer_in')->count(), 'transfer in count');
    echo "Round {$round}: all MySQL concurrency invariants passed.\n";
}
