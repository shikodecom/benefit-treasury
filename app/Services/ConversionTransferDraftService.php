<?php

namespace App\Services;

use App\Models\BenefitAccount;
use App\Models\BenefitTransferGroup;
use App\Models\ConversionRouteTemplate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversionTransferDraftService
{
    public function __construct(private readonly ConversionRouteService $routes,
        private readonly ConversionRuleService $rules, private readonly BenefitTransferService $transfers) {}

    public function preview(ConversionRouteTemplate $route, array $input): array
    {
        if (! $route->active) {
            throw ValidationException::withMessages(['route' => '無効なルートです。']);
        }
        $this->routes->validateContinuity($route);
        $steps = $route->steps()->with(['ruleGroup.fromProgram', 'ruleGroup.toProgram'])->orderBy('sequence_no')->get();
        if ($steps->isEmpty()) {
            throw ValidationException::withMessages(['route' => 'ステップがありません。']);
        }
        $source = BenefitAccount::query()->with('program')->findOrFail($input['source_account_id']);
        if (! $source->active || ! $source->program->active || $source->program_id !== $steps->first()->ruleGroup->from_program_id) {
            throw ValidationException::withMessages(['source_account_id' => 'ルートの移行元に一致する有効な口座を選択してください。']);
        }
        $quantity = (string) $input['source_quantity'];
        $account = $source;
        $date = CarbonImmutable::parse($input['started_at'] ?? now('Asia/Tokyo')->toDateString(), 'Asia/Tokyo');
        $rows = [];
        foreach ($steps as $index => $step) {
            $candidates = $this->routes->availableRulesForStep($step);
            $selectedId = $input['steps'][$index]['rule_id'] ?? null;
            $rule = $selectedId ? $candidates->firstWhere('id', (int) $selectedId) : null;
            if (! $rule && $selectedId) {
                throw ValidationException::withMessages(["steps.$index.rule_id" => '選択したルールは現在有効ではありません。']);
            }
            if (! $rule && $candidates->count() === 1) {
                $rule = $candidates->first();
            }
            if (! $rule && $candidates->count() > 1) {
                $preferred = $step->preferred_rule_id ? $candidates->firstWhere('id', $step->preferred_rule_id) : null;
                if ($candidates->where('campaign_only', true)->isEmpty()) {
                    $rule = $preferred;
                }
            }
            if (! $rule) {
                throw ValidationException::withMessages(["steps.$index.rule_id" => '有効なルールを選択してください。']);
            }
            if ($rule->fee_quantity !== null || filled($rule->conditions_text)) {
                throw ValidationException::withMessages(["steps.$index.rule_id" => '手数料または複雑な条件を含むルールは自動計算できません。手動で移行を作成してください。']);
            }
            $to = BenefitAccount::query()->with('program')->findOrFail($input['steps'][$index]['to_account_id'] ?? 0);
            if (! $to->active || ! $to->program->active || $to->program_id !== $step->ruleGroup->to_program_id
                || $to->household_member_id !== $source->household_member_id) {
                throw ValidationException::withMessages(["steps.$index.to_account_id" => '同じ名義の有効な移行先口座を選択してください。']);
            }
            $used = $index === 0 ? $quantity : (string) ($input['steps'][$index]['source_quantity'] ?? $quantity);
            $this->rules->validateQuantity($rule, $used);
            $received = $this->rules->calculateDestination($rule, $used);
            $exact = BigDecimal::of($used)->multipliedBy($rule->to_quantity)->dividedBy($rule->from_quantity, 8, RoundingMode::DOWN);
            $warnings = [];
            if (! $exact->isEqualTo($received)) {
                $warnings[] = '小数4桁を超える端数は切り捨てられます。';
            }
            if ($index > 0 && ! BigDecimal::of($used)->isEqualTo($quantity)) {
                $warnings[] = '前ステップの受取予定数量と投入数量が異なります。中間口座の残高を確認してください。';
            }
            $expectedDate = $input['steps'][$index]['expected_complete_at'] ?? null;
            if (! $expectedDate && $rule->estimated_days_max !== null) {
                $expectedDate = $date->addDays($rule->estimated_days_max)->toDateString();
            }
            $rows[] = compact('step', 'rule', 'account', 'to', 'used', 'received', 'expectedDate', 'candidates', 'warnings');
            $account = $to;
            $quantity = $received;
            if ($expectedDate) {
                $date = CarbonImmutable::parse($expectedDate, 'Asia/Tokyo');
            }
        }

        return $rows;
    }

    public function create(ConversionRouteTemplate $route, array $input): BenefitTransferGroup
    {
        return DB::transaction(function () use ($route, $input): BenefitTransferGroup {
            $rows = $this->preview($route, $input);
            $group = $this->transfers->createGroup([
                'name' => $route->name, 'household_member_id' => $rows[0]['account']->household_member_id,
                'purpose' => $input['purpose'] ?? null,
                'target_program_id' => $rows[count($rows) - 1]['to']->program_id,
                'target_quantity' => $rows[count($rows) - 1]['received'],
                'expected_complete_at' => $rows[count($rows) - 1]['expectedDate'],
            ]);
            foreach ($rows as $row) {
                $this->transfers->addStep($group, [
                    'from_account_id' => $row['account']->id, 'to_account_id' => $row['to']->id,
                    'source_quantity' => $row['used'], 'conversion_rule_id' => $row['rule']->id,
                    'expected_complete_at' => $row['expectedDate'],
                ]);
            }

            return $group;
        });
    }
}
