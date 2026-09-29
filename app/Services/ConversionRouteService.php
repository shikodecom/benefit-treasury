<?php

namespace App\Services;

use App\Models\ConversionRouteTemplate;
use App\Models\ConversionRouteTemplateStep;
use App\Models\ConversionRule;
use App\Models\ConversionRuleGroup;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversionRouteService
{
    public function __construct(private readonly ConversionRuleService $rules) {}

    public function createRoute(array $data, array $steps): ConversionRouteTemplate
    {
        return DB::transaction(function () use ($data, $steps): ConversionRouteTemplate {
            if ($steps === []) {
                throw ValidationException::withMessages(['steps' => '交換ルールを1件以上選択してください。']);
            }
            $route = new ConversionRouteTemplate;
            $route->name = $data['name'];
            $route->target_program_id = $data['target_program_id'] ?? null;
            $route->description = $data['description'] ?? null;
            $route->active = $data['active'] ?? true;
            $route->save();
            foreach ($steps as $step) {
                $this->addStep($route, $step['rule_group_id'], $step['preferred_rule_id'] ?? null);
            }
            $this->validateContinuity($route);

            return $route;
        });
    }

    public function addStep(ConversionRouteTemplate $route, int $ruleGroupId, ?int $preferredRuleId = null): ConversionRouteTemplateStep
    {
        return DB::transaction(function () use ($route, $ruleGroupId, $preferredRuleId): ConversionRouteTemplateStep {
            $route = ConversionRouteTemplate::query()->lockForUpdate()->findOrFail($route->id);
            $group = ConversionRuleGroup::query()->findOrFail($ruleGroupId);
            $preferred = $preferredRuleId ? ConversionRule::query()->findOrFail($preferredRuleId) : null;
            if ($preferred && $preferred->rule_group_id !== $group->id) {
                throw ValidationException::withMessages(['preferred_rule_id' => '優先ルールが交換関係と一致しません。']);
            }
            $existing = $route->steps()->with('ruleGroup')->orderBy('sequence_no')->get();
            $last = $existing->last();
            if ($last && $last->ruleGroup->to_program_id !== $group->from_program_id) {
                throw ValidationException::withMessages(['rule_group_id' => '前の交換先から続くルールを選択してください。']);
            }
            $visited = $existing->flatMap(fn ($step) => [$step->ruleGroup->from_program_id, $step->ruleGroup->to_program_id])->unique()->all();
            if ($group->from_program_id === $group->to_program_id || in_array($group->to_program_id, $visited, true)) {
                throw ValidationException::withMessages(['rule_group_id' => '循環する交換ルートは登録できません。']);
            }
            $step = new ConversionRouteTemplateStep;
            $step->route_template_id = $route->id;
            $step->sequence_no = ($last?->sequence_no ?? 0) + 1;
            $step->rule_group_id = $group->id;
            $step->preferred_rule_id = $preferred?->id;
            $step->save();
            $this->validateContinuity($route, false);

            return $step;
        });
    }

    public function validateContinuity(ConversionRouteTemplate $route, bool $checkTarget = true): void
    {
        $steps = $route->steps()->with('ruleGroup')->orderBy('sequence_no')->get();
        $previousTo = null;
        $visited = [];
        foreach ($steps as $index => $step) {
            $from = $step->ruleGroup->from_program_id;
            $to = $step->ruleGroup->to_program_id;
            if ($step->sequence_no !== $index + 1 || $previousTo !== null && $previousTo !== $from
                || in_array($to, $visited, true) || $from === $to) {
                throw ValidationException::withMessages(['steps' => 'ルートの連続性または循環を確認してください。']);
            }
            $visited[] = $from;
            $previousTo = $to;
        }
        if ($checkTarget && $route->target_program_id && $previousTo && $route->target_program_id !== $previousTo) {
            throw ValidationException::withMessages(['target_program_id' => '最終制度はルートの到着先と一致させてください。']);
        }
    }

    public function availableRulesForStep(ConversionRouteTemplateStep $step, ?string $date = null): Collection
    {
        return $this->rules->currentRules($step->ruleGroup->from_program_id, $step->ruleGroup->to_program_id, $date)
            ->filter(fn ($rule) => $rule->rule_group_id === $step->rule_group_id)->values();
    }

    public function resolveCurrentRule(ConversionRouteTemplateStep $step, ?string $date = null): ?ConversionRule
    {
        $available = $this->availableRulesForStep($step, $date);
        $preferred = $step->preferred_rule_id ? $available->firstWhere('id', $step->preferred_rule_id) : null;
        if ($preferred) {
            return $preferred;
        }
        $normal = $available->where('campaign_only', false);

        return $normal->count() === 1 ? $normal->first() : ($available->count() === 1 ? $available->first() : null);
    }

    public function canUse(ConversionRouteTemplate $route, ?string $date = null): bool
    {
        if (! $route->active || ! $route->steps()->exists()) {
            return false;
        }

        return $route->steps()->with(['ruleGroup', 'preferredRule'])->orderBy('sequence_no')->get()
            ->every(fn ($step) => $this->resolveCurrentRule($step, $date) !== null);
    }

    public function simulate(ConversionRouteTemplate $route, string $sourceQuantity, ?string $date = null): array
    {
        $this->validateContinuity($route);
        try {
            $available = BigDecimal::of($sourceQuantity);
            if ($available->isLessThanOrEqualTo(0) || $available->getScale() > 4) {
                throw new \InvalidArgumentException;
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['quantity' => '数量は0より大きい数値（小数4桁以内）で入力してください。']);
        }
        $rows = [];
        $days = 0;
        $steps = $route->steps()->with(['ruleGroup.fromProgram', 'ruleGroup.toProgram', 'preferredRule'])->orderBy('sequence_no')->get();
        foreach ($steps as $step) {
            $rule = $this->resolveCurrentRule($step, $date);
            if (! $rule || $rule->fee_quantity !== null || filled($rule->conditions_text)) {
                $rows[] = ['step' => $step, 'rule' => $rule, 'available' => (string) $available,
                    'used' => null, 'remaining' => null, 'received' => null,
                    'reason' => ! $rule ? '現在有効なルールを確定できません。' : '手数料または複雑な条件があるため概算できません。'];
                break;
            }
            $used = $available;
            if ($rule->maximum_from_quantity !== null && $used->isGreaterThan($rule->maximum_from_quantity)) {
                $used = BigDecimal::of($rule->maximum_from_quantity);
            }
            if ($rule->increment_from_quantity !== null) {
                $increment = BigDecimal::of($rule->increment_from_quantity);
                $used = $used->dividedBy($increment, 0, RoundingMode::DOWN)->multipliedBy($increment);
            }
            try {
                $received = $this->rules->calculateDestination($rule, (string) $used);
            } catch (ValidationException) {
                $rows[] = ['step' => $step, 'rule' => $rule, 'available' => (string) $available,
                    'used' => null, 'remaining' => null, 'received' => null, 'reason' => '最低交換量または交換単位に達していません。'];
                break;
            }
            $rows[] = ['step' => $step, 'rule' => $rule, 'available' => (string) $available,
                'used' => (string) $used, 'remaining' => (string) $available->minus($used), 'received' => $received, 'reason' => null];
            $available = BigDecimal::of($received);
            if ($rule->estimated_days_max === null) {
                $days = null;
            } elseif ($days !== null) {
                $days += (int) $rule->estimated_days_max;
            }
        }

        return ['rows' => $rows, 'completed' => count($rows) === $steps->count() && $rows !== [] && end($rows)['received'] !== null,
            'destination_quantity' => $rows && end($rows)['received'] !== null ? (string) $available : null,
            'estimated_days_max' => $days];
    }
}
