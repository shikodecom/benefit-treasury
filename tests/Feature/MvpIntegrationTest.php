<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\BenefitProgramAlias;
use App\Models\BenefitTransferGroup;
use App\Models\ImportBatch;
use App\Models\ImportRecord;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\BenefitLotService;
use App\Services\BenefitNotificationService;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use App\Services\BenefitTransferService;
use App\Services\ConversionRouteService;
use App\Services\ConversionRuleService;
use App\Services\ConversionTransferDraftService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class MvpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_draft_selects_campaign_and_freezes_rule_quantity(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 09:00', 'Asia/Tokyo'));
        $from = $this->account('V');
        $to = $this->account('JQ');
        $rules = app(ConversionRuleService::class);
        $group = $rules->createRuleGroup(['from_program_id' => $from->program_id, 'to_program_id' => $to->program_id]);
        $normal = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '50', 'active' => true]);
        $campaign = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '70', 'active' => true,
            'campaign_only' => true, 'valid_from' => '2026-09-29', 'valid_to' => '2026-09-30', 'estimated_days_max' => 2,
            'minimum_from_quantity' => '500', 'increment_from_quantity' => '100']);
        $route = app(ConversionRouteService::class)->createRoute(['name' => 'V→JQ'], [['rule_group_id' => $group->id]]);
        $input = ['source_account_id' => $from->id, 'source_quantity' => '1000', 'started_at' => '2026-09-29',
            'steps' => [['rule_id' => $campaign->id, 'to_account_id' => $to->id]]];
        $service = app(ConversionTransferDraftService::class);
        $this->assertSame('700.0000', $service->preview($route, $input)[0]['received']);
        $this->assertSame('2026-10-01', $service->preview($route, $input)[0]['expectedDate']);
        foreach (['400', '550'] as $bad) {
            try {
                $service->preview($route, array_replace($input, ['source_quantity' => $bad]));
                $this->fail('Minimum and increment must be enforced in the draft');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $ambiguous = $input;
        unset($ambiguous['steps'][0]['rule_id']);
        try {
            $service->preview($route, $ambiguous);
            $this->fail('Normal and campaign rules require a choice');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->actingAs(User::factory()->create());
        $this->post(route('conversion.routes.draft.preview', $route), $input)->assertOk()->assertSee('700.0000');
        $this->post(route('conversion.routes.draft.store', $route), $input)->assertRedirect();
        $step = BenefitTransferGroup::query()->firstOrFail()->steps()->firstOrFail();
        $this->assertEquals($campaign->id, $step->conversion_rule_id);
        $this->assertEquals(700, $step->expected_destination_quantity);
        $rules->createRuleVersion($group, ['to_quantity' => '90'], $campaign, true);
        $this->assertEquals(700, $step->fresh()->expected_destination_quantity);
        $this->post(route('conversion.routes.draft.store', $route), $input)->assertSessionHasErrors('steps.0.rule_id');
        $this->assertEquals(100, $normal->from_quantity);
    }

    public function test_excel_preview_drops_private_columns_and_reimport_is_idempotent(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('ANA');
        $file = $this->xlsx();
        $this->post(route('imports.upload'), ['file' => $file])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(2, $batch->records()->count());
        $this->assertStringNotContainsString('private@example.com', json_encode($batch->records()->first()->raw_data_json));
        $this->post(route('imports.configure', $batch), ['mappings' => ['ANA' => ['action' => 'import', 'account_id' => $account->id]]])->assertRedirect();
        $this->assertSame(2, $batch->records()->where('import_status', 'ready')->count());
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertEquals(1235, (float) app(BenefitReadService::class)->accountBalance($account->id));
        $this->post(route('imports.upload'), ['file' => $this->xlsx('renamed.xlsx')])->assertRedirect();
        $this->assertSame(4, ImportRecord::query()->count());
        $this->assertSame(2, ImportRecord::query()->where('import_status', 'skipped_duplicate')->count());
        $this->assertSame(2, DB::table('benefit_transactions')->count());
    }

    public function test_search_filters_and_notifications_are_deduplicated_in_jst(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 00:30', 'Asia/Tokyo'));
        $this->actingAs(User::factory()->create());
        $account = $this->account('ANA');
        $lot = app(BenefitLotService::class)->acquire($account, ['display_name' => 'ANA優待', 'quantity' => '2',
            'acquired_at' => '2026-09-01', 'expires_at' => '2026-09-30', 'action_policy' => 'sell_now']);
        $other = app(BenefitLotService::class)->acquire($account, ['display_name' => '別の特典', 'quantity' => '1',
            'acquired_at' => '2026-09-01', 'expires_at' => '2026-12-31', 'action_policy' => 'hold']);
        $this->get(route('search.index', ['q' => 'ANA', 'expires_within' => '30', 'policy' => 'sell_now', 'listing' => 'unlisted']))
            ->assertOk()->assertSee('ANA優待')->assertDontSee('別の特典');
        $this->get(route('search.index', ['q' => '<script>alert(1)</script>']))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $service = app(BenefitNotificationService::class);
        $this->assertSame(1, $service->generate());
        $this->assertSame(0, $service->generate());
        $notification = Notification::query()->firstOrFail();
        $this->assertSame('sell_now_unlisted', $notification->type);
        $this->assertSame('expire_7d', $notification->milestone_key);
        app(BenefitLotService::class)->use($lot, '2', '2026-09-23');
        $this->assertTrue($service->isStale($notification));
        $this->assertSame(0, $service->generate());
        $this->post(route('notifications.open', $notification))->assertRedirect();
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNotEquals($other->id, $lot->id);
    }

    public function test_excel_abandoned_preview_can_be_reuploaded_and_malformed_file_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('imports.upload'), ['file' => $this->xlsx()])->assertRedirect();
        $this->post(route('imports.upload'), ['file' => $this->xlsx('second.xlsx')])->assertRedirect();
        $this->assertSame(4, ImportRecord::query()->where('import_status', 'pending')->count());
        $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'not a zip')])
            ->assertSessionHasErrors('file');
        $this->assertSame(2, ImportBatch::query()->count());
    }

    public function test_excel_failed_row_can_be_retried_without_reimporting_successful_rows(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('ANA');
        $this->post(route('imports.upload'), ['file' => $this->xlsx()])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->post(route('imports.configure', $batch), ['mappings' => ['ANA' => ['action' => 'import', 'account_id' => $account->id]]])->assertRedirect();
        $second = $batch->records()->orderByDesc('source_row_number')->firstOrFail();
        $normalized = $second->normalized_data_json;
        $normalized['quantity'] = '3000';
        $second->normalized_data_json = $normalized;
        $second->save();
        $this->post(route('imports.execute', $batch), ['confirm_mismatch' => '1'])->assertRedirect();
        $this->assertSame('completed_with_errors', $batch->fresh()->status);
        $this->assertSame(1, DB::table('benefit_transactions')->count());
        $this->post(route('imports.records.override', [$batch, $second]), [
            'action' => 'import', 'account_id' => $account->id, 'transaction_type' => 'use',
        ])->assertRedirect();
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame(2, DB::table('benefit_transactions')->count());
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_point_history_requires_explicit_native_quantity_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('ポイント');
        $this->post(route('imports.upload'), ['file' => $this->xlsx('points.xlsx', 'ポイント積立')])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->post(route('imports.configure', $batch), ['mappings' => ['ポイント積立' => [
            'action' => 'import', 'account_id' => $account->id,
        ]]])->assertRedirect();
        $this->assertSame(2, $batch->records()->where('import_status', 'needs_review')->count());
        $this->post(route('imports.configure', $batch), ['mappings' => ['ポイント積立' => [
            'action' => 'import', 'account_id' => $account->id, 'confirm_native' => '1',
        ]]])->assertRedirect();
        $this->assertSame(2, $batch->records()->where('import_status', 'ready')->count());
    }

    public function test_unknown_program_alias_stays_unresolved_until_scoped_alias_is_registered(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('JRキューポ');
        $this->post(route('imports.upload'), ['file' => $this->xlsx('unknown.xlsx', 'ポイント積立', '利用', false, false, null, false, 0, 'JQX')])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $mapping = ['mappings' => ['ポイント積立' => [
            'action' => 'import', 'account_id' => $account->id, 'confirm_native' => '1',
        ]]];
        $this->post(route('imports.configure', $batch), $mapping)->assertRedirect();
        $this->assertSame(2, $batch->records()->where('warning_code', 'program_unresolved')->count());
        $alias = new BenefitProgramAlias;
        $alias->program_id = $account->program_id;
        $alias->alias = 'JQX';
        $alias->source_scope = 'ポイント積立';
        $alias->save();
        $this->post(route('imports.configure', $batch), $mapping)->assertRedirect();
        $this->assertSame(2, $batch->records()->where('import_status', 'ready')->count());
    }

    public function test_excel_new_account_is_created_only_when_import_executes(): void
    {
        $this->actingAs(User::factory()->create());
        $existing = $this->account('ANA');
        $this->post(route('imports.upload'), ['file' => $this->xlsx()])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->post(route('imports.configure', $batch), ['mappings' => ['ANA' => [
            'action' => 'import', 'create_account' => '1', 'new_program_id' => $existing->program_id,
            'new_label' => '取込用',
        ]]])->assertRedirect();
        $this->assertSame(1, BenefitAccount::query()->count());
        $this->assertSame(2, $batch->records()->where('import_status', 'ready')->count());
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame(2, BenefitAccount::query()->count());
        $new = BenefitAccount::query()->where('account_label', '取込用')->firstOrFail();
        $this->assertSame(2, DB::table('benefit_transactions')->where('account_id', $new->id)->count());
    }

    public function test_excel_free_text_private_data_is_redacted_and_formula_cell_is_ignored(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('imports.upload'), ['file' => $this->xlsx('pii.xlsx', 'ANA', '利用 private@example.com 090-1234-5678')])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $raw = json_encode($batch->records()->orderByDesc('source_row_number')->firstOrFail()->raw_data_json);
        $this->assertStringNotContainsString('private@example.com', $raw);
        $this->assertStringNotContainsString('090-1234-5678', $raw);
        $this->post(route('imports.upload'), ['file' => $this->xlsx('formula.xlsx', 'ANA', '利用', true)])->assertRedirect();
        $this->assertSame(2, ImportBatch::query()->count());
        $formulaBatch = ImportBatch::query()->latest('id')->firstOrFail();
        $formulaRow = $formulaBatch->records()->where('source_row_number', 3)->firstOrFail();
        $this->assertArrayNotHasKey('quantity', $formulaRow->raw_data_json);
        $this->assertStringNotContainsString('1+1', json_encode($formulaRow->raw_data_json));
        $account = $this->account('ANA');
        $this->post(route('imports.configure', $formulaBatch), ['mappings' => ['ANA' => [
            'action' => 'import', 'account_id' => $account->id,
        ]]])->assertRedirect();
        $this->assertSame('needs_review', $formulaRow->fresh()->import_status);
        $this->post(route('imports.records.override', [$formulaBatch, $formulaRow]), [
            'action' => 'import', 'account_id' => $account->id, 'quantity_override' => '-765',
            'transaction_type' => 'use',
        ])->assertRedirect();
        $this->assertSame('ready', $formulaRow->fresh()->import_status);
    }

    public function test_jal_layout_keeps_undated_opening_row_for_explicit_date_override(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('JAL');
        $this->post(route('imports.upload'), ['file' => $this->xlsx('jal.xlsx', 'JAL', actualLayout: true)])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $opening = $batch->records()->where('source_row_number', 2)->firstOrFail();
        $this->assertArrayNotHasKey('date', $opening->raw_data_json);
        $this->assertSame('2000', $opening->raw_data_json['quantity']);
        $this->post(route('imports.configure', $batch), ['mappings' => ['JAL' => [
            'action' => 'import', 'account_id' => $account->id,
        ]]])->assertRedirect();
        $this->assertSame('needs_review', $opening->fresh()->import_status);
        $this->post(route('imports.records.override', [$batch, $opening]), [
            'action' => 'import', 'account_id' => $account->id, 'date_override' => '2026-08-31',
            'transaction_type' => 'opening_balance',
        ])->assertRedirect();
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame(2, DB::table('benefit_transactions')->where('account_id', $account->id)->count());
    }

    public function test_anonymous_workbook_layouts_preview_reconcile_and_dedupe(): void
    {
        $this->actingAs(User::factory()->create());
        $jal = $this->account('JAL');
        $ana = $this->account('ANA');
        $familyAna = $this->account('ANA');
        $fixture = file_get_contents(base_path('tests/Fixtures/anonymous-mileage.xlsx'));
        $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent('miles.xlsx', $fixture)])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(7, $batch->records()->where('record_type', 'transaction')->count());
        $this->assertSame(3, $batch->records()->where('import_status', 'needs_review')->count());
        $this->post(route('imports.configure', $batch), ['mappings' => [
            'JAL' => ['action' => 'import', 'account_id' => $jal->id],
            'ANA' => ['action' => 'import', 'account_id' => $ana->id],
            'えりANA' => ['action' => 'import', 'account_id' => $familyAna->id],
        ]])->assertRedirect();
        $opening = $batch->records()->where('source_sheet', 'JAL')->where('source_row_number', 2)->firstOrFail();
        $this->assertSame('needs_review', $opening->fresh()->import_status);
        $this->post(route('imports.records.override', [$batch, $opening]), [
            'action' => 'import', 'account_id' => $jal->id, 'date_override' => '2026-09-01',
            'transaction_type' => 'opening_balance',
        ])->assertRedirect();
        $this->assertSame(0, $batch->records()->where('warning_code', 'balance_mismatch')->count());
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame(7, $batch->records()->where('import_status', 'imported')->count());
        $this->assertSame('1500.0000', app(BenefitReadService::class)->unallocatedBalance($jal->id));
        $this->assertSame('2500.0000', app(BenefitReadService::class)->unallocatedBalance($ana->id));
        $this->assertSame('800.0000', app(BenefitReadService::class)->unallocatedBalance($familyAna->id));
        $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent('renamed.xlsx', $fixture)])->assertRedirect();
        $this->assertSame(7, ImportBatch::query()->latest('id')->firstOrFail()->records()->where('import_status', 'skipped_duplicate')->count());

        $points = $this->account('架空ポイント');
        $pointFixture = file_get_contents(base_path('tests/Fixtures/anonymous-points.xlsx'));
        $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent('points.xlsx', $pointFixture)])->assertRedirect();
        $pointBatch = ImportBatch::query()->latest('id')->firstOrFail();
        $this->assertSame(3, $pointBatch->records()->where('record_type', 'transaction')->count());
        $this->assertSame(1, $pointBatch->records()->where('source_sheet', 'プレ商品券')->where('import_status', 'needs_review')->count());
        $this->assertStringNotContainsString('000-0000-0000', json_encode($pointBatch->records()->pluck('raw_data_json')));
        $this->post(route('imports.configure', $pointBatch), ['mappings' => [
            'ポイント積立' => ['action' => 'import', 'account_id' => $points->id, 'confirm_native' => '1'],
            'ポイント利用' => ['action' => 'import', 'account_id' => $points->id, 'confirm_native' => '1'],
        ]])->assertRedirect();
        $use = $pointBatch->records()->where('source_sheet', 'ポイント利用')->firstOrFail();
        $this->assertSame('use', $use->fresh()->normalized_data_json['type']);
        $this->post(route('imports.execute', $pointBatch))->assertRedirect();
        $this->assertSame('110.0000', app(BenefitReadService::class)->unallocatedBalance($points->id));
    }

    public function test_excel_external_link_and_expanded_size_bomb_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $source = base_path('tests/Fixtures/anonymous-mileage.xlsx');
        foreach (['external', 'bomb'] as $kind) {
            $path = tempnam(sys_get_temp_dir(), 'unsafe-xlsx');
            copy($source, $path);
            $zip = new ZipArchive;
            $zip->open($path);
            if ($kind === 'external') {
                $zip->addFromString('xl/externalLinks/externalLink1.xml', '<externalLink/>');
            } else {
                $large = tempnam(sys_get_temp_dir(), 'xlsx-bomb');
                $handle = fopen($large, 'wb');
                ftruncate($handle, 81 * 1024 * 1024);
                fclose($handle);
                $zip->addFile($large, 'xl/worksheets/oversized.xml');
            }
            $zip->close();
            if (isset($large)) {
                unlink($large);
                unset($large);
            }
            $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent($kind.'.xlsx', file_get_contents($path))])
                ->assertSessionHasErrors('file');
            unlink($path);
        }
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_excel_identical_legitimate_rows_keep_distinct_occurrence_fingerprints(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('ANA');
        $this->post(route('imports.upload'), ['file' => $this->xlsx('duplicate.xlsx', 'ANA', '利用', false, true)])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(3, $batch->records()->count());
        $this->assertSame(3, $batch->records()->distinct()->count('row_fingerprint'));
        $this->post(route('imports.configure', $batch), ['mappings' => ['ANA' => [
            'action' => 'import', 'account_id' => $account->id,
        ]]])->assertRedirect();
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame(3, DB::table('benefit_transactions')->count());
        $this->post(route('imports.upload'), ['file' => $this->xlsx('renamed.xlsx', 'ANA', '利用', false, true)])->assertRedirect();
        $this->assertSame(3, ImportRecord::query()->where('import_status', 'skipped_duplicate')->count());
    }

    public function test_excel_balance_mismatch_requires_explicit_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('ANA');
        $this->post(route('imports.upload'), ['file' => $this->xlsx('mismatch.xlsx', 'ANA', '利用', false, false, '999')])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->post(route('imports.configure', $batch), ['mappings' => ['ANA' => [
            'action' => 'import', 'account_id' => $account->id,
        ]]])->assertRedirect();
        $this->assertSame(1, $batch->records()->where('warning_code', 'balance_mismatch')->count());
        $this->post(route('imports.execute', $batch))->assertSessionHasErrors('confirm_mismatch');
        $this->assertSame(0, DB::table('benefit_transactions')->count());
        $this->post(route('imports.execute', $batch), ['confirm_mismatch' => '1'])->assertRedirect();
        $this->assertSame(2, DB::table('benefit_transactions')->count());
    }

    public function test_excel_right_side_aggregate_columns_are_not_transactions(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('imports.upload'), ['file' => $this->xlsx('summary.xlsx', 'ポイント積立', '利用', false, false, null, true)])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(2, $batch->records()->count());
        $this->assertSame('2026-09-01', $batch->records()->orderBy('source_row_number')->firstOrFail()->raw_data_json['date']);
        $this->assertSame('2000', $batch->records()->orderBy('source_row_number')->firstOrFail()->raw_data_json['quantity']);
    }

    public function test_alias_search_and_revised_expiry_and_transfer_overdue_notifications(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 00:30', 'Asia/Tokyo'));
        $this->actingAs(User::factory()->create());
        $account = $this->account('JRキューポ');
        $alias = new BenefitProgramAlias;
        $alias->program_id = $account->program_id;
        $alias->alias = 'JQ';
        $alias->save();
        $account->external_account_hint = 'secret-hint';
        $account->save();
        $lot = app(BenefitLotService::class)->acquire($account, ['display_name' => 'テスト優待', 'quantity' => '2',
            'acquired_at' => '2026-09-01', 'expires_at' => '2026-09-30', 'memo' => 'secret-memo']);
        $this->get(route('search.index', ['q' => 'JQ']))->assertOk()->assertSee('テスト優待');
        $notifications = app(BenefitNotificationService::class);
        $this->assertSame(1, $notifications->generate());
        $first = Notification::query()->firstOrFail();
        $this->assertStringNotContainsString('secret-hint', $first->title.$first->body);
        $this->assertStringNotContainsString('secret-memo', $first->title.$first->body);
        $lot->expires_at = '2026-10-31';
        $lot->save();
        $this->assertTrue($notifications->isStale($first));
        $this->travelTo(Carbon::parse('2026-10-24 00:30', 'Asia/Tokyo'));
        $this->assertSame(1, $notifications->generate());
        $this->assertSame(2, Notification::query()->count());

        $source = $this->account('V');
        $destination = $this->account('ANA');
        app(BenefitTransactionService::class)->createOpeningBalance($source, '2026-10-20', '1000', null);
        $transfers = app(BenefitTransferService::class);
        $group = $transfers->createGroup([]);
        $step = $transfers->addStep($group, ['from_account_id' => $source->id, 'to_account_id' => $destination->id,
            'source_quantity' => '100', 'expected_complete_at' => '2026-10-17']);
        $transfers->startStep($step, '2026-10-20');
        $this->assertSame(1, $notifications->generate());
        $this->assertSame('overdue_7d', Notification::query()->where('subject_type', 'transfer_step')->firstOrFail()->milestone_key);
        $this->get(route('search.index', ['transfer_status' => 'overdue']))->assertOk()->assertSee('移行予定日超過');
        $transfers->completeStep($step, '100', '2026-10-24');
        $this->assertSame(0, $notifications->generate());
    }

    public function test_three_step_route_transfer_finishes_with_correct_native_balances(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 09:00', 'Asia/Tokyo'));
        $accounts = [
            $this->account('V'), $this->account('JQ'), $this->account('永久不滅'), $this->account('ANA'),
        ];
        app(BenefitTransactionService::class)->createOpeningBalance($accounts[0], '2026-09-29', '1000', null);
        $rules = app(ConversionRuleService::class);
        $routeSteps = [];
        $inputSteps = [];
        for ($i = 0; $i < 3; $i++) {
            $group = $rules->createRuleGroup(['from_program_id' => $accounts[$i]->program_id, 'to_program_id' => $accounts[$i + 1]->program_id]);
            $rule = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '100', 'active' => true]);
            $routeSteps[] = ['rule_group_id' => $group->id];
            $inputSteps[] = ['rule_id' => $rule->id, 'to_account_id' => $accounts[$i + 1]->id];
        }
        $route = app(ConversionRouteService::class)->createRoute(['name' => 'VからANA'], $routeSteps);
        $transferGroup = app(ConversionTransferDraftService::class)->create($route, [
            'source_account_id' => $accounts[0]->id, 'source_quantity' => '1000',
            'started_at' => '2026-09-29', 'steps' => $inputSteps,
        ]);
        $transfer = app(BenefitTransferService::class);
        foreach ($transferGroup->steps()->orderBy('sequence_no')->get() as $step) {
            $transfer->startStep($step, '2026-09-29');
            $transfer->completeStep($step, '1000', '2026-09-30');
        }
        $this->assertSame('completed', $transferGroup->fresh()->status);
        $read = app(BenefitReadService::class);
        $this->assertEquals(0, $read->accountBalance($accounts[0]->id));
        $this->assertEquals(0, $read->accountBalance($accounts[1]->id));
        $this->assertEquals(0, $read->accountBalance($accounts[2]->id));
        $this->assertEquals(1000, $read->accountBalance($accounts[3]->id));
    }

    public function test_ended_unsold_notification_is_single_and_can_be_dismissed(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 10:00', 'Asia/Tokyo'));
        $this->actingAs(User::factory()->create());
        $account = $this->account('優待');
        $lot = app(BenefitLotService::class)->acquire($account, ['display_name' => 'テスト優待', 'quantity' => '1',
            'acquired_at' => '2026-09-01', 'expires_at' => '2026-12-31']);
        $listingId = DB::table('benefit_listings')->insertGetId([
            'marketplace' => 'test', 'status' => 'ended_unsold', 'ended_at' => now(),
        ]);
        DB::table('benefit_listing_items')->insert(['listing_id' => $listingId, 'lot_id' => $lot->id, 'quantity' => '1']);
        $notifications = app(BenefitNotificationService::class);
        $this->assertSame(1, $notifications->generate());
        $this->assertSame(0, $notifications->generate());
        $notice = Notification::query()->firstOrFail();
        $this->assertSame('listing_ended_unsold', $notice->type);
        $this->post(route('notifications.dismiss', $notice))->assertRedirect();
        $this->get(route('notifications.index'))->assertOk()->assertDontSee('出品が売れずに終了しました');
        $this->get(route('notifications.index', ['scope' => 'all']))->assertOk()->assertSee('出品が売れずに終了しました');
    }

    public function test_notification_milestone_can_be_disabled_individually(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 00:30', 'Asia/Tokyo'));
        $this->actingAs(User::factory()->create());
        $account = $this->account('優待');
        app(BenefitLotService::class)->acquire($account, ['display_name' => 'テスト優待', 'quantity' => '1',
            'acquired_at' => '2026-09-01', 'expires_at' => '2026-09-30']);
        NotificationPreference::query()->create(['notification_type' => 'milestone:expire_7d',
            'enabled' => false, 'in_app_enabled' => false]);
        $this->assertSame(0, app(BenefitNotificationService::class)->generate());
        $this->travelTo(Carbon::parse('2026-09-27 00:30', 'Asia/Tokyo'));
        $this->assertSame(1, app(BenefitNotificationService::class)->generate());
        $this->assertSame('expire_3d', Notification::query()->firstOrFail()->milestone_key);
        $this->travelTo(Carbon::parse('2026-10-01 00:30', 'Asia/Tokyo'));
        $this->assertSame(1, app(BenefitNotificationService::class)->generate());
        $this->assertSame('lot_expired_pending', Notification::query()->orderByDesc('id')->firstOrFail()->type);
    }

    public function test_notification_generation_uses_bounded_queries_for_many_lots(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 00:30', 'Asia/Tokyo'));
        $account = $this->account('優待');
        $transactions = [];
        for ($i = 0; $i < 100; $i++) {
            $lotId = DB::table('benefit_lots')->insertGetId([
                'account_id' => $account->id, 'display_name' => 'テスト優待 '.$i,
                'expires_at' => '2026-09-30', 'action_policy' => 'self_use',
            ]);
            $transactions[] = ['account_id' => $account->id, 'lot_id' => $lotId,
                'transaction_type' => 'earn', 'direction' => 'in', 'quantity' => '1',
                'transaction_at' => '2026-09-01', 'source_type' => 'manual'];
        }
        DB::table('benefit_transactions')->insert($transactions);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $created = app(BenefitNotificationService::class)->generate();
        $queryCount = $queries;
        $this->assertSame(100, $created);
        $this->assertLessThan(20, $queryCount);
    }

    public function test_two_thousand_point_history_rows_can_be_previewed_and_imported(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('ポイント');
        $this->post(route('imports.upload'), ['file' => $this->xlsx('large.xlsx', 'ポイント積立', '利用', false, false, null, false, 2000)])->assertRedirect();
        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame(2000, $batch->total_rows);
        $this->post(route('imports.configure', $batch), ['mappings' => ['ポイント積立' => [
            'action' => 'import', 'account_id' => $account->id, 'confirm_native' => '1',
        ]]])->assertRedirect();
        $this->assertSame(2000, $batch->records()->where('import_status', 'ready')->count());
        $this->post(route('imports.execute', $batch))->assertRedirect();
        $this->assertSame(2000, DB::table('benefit_transactions')->count());
    }

    private function account(string $name): BenefitAccount
    {
        $program = new BenefitProgram;
        $program->name = $name;
        $program->category = 'point';
        $program->unit_name = 'pt';
        $program->active = true;
        $program->save();
        $account = new BenefitAccount;
        $account->program_id = $program->id;
        $account->active = true;
        $account->save();

        return $account;
    }

    private function xlsx(string $name = 'mileage.xlsx', string $sheet = 'ANA', string $description = '利用', bool $formula = false, bool $duplicate = false, ?string $balanceOverride = null, bool $aggregate = false, int $historyRows = 0, ?string $programLabel = null, bool $actualLayout = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'benefit-xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars($sheet, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $rows = [
            ['日付', '内容', '数量', '残高', 'メール'],
            ['2026-09-01', '過去分合計繰り越し', '2000', '2000', 'private@example.com'],
            ['2026-09-02', $description, '-765', $duplicate ? '' : ($balanceOverride ?? '1235'), 'private@example.com'],
        ];
        if ($actualLayout && $sheet === 'JAL') {
            $rows = [
                ['日付', '内容', '', '', '', 'マイル', 'ボーナスマイル', '合計マイル＋', '有効マイル'],
                ['', '過去分合計繰り越し', '', '', '', '', '', '2000', '2000'],
                ['2026-09-02', '利用', '', '', '', '', '', '-765', '1235'],
            ];
        }
        if ($duplicate) {
            $rows[] = $rows[2];
        }
        if ($aggregate) {
            $rows[0] = array_merge($rows[0], ['日付', '数量']);
            $rows[1] = array_merge($rows[1], ['2026-12-01', '999999']);
            $rows[2] = array_merge($rows[2], ['2026-12-02', '888888']);
        }
        if ($historyRows > 0) {
            $rows = [['日付', '内容', '数量']];
            for ($i = 1; $i <= $historyRows; $i++) {
                $rows[] = ['2026-09-01', '獲得 '.$i, '1'];
            }
        }
        if ($programLabel !== null) {
            $rows[0][] = '制度';
            for ($i = 1; $i < count($rows); $i++) {
                $rows[$i][] = $programLabel;
            }
        }
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $i => $row) {
            $n = $i + 1;
            $xml .= '<row r="'.$n.'">';
            foreach ($row as $j => $value) {
                $xml .= '<c r="'.chr(65 + $j).$n.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is>'.($formula && $n === 3 && $j === 2 ? '<f>1+1</f>' : '').'</c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
