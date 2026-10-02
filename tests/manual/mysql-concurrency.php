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
use App\Models\ConversionRule;
use App\Models\ImportBatch;
use App\Models\ImportRecord;
use App\Services\BenefitListingService;
use App\Services\BenefitLotService;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use App\Services\BenefitTransferService;
use App\Services\Imports\ExcelImportService;
use App\Services\Imports\ImportFingerprint;
use App\Services\Imports\MappedImportService;
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
            'import' => app(ExcelImportService::class)->execute(ImportBatch::findOrFail($id)),
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

function race(string $operation, array $ids, string $lockedTable, int $lockedId, array $expectedResults = ['ok', 'validation']): void
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
        assertEqual($expectedResults, $results, $operation.' outcomes');
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

function importBatch(BenefitAccount $account, string $source, ?array $plan = null): ImportBatch
{
    $batch = new ImportBatch;
    $batch->source_filename = 'synthetic.xlsx';
    $batch->file_checksum = hash('sha256', $source);
    $batch->importer_version = ExcelImportService::VERSION;
    $batch->started_at = now();
    $batch->status = 'preview';
    $batch->total_rows = 1;
    $batch->save();
    $record = new ImportRecord;
    $record->import_batch_id = $batch->id;
    $record->source_sheet = 'ANA';
    $record->source_row_number = 2;
    $record->row_fingerprint = hash('sha256', $source);
    $record->record_type = 'transaction';
    $record->import_status = 'ready';
    $record->raw_data_json = ['date' => '2026-09-29', 'quantity' => '10', 'description' => $source];
    $record->normalized_data_json = ['account_id' => $plan ? null : $account->id,
        'date' => '2026-09-29', 'quantity' => '10.0000', 'direction' => 'in', 'type' => 'earn',
        'description' => $source, 'balance' => null] + ($plan ? ['account_plan' => $plan] : []);
    $record->save();

    return $batch;
}

function mappedBatch(BenefitAccount $from, BenefitAccount $to, string $source, string $type = 'transfer', array $options = []): ImportBatch
{
    $batch = new ImportBatch;
    $batch->forceFill(['source_filename' => 'synthetic-mapped.xlsx', 'file_checksum' => hash('sha256', $source),
        'importer_version' => ExcelImportService::VERSION, 'started_at' => now(), 'status' => 'preview', 'total_rows' => 1])->save();
    $record = new ImportRecord;
    $record->forceFill(['import_batch_id' => $batch->id, 'source_sheet' => $type === 'transfer' ? 'ANA移行' : 'ポイントマイル状況',
        'source_row_number' => 2, 'row_fingerprint' => hash('sha256', $source), 'record_type' => $type,
        'import_status' => 'needs_review', 'raw_data_json' => []])->save();
    $mapping = $type === 'transfer'
        ? ['from_account_id' => $from->id, 'to_account_id' => $to->id, 'source_quantity' => '7', 'status' => 'planned', 'confirm_status' => 1]
        : ['from_program_id' => $from->program_id, 'to_program_id' => $to->program_id, 'from_quantity' => '100', 'to_quantity' => '50'];
    DB::transaction(fn () => app(MappedImportService::class)->preview($record, array_replace($mapping, ['action' => 'import', 'confirm_native' => 1], $options)));
    assertEqual('ready', $record->fresh()->import_status, 'mapped preview status');

    return $batch;
}

function voucherBatch(BenefitAccount $account, string $source, array $options = []): ImportBatch
{
    $batch = new ImportBatch;
    $batch->forceFill(['source_filename' => 'synthetic-voucher.xlsx', 'file_checksum' => hash('sha256', $source),
        'importer_version' => ExcelImportService::VERSION, 'started_at' => now(), 'status' => 'preview', 'total_rows' => 1])->save();
    $record = new ImportRecord;
    $record->forceFill(['import_batch_id' => $batch->id, 'source_sheet' => 'プレ商品券',
        'source_row_number' => 2, 'row_fingerprint' => hash('sha256', $source), 'record_type' => 'premium_voucher',
        'import_status' => 'needs_review', 'raw_data_json' => ['program' => $account->program->name]])->save();
    $mapping = array_replace(['action' => 'import', 'account_id' => $account->id, 'confirm_native' => 1, 'confirm_snapshot' => 1,
        'remaining_quantity' => '7', 'remaining_yen' => 700, 'native_unit' => $account->program->unit_name,
        'acquired_at' => '2026-09-01', 'expires_at' => '2027-12-31', 'snapshot_at' => '2026-10-01', 'voucher_state' => 'partial'], $options);
    DB::transaction(fn () => app(MappedImportService::class)->preview($record, $mapping));
    assertEqual('ready', $record->fresh()->import_status, 'voucher preview status');

    return $batch;
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

$legacyAccount = account('Legacy import migration');
$legacy = importBatch($legacyAccount, 'legacy-import');
app(ExcelImportService::class)->execute($legacy);
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_02_000001_scope_imported_fingerprints_to_accounts.php';
$migration->down();
$legacyRecord = $legacy->records()->firstOrFail();
assertEqual($legacyRecord->row_fingerprint, DB::table('imported_fingerprints')->where('import_record_id', $legacyRecord->id)->value('row_fingerprint'), 'legacy rollback key');
$migration->up();
assertEqual(ImportFingerprint::forAccount($legacyRecord->row_fingerprint, $legacyAccount->id),
    DB::table('imported_fingerprints')->where('import_record_id', $legacyRecord->id)->value('row_fingerprint'), 'legacy account migration key');
app(ExcelImportService::class)->execute(importBatch($legacyAccount, 'legacy-import'));
assertEqual('10.0000', $read->accountBalance($legacyAccount->id), 'legacy migration reimport balance');

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

    $importAccount = account("Concurrent import {$round}");
    $firstImport = importBatch($importAccount, 'import-'.$round);
    $secondImport = importBatch($importAccount, 'import-'.$round);
    race('import', [$firstImport->id, $secondImport->id], 'benefit_accounts', $importAccount->id, ['ok', 'ok']);
    assertEqual('10.0000', $read->accountBalance($importAccount->id), 'competing import balance');
    assertEqual(1, $importAccount->transactions()->count(), 'competing import transaction count');
    assertEqual(1, ImportRecord::query()->whereIn('import_batch_id', [$firstImport->id, $secondImport->id])->where('import_status', 'skipped_duplicate')->count(), 'competing import duplicate count');

    $sameBatch = importBatch($importAccount, 'same-batch-'.$round);
    race('import', [$sameBatch->id, $sameBatch->id], 'import_records', $sameBatch->records()->firstOrFail()->id, ['ok', 'ok']);
    assertEqual('20.0000', $read->accountBalance($importAccount->id), 'same batch balance');
    assertEqual('imported', $sameBatch->records()->firstOrFail()->import_status, 'same batch committed status');
    assertEqual(1, $sameBatch->fresh()->imported_rows, 'same batch committed count');

    $plan = ['program_id' => $importAccount->program_id, 'member_id' => null, 'label' => 'Planned import'];
    $plannedFirst = importBatch($importAccount, 'planned-'.$round, $plan);
    $plannedSecond = importBatch($importAccount, 'planned-'.$round, $plan);
    race('import', [$plannedFirst->id, $plannedSecond->id], 'benefit_programs', $importAccount->program_id, ['ok', 'ok']);
    $plannedAccounts = BenefitAccount::query()->where('program_id', $importAccount->program_id)->where('account_label', $plan['label'])->get();
    assertEqual(1, $plannedAccounts->count(), 'planned import account count');
    assertEqual('10.0000', $read->accountBalance($plannedAccounts->first()->id), 'planned import balance');

    $mappedFrom = account("Mapped source {$round}");
    $mappedTo = account("Mapped destination {$round}");
    $mappedFirst = mappedBatch($mappedFrom, $mappedTo, 'mapped-transfer-'.$round);
    $mappedSecond = mappedBatch($mappedFrom, $mappedTo, 'mapped-transfer-'.$round);
    race('import', [$mappedFirst->id, $mappedSecond->id], 'benefit_accounts', $mappedFrom->id, ['ok', 'ok']);
    assertEqual(1, BenefitTransferStep::query()->where('from_account_id', $mappedFrom->id)->count(), 'competing mapped step count');
    assertEqual(1, ImportRecord::query()->whereIn('import_batch_id', [$mappedFirst->id, $mappedSecond->id])->where('import_status', 'skipped_duplicate')->count(), 'mapped duplicate count');
    $mappedSame = mappedBatch($mappedFrom, $mappedTo, 'mapped-same-batch-'.$round);
    race('import', [$mappedSame->id, $mappedSame->id], 'import_records', $mappedSame->records()->firstOrFail()->id, ['ok', 'ok']);
    assertEqual('imported', $mappedSame->records()->firstOrFail()->import_status, 'mapped same batch status');
    assertEqual(2, BenefitTransferStep::query()->where('from_account_id', $mappedFrom->id)->count(), 'mapped same batch count');

    $transactions->earn($mappedFrom, '10', '2026-10-01');
    $historicalOut = $transactions->use($mappedFrom, '7', '2026-10-01');
    $historyOptions = ['status' => 'processing', 'started_at' => '2026-10-01', 'out_transaction_id' => $historicalOut->id];
    $historyFirst = mappedBatch($mappedFrom, $mappedTo, 'history-first-'.$round, 'transfer', $historyOptions);
    $historySecond = mappedBatch($mappedFrom, $mappedTo, 'history-second-'.$round, 'transfer', $historyOptions);
    race('import', [$historyFirst->id, $historySecond->id], 'benefit_accounts', $mappedFrom->id, ['ok', 'ok']);
    assertEqual(1, BenefitTransferStep::query()->where('from_account_id', $mappedFrom->id)->where('status', 'processing')->count(), 'exclusive history link');
    assertEqual(1, ImportRecord::query()->whereIn('import_batch_id', [$historyFirst->id, $historySecond->id])->where('import_status', 'error')->count(), 'competing history error');
    assertEqual('3.0000', $read->accountBalance($mappedFrom->id), 'historical link balance');
    assertEqual(2, $mappedFrom->transactions()->count(), 'historical link transaction count');

    $ruleFirst = mappedBatch($mappedFrom, $mappedTo, 'mapped-rule-'.$round, 'conversion_rule');
    $ruleSecond = mappedBatch($mappedFrom, $mappedTo, 'mapped-rule-'.$round, 'conversion_rule');
    race('import', [$ruleFirst->id, $ruleSecond->id], 'benefit_programs', $mappedFrom->program_id, ['ok', 'ok']);
    assertEqual(1, ConversionRule::query()->where('from_program_id', $mappedFrom->program_id)->count(), 'competing rule count');
    $rule = ConversionRule::query()->where('from_program_id', $mappedFrom->program_id)->firstOrFail();
    $versionFirst = mappedBatch($mappedFrom, $mappedTo, 'version-first-'.$round, 'conversion_rule', ['rule_group_id' => $rule->rule_group_id]);
    $versionSecond = mappedBatch($mappedFrom, $mappedTo, 'version-second-'.$round, 'conversion_rule', ['rule_group_id' => $rule->rule_group_id]);
    race('import', [$versionFirst->id, $versionSecond->id], 'conversion_rule_groups', $rule->rule_group_id, ['ok', 'ok']);
    assertEqual([1, 2, 3], ConversionRule::query()->where('rule_group_id', $rule->rule_group_id)->orderBy('version_no')->pluck('version_no')->all(), 'concurrent rule versions');
    $voucher = account("Voucher same row {$round}");
    $voucherFirst = voucherBatch($voucher, 'voucher-'.$round);
    $voucherSecond = voucherBatch($voucher, 'voucher-'.$round);
    race('import', [$voucherFirst->id, $voucherSecond->id], 'benefit_accounts', $voucher->id, ['ok', 'ok']);
    assertEqual(1, $voucher->lots()->count(), 'voucher competing lot count');
    assertEqual(1, $voucher->transactions()->count(), 'voucher competing transaction count');
    assertEqual('7.0000', $read->accountBalance($voucher->id), 'voucher competing balance');
    assertEqual(1, ImportRecord::query()->whereIn('import_batch_id', [$voucherFirst->id, $voucherSecond->id])->where('import_status', 'skipped_duplicate')->count(), 'voucher duplicate count');

    $voucherSameAccount = account("Voucher same batch {$round}");
    $voucherSame = voucherBatch($voucherSameAccount, 'voucher-same-'.$round);
    race('import', [$voucherSame->id, $voucherSame->id], 'import_records', $voucherSame->records()->firstOrFail()->id, ['ok', 'ok']);
    assertEqual(1, $voucherSameAccount->lots()->count(), 'voucher same batch lot count');
    assertEqual('imported', $voucherSame->records()->firstOrFail()->import_status, 'voucher same batch status');

    $voucherConflict = account("Voucher competing snapshot {$round}");
    $snapshotFirst = voucherBatch($voucherConflict, 'snapshot-first-'.$round);
    $snapshotSecond = voucherBatch($voucherConflict, 'snapshot-second-'.$round);
    race('import', [$snapshotFirst->id, $snapshotSecond->id], 'benefit_accounts', $voucherConflict->id, ['ok', 'ok']);
    assertEqual(1, $voucherConflict->lots()->count(), 'competing snapshot leaves no orphan lot');
    assertEqual(1, ImportRecord::query()->whereIn('import_batch_id', [$snapshotFirst->id, $snapshotSecond->id])->where('import_status', 'error')->count(), 'competing snapshot error count');
    assertEqual(1, DB::table('imported_fingerprints')->whereIn('import_record_id', [$snapshotFirst->records()->first()->id, $snapshotSecond->records()->first()->id])->count(), 'competing snapshot key count');

    $voucherPlanAccount = account("Voucher planned {$round}");
    $voucherPlan = ['create_account' => 1, 'new_program_id' => $voucherPlanAccount->program_id, 'new_label' => 'Planned voucher'];
    $voucherPlanFirst = voucherBatch($voucherPlanAccount, 'voucher-plan-'.$round, $voucherPlan);
    $voucherPlanSecond = voucherBatch($voucherPlanAccount, 'voucher-plan-'.$round, $voucherPlan);
    race('import', [$voucherPlanFirst->id, $voucherPlanSecond->id], 'benefit_programs', $voucherPlanAccount->program_id, ['ok', 'ok']);
    $plannedVoucherAccounts = BenefitAccount::query()->where('program_id', $voucherPlanAccount->program_id)->where('account_label', 'Planned voucher')->get();
    assertEqual(1, $plannedVoucherAccounts->count(), 'voucher planned account count');
    assertEqual(1, $plannedVoucherAccounts->first()->lots()->count(), 'voucher planned lot count');
    assertEqual('7.0000', $read->accountBalance($plannedVoucherAccounts->first()->id), 'voucher planned balance');

    echo "Round {$round}: all MySQL concurrency invariants passed.\n";
}
