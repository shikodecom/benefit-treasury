<?php

namespace App\Services;

use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Models\BenefitTransaction;
use App\Models\BenefitTransferGroup;
use App\Models\BenefitTransferStep;
use App\Models\ConversionRule;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BenefitTransferService
{
    public function __construct(private readonly BenefitReadService $read, private readonly BenefitTransactionService $transactions,
        private readonly ConversionRuleService $conversionRules) {}

    public function createGroup(array $data): BenefitTransferGroup
    {
        $group = new BenefitTransferGroup;
        foreach (['name', 'household_member_id', 'purpose', 'target_program_id', 'target_quantity', 'expected_complete_at', 'memo'] as $field) {
            $group->{$field} = $data[$field] ?? null;
        }
        $group->status = 'planned';
        $group->save();

        return $group;
    }

    public function addStep(BenefitTransferGroup $group, array $data): BenefitTransferStep
    {
        return DB::transaction(function () use ($group, $data): BenefitTransferStep {
            $group = BenefitTransferGroup::query()->lockForUpdate()->findOrFail($group->id);
            if (in_array($group->status, ['cancelled', 'error', 'completed'], true)) {
                throw ValidationException::withMessages(['group' => 'この移行にはステップを追加できません。']);
            }
            $from = BenefitAccount::query()->with('program')->findOrFail($data['from_account_id']);
            $to = BenefitAccount::query()->with('program')->findOrFail($data['to_account_id']);
            if ($from->id === $to->id || $from->program_id === $to->program_id) {
                throw ValidationException::withMessages(['to_account_id' => '異なる制度の口座を選択してください。']);
            }
            if (! $from->active || ! $to->active || ! $from->program->active || ! $to->program->active) {
                throw ValidationException::withMessages(['from_account_id' => '有効な口座と制度を選択してください。']);
            }
            $last = $group->steps()->orderByDesc('sequence_no')->first();
            if ($last && $last->to_account_id !== $from->id) {
                throw ValidationException::withMessages(['from_account_id' => '前のステップの移行先口座から始めてください。']);
            }
            $quantity = $this->quantity((string) $data['source_quantity'], true);
            $rule = isset($data['conversion_rule_id']) ? ConversionRule::query()->findOrFail($data['conversion_rule_id']) : null;
            if ($rule) {
                $this->validateRule($rule, $from, $to);
                $expected = $this->calculateExpectedDestination($rule, $quantity);
            } else {
                $expected = isset($data['expected_destination_quantity']) && $data['expected_destination_quantity'] !== ''
                    ? $this->quantity((string) $data['expected_destination_quantity'], false) : null;
            }
            $equivalent = isset($data['planning_equivalent_quantity']) && $data['planning_equivalent_quantity'] !== ''
                ? $this->quantity((string) $data['planning_equivalent_quantity'], false) : null;
            if (($data['planning_equivalent_program_id'] ?? null) === null && $equivalent !== null
                || ($data['planning_equivalent_program_id'] ?? null) !== null && $equivalent === null) {
                throw ValidationException::withMessages(['planning_equivalent_quantity' => '換算先制度と換算数量を両方入力してください。']);
            }
            $step = new BenefitTransferStep;
            $step->transfer_group_id = $group->id;
            $step->sequence_no = ($last?->sequence_no ?? 0) + 1;
            $step->from_account_id = $from->id;
            $step->to_account_id = $to->id;
            $step->conversion_rule_id = $rule?->id;
            $step->source_quantity = $quantity;
            $step->expected_destination_quantity = $expected;
            $step->planning_equivalent_program_id = $data['planning_equivalent_program_id'] ?? null;
            $step->planning_equivalent_quantity = $equivalent;
            $step->expected_complete_at = $data['expected_complete_at'] ?? null;
            $step->external_reference_hint = $data['external_reference_hint'] ?? null;
            $step->memo = $data['memo'] ?? null;
            $step->status = 'planned';
            $step->save();
            $this->calculateGroupStatus($group);

            return $step;
        });
    }

    public function startStep(BenefitTransferStep $step, string $startedAt): void
    {
        DB::transaction(function () use ($step, $startedAt): void {
            $step = BenefitTransferStep::query()->lockForUpdate()->findOrFail($step->id);
            $this->requireStatus($step, ['planned']);
            $previous = $step->group->steps()->where('sequence_no', '<', $step->sequence_no)->orderByDesc('sequence_no')->first();
            if ($previous && $previous->status !== 'completed') {
                throw ValidationException::withMessages(['step' => '前のステップが着弾してから申請してください。']);
            }
            $source = BenefitAccount::query()->lockForUpdate()->findOrFail($step->from_account_id);
            if (! $source->active || ! $source->program()->where('active', true)->exists()) {
                throw ValidationException::withMessages(['from_account_id' => '移行元口座または制度が無効です。']);
            }
            $lots = BenefitLot::query()->where('account_id', $source->id)->whereNull('cancelled_at')
                ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();
            $needed = BigDecimal::of($step->source_quantity);
            foreach ($lots as $lot) {
                if ($needed->isLessThanOrEqualTo(0)) {
                    break;
                }
                $available = BigDecimal::of($this->read->lotAvailableQuantity($lot->id));
                if ($available->isLessThanOrEqualTo(0)) {
                    continue;
                }
                $take = $available->isLessThan($needed) ? $available : $needed;
                $this->writeTransaction($step, $source->id, $lot->id, 'transfer_out', (string) $take, $startedAt);
                $needed = $needed->minus($take);
            }
            $plain = BigDecimal::of($this->read->unallocatedBalance($source->id));
            if ($needed->isGreaterThan(0) && $plain->isGreaterThan(0)) {
                $take = $plain->isLessThan($needed) ? $plain : $needed;
                $this->writeTransaction($step, $source->id, null, 'transfer_out', (string) $take, $startedAt);
                $needed = $needed->minus($take);
            }
            if ($needed->isGreaterThan(0)) {
                throw ValidationException::withMessages(['source_quantity' => '移行元の利用可能残高が不足しています。']);
            }
            $step->started_at = $startedAt;
            if (! $step->expected_complete_at && $step->conversion_rule_id) {
                $days = $step->conversionRule->estimated_days_max;
                if ($days !== null) {
                    $step->expected_complete_at = Carbon::parse($startedAt, 'Asia/Tokyo')->addDays($days)->toDateString();
                }
            }
            $step->status = 'processing';
            $step->save();
            $this->calculateGroupStatus($step->group);
        });
    }

    public function completeStep(BenefitTransferStep $step, string $actualQuantity, string $completedAt): void
    {
        DB::transaction(function () use ($step, $actualQuantity, $completedAt): void {
            $step = BenefitTransferStep::query()->lockForUpdate()->findOrFail($step->id);
            $this->requireStatus($step, ['processing', 'error']);
            if ($step->started_at && $completedAt < $step->started_at) {
                throw ValidationException::withMessages(['completed_at' => '着弾日は申請日以降にしてください。']);
            }
            $actual = $this->quantity($actualQuantity, false);
            BenefitAccount::query()->lockForUpdate()->findOrFail($step->to_account_id);
            if (BigDecimal::of($actual)->isGreaterThan(0)) {
                $this->writeTransaction($step, $step->to_account_id, null, 'transfer_in', $actual, $completedAt);
            }
            $step->actual_destination_quantity = $actual;
            $step->completed_at = $completedAt;
            $step->status = 'completed';
            $step->save();
            $this->calculateGroupStatus($step->group);
        });
    }

    public function cancelPlannedStep(BenefitTransferStep $step): void
    {
        DB::transaction(function () use ($step): void {
            $step = BenefitTransferStep::query()->lockForUpdate()->findOrFail($step->id);
            $this->requireStatus($step, ['planned']);
            $step->status = 'cancelled';
            $step->save();
            $this->calculateGroupStatus($step->group);
        });
    }

    public function cancelProcessingStepWithReversal(BenefitTransferStep $step, string $memo): void
    {
        DB::transaction(function () use ($step, $memo): void {
            $step = BenefitTransferStep::query()->lockForUpdate()->findOrFail($step->id);
            $this->requireStatus($step, ['processing', 'error']);
            if (trim($memo) === '') {
                throw ValidationException::withMessages(['memo' => '返還の理由を入力してください。']);
            }
            $outs = $step->transactions()->where('transaction_type', 'transfer_out')->orderBy('account_id')->orderBy('lot_id')->get();
            if ($outs->isEmpty()) {
                throw ValidationException::withMessages(['step' => '移行元の取引が見つかりません。']);
            }
            foreach ($outs as $out) {
                $this->transactions->reverse($out, true);
            }
            $step->status = 'cancelled';
            $step->memo = trim(($step->memo ? $step->memo."\n" : '').$memo);
            $step->save();
            $this->calculateGroupStatus($step->group);
        });
    }

    public function markError(BenefitTransferStep $step, string $memo): void
    {
        DB::transaction(function () use ($step, $memo): void {
            $step = BenefitTransferStep::query()->lockForUpdate()->findOrFail($step->id);
            $this->requireStatus($step, ['processing']);
            if (trim($memo) === '') {
                throw ValidationException::withMessages(['memo' => 'エラーの理由を入力してください。']);
            }
            $step->status = 'error';
            $step->memo = trim(($step->memo ? $step->memo."\n" : '').$memo);
            $step->save();
            $this->calculateGroupStatus($step->group);
        });
    }

    public function updateExpectedDate(BenefitTransferStep $step, string $date, ?string $memo = null): void
    {
        DB::transaction(function () use ($step, $date, $memo): void {
            $step = BenefitTransferStep::query()->lockForUpdate()->findOrFail($step->id);
            $this->requireStatus($step, ['planned', 'processing']);
            $step->expected_complete_at = $date;
            if ($memo) {
                $step->memo = trim(($step->memo ? $step->memo."\n" : '').$memo);
            }
            $step->save();
            $this->calculateGroupStatus($step->group);
        });
    }

    public function calculateExpectedDestination(ConversionRule $rule, string $sourceQuantity): string
    {
        return $this->conversionRules->calculateDestination($rule, $sourceQuantity);
    }

    public function calculateGroupStatus(BenefitTransferGroup $group): string
    {
        $group = BenefitTransferGroup::query()->lockForUpdate()->findOrFail($group->id);
        $steps = $group->steps()->orderBy('sequence_no')->get();
        $statuses = $steps->pluck('status')->all();
        if (in_array('error', $statuses, true)) {
            $status = 'error';
        } elseif (in_array('processing', $statuses, true)) {
            $status = 'processing';
        } elseif ($statuses && count(array_unique($statuses)) === 1 && $statuses[0] === 'completed') {
            $status = 'completed';
        } elseif (in_array('cancelled', $statuses, true) && ! in_array('planned', $statuses, true)) {
            $status = 'cancelled';
        } elseif (in_array('completed', $statuses, true)) {
            $status = 'processing';
        } else {
            $status = 'planned';
        }
        $group->status = $status;
        $group->started_at = $steps->pluck('started_at')->filter()->sort()->first();
        $group->completed_at = $status === 'completed' ? $steps->pluck('completed_at')->filter()->sort()->last() : null;
        $group->save();

        return $status;
    }

    public function overdueSteps(): Collection
    {
        return BenefitTransferStep::query()->with(['group', 'fromAccount.program', 'toAccount.program'])
            ->where('status', 'processing')->whereDate('expected_complete_at', '<', now('Asia/Tokyo')->toDateString())
            ->orderBy('expected_complete_at')->get();
    }

    public function pendingEquivalent(?int $programId = null): string
    {
        $query = BenefitTransferStep::query()->where('status', 'processing')
            ->when($programId, fn ($query) => $query->where('planning_equivalent_program_id', $programId))
            ->whereNotNull('planning_equivalent_quantity');
        if ($programId === null && (clone $query)->distinct()->count('planning_equivalent_program_id') > 1) {
            throw new \InvalidArgumentException('programId is required when multiple equivalent units exist');
        }
        $steps = $query->pluck('planning_equivalent_quantity');

        return (string) $steps->reduce(fn (BigDecimal $sum, $value) => $sum->plus($value), BigDecimal::zero())->toScale(4);
    }

    private function validateRule(ConversionRule $rule, BenefitAccount $from, BenefitAccount $to): void
    {
        if (! $this->conversionRules->isCurrentlyValid($rule)
            || $rule->from_program_id !== $from->program_id || $rule->to_program_id !== $to->program_id) {
            throw ValidationException::withMessages(['conversion_rule_id' => '現在利用できる交換ルールを選択してください。']);
        }
    }

    private function writeTransaction(BenefitTransferStep $step, int $accountId, ?int $lotId, string $type, string $quantity, string $date): void
    {
        $transaction = new BenefitTransaction;
        $transaction->account_id = $accountId;
        $transaction->lot_id = $lotId;
        $transaction->transfer_step_id = $step->id;
        $transaction->transaction_type = $type;
        $transaction->direction = $type === 'transfer_out' ? 'out' : 'in';
        $transaction->quantity = $quantity;
        $transaction->transaction_at = $date;
        $transaction->source_type = 'manual';
        $transaction->save();
    }

    private function quantity(string $value, bool $positive): string
    {
        try {
            $quantity = BigDecimal::of($value);
            if (($positive ? $quantity->isLessThanOrEqualTo(0) : $quantity->isLessThan(0))
                || $quantity->getScale() > 4 || $quantity->isGreaterThanOrEqualTo('100000000000000')) {
                throw new \InvalidArgumentException;
            }

            return (string) $quantity->toScale(4);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['quantity' => '数量は0以上、小数4桁以内で入力してください。']);
        }
    }

    private function requireStatus(BenefitTransferStep $step, array $allowed): void
    {
        if (! in_array($step->status, $allowed, true)) {
            throw ValidationException::withMessages(['step' => 'このステップは現在の状態では操作できません。']);
        }
    }
}
