<?php

namespace App\Services;

use App\Models\BenefitProgram;
use App\Models\BenefitTransferStep;
use App\Models\ConversionRule;
use App\Models\ConversionRuleGroup;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversionRuleService
{
    public function createRuleGroup(array $data): ConversionRuleGroup
    {
        $from = BenefitProgram::query()->findOrFail($data['from_program_id']);
        $to = BenefitProgram::query()->findOrFail($data['to_program_id']);
        if ($from->id === $to->id) throw ValidationException::withMessages(['to_program_id' => '異なる制度を選択してください。']);
        $group = new ConversionRuleGroup;
        $group->from_program_id = $from->id;
        $group->to_program_id = $to->id;
        $group->name = $data['name'] ?? null;
        $group->notes = $data['notes'] ?? null;
        $group->save();

        return $group;
    }

    public function createRuleVersion(ConversionRuleGroup $group, array $data, ?ConversionRule $previous = null, bool $deactivatePrevious = false): ConversionRule
    {
        return DB::transaction(function () use ($group, $data, $previous, $deactivatePrevious): ConversionRule {
            $group = ConversionRuleGroup::query()->lockForUpdate()->findOrFail($group->id);
            if ($previous && $previous->rule_group_id !== $group->id) {
                throw ValidationException::withMessages(['rule' => '複製元の交換ルールが一致しません。']);
            }
            $base = $previous ? $previous->fresh() : null;
            $values = [];
            foreach ($this->economicFields() as $field) {
                $values[$field] = array_key_exists($field, $data) ? $data[$field] : $base?->{$field};
            }
            $values['campaign_only'] ??= false;
            foreach (['instructions', 'official_url', 'notes'] as $field) {
                $values[$field] = array_key_exists($field, $data) ? $data[$field] : $base?->{$field};
            }
            $values['active'] = $data['active'] ?? true;
            $this->validateValues($values);
            $rule = new ConversionRule;
            $rule->rule_group_id = $group->id;
            $rule->version_no = ((int) $group->rules()->max('version_no')) + 1;
            $rule->from_program_id = $group->from_program_id;
            $rule->to_program_id = $group->to_program_id;
            foreach ($values as $field => $value) $rule->{$field} = $value;
            $rule->save();
            if ($deactivatePrevious && $previous) {
                $previous->active = false;
                $previous->save();
            }

            return $rule;
        });
    }

    public function updateNonEconomicFields(ConversionRule $rule, array $data): ConversionRule
    {
        foreach (array_keys($data) as $field) {
            if (! in_array($field, ['instructions', 'official_url', 'notes'], true)) {
                throw ValidationException::withMessages(['rule' => '交換条件の変更は新しいバージョンとして登録してください。']);
            }
        }
        if (! empty($data['official_url']) && ! preg_match('~^https?://~i', $data['official_url'])) {
            throw ValidationException::withMessages(['official_url' => '公式 URL は http または https にしてください。']);
        }
        foreach ($data as $field => $value) $rule->{$field} = $value;
        $rule->save();

        return $rule;
    }

    public function deactivate(ConversionRule $rule): void
    {
        $rule->active = false;
        $rule->save();
    }

    public function currentRules(?int $fromProgramId = null, ?int $toProgramId = null, ?string $date = null): Collection
    {
        $date ??= now('Asia/Tokyo')->toDateString();

        return ConversionRule::query()->with(['fromProgram', 'toProgram', 'group'])
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->where(fn ($query) => $query->where('campaign_only', false)
                ->orWhere(fn ($query) => $query->whereNotNull('valid_from')->whereNotNull('valid_to')))
            ->when($fromProgramId, fn ($query) => $query->where('from_program_id', $fromProgramId))
            ->when($toProgramId, fn ($query) => $query->where('to_program_id', $toProgramId))
            ->orderBy('rule_group_id')->orderByDesc('version_no')->get();
    }

    public function isCurrentlyValid(ConversionRule $rule, ?string $date = null): bool
    {
        $date ??= now('Asia/Tokyo')->toDateString();

        return (bool) $rule->active
            && (! $rule->valid_from || $rule->valid_from <= $date)
            && (! $rule->valid_to || $rule->valid_to >= $date)
            && (! $rule->campaign_only || $rule->valid_from && $rule->valid_to);
    }

    public function hasBeenUsed(ConversionRule $rule): bool
    {
        return BenefitTransferStep::query()->where('conversion_rule_id', $rule->id)->exists();
    }

    public function validateQuantity(ConversionRule $rule, string $sourceQuantity): string
    {
        $source = $this->decimal($sourceQuantity, true, 'source_quantity');
        $minimum = $rule->minimum_from_quantity ? BigDecimal::of($rule->minimum_from_quantity) : null;
        $maximum = $rule->maximum_from_quantity ? BigDecimal::of($rule->maximum_from_quantity) : null;
        $increment = $rule->increment_from_quantity ? BigDecimal::of($rule->increment_from_quantity) : null;
        if ($minimum && $source->isLessThan($minimum)) throw ValidationException::withMessages(['source_quantity' => '交換の最低数量に達していません。']);
        if ($maximum && $source->isGreaterThan($maximum)) throw ValidationException::withMessages(['source_quantity' => '交換の最大数量を超えています。']);
        if ($increment && ! $source->remainder($increment)->isZero()) throw ValidationException::withMessages(['source_quantity' => '交換単位に合う数量を入力してください。']);

        return (string) $source->toScale(4);
    }

    public function calculateDestination(ConversionRule $rule, string $sourceQuantity): string
    {
        $source = BigDecimal::of($this->validateQuantity($rule, $sourceQuantity));

        return (string) $source->multipliedBy($rule->to_quantity)->dividedBy($rule->from_quantity, 4, RoundingMode::DOWN);
    }

    public function overlapCount(ConversionRule $rule): int
    {
        return ConversionRule::query()->where('rule_group_id', $rule->rule_group_id)->whereKeyNot($rule->id)
            ->where('active', true)
            ->where(fn ($query) => $rule->valid_to ? $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $rule->valid_to) : null)
            ->where(fn ($query) => $rule->valid_from ? $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $rule->valid_from) : null)
            ->count();
    }

    private function validateValues(array $values): void
    {
        $this->decimal((string) ($values['from_quantity'] ?? ''), true, 'from_quantity');
        $this->decimal((string) ($values['to_quantity'] ?? ''), false, 'to_quantity');
        foreach (['minimum_from_quantity', 'maximum_from_quantity', 'fee_quantity'] as $field) {
            if ($values[$field] !== null) $this->decimal((string) $values[$field], false, $field);
        }
        if ($values['increment_from_quantity'] !== null) $this->decimal((string) $values['increment_from_quantity'], true, 'increment_from_quantity');
        if ($values['minimum_from_quantity'] !== null && $values['maximum_from_quantity'] !== null
            && BigDecimal::of($values['maximum_from_quantity'])->isLessThan($values['minimum_from_quantity'])) {
            throw ValidationException::withMessages(['maximum_from_quantity' => '最大数量は最低数量以上にしてください。']);
        }
        if ($values['estimated_days_min'] !== null && $values['estimated_days_max'] !== null
            && $values['estimated_days_max'] < $values['estimated_days_min']) {
            throw ValidationException::withMessages(['estimated_days_max' => '最長日数は最短日数以上にしてください。']);
        }
        if ($values['valid_from'] && $values['valid_to'] && $values['valid_from'] > $values['valid_to']) {
            throw ValidationException::withMessages(['valid_to' => '終了日は開始日以降にしてください。']);
        }
        if (($values['fee_quantity'] === null) !== ($values['fee_program_id'] === null)) {
            throw ValidationException::withMessages(['fee_program_id' => '手数料数量と手数料制度を両方指定してください。']);
        }
        if (! empty($values['official_url']) && ! preg_match('~^https?://~i', $values['official_url'])) {
            throw ValidationException::withMessages(['official_url' => '公式 URL は http または https にしてください。']);
        }
    }

    private function decimal(string $value, bool $positive, string $field): BigDecimal
    {
        try {
            $decimal = BigDecimal::of($value);
            if (($positive ? $decimal->isLessThanOrEqualTo(0) : $decimal->isLessThan(0))
                || $decimal->getScale() > 4 || $decimal->isGreaterThanOrEqualTo('100000000000000')) throw new \InvalidArgumentException;

            return $decimal;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => '数量は0以上、小数4桁以内で入力してください。']);
        }
    }

    private function economicFields(): array
    {
        return [
            'from_quantity', 'to_quantity', 'minimum_from_quantity', 'increment_from_quantity',
            'maximum_from_quantity', 'fee_quantity', 'fee_program_id', 'estimated_days_min',
            'estimated_days_max', 'duration_text', 'campaign_only', 'campaign_name',
            'valid_from', 'valid_to', 'conditions_text',
        ];
    }
}
