<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Models\BenefitProgram;
use App\Models\BenefitProgramAlias;
use App\Models\BenefitTransaction;
use App\Models\ImportBatch;
use App\Models\Notification;
use App\Models\User;
use App\Services\BenefitDashboardService;
use App\Services\BenefitListingService;
use App\Services\BenefitLotService;
use App\Services\BenefitNotificationService;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use App\Services\BenefitTransferService;
use App\Services\Imports\BenefitProgramResolver;
use App\Services\Imports\ExcelImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class ReleaseGateBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 00:30', 'Asia/Tokyo'));
        $this->actingAs(User::factory()->create());
    }

    private function account(string $name = '合成境界'): BenefitAccount
    {
        $program = BenefitProgram::query()->forceCreate(['name' => $name, 'category' => 'point', 'unit_name' => 'pt', 'active' => true]);

        return BenefitAccount::query()->forceCreate(['program_id' => $program->id, 'active' => true]);
    }

    private function lot(BenefitAccount $account, string $quantity = '10', ?string $expiry = null, string $policy = 'sell_now'): BenefitLot
    {
        return app(BenefitLotService::class)->acquire($account, ['quantity' => $quantity, 'expires_at' => $expiry,
            'display_name' => '合成ロット', 'action_policy' => $policy]);
    }

    private function reject(callable $action, string $field): ValidationException
    {
        try {
            $action();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());

            return $error;
        }
    }

    public function test_negative_quantity_and_forged_direction_cannot_create_transactions(): void
    {
        $account = $this->account();
        $data = ['account_id' => $account->id, 'transaction_type' => 'earn', 'transaction_at' => '2026-09-30', 'quantity' => '-500'];
        $this->post(route('ledger.transactions.store'), $data)->assertSessionHasErrors('quantity');
        $this->post(route('ledger.lots.store'), ['account_id' => $account->id, 'quantity' => '-500', 'action_policy' => 'undecided'])
            ->assertSessionHasErrors('quantity');
        $this->reject(fn () => app(BenefitTransactionService::class)->earn($account, '-500', '2026-09-30'), 'quantity');
        $data['quantity'] = '500';
        $this->post(route('ledger.transactions.store'), $data + ['direction' => 'out'])->assertSessionHasErrors('direction');
        // Service has no direction parameter: a named forged argument must be rejected before any insert.
        try {
            app(BenefitTransactionService::class)->record($account, 'earn', '500', '2026-09-30', direction: 'out');
            $this->fail('Service must not accept direction.');
        } catch (\Error $error) {
            $this->assertStringContainsString('direction', $error->getMessage());
        }
        $this->assertDatabaseCount('benefit_transactions', 0);
        $this->assertDatabaseCount('benefit_lots', 0);
        $earned = app(BenefitTransactionService::class)->earn($account, '500', '2026-09-30');
        $this->assertSame('in', $earned->direction);
        $this->assertSame('500.0000', app(BenefitReadService::class)->accountBalance($account->id));
    }

    public function test_reversal_of_reversal_rejects_and_guides_to_adjustment(): void
    {
        $account = $this->account();
        $service = app(BenefitTransactionService::class);
        $original = $service->earn($account, '500', '2026-09-30');
        $reversal = $service->reverse($original);
        $error = $this->reject(fn () => $service->reverse($reversal), 'transaction');
        $this->assertStringContainsString('調整', $error->errors()['transaction'][0]);
        $this->post(route('ledger.transactions.reverse', $reversal))->assertSessionHasErrors('transaction');
        $this->assertDatabaseCount('benefit_transactions', 2);
        $this->assertSame('0.0000', app(BenefitReadService::class)->accountBalance($account->id));
    }

    public function test_acquisition_insert_failure_rolls_back_the_inserted_lot(): void
    {
        $account = $this->account();
        $dispatcher = BenefitTransaction::getEventDispatcher();
        BenefitTransaction::setEventDispatcher(clone $dispatcher);
        $sawLot = false;
        try {
            BenefitTransaction::creating(function () use (&$sawLot): void {
                $sawLot = BenefitLot::query()->count() === 1;
                throw new \RuntimeException('synthetic insert failure');
            });
            try {
                $this->lot($account);
                $this->fail('Expected synthetic transaction insert failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame('synthetic insert failure', $error->getMessage());
            }
        } finally {
            BenefitTransaction::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($sawLot);
        $this->assertDatabaseCount('benefit_lots', 0);
        $this->assertDatabaseCount('benefit_transactions', 0);
    }

    public function test_expired_inventory_is_derived_and_account_change_is_rejected(): void
    {
        $account = $this->account();
        $other = $this->account('変更先');
        $lot = $this->lot($account, '4', '2026-09-30');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:01', 'Asia/Tokyo'));
        $this->assertSame(1, app(BenefitNotificationService::class)->generate());
        $this->assertSame('4.0000', app(BenefitReadService::class)->lotRemainingQuantity($lot->id));
        $this->assertDatabaseCount('benefit_transactions', 1);
        $this->get(route('dashboard.index', ['expiry' => 'expired']))->assertOk()->assertSee('合成ロット')->assertSee('失効処理');
        $this->put(route('ledger.lots.update', $lot), ['account_id' => $other->id, 'action_policy' => 'sell_now'])
            ->assertSessionHasErrors('account_id');
        $this->reject(fn () => app(BenefitLotService::class)->update($lot, ['account_id' => $other->id]), 'account_id');
        $this->assertSame($account->id, $lot->fresh()->account_id);
        $this->assertSame('4.0000', app(BenefitReadService::class)->accountBalance($account->id));
        $this->assertSame('0.0000', app(BenefitReadService::class)->accountBalance($other->id));
    }

    public function test_using_listed_inventory_keeps_reservation_and_rejects_overuse(): void
    {
        $account = $this->account();
        $lot = $this->lot($account);
        $listings = app(BenefitListingService::class);
        $listing = $listings->createDraft(['marketplace' => '合成'], [$lot->id => '4']);
        $listings->publish($listing);
        $this->post(route('ledger.lots.use', $lot), ['quantity' => '6', 'transaction_at' => '2026-09-30'])->assertSessionHasNoErrors();
        $read = app(BenefitReadService::class);
        $this->assertSame('4.0000', $read->lotRemainingQuantity($lot->id));
        $this->assertSame('4.0000', $read->lotListedQuantity($lot->id));
        $this->assertSame('0.0000', $read->lotAvailableQuantity($lot->id));
        $this->post(route('ledger.lots.use', $lot), ['quantity' => '1', 'transaction_at' => '2026-09-30'])->assertSessionHasErrors('quantity');
        $this->assertSame('4.0000', $read->lotRemainingQuantity($lot->id));
        $this->assertSame('listed', $listing->fresh()->status);
        $this->assertSame(1, $lot->transactions()->where('transaction_type', 'use')->count());
    }

    public function test_second_sale_insert_failure_rolls_back_both_lots_and_listing(): void
    {
        $account = $this->account();
        $first = $this->lot($account);
        $second = $this->lot($account);
        $service = app(BenefitListingService::class);
        $listing = $service->createDraft(['marketplace' => '合成'], [$first->id => '2', $second->id => '3']);
        $service->publish($listing);
        $dispatcher = BenefitTransaction::getEventDispatcher();
        BenefitTransaction::setEventDispatcher(clone $dispatcher);
        $inserts = 0;
        try {
            BenefitTransaction::creating(function ($transaction) use (&$inserts): void {
                if ($transaction->transaction_type === 'sell' && ++$inserts === 2) {
                    throw new \RuntimeException('synthetic second sale failure');
                }
            });
            try {
                $service->sell($listing, 100, 80, 50, '2026-09-30');
                $this->fail('Expected second insert failure.');
            } catch (\RuntimeException $error) {
                $this->assertSame('synthetic second sale failure', $error->getMessage());
            }
        } finally {
            BenefitTransaction::setEventDispatcher($dispatcher);
        }
        $this->assertSame(2, $inserts);
        $this->assertSame('listed', $listing->fresh()->status);
        $this->assertNull($listing->fresh()->sold_at);
        $this->assertNull($listing->fresh()->net_proceeds_yen);
        $this->assertSame(0, $listing->transactions()->count());
        $read = app(BenefitReadService::class);
        foreach ([$first, $second] as $lot) {
            $this->assertSame('10.0000', $read->lotRemainingQuantity($lot->id));
        }
        $this->assertSame('2.0000', $read->lotListedQuantity($first->id));
        $this->assertSame('3.0000', $read->lotListedQuantity($second->id));
        $service->sell($listing, 100, 80, 50, '2026-09-30');
        $this->get(route('listings.show', $listing))->assertOk()->assertSee('-30')->assertSee('手数料と送料が売却価格を上回っています。');
        $this->assertSame(-30, (int) $listing->fresh()->net_proceeds_yen);
    }

    public function test_insufficient_transfer_rolls_back_and_planning_equivalent_never_becomes_native(): void
    {
        $source = $this->account('V');
        $destination = $this->account('JQ');
        $ana = $this->account('ANA');
        $transactions = app(BenefitTransactionService::class);
        $transactions->createOpeningBalance($source, '2026-09-30', '5000', null);
        $service = app(BenefitTransferService::class);
        $group = $service->createGroup([]);
        $step = $service->addStep($group, ['from_account_id' => $source->id, 'to_account_id' => $destination->id,
            'source_quantity' => '10000', 'expected_destination_quantity' => '10000',
            'planning_equivalent_program_id' => $ana->program_id, 'planning_equivalent_quantity' => '7000']);
        $this->post(route('transfers.steps.start', $step), ['started_at' => '2026-09-30'])->assertSessionHasErrors('source_quantity');
        $this->assertSame('planned', $step->fresh()->status);
        $this->assertSame(0, $step->transactions()->count());
        $read = app(BenefitReadService::class);
        $this->assertSame('5000.0000', $read->accountBalance($source->id));
        $transactions->earn($source, '5000', '2026-09-30');
        $service->startStep($step, '2026-09-30');
        $service->completeStep($step, '10000', '2026-09-30');
        $this->assertEquals(10000, $step->fresh()->source_quantity);
        $this->assertEquals(10000, $step->fresh()->expected_destination_quantity);
        $this->assertEquals(7000, $step->fresh()->planning_equivalent_quantity);
        $this->assertSame('10000.0000', $read->accountBalance($destination->id));
        $this->assertSame('0.0000', $read->accountBalance($ana->id));
    }

    public function test_jst_today_and_dashboard_exact_boundaries_keep_hold_expiry_visible(): void
    {
        $account = $this->account();
        $lots = [];
        foreach ([0, 7, 8, 30, 31] as $days) {
            $lots[$days] = $this->lot($account, '1', now('Asia/Tokyo')->addDays($days)->toDateString(), 'hold');
        }
        $dashboard = app(BenefitDashboardService::class);
        $collection = $dashboard->lots();
        $this->assertSame(0, $dashboard->daysUntilExpiry($lots[0]));
        $this->assertSame([$lots[0]->id, $lots[7]->id], $dashboard->expiringWithin($collection, 7)->pluck('id')->sort()->values()->all());
        $this->assertSame([$lots[0]->id, $lots[7]->id, $lots[8]->id, $lots[30]->id], $dashboard->expiringWithin($collection, 30)->pluck('id')->sort()->values()->all());
        $this->assertSame(2, $dashboard->summary($collection)['within7']['count']);
        $this->assertSame(4, $dashboard->summary($collection)['within30']['count']);
        $this->get(route('dashboard.index', ['expiry' => '7', 'policy' => 'hold']))->assertOk()->assertSee('合成ロット');
        $service = app(BenefitNotificationService::class);
        $this->assertSame(4, $service->generate());
        $today = Notification::query()->where('subject_id', $lots[0]->id)->firstOrFail();
        $this->assertStringContainsString('本日期限', $today->title);
        $this->assertSame('expire_today', $today->milestone_key);
        $this->assertSame('2026-09-30', $today->milestone_date);
    }

    public function test_notification_text_deduplicates_listed_unlisted_and_never_copies_private_attributes(): void
    {
        $account = $this->account();
        $account->account_label = 'SYNTHETIC-ACCOUNT-ID';
        $account->save();
        $account->program->notes = 'SYNTHETIC-PROGRAM-NOTES';
        $account->program->save();
        $unlisted = $this->lot($account, '10', '2026-10-07');
        $listed = $this->lot($account, '10', '2026-10-03');
        foreach ([$unlisted, $listed] as $lot) {
            $lot->memo = 'SYNTHETIC-PIN-QR-MEMO';
            $lot->usage_conditions = 'SYNTHETIC-EMAIL-PHONE-CODE';
            $lot->save();
        }
        $listings = app(BenefitListingService::class);
        $listing = $listings->createDraft(['marketplace' => '合成', 'memo' => 'SYNTHETIC-LISTING-MEMO'], [$listed->id => '4']);
        $listings->publish($listing);
        $service = app(BenefitNotificationService::class);
        $this->assertSame(2, $service->generate());
        $this->assertSame(0, $service->generate());
        $this->assertSame(0, $service->generate());
        $this->assertSame(2, Notification::query()->count());
        $first = Notification::query()->where('subject_id', $unlisted->id)->firstOrFail();
        $second = Notification::query()->where('subject_id', $listed->id)->firstOrFail();
        $this->assertSame('sell_now_unlisted', $first->type);
        $this->assertStringContainsString('未出品', $first->body);
        $this->assertSame('listing_expiry', $second->type);
        $this->assertStringContainsString('出品価格を見直し', $second->body);
        $this->assertSame('expire_3d', $second->milestone_key);
        $source = $this->account('移行元');
        $target = $this->account('移行先');
        app(BenefitTransactionService::class)->earn($source, '10', '2026-09-30');
        $transfers = app(BenefitTransferService::class);
        $group = $transfers->createGroup(['memo' => 'SYNTHETIC-GROUP-MEMO']);
        $step = $transfers->addStep($group, ['from_account_id' => $source->id, 'to_account_id' => $target->id,
            'source_quantity' => '10', 'expected_complete_at' => '2026-09-29',
            'external_reference_hint' => 'SYNTHETIC-EXTERNAL-ID', 'memo' => 'SYNTHETIC-STEP-MEMO']);
        $transfers->startStep($step, '2026-09-28');
        $this->assertSame(1, $service->generate());
        $json = Notification::query()->get(['title', 'body', 'action_url'])->toJson();
        foreach (['SYNTHETIC-ACCOUNT', 'SYNTHETIC-PIN', 'SYNTHETIC-EMAIL', 'SYNTHETIC-LISTING', 'SYNTHETIC-GROUP', 'SYNTHETIC-EXTERNAL', 'SYNTHETIC-STEP', 'SYNTHETIC-PROGRAM'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_same_alias_resolves_by_scope_and_injection_search_keeps_database_intact(): void
    {
        $first = $this->account('合成Alpha');
        $second = $this->account('合成Beta');
        foreach ([[$first, '積立A'], [$second, '積立B']] as [$account, $scope]) {
            BenefitProgramAlias::query()->forceCreate(['program_id' => $account->program_id, 'alias' => '合成共通略称', 'source_scope' => $scope]);
            app(BenefitTransactionService::class)->earn($account, '10', '2026-09-30');
        }
        $resolver = app(BenefitProgramResolver::class);
        $this->assertSame($first->program_id, $resolver->resolve('合成共通略称', '積立A'));
        $this->assertSame($second->program_id, $resolver->resolve('合成共通略称', '積立B'));
        $this->assertNull($resolver->resolve('合成共通略称'));
        $this->assertNull($resolver->resolve('合成共通略称', '別scope'));
        foreach (["' OR 1=1 --", "'; DROP TABLE benefit_transactions; --"] as $input) {
            $this->get(route('search.index', ['q' => $input]))->assertOk()->assertViewHas('results', function ($results): bool {
                foreach (['lots', 'accounts'] as $key) {
                    if ($results[$key]->total() !== 0) {
                        return false;
                    }
                }

                foreach ($results['extra'] as $items) {
                    if ($items->isNotEmpty()) {
                        return false;
                    }
                }

                return true;
            });
            $this->assertDatabaseCount('benefit_transactions', 2);
            $this->assertSame('10.0000', app(BenefitReadService::class)->accountBalance($first->id));
        }
        $this->get(route('search.index', ['q' => '合成Alpha']))->assertOk()->assertSee('合成Alpha');
    }

    public function test_import_version_and_missing_headers_never_guess_column_positions(): void
    {
        foreach ([['内容', '数量'], ['日付', '内容']] as $headings) {
            $path = tempnam(sys_get_temp_dir(), 'benefit-header-');
            try {
                $zip = new ZipArchive;
                $zip->open($path, ZipArchive::OVERWRITE);
                $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="ANA" sheetId="1" r:id="rId1"/></sheets></workbook>');
                $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
                $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
                foreach ([$headings, ['2026-09-30', '100']] as $index => $cells) {
                    $xml .= '<row r="'.($index + 1).'">';
                    foreach ($cells as $column => $value) {
                        $xml .= '<c r="'.chr(65 + $column).($index + 1).'" t="inlineStr"><is><t>'.$value.'</t></is></c>';
                    }
                    $xml .= '</row>';
                }
                $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
                $zip->close();
                $this->post(route('imports.upload'), ['file' => new UploadedFile($path, 'missing.xlsx', null, null, true)])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $batch = ImportBatch::query()->latest('id')->firstOrFail();
                $this->assertSame(ExcelImportService::VERSION, $batch->importer_version);
                $this->assertSame(1, $batch->records()->count());
                $record = $batch->records()->firstOrFail();
                $this->assertSame('unsupported_headers', $record->warning_code);
                $this->assertSame('needs_review', $record->import_status);
                $this->assertSame(0, $record->source_row_number);
                $account = $this->account();
                $this->post(route('imports.configure', $batch), ['mappings' => ['ANA' => ['action' => 'import', 'account_id' => $account->id]]])->assertRedirect();
                $this->post(route('imports.execute', $batch))->assertRedirect();
                $this->assertSame(0, $batch->fresh()->imported_rows);
                $this->assertDatabaseCount('benefit_transactions', 0);
            } finally {
                unlink($path);
            }
        }
    }
}
