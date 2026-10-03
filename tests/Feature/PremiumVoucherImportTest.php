<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Models\BenefitProgram;
use App\Models\BenefitTransaction;
use App\Models\HouseholdMember;
use App\Models\ImportBatch;
use App\Models\ImportRecord;
use App\Models\User;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PremiumVoucherImportTest extends TestCase
{
    use RefreshDatabase;

    private BenefitAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 3));
        $this->actingAs(User::factory()->create());
        $program = BenefitProgram::query()->forceCreate(['name' => '合成商品券', 'category' => 'voucher', 'unit_name' => '枚', 'active' => true]);
        $member = HouseholdMember::query()->forceCreate(['display_name' => '合成名義A', 'active' => true]);
        $this->account = BenefitAccount::query()->forceCreate(['program_id' => $program->id, 'household_member_id' => $member->id, 'active' => true]);
    }

    private function upload(string $name = 'vouchers.xlsx'): ImportBatch
    {
        $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent($name,
            file_get_contents(base_path('tests/Fixtures/anonymous-premium-vouchers.xlsx')))])->assertRedirect()->assertSessionHasNoErrors();

        return ImportBatch::query()->latest('id')->firstOrFail();
    }

    private function configure(ImportBatch $batch, array $options = []): void
    {
        $this->post(route('imports.configure', $batch), ['mappings' => ['プレ商品券' => array_replace([
            'action' => 'import', 'account_id' => $this->account->id, 'confirm_native' => 1, 'confirm_snapshot' => 1,
        ], $options)]])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function row(ImportBatch $batch, int $number): ImportRecord
    {
        return $batch->records()->where('source_row_number', $number)->firstOrFail();
    }

    private function override(ImportRecord $record, array $options): void
    {
        $this->post(route('imports.records.override', [$record->batch, $record]), ['action' => 'import', 'row' => $options])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_preview_and_commit_preserve_native_units_expiry_and_remaining_snapshot(): void
    {
        $batch = $this->upload();
        $this->assertSame(7, $batch->total_rows);
        $this->configure($batch, ['native_unit' => '', 'snapshot_at' => '']);
        $this->assertDatabaseCount('benefit_lots', 0);
        $this->assertDatabaseCount('benefit_transactions', 0);
        $this->assertSame('2.0000', $this->row($batch, 2)->normalized_data_json['remaining_quantity']);
        $this->assertSame(2000, $this->row($batch, 2)->normalized_data_json['remaining_yen']);
        $this->assertSame('0.5000', $this->row($batch, 3)->normalized_data_json['remaining_quantity']);
        $this->assertSame('ready', $this->row($batch, 4)->import_status);
        $this->get(route('imports.show', $batch))->assertOk()->assertSee('合成名義A')->assertSee('残額の初期移行')
            ->assertSee('0.5000')->assertSee('300')->assertSee('2027-01-31');
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertDatabaseCount('benefit_lots', 3);
        $this->assertDatabaseCount('benefit_transactions', 2);
        $read = app(BenefitReadService::class);
        $this->assertSame('2.5000', $read->accountBalance($this->account->id));
        $this->assertSame('0.0000', $read->unallocatedBalance($this->account->id));
        foreach ([2 => ['2.0000', 2000, '2026-12-31'], 3 => ['0.5000', 300, '2027-01-31'], 4 => ['0.0000', 0, '2026-09-30']] as $number => [$quantity, $yen, $expiry]) {
            $record = $this->row($batch, $number);
            $lot = BenefitLot::query()->findOrFail($record->target_id);
            $this->assertSame('benefit_lots', $record->target_table);
            $this->assertSame('imported', $record->import_status);
            $this->assertSame($expiry, $lot->expires_at);
            $this->assertEquals($yen, $lot->face_value_yen);
            $this->assertEquals($yen, $lot->estimated_use_value_yen);
            $this->assertSame($quantity, $read->lotRemainingQuantity($lot->id));
            $this->assertSame('excel_voucher_snapshot:'.$batch->id, $lot->source);
            $this->get(route('ledger.lots.show', $lot))->assertOk();
        }
        $transaction = BenefitTransaction::query()->where('import_record_id', $this->row($batch, 3)->id)->firstOrFail();
        $this->assertSame('earn', $transaction->transaction_type);
        $this->assertSame('2026-10-01', $transaction->transaction_at);
        $this->assertSame('excel_voucher_snapshot', $transaction->source_type);
        $this->assertNull($transaction->value_yen); // Inventory is not a newly earned yen value.
        $this->get(route('imports.show', $batch))->assertOk()->assertSee(route('ledger.lots.show', $this->row($batch, 3)->target_id), false)
            ->assertSee(route('ledger.transactions.show', $transaction), false);
        $this->get(route('ledger.transactions.show', $transaction))->assertOk();
        $this->assertSame(3, $batch->fresh()->imported_rows);
    }

    public function test_unknown_dates_units_quantity_and_state_are_held_without_inference(): void
    {
        $batch = $this->upload();
        $this->configure($batch, ['confirm_native' => 0]);
        $this->assertSame('native_quantity_unconfirmed', $this->row($batch, 2)->warning_code);
        $this->configure($batch, ['confirm_snapshot' => 0]);
        $this->assertSame('snapshot_unconfirmed', $this->row($batch, 2)->warning_code);
        $this->configure($batch);
        foreach ([5 => 'native_quantity_missing', 6 => 'voucher_dates_unconfirmed', 7 => 'voucher_state_unconfirmed', 8 => 'native_quantity_unconfirmed'] as $number => $code) {
            $this->assertSame('needs_review', $this->row($batch, $number)->import_status);
            $this->assertSame($code, $this->row($batch, $number)->warning_code);
        }
        $record = $this->row($batch, 5);
        $this->override($record, ['remaining_quantity' => '0.25']);
        $this->assertSame('ready', $record->fresh()->import_status);
        $this->override($record, ['remaining_yen' => '12.5']);
        $this->assertSame('invalid_remaining_yen', $record->fresh()->warning_code);
        $this->override($record, ['remaining_yen' => 500, 'voucher_state' => 'used']);
        $this->assertSame('voucher_balance_mismatch', $record->fresh()->warning_code);
        $this->override($record, ['voucher_state' => 'unused', 'expires_at' => '2026-09-01']);
        $this->assertSame('voucher_dates_unconfirmed', $record->fresh()->warning_code);
        $this->override($record, ['expires_at' => '2027-02-28', 'snapshot_at' => '2026-10-04']);
        $this->assertSame('voucher_dates_unconfirmed', $record->fresh()->warning_code);
        $this->assertDatabaseCount('benefit_lots', 0);
    }

    public function test_yen_accounts_require_explicit_native_quantity_and_exact_remaining_amount(): void
    {
        $this->account->program->forceFill(['unit_name' => '円'])->save();
        $batch = $this->upload();
        $this->configure($batch, ['native_unit' => '円']);
        $this->assertSame('voucher_balance_mismatch', $this->row($batch, 2)->warning_code);
        $record = $this->row($batch, 2);
        $this->override($record, ['remaining_quantity' => 2000]);
        $this->assertSame('ready', $record->fresh()->import_status);
        $this->override($record, ['native_unit' => '']);
        $this->assertSame('native_quantity_unconfirmed', $record->fresh()->warning_code);
        $this->override($record, ['native_unit' => '円']);
        $this->assertSame('ready', $record->fresh()->import_status);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame('2000.0000', app(BenefitReadService::class)->accountBalance($this->account->id));
    }

    public function test_reimport_and_account_remapping_keep_duplicates_scoped_and_skips_persistent(): void
    {
        $batch = $this->upload();
        $this->configure($batch);
        $skipped = $this->row($batch, 3);
        $this->post(route('imports.records.override', [$batch, $skipped]), ['action' => 'skip'])->assertRedirect();
        $other = BenefitAccount::query()->forceCreate(['program_id' => $this->account->program_id, 'active' => true]);
        $overridden = $this->row($batch, 2);
        $this->override($overridden, ['account_id' => $other->id]);
        $this->configure($batch);
        $this->assertSame('skipped_out_of_scope', $skipped->fresh()->import_status);
        $this->assertSame($other->id, $overridden->fresh()->normalized_data_json['account_id']);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $again = $this->upload('renamed.xlsx');
        $this->configure($again, ['account_id' => $other->id]);
        $this->assertSame('skipped_duplicate', $this->row($again, 2)->import_status);
        $this->assertSame('snapshot_account_has_history', $this->row($again, 3)->warning_code);
        $this->assertDatabaseCount('benefit_lots', 2);
        $this->assertDatabaseCount('benefit_transactions', 1);
        $third = BenefitAccount::query()->forceCreate(['program_id' => $this->account->program_id, 'active' => true]);
        $this->configure($again, ['account_id' => $third->id]);
        $this->post(route('imports.execute', $again))->assertRedirect();
        $this->assertDatabaseCount('benefit_lots', 5);
        $this->assertSame('2.5000', app(BenefitReadService::class)->accountBalance($third->id));
    }

    public function test_existing_ledger_never_adds_inventory(): void
    {
        $tx = app(BenefitTransactionService::class)->createOpeningBalance($this->account, '2026-10-01', '2.5', null);
        $batch = $this->upload();
        $this->configure($batch);
        $this->assertSame('snapshot_account_has_history', $this->row($batch, 2)->warning_code);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertDatabaseCount('benefit_lots', 0);
        $this->assertDatabaseCount('benefit_transactions', 1);
        $this->assertSame('2.5000', app(BenefitReadService::class)->accountBalance($this->account->id));
        $this->assertNull($tx->fresh()->lot_id);
    }

    public function test_failed_row_rolls_back_lot_transaction_key_and_can_be_corrected_and_retried(): void
    {
        Log::spy();
        $batch = $this->upload();
        $this->configure($batch);
        $fail = true;
        BenefitTransaction::saving(function ($transaction) use (&$fail): void {
            if ($fail && $transaction->import_record_id === $this->row(ImportBatch::query()->latest('id')->first(), 3)->id) {
                throw new \RuntimeException('synthetic-secret-21');
            }
        });
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $failed = $this->row($batch, 3);
        $this->assertSame('error', $failed->import_status);
        $this->assertSame('commit_failed', $failed->warning_code);
        $this->assertNull($failed->target_id);
        $this->assertStringNotContainsString('synthetic-secret-21', json_encode($failed));
        Log::shouldNotHaveReceived('error');
        $this->assertDatabaseCount('benefit_lots', 2);
        $this->assertDatabaseCount('benefit_transactions', 1);
        $this->assertSame(0, DB::table('imported_fingerprints')->where('import_record_id', $failed->id)->count());
        $fail = false;
        $this->override($failed, ['remaining_quantity' => '0.75']);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame('imported', $failed->fresh()->import_status);
        $this->assertDatabaseCount('benefit_lots', 3);
        $this->assertDatabaseCount('benefit_transactions', 2);
        $this->assertSame('2.7500', app(BenefitReadService::class)->accountBalance($this->account->id));
    }

    public function test_new_account_is_only_created_at_commit_and_existing_plan_is_deduplicated(): void
    {
        $batch = $this->upload();
        $options = ['create_account' => 1, 'new_program_id' => $this->account->program_id,
            'new_member_id' => $this->account->household_member_id, 'new_label' => '合成移行口座'];
        $this->configure($batch, $options);
        $this->assertDatabaseCount('benefit_accounts', 1);
        $this->assertNull($this->row($batch, 2)->normalized_data_json['account_id']);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertDatabaseCount('benefit_accounts', 2);
        $account = BenefitAccount::query()->where('account_label', '合成移行口座')->firstOrFail();
        $this->assertSame('2.5000', app(BenefitReadService::class)->accountBalance($account->id));
        $again = $this->upload('renamed.xlsx');
        $this->configure($again, $options);
        foreach ([2, 3, 4] as $number) {
            $this->assertSame('skipped_duplicate', $this->row($again, $number)->import_status);
        }
        $this->configure($again, ['account_id' => $account->id]);
        $this->assertSame('skipped_duplicate', $this->row($again, 2)->import_status);
        $this->assertDatabaseCount('benefit_accounts', 2);
    }

    public function test_commit_rechecks_ledger_and_rolls_back_planned_account_on_failure(): void
    {
        $batch = $this->upload();
        $this->configure($batch);
        app(BenefitTransactionService::class)->earn($this->account, '1', '2026-10-02');
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame('error', $this->row($batch, 2)->import_status);
        $this->assertDatabaseCount('benefit_lots', 0);
        $this->assertDatabaseCount('imported_fingerprints', 0);
        $planned = $this->upload();
        $this->configure($planned, ['create_account' => 1, 'new_program_id' => $this->account->program_id,
            'new_label' => '失敗時に作られない口座']);
        $this->account->program->forceFill(['active' => false])->save();
        $this->post(route('imports.execute', $planned))->assertRedirect();
        $this->assertSame('error', $this->row($planned, 2)->import_status);
        $this->assertDatabaseCount('benefit_accounts', 1);
        $this->assertDatabaseCount('benefit_lots', 0);
        $this->assertDatabaseCount('imported_fingerprints', 0);
        $this->account->program->forceFill(['active' => true])->save();
        $this->configure($planned, ['create_account' => 1, 'new_program_id' => $this->account->program_id,
            'new_label' => '失敗時に作られない口座']);
        $fail = true;
        BenefitLot::created(function () use (&$fail): void {
            if ($fail) {
                throw new \RuntimeException('Synthetic failure after account and lot creation');
            }
        });
        $this->post(route('imports.execute', $planned))->assertRedirect();
        $this->assertSame('error', $this->row($planned, 2)->import_status);
        $this->assertDatabaseCount('benefit_accounts', 1);
        $this->assertDatabaseCount('benefit_lots', 0);
        $this->assertDatabaseCount('imported_fingerprints', 0);
        $fail = false;
        $this->configure($planned, ['create_account' => 1, 'new_program_id' => $this->account->program_id,
            'new_label' => '失敗時に作られない口座']);
        $this->post(route('imports.execute', $planned))->assertRedirect();
        $this->assertSame('imported', $this->row($planned, 2)->import_status);
        $this->assertDatabaseCount('benefit_accounts', 2);
        $this->assertDatabaseCount('benefit_lots', 3);
    }

    public function test_secret_columns_are_excluded_before_raw_normalized_business_or_log_storage(): void
    {
        Log::spy();
        $batch = $this->upload();
        $this->configure($batch);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $stored = '';
        foreach (['import_batches', 'import_records', 'imported_fingerprints', 'benefit_lots', 'benefit_transactions', 'benefit_accounts'] as $table) {
            $stored .= json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE);
        }
        foreach (['voucher@example.test', '000-0000-0000', 'synthetic-login-21', 'synthetic-pin-21', 'synthetic-code-21', 'synthetic-qr-21', 'synthetic-secret-21', 'synthetic-notes-secret-21'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
        $this->assertEqualsCanonicalizing(['program', 'acquired_at', 'expires_at', 'snapshot_at', 'remaining_quantity', 'native_unit', 'remaining_yen', 'voucher_state'], array_keys($this->row($batch, 2)->raw_data_json));
        foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }
}
