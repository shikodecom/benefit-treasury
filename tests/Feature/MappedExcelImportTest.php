<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\BenefitTransaction;
use App\Models\BenefitTransferGroup;
use App\Models\BenefitTransferStep;
use App\Models\ConversionRule;
use App\Models\ImportBatch;
use App\Models\ImportRecord;
use App\Models\User;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use App\Services\BenefitTransferService;
use App\Services\ConversionRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MappedExcelImportTest extends TestCase
{
    use RefreshDatabase;

    private BenefitAccount $from;

    private BenefitAccount $ana;

    private BenefitAccount $jal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        foreach (['from' => '合成ポイント', 'ana' => 'ANA', 'jal' => 'JAL'] as $field => $name) {
            $program = BenefitProgram::query()->forceCreate(['name' => $name, 'category' => 'point', 'unit_name' => 'pt', 'active' => true]);
            $this->{$field} = BenefitAccount::query()->forceCreate(['program_id' => $program->id, 'active' => true]);
        }
    }

    public function test_three_sheets_are_row_previews_and_equivalents_never_become_native_quantities(): void
    {
        $batch = $this->upload();
        $this->assertSame(8, $batch->total_rows);
        $this->assertSame(8, $batch->records()->where('import_status', 'needs_review')->count());
        $this->assertDatabaseCount('benefit_transfer_groups', 0);
        $this->assertDatabaseCount('conversion_rules', 0);
        $this->assertStringNotContainsString('synthetic@example.test', json_encode($batch->records()->pluck('raw_data_json')));
        $this->assertStringNotContainsString('synthetic-secret', json_encode($batch->records()->pluck('raw_data_json')));
        $this->get(route('imports.show', $batch))->assertOk()->assertSee('元・先の数量')->assertSee('既存の出金取引ID');
        $this->configure($batch);
        $planned = $this->row($batch, 'ANA移行', 2);
        $this->assertSame('native_quantity_missing', $planned->fresh()->warning_code);
        $this->assertArrayNotHasKey('source_quantity', $planned->raw_data_json);
        $jal = $this->row($batch, 'JAL移行', 2);
        $this->assertSame('ready', $jal->fresh()->import_status);
        $this->assertNull($jal->fresh()->normalized_data_json['expected_destination_quantity']);
        $this->assertSame('40.0000', $jal->fresh()->normalized_data_json['planning_equivalent_quantity']);
        $this->override($planned, ['source_quantity' => '100']);
        $this->assertSame('ready', $planned->fresh()->import_status);
        $blank = $this->row($batch, 'ANA移行', 5);
        $this->override($blank, ['source_quantity' => '100']);
        $this->assertSame('status_unconfirmed', $blank->fresh()->warning_code);
        $this->post(route('imports.records.override', [$batch, $blank]), ['action' => 'skip'])->assertRedirect();
        $this->configure($batch);
        $this->assertSame('skipped_out_of_scope', $blank->fresh()->import_status);
        $this->assertSame('100.0000', $planned->fresh()->normalized_data_json['source_quantity']);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame(2, BenefitTransferStep::query()->where('status', 'planned')->count());
        $this->assertDatabaseCount('benefit_transactions', 0);
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->get(route('imports.show', $batch))->assertOk()->assertSee(route('transfers.show', $planned->fresh()->target_id));
        $this->override($planned, ['source_quantity' => '999'], 409);
    }

    public function test_completed_and_processing_transfers_link_history_without_counting_balances_twice(): void
    {
        $tx = app(BenefitTransactionService::class);
        $tx->earn($this->from, '1000', '2026-10-01');
        $out = $tx->use($this->from, '100', '2026-10-01');
        $in = $tx->earn($this->ana, '50', '2026-10-02');
        $processingOut = $tx->use($this->from, '200', '2026-10-01');
        $batch = $this->upload();
        $this->configure($batch);
        $complete = $this->row($batch, 'ANA移行', 4);
        $options = ['source_quantity' => '100', 'actual_destination_quantity' => '50', 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id];
        $this->override($complete, $options);
        $processing = $this->row($batch, 'ANA移行', 3);
        $this->override($processing, ['source_quantity' => '200', 'out_transaction_id' => $processingOut->id]);
        $beforeCount = BenefitTransaction::query()->count();
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame('imported', $complete->fresh()->import_status);
        $this->assertSame('imported', $processing->fresh()->import_status);
        $this->assertSame($beforeCount, BenefitTransaction::query()->count());
        $this->assertSame('700.0000', app(BenefitReadService::class)->unallocatedBalance($this->from->id));
        $this->assertSame('50.0000', app(BenefitReadService::class)->unallocatedBalance($this->ana->id));
        $this->assertSame('transfer_out', $out->fresh()->transaction_type);
        $this->assertSame('transfer_in', $in->fresh()->transaction_type);
        $step = BenefitTransferStep::query()->where('transfer_group_id', $complete->fresh()->target_id)->firstOrFail();
        $this->assertSame('completed', $step->status);
        $this->assertSame('completed', $step->group->status);
        $this->assertEquals(50, $step->actual_destination_quantity);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame($beforeCount, BenefitTransaction::query()->count());
        $again = $this->upload('renamed.xlsx');
        $this->configure($again);
        $againComplete = $this->row($again, 'ANA移行', 4);
        $this->override($againComplete, $options);
        $this->assertSame('skipped_duplicate', $againComplete->fresh()->import_status);
        $this->post(route('imports.execute', $again))->assertRedirect();
        $this->assertSame(1, BenefitTransferStep::query()->where('status', 'completed')->count());
        $this->assertSame($beforeCount, BenefitTransaction::query()->count());
        $service = app(BenefitTransferService::class);
        $processingStep = BenefitTransferStep::query()->where('transfer_group_id', $processing->fresh()->target_id)->firstOrFail();
        try {
            $tx->reverse($processingOut); // This model was read before import linked the history.
            $this->fail('Stale models must not bypass managed transfer correction.');
        } catch (ValidationException $exception) {
            $this->assertSame('移行詳細から訂正してください。', $exception->errors()['transaction'][0]);
        }
        $service->cancelProcessingStepWithReversal($processingStep, '合成返却');
        $this->assertSame('900.0000', app(BenefitReadService::class)->unallocatedBalance($this->from->id));
    }

    public function test_failed_commit_rolls_back_links_group_and_key_and_only_failed_row_is_retried(): void
    {
        $tx = app(BenefitTransactionService::class);
        $tx->earn($this->from, '1000', '2026-10-01');
        $out = $tx->use($this->from, '100', '2026-10-01');
        $batch = $this->upload();
        $this->configure($batch);
        $record = $this->row($batch, 'ANA移行', 3);
        $this->override($record, ['source_quantity' => '100', 'out_transaction_id' => $out->id]);
        $tx->reverse($out);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame('error', $record->fresh()->import_status);
        $this->assertNull($out->fresh()->transfer_step_id);
        $this->assertSame(1, BenefitTransferGroup::query()->count()); // JAL planned row succeeded.
        $this->assertSame(0, DB::table('imported_fingerprints')->where('import_record_id', $record->id)->count());
        $replacement = $tx->use($this->from, '100', '2026-10-01');
        $this->override($record, ['out_transaction_id' => $replacement->id]);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame('imported', $record->fresh()->import_status);
        $this->assertSame(2, BenefitTransferGroup::query()->count());
        $this->assertSame(1, DB::table('imported_fingerprints')->where('import_record_id', $record->id)->count());
        $this->assertSame(1, BenefitTransferStep::query()->where('status', 'planned')->count());
    }

    public function test_linking_previously_imported_ledger_keeps_its_provenance_and_reimport_keys(): void
    {
        app(BenefitTransactionService::class)->earn($this->from, '100', '2026-09-01');
        $fixture = file_get_contents(base_path('tests/Fixtures/anonymous-points.xlsx'));
        $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent('ledger.xlsx', $fixture)])->assertRedirect();
        $ledger = ImportBatch::query()->latest('id')->firstOrFail();
        $mapping = ['ポイント積立' => ['action' => 'import', 'account_id' => $this->ana->id, 'confirm_native' => 1],
            'ポイント利用' => ['action' => 'import', 'account_id' => $this->from->id, 'confirm_native' => 1]];
        $this->post(route('imports.configure', $ledger), ['mappings' => $mapping])->assertRedirect();
        $incomingRecord = $this->row($ledger, 'ポイント積立', 3);
        $outgoingRecord = $this->row($ledger, 'ポイント利用', 2);
        $this->post(route('imports.records.override', [$ledger, $incomingRecord]), [
            'action' => 'import', 'account_id' => $this->ana->id, 'date_override' => '2026-09-04',
        ])->assertRedirect();
        $this->post(route('imports.execute', $ledger))->assertRedirect();
        $out = BenefitTransaction::query()->findOrFail($outgoingRecord->fresh()->target_id);
        $in = BenefitTransaction::query()->findOrFail($incomingRecord->fresh()->target_id);
        $batch = $this->upload();
        $this->configure($batch);
        $completed = $this->row($batch, 'ANA移行', 4);
        $this->override($completed, ['source_quantity' => '40', 'actual_destination_quantity' => '50',
            'started_at' => '2026-09-03', 'completed_at' => '2026-09-04',
            'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id]);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame('imported', $completed->fresh()->import_status);
        $this->assertSame($outgoingRecord->id, $out->fresh()->import_record_id);
        $this->assertSame($incomingRecord->id, $in->fresh()->import_record_id);
        $this->assertSame('excel_import', $out->fresh()->source_type);
        $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent('renamed-ledger.xlsx', $fixture)])->assertRedirect();
        $again = ImportBatch::query()->latest('id')->firstOrFail();
        $this->post(route('imports.configure', $again), ['mappings' => $mapping])->assertRedirect();
        $this->assertSame(3, $again->records()->where('import_status', 'skipped_duplicate')->count());
        $this->post(route('imports.execute', $again))->assertRedirect();
        $this->assertDatabaseCount('benefit_transactions', 4);
        $this->assertSame('60.0000', app(BenefitReadService::class)->unallocatedBalance($this->from->id));
        $this->assertSame('150.0000', app(BenefitReadService::class)->unallocatedBalance($this->ana->id));
    }

    public function test_rules_create_new_versions_keep_existing_versions_and_hold_ambiguous_campaigns(): void
    {
        $rules = app(ConversionRuleService::class);
        $group = $rules->createRuleGroup(['from_program_id' => $this->from->program_id, 'to_program_id' => $this->ana->program_id]);
        $old = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '30', 'active' => true]);
        $batch = $this->upload();
        $this->configure($batch, ['ポイントマイル状況' => ['rule_group_id' => $group->id, 'activate_rule' => 1]]);
        $normal = $this->row($batch, 'ポイントマイル状況', 2);
        $rate = $this->row($batch, 'ポイントマイル状況', 3);
        $campaign = $this->row($batch, 'ポイントマイル状況', 4);
        $this->assertSame('ready', $normal->fresh()->import_status);
        $this->assertSame('native_quantity_missing', $rate->fresh()->warning_code);
        $this->assertSame('campaign_period_unresolved', $campaign->fresh()->warning_code);
        $this->override($rate, ['from_quantity' => '100', 'to_quantity' => '50']);
        $this->override($campaign, ['valid_from' => '2026-10-01', 'valid_to' => '2026-10-31']);
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame([1, 2, 3, 4], $group->rules()->orderBy('version_no')->pluck('version_no')->all());
        $this->assertTrue((bool) $old->fresh()->active);
        $this->assertEquals(30, $old->fresh()->to_quantity);
        $new = ConversionRule::query()->findOrFail($normal->fresh()->target_id);
        $this->assertSame(2, $new->estimated_days_min);
        $this->assertSame(5, $new->estimated_days_max);
        $this->assertSame('0.5', $normal->raw_data_json['effective_rate']);
        $this->get(route('imports.show', $batch))->assertOk()->assertSee(route('conversion.rules.show', $new));
        $again = $this->upload('other-name.xlsx');
        $this->configure($again, ['ポイントマイル状況' => ['rule_group_id' => $group->id, 'activate_rule' => 1]]);
        $this->assertSame('skipped_duplicate', $this->row($again, 'ポイントマイル状況', 2)->fresh()->import_status);
        $this->post(route('imports.execute', $again))->assertRedirect();
        $this->assertSame(4, $group->rules()->count());
    }

    private function upload(string $name = 'mapped.xlsx'): ImportBatch
    {
        $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/anonymous-transfer-rules.xlsx')))])->assertRedirect();

        return ImportBatch::query()->latest('id')->firstOrFail();
    }

    public function test_confirmations_history_mismatches_and_wrong_rule_groups_stay_in_review(): void
    {
        $batch = $this->upload();
        $this->configure($batch, ['JAL移行' => ['confirm_native' => 0], 'ポイントマイル状況' => ['confirm_native' => 0]]);
        $this->assertSame('native_quantity_unconfirmed', $this->row($batch, 'JAL移行', 2)->fresh()->warning_code);
        $this->configure($batch, ['JAL移行' => ['confirm_status' => 0]]);
        $this->assertSame('status_unconfirmed', $this->row($batch, 'JAL移行', 2)->fresh()->warning_code);
        $record = $this->row($batch, 'ANA移行', 3);
        $tx = app(BenefitTransactionService::class);
        $tx->earn($this->from, '1000', '2026-10-01');
        $wrongDate = $tx->use($this->from, '100', '2026-10-02');
        $wrongQuantity = $tx->use($this->from, '50', '2026-10-01');
        $wrongDirection = $tx->earn($this->from, '100', '2026-10-01');
        foreach ([$wrongDate, $wrongQuantity, $wrongDirection] as $link) {
            $this->override($record, ['source_quantity' => '100', 'out_transaction_id' => $link->id]);
            $this->assertSame('history_link_unresolved', $record->fresh()->warning_code);
            $this->assertSame('needs_review', $record->fresh()->import_status);
        }
        $this->override($record, ['from_account_id' => $this->jal->id]);
        $this->assertSame('program_unresolved', $record->fresh()->warning_code);
        $ruleGroup = app(ConversionRuleService::class)->createRuleGroup(['from_program_id' => $this->from->program_id, 'to_program_id' => $this->jal->program_id]);
        $normal = $this->row($batch, 'ポイントマイル状況', 2);
        $this->override($normal, ['rule_group_id' => $ruleGroup->id]);
        $this->assertSame('rule_group_mismatch', $normal->fresh()->warning_code);
        $raw = $normal->raw_data_json;
        unset($raw['campaign_only']);
        $raw['conditions_text'] = 'キャンペーン時のみ';
        $normal->raw_data_json = $raw;
        $normal->save();
        $this->override($normal, ['rule_group_id' => null]);
        $this->assertSame('campaign_period_unresolved', $normal->fresh()->warning_code);
        $this->assertDatabaseCount('benefit_transfer_groups', 0);
        $this->assertDatabaseCount('conversion_rules', 0);
    }

    public function test_same_source_rows_can_be_imported_for_distinct_account_pairs(): void
    {
        $first = $this->upload();
        $this->configure($first);
        $this->post(route('imports.execute', $first))->assertRedirect();
        $secondFrom = BenefitAccount::query()->forceCreate(['program_id' => $this->from->program_id, 'account_label' => '別名義口座', 'active' => true]);
        $secondTo = BenefitAccount::query()->forceCreate(['program_id' => $this->jal->program_id, 'account_label' => '別名義口座', 'active' => true]);
        $second = $this->upload('renamed.xlsx');
        $this->configure($second, ['JAL移行' => ['from_account_id' => $secondFrom->id, 'to_account_id' => $secondTo->id]]);
        $this->assertSame('ready', $this->row($second, 'JAL移行', 2)->fresh()->import_status);
        $this->post(route('imports.execute', $second))->assertRedirect();
        $this->assertSame(2, BenefitTransferStep::query()->where('status', 'planned')->count());
        $this->assertDatabaseCount('benefit_transactions', 0);
    }

    private function row(ImportBatch $batch, string $sheet, int $number): ImportRecord
    {
        return $batch->records()->where('source_sheet', $sheet)->where('source_row_number', $number)->firstOrFail();
    }

    private function configure(ImportBatch $batch, array $extra = []): void
    {
        $mappings = ['ANA移行' => ['action' => 'import', 'from_account_id' => $this->from->id, 'to_account_id' => $this->ana->id, 'planning_equivalent_program_id' => $this->ana->program_id, 'confirm_native' => 1, 'confirm_status' => 1],
            'JAL移行' => ['action' => 'import', 'from_account_id' => $this->from->id, 'to_account_id' => $this->jal->id, 'planning_equivalent_program_id' => $this->jal->program_id, 'confirm_native' => 1, 'confirm_status' => 1],
            'ポイントマイル状況' => ['action' => 'import', 'from_program_id' => $this->from->program_id, 'to_program_id' => $this->ana->program_id, 'confirm_native' => 1]];
        foreach ($extra as $sheet => $options) {
            $mappings[$sheet] = array_replace($mappings[$sheet], $options);
        }
        $this->post(route('imports.configure', $batch), ['mappings' => $mappings])->assertRedirect();
    }

    private function override(ImportRecord $record, array $options, int $status = 302): void
    {
        $response = $this->post(route('imports.records.override', [$record->batch, $record]), ['action' => 'import', 'row' => $options]);
        $response->assertStatus($status);
    }
}
