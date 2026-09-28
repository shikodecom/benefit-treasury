<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\BenefitTransferStep;
use App\Models\ConversionRule;
use App\Models\ConversionRuleGroup;
use App\Models\User;
use App\Services\BenefitLotService;
use App\Services\BenefitListingService;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use App\Services\BenefitTransferService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_planned_start_complete_and_actual_difference_are_history_based(): void
    {
        [$from, $to] = $this->accounts();
        $service = app(BenefitTransferService::class);
        $read = app(BenefitReadService::class);
        $group = $service->createGroup(['name' => 'VからJQ', 'target_program_id' => $to->program_id]);
        $step = $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $to->id,
            'source_quantity' => '1000', 'expected_destination_quantity' => '1000',
            'planning_equivalent_program_id' => $to->program_id, 'planning_equivalent_quantity' => '700']);
        $this->assertSame(0, $step->transactions()->count());
        $this->assertSame('2000.0000', $read->accountBalance($from->id));
        $service->startStep($step, '2026-09-29');
        $this->assertSame('processing', $step->fresh()->status);
        $this->assertSame('processing', $group->fresh()->status);
        $this->assertSame('1000.0000', $read->accountBalance($from->id));
        $this->assertSame('0.0000', $read->accountBalance($to->id));
        $this->assertSame('700.0000', $service->pendingEquivalent($to->program_id));
        try {
            $service->startStep($step, '2026-09-29');
            $this->fail('A step cannot start twice');
        } catch (ValidationException) {
            $this->assertSame(1, $step->transactions()->where('transaction_type', 'transfer_out')->count());
        }
        $service->completeStep($step, '998', '2026-09-30');
        $this->assertSame('completed', $group->fresh()->status);
        $this->assertSame('998.0000', $read->accountBalance($to->id));
        $this->assertEquals(998, $step->fresh()->actual_destination_quantity);
        $this->assertEquals(1000, $step->fresh()->expected_destination_quantity);
        $this->assertSame(1, $step->transactions()->where('transaction_type', 'transfer_in')->count());
        $this->expectException(ValidationException::class);
        $service->completeStep($step, '998', '2026-09-30');
    }

    public function test_cancel_refund_error_and_overdue_do_not_invent_balance(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 00:30:00', 'Asia/Tokyo'));
        [$from, $to] = $this->accounts();
        $service = app(BenefitTransferService::class);
        $group = $service->createGroup([]);
        $planned = $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $to->id, 'source_quantity' => '100']);
        $service->cancelPlannedStep($planned);
        $this->assertSame(0, $planned->transactions()->count());
        $this->assertSame('cancelled', $group->fresh()->status);

        $group = $service->createGroup([]);
        $step = $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $to->id,
            'source_quantity' => '500', 'expected_complete_at' => '2026-09-28']);
        $service->startStep($step, '2026-09-27');
        $this->assertSame(1, $service->overdueSteps()->count());
        $service->markError($step, '外部申請に失敗');
        $this->assertSame('1500.0000', app(BenefitReadService::class)->accountBalance($from->id));
        $this->assertSame(0, $service->overdueSteps()->count());
        $service->cancelProcessingStepWithReversal($step, '返還済みを確認');
        $this->assertSame('2000.0000', app(BenefitReadService::class)->accountBalance($from->id));
        $this->assertSame('cancelled', $group->fresh()->status);
        $this->expectException(ValidationException::class);
        $service->cancelProcessingStepWithReversal($step, '再度取消');
    }

    public function test_multi_step_accepts_pooled_intermediate_balance(): void
    {
        [$from, $middle, $to] = $this->accounts(3);
        $service = app(BenefitTransferService::class);
        $group = $service->createGroup(['target_program_id' => $to->program_id, 'target_quantity' => '700']);
        $first = $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $middle->id,
            'source_quantity' => '1000', 'expected_destination_quantity' => '1000']);
        $second = $service->addStep($group, ['from_account_id' => $middle->id, 'to_account_id' => $to->id,
            'source_quantity' => '1500', 'expected_destination_quantity' => '700']);
        try {
            $service->startStep($second, '2026-09-29');
            $this->fail('Previous step must complete first');
        } catch (ValidationException) {
            $this->assertSame('planned', $second->fresh()->status);
        }
        $service->startStep($first, '2026-09-29');
        $service->completeStep($first, '1000', '2026-09-30');
        $this->assertSame('processing', $group->fresh()->status);
        $service->startStep($second, '2026-10-01');
        $this->assertSame('1500.0000', app(BenefitReadService::class)->accountBalance($middle->id));
        $service->completeStep($second, '700', '2026-10-02');
        $this->assertSame('completed', $group->fresh()->status);
        $this->assertSame('700.0000', app(BenefitReadService::class)->accountBalance($to->id));
    }

    public function test_conversion_rule_validates_increment_and_snapshots_expected_quantity(): void
    {
        [$from, $to] = $this->accounts();
        $ruleGroup = new ConversionRuleGroup;
        $ruleGroup->from_program_id = $from->program_id;
        $ruleGroup->to_program_id = $to->program_id;
        $ruleGroup->save();
        $rule = new ConversionRule;
        $rule->rule_group_id = $ruleGroup->id;
        $rule->version_no = 1;
        $rule->from_program_id = $from->program_id;
        $rule->to_program_id = $to->program_id;
        $rule->from_quantity = '100';
        $rule->to_quantity = '70';
        $rule->minimum_from_quantity = '500';
        $rule->increment_from_quantity = '100';
        $rule->estimated_days_max = 2;
        $rule->active = true;
        $rule->save();
        $service = app(BenefitTransferService::class);
        $group = $service->createGroup([]);
        try {
            $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $to->id,
                'source_quantity' => '550', 'conversion_rule_id' => $rule->id]);
            $this->fail('Increment must be enforced');
        } catch (ValidationException) {
            $this->assertSame(0, $group->steps()->count());
        }
        $step = $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $to->id,
            'source_quantity' => '600', 'conversion_rule_id' => $rule->id]);
        $this->assertEquals(420, $step->expected_destination_quantity);
        $rule->to_quantity = '90';
        $rule->save();
        $this->assertEquals(420, $step->fresh()->expected_destination_quantity);
        $service->startStep($step, '2026-09-29');
        $this->assertSame('2026-10-01', $step->fresh()->expected_complete_at);
    }

    public function test_transfer_pages_require_login_and_show_overdue(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 00:30:00', 'Asia/Tokyo'));
        [$from, $to] = $this->accounts();
        $service = app(BenefitTransferService::class);
        $group = $service->createGroup(['name' => 'ANAへの移行']);
        $step = $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $to->id,
            'source_quantity' => '100', 'expected_complete_at' => '2026-09-28']);
        $this->get(route('transfers.index'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get(route('transfers.create'))->assertOk();
        $this->get(route('transfers.steps.create', $group))->assertOk();
        $service->startStep($step, '2026-09-27');
        $this->get(route('transfers.index', ['status' => 'overdue']))->assertOk()->assertSee('ANAへの移行');
        $this->get(route('transfers.show', $group))->assertOk()->assertSee('予定日超過');
        $this->get(route('transfers.steps.complete.form', $step))->assertOk()->assertSee('着弾を確認');
        $this->get(route('dashboard.index'))->assertOk()->assertSee('着弾確認が必要な移行');
        $this->get(route('settings.accounts.show', $from))->assertOk()->assertSee('最近の移行');
    }

    public function test_start_rolls_back_when_listed_lot_reservation_makes_balance_insufficient(): void
    {
        [$from, $to] = $this->accounts();
        $lot = app(BenefitLotService::class)->acquire($from, [
            'display_name' => '期限付きポイント', 'quantity' => '4', 'expires_at' => '2026-10-31',
            'action_policy' => 'sell_now',
        ]);
        $listingService = app(BenefitListingService::class);
        $listing = $listingService->createDraft(['marketplace' => 'test'], [$lot->id => '3']);
        $listingService->publish($listing);
        $service = app(BenefitTransferService::class);
        $group = $service->createGroup([]);
        $step = $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $to->id, 'source_quantity' => '2002']);
        try {
            $service->startStep($step, '2026-09-29');
            $this->fail('Listed quantity cannot be transferred');
        } catch (ValidationException) {
            $this->assertSame('planned', $step->fresh()->status);
            $this->assertSame(0, $step->transactions()->count());
            $this->assertSame('2004.0000', app(BenefitReadService::class)->accountBalance($from->id));
        }
        $listingService->cancel($listing);
        $service->startStep($step, '2026-09-29');
        $this->assertSame(2, $step->transactions()->where('transaction_type', 'transfer_out')->count());
        $this->assertSame('2.0000', app(BenefitReadService::class)->accountBalance($from->id));
    }

    public function test_transfer_forms_create_start_and_complete_through_routes(): void
    {
        [$from, $to] = $this->accounts();
        $this->actingAs(User::factory()->create());
        $this->post(route('transfers.store'), ['name' => 'JQへ', 'target_quantity' => '100'])
            ->assertSessionHasErrors('target_program_id');
        $this->post(route('transfers.store'), ['name' => 'JQへ', 'target_program_id' => $to->program_id,
            'target_quantity' => '100'])->assertRedirect();
        $group = \App\Models\BenefitTransferGroup::query()->firstOrFail();
        $this->post(route('transfers.steps.store', $group), [
            'from_account_id' => $from->id, 'to_account_id' => $to->id,
            'source_quantity' => '100', 'expected_destination_quantity' => '100',
        ])->assertRedirect(route('transfers.show', $group));
        $step = BenefitTransferStep::query()->firstOrFail();
        $this->post(route('transfers.steps.start', $step), ['started_at' => '2026-09-29'])->assertRedirect();
        $this->post(route('transfers.steps.complete', $step), [
            'completed_at' => '2026-09-30', 'actual_destination_quantity' => '99',
        ])->assertRedirect();
        $this->assertSame('completed', $step->fresh()->status);
        $this->assertSame('99.0000', app(BenefitReadService::class)->accountBalance($to->id));
    }

    private function accounts(int $count = 2): array
    {
        $accounts = [];
        foreach (range(1, $count) as $index) {
            $program = new BenefitProgram;
            $program->name = '制度'.$index;
            $program->category = 'point';
            $program->unit_name = 'pt';
            $program->active = true;
            $program->save();
            $account = new BenefitAccount;
            $account->program_id = $program->id;
            $account->active = true;
            $account->save();
            $accounts[] = $account;
        }
        app(BenefitTransactionService::class)->createOpeningBalance($accounts[0], '2026-09-28', '2000', null);
        if ($count > 2) app(BenefitTransactionService::class)->createOpeningBalance($accounts[1], '2026-09-28', '2000', null);

        return $accounts;
    }
}
