<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\BenefitTransferGroup;
use App\Models\ImportBatch;
use App\Models\Notification;
use App\Models\User;
use App\Services\BenefitLotService;
use App\Services\BenefitNotificationService;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use App\Services\ConversionRouteService;
use App\Services\ConversionRuleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 08:00', 'Asia/Tokyo'));
        $this->actingAs(User::factory()->create());
    }

    private function account(string $name): BenefitAccount
    {
        $program = BenefitProgram::query()->forceCreate(['name' => $name, 'category' => 'point', 'unit_name' => 'pt', 'active' => true]);

        return BenefitAccount::query()->forceCreate(['program_id' => $program->id, 'active' => true]);
    }

    private function aliasFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'benefit-alias-');
        try {
            copy(base_path('tests/Fixtures/anonymous-points.xlsx'), $path);
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = str_replace(['<x:v>項目</x:v>', '<x:v>交換額</x:v>', '<x:v>交換日</x:v>'],
                ['<x:v>内容</x:v>', '<x:v>数量</x:v>', '<x:v>日付</x:v>'], $zip->getFromName('xl/worksheets/sheet1.xml'));
            $xml = preg_replace_callback('/(<x:row r="(\d+)">)(.*?)(<\/x:row>)/s', fn ($match) => $match[1].$match[3]
                .'<x:c r="Z'.$match[2].'" t="str"><x:v>'.($match[2] === '1' ? '制度' : '合成略称').'</x:v></x:c>'.$match[4], $xml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    public function test_e2e_03_route_preview_commit_three_steps_and_all_ledger_views(): void
    {
        $accounts = array_map(fn ($name) => $this->account($name), ['V', 'JQ', '永久不滅', 'ANA']);
        app(BenefitTransactionService::class)->createOpeningBalance($accounts[0], '2026-10-01', '1000', null);
        $rules = app(ConversionRuleService::class);
        $routeSteps = $inputSteps = [];
        for ($i = 0; $i < 3; $i++) {
            $group = $rules->createRuleGroup(['from_program_id' => $accounts[$i]->program_id, 'to_program_id' => $accounts[$i + 1]->program_id]);
            $rule = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '100', 'active' => true]);
            $routeSteps[] = ['rule_group_id' => $group->id];
            $inputSteps[] = ['rule_id' => $rule->id, 'to_account_id' => $accounts[$i + 1]->id, 'expected_complete_at' => '2026-10-05'];
        }
        $route = app(ConversionRouteService::class)->createRoute(['name' => '合成3段移行'], $routeSteps);
        $input = ['source_account_id' => $accounts[0]->id, 'source_quantity' => 1000, 'steps' => $inputSteps];
        $this->get(route('conversion.routes.draft', $route))->assertOk()->assertSee('合成3段移行');
        $this->post(route('conversion.routes.draft.preview', $route), $input)->assertOk()->assertSee('1000');
        $this->assertDatabaseCount('benefit_transfer_groups', 0);
        $this->post(route('conversion.routes.draft.store', $route), $input)->assertRedirect()->assertSessionHasNoErrors();
        $group = BenefitTransferGroup::query()->firstOrFail();
        foreach ($group->steps()->orderBy('sequence_no')->get() as $step) {
            $this->post(route('transfers.steps.start', $step), ['started_at' => '2026-10-03'])->assertRedirect()->assertSessionHasNoErrors();
            $this->get(route('transfers.steps.complete.form', $step))->assertOk();
            $this->post(route('transfers.steps.complete', $step), ['completed_at' => '2026-10-03', 'actual_destination_quantity' => 1000])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame('completed', $group->fresh()->status);
        $this->assertSame(3, $group->steps()->where('status', 'completed')->count());
        foreach ($accounts as $i => $account) {
            $balance = $i === 3 ? '1000.0000' : '0.0000';
            $this->assertSame($balance, app(BenefitReadService::class)->accountBalance($account->id));
            $this->get(route('ledger.accounts.transactions', $account))->assertOk()->assertSee($balance);
        }
        $this->get(route('transfers.show', $group))->assertOk()->assertSee('完了');
        $this->get(route('transfers.index', ['status' => 'overdue']))->assertOk()->assertDontSee('合成3段移行');
        $this->get(route('search.index', ['transfer_status' => 'completed']))->assertOk()->assertSee('ANA');
        $this->get('/dashboard')->assertOk()->assertSee('1000.0000');
    }

    public function test_e2e_04_upload_resolve_alias_verify_commit_and_reimport_two_filenames(): void
    {
        $account = $this->account('合成ポイント');
        $contents = $this->aliasFixture();
        foreach (['first.xlsx', 'first.xlsx', 'renamed.xlsx'] as $run => $name) {
            $this->post(route('imports.upload'), ['file' => UploadedFile::fake()->createWithContent($name, $contents)])
                ->assertRedirect()->assertSessionHasNoErrors();
            $batch = ImportBatch::query()->latest('id')->firstOrFail();
            $mappings = ['ポイント積立' => ['action' => 'import', 'account_id' => $account->id, 'confirm_native' => 1],
                'ポイント利用' => ['action' => 'import', 'account_id' => $account->id, 'confirm_native' => 1]];
            $record = $batch->records()->where('source_sheet', 'ポイント積立')->firstOrFail();
            $this->post(route('imports.configure', $batch), ['mappings' => $mappings])->assertRedirect();
            if ($run === 0) {
                $this->assertSame('program_unresolved', $record->fresh()->warning_code);
                $this->post(route('settings.programs.aliases.store', $account->program_id), ['alias' => '合成略称', 'source_scope' => 'ポイント積立'])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $this->post(route('imports.configure', $batch), ['mappings' => $mappings])->assertRedirect();
                $this->assertSame('ready', $record->fresh()->import_status);
                $this->assertDatabaseCount('benefit_transactions', 0);
            }
            $this->get(route('imports.show', $batch))->assertOk()->assertDontSee('SYNTHETIC-ID')->assertDontSee('000-0000-0000');
            $this->post(route('imports.execute', $batch))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('110.0000', app(BenefitReadService::class)->accountBalance($account->id));
            $this->assertSame(3, $account->transactions()->count());
            $this->assertSame($run === 0 ? 3 : 0, $batch->fresh()->imported_rows);
            $this->assertSame($run === 0 ? 0 : 3, $batch->records()->where('import_status', 'skipped_duplicate')->count());
            $this->get(route('ledger.accounts.transactions', $account))->assertOk()->assertSee('110.0000');
        }
    }

    public function test_e2e_05_notice_preferences_rerun_action_read_and_dismiss(): void
    {
        $account = $this->account('合成通知優待');
        $lot = app(BenefitLotService::class)->acquire($account, ['quantity' => 2, 'display_name' => '合成通知ロット', 'expires_at' => '2026-10-10']);
        $this->get(route('notifications.preferences'))->assertOk();
        $this->post(route('notifications.preferences.update'), ['enabled' => ['lot_expiry' => 1, 'milestone:expire_7d' => 1]])->assertRedirect();
        $service = app(BenefitNotificationService::class);
        $this->assertSame(1, $service->generate());
        $this->assertSame(0, $service->generate());
        $notice = Notification::query()->firstOrFail();
        $this->get(route('notifications.index'))->assertOk()->assertSee('合成通知ロット');
        $this->post(route('notifications.open', $notice))->assertRedirect(route('ledger.lots.show', $lot));
        $this->assertNotNull($notice->fresh()->read_at);
        $this->post(route('ledger.lots.use', $lot), ['quantity' => 2, 'transaction_at' => '2026-10-03'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('0.0000', app(BenefitReadService::class)->lotRemainingQuantity($lot->id));
        $this->travel(8)->days();
        $this->assertSame(0, $service->generate());
        $this->assertDatabaseCount('notifications', 1);
        $this->post(route('notifications.open', $notice))->assertRedirect(route('ledger.lots.show', $lot));
        $this->get(route('notifications.index', ['scope' => 'all']))->assertOk()->assertSee('対応済み');
        $this->post(route('notifications.read-all'))->assertRedirect();
        $this->post(route('notifications.dismiss', $notice))->assertRedirect();
        $this->assertNotNull($notice->fresh()->dismissed_at);
        $this->get(route('notifications.index'))->assertOk()->assertDontSee('合成通知ロット');
    }

    public function test_notification_catch_up_only_creates_current_stage_and_command_has_safe_timestamps(): void
    {
        $account = $this->account('合成復旧');
        $lot = app(BenefitLotService::class)->acquire($account, ['quantity' => 1, 'expires_at' => '2026-10-10']);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00', 'Asia/Tokyo'));
        $this->assertSame(0, Artisan::call('benefit:notifications'));
        $lines = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", trim(Artisan::output()))));
        $this->assertSame('notifications.started', $lines[0]['event']);
        $this->assertSame('notifications.completed', $lines[1]['event']);
        $this->assertSame($lines[0]['run_id'], $lines[1]['run_id']);
        $this->assertSame(1, $lines[1]['created']);
        $this->assertSame('2026-11-20T08:00:00+09:00', $lines[1]['at']);
        $this->assertGreaterThanOrEqual(0, $lines[1]['duration_ms']);
        $this->assertSame(['expired_30d'], Notification::query()->where('subject_id', $lot->id)->pluck('milestone_key')->all());
        $this->assertSame(0, Artisan::call('benefit:notifications'));
        $this->assertStringContainsString('"created":0', Artisan::output());
        $this->assertDatabaseCount('notifications', 1);
        $this->mock(BenefitNotificationService::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('synthetic-password-must-not-leak'));
        $this->assertSame(1, Artisan::call('benefit:notifications'));
        $output = Artisan::output();
        $this->assertStringContainsString('notifications.failed', $output);
        $this->assertStringContainsString('generation_failed', $output);
        $this->assertStringNotContainsString('synthetic-password-must-not-leak', $output);
    }
}
