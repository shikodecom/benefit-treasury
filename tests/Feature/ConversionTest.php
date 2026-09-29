<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\ConversionRouteTemplate;
use App\Models\ConversionRule;
use App\Models\User;
use App\Services\BenefitTransactionService;
use App\Services\BenefitTransferService;
use App\Services\ConversionRouteService;
use App\Services\ConversionRuleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_versions_protect_history_and_validate_exchange_quantities(): void
    {
        [$from, $to] = $this->programs(2);
        $rules = app(ConversionRuleService::class);
        $group = $rules->createRuleGroup(['from_program_id' => $from->id, 'to_program_id' => $to->id]);
        $rule = $rules->createRuleVersion($group, [
            'from_quantity' => '100', 'to_quantity' => '70', 'minimum_from_quantity' => '500',
            'increment_from_quantity' => '100', 'active' => true,
        ]);
        $this->assertSame('700.0000', $rules->calculateDestination($rule, '1000'));
        foreach (['400', '550'] as $bad) {
            try {
                $rules->calculateDestination($rule, $bad);
                $this->fail('Minimum and increment must be enforced');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $fromAccount = $this->account($from, '1000');
        $toAccount = $this->account($to, '0');
        $transfers = app(BenefitTransferService::class);
        $transferGroup = $transfers->createGroup([]);
        $step = $transfers->addStep($transferGroup, ['from_account_id' => $fromAccount->id,
            'to_account_id' => $toAccount->id, 'source_quantity' => '1000', 'conversion_rule_id' => $rule->id]);
        $this->assertTrue($rules->hasBeenUsed($rule));
        try {
            $rules->updateNonEconomicFields($rule, ['to_quantity' => '90']);
            $this->fail('Economic edit must be rejected');
        } catch (ValidationException) {
            $this->assertEquals(70, $rule->fresh()->to_quantity);
        }
        $new = $rules->createRuleVersion($group, ['to_quantity' => '90', 'minimum_from_quantity' => null,
            'increment_from_quantity' => null], $rule, true);
        $this->assertSame(2, $new->version_no);
        $this->assertFalse((bool) $rule->fresh()->active);
        $this->assertEquals(700, $step->fresh()->expected_destination_quantity);
        $this->assertNull($new->minimum_from_quantity);
        $this->assertSame('900.0000', $rules->calculateDestination($new, '1000'));
    }

    public function test_current_rules_use_jst_and_keep_normal_and_campaign_versions(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 00:30:00', 'Asia/Tokyo'));
        [$from, $to] = $this->programs(2);
        $rules = app(ConversionRuleService::class);
        $group = $rules->createRuleGroup(['from_program_id' => $from->id, 'to_program_id' => $to->id]);
        $normal = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '50', 'active' => true]);
        $campaign = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '62.5',
            'campaign_only' => true, 'campaign_name' => '秋', 'valid_from' => '2026-09-29', 'valid_to' => '2026-09-29', 'active' => true]);
        $expired = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '55',
            'valid_to' => '2026-09-28', 'active' => true]);
        $this->assertSame([$campaign->id, $normal->id], $rules->currentRules($from->id, $to->id)->pluck('id')->all());
        $this->assertTrue($rules->isCurrentlyValid($campaign));
        $this->assertFalse($rules->isCurrentlyValid($expired));
        $this->assertGreaterThan(0, $rules->overlapCount($campaign));
        $this->travelTo(Carbon::parse('2026-09-30 00:01:00', 'Asia/Tokyo'));
        $this->assertFalse($rules->isCurrentlyValid($campaign));
    }

    public function test_route_continuity_loop_simulation_and_invalid_preferred_rule(): void
    {
        [$a, $b, $c] = $this->programs(3);
        $rules = app(ConversionRuleService::class);
        $ab = $rules->createRuleGroup(['from_program_id' => $a->id, 'to_program_id' => $b->id]);
        $bc = $rules->createRuleGroup(['from_program_id' => $b->id, 'to_program_id' => $c->id]);
        $ba = $rules->createRuleGroup(['from_program_id' => $b->id, 'to_program_id' => $a->id]);
        $first = $rules->createRuleVersion($ab, ['from_quantity' => '100', 'to_quantity' => '105', 'active' => true,
            'estimated_days_max' => 2]);
        $second = $rules->createRuleVersion($bc, ['from_quantity' => '100', 'to_quantity' => '70',
            'increment_from_quantity' => '100', 'active' => true, 'estimated_days_max' => 3]);
        $routes = app(ConversionRouteService::class);
        $route = $routes->createRoute(['name' => 'AからC', 'target_program_id' => $c->id], [
            ['rule_group_id' => $ab->id, 'preferred_rule_id' => $first->id],
            ['rule_group_id' => $bc->id, 'preferred_rule_id' => $second->id],
        ]);
        $result = $routes->simulate($route, '1000');
        $this->assertTrue($result['completed']);
        $this->assertSame('700.0000', $result['destination_quantity']);
        $this->assertSame('50.0000', $result['rows'][1]['remaining']);
        $this->assertSame(5, $result['estimated_days_max']);
        $this->assertTrue($routes->canUse($route));
        try {
            $routes->createRoute(['name' => '循環'], [['rule_group_id' => $ab->id], ['rule_group_id' => $ba->id]]);
            $this->fail('Loop must be rejected');
        } catch (ValidationException) {
            $this->assertSame(1, ConversionRouteTemplate::query()->count());
        }
        try {
            $routes->createRoute(['name' => '不連続'], [['rule_group_id' => $bc->id], ['rule_group_id' => $ab->id]]);
            $this->fail('Discontinuity must be rejected');
        } catch (ValidationException) {
            $this->assertSame(1, ConversionRouteTemplate::query()->count());
        }
        $rules->deactivate($second);
        $this->assertFalse($routes->canUse($route));
        $this->assertFalse($routes->simulate($route, '1000')['completed']);
        $replacement = $rules->createRuleVersion($bc, ['to_quantity' => '80', 'active' => true], $second);
        $this->assertTrue($routes->canUse($route));
        $this->assertSame($replacement->id, $routes->resolveCurrentRule($route->steps()->orderByDesc('sequence_no')->first())->id);
        $this->assertSame('800.0000', $routes->simulate($route, '1000')['destination_quantity']);
    }

    public function test_fee_or_complex_condition_stops_estimate_and_http_pages_render(): void
    {
        [$from, $to] = $this->programs(2);
        $rules = app(ConversionRuleService::class);
        $group = $rules->createRuleGroup(['from_program_id' => $from->id, 'to_program_id' => $to->id]);
        $rule = $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '70',
            'fee_quantity' => '5', 'fee_program_id' => $from->id, 'active' => true]);
        $route = app(ConversionRouteService::class)->createRoute(['name' => '手数料あり'], [['rule_group_id' => $group->id]]);
        $this->assertFalse(app(ConversionRouteService::class)->simulate($route, '1000')['completed']);
        $this->get(route('conversion.rules.index'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get(route('conversion.rules.create'))->assertOk();
        $this->get(route('conversion.rules.index'))->assertOk()->assertSee($from->name);
        $this->get(route('conversion.rules.show', $rule))->assertOk()->assertSee('手数料');
        $this->get(route('conversion.routes.create'))->assertOk();
        $this->get(route('conversion.routes.show', ['route' => $route, 'quantity' => '1000']))->assertOk()->assertSee('条件により変動');
    }

    public function test_rule_and_route_can_be_created_from_forms(): void
    {
        [$from, $to] = $this->programs(2);
        $this->actingAs(User::factory()->create());
        $this->post(route('conversion.rules.store'), [
            'from_program_id' => $from->id, 'to_program_id' => $to->id,
            'from_quantity' => '100', 'to_quantity' => '70',
            'official_url' => 'javascript:alert(1)', 'active' => '1',
        ])->assertSessionHasErrors('official_url');
        $this->post(route('conversion.rules.store'), [
            'from_program_id' => $from->id, 'to_program_id' => $to->id,
            'from_quantity' => '100', 'to_quantity' => '70', 'active' => '1',
        ])->assertRedirect();
        $rule = ConversionRule::query()->firstOrFail();
        $this->post(route('conversion.routes.store'), [
            'name' => '通常ルート', 'target_program_id' => $to->id, 'active' => '1',
            'steps' => [['rule_group_id' => $rule->rule_group_id, 'preferred_rule_id' => $rule->id]],
        ])->assertRedirect();
        $route = ConversionRouteTemplate::query()->firstOrFail();
        $this->get(route('conversion.routes.show', ['route' => $route, 'quantity' => '1000']))
            ->assertOk()->assertSee('700.0000');
        $this->post(route('conversion.rules.versions.store', $rule), [
            'from_quantity' => '100', 'to_quantity' => '80', 'active' => '1',
        ])->assertRedirect();
        $this->assertSame(2, ConversionRule::query()->count());
    }

    private function programs(int $count): array
    {
        $programs = [];
        foreach (range(1, $count) as $index) {
            $program = new BenefitProgram;
            $program->name = '制度'.$index;
            $program->category = 'point';
            $program->unit_name = 'pt';
            $program->active = true;
            $program->save();
            $programs[] = $program;
        }

        return $programs;
    }

    private function account(BenefitProgram $program, string $opening): BenefitAccount
    {
        $account = new BenefitAccount;
        $account->program_id = $program->id;
        $account->active = true;
        $account->save();
        if ($opening !== '0') {
            app(BenefitTransactionService::class)->createOpeningBalance($account, '2026-09-28', $opening, null);
        }

        return $account;
    }
}
