<?php

namespace App\Services;

use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BenefitLotService
{
    public function __construct(
        private readonly BenefitReadService $readService,
        private readonly BenefitTransactionService $transactions,
    ) {}

    public function acquire(BenefitAccount $account, array $data): BenefitLot
    {
        return DB::transaction(function () use ($account, $data): BenefitLot {
            $lot = new BenefitLot;
            $lot->account_id = $account->id;
            $this->fill($lot, $data);
            $lot->source = 'manual';
            $lot->save();
            $this->transactions->earn(
                $account, (string) $data['quantity'],
                $data['acquired_at'] ?? now('Asia/Tokyo')->toDateString(),
                $data['display_name'] ?? null, null, $lot,
            );

            return $lot;
        });
    }

    public function update(BenefitLot $lot, array $data): BenefitLot
    {
        if (array_key_exists('account_id', $data) && (int) $data['account_id'] !== $lot->account_id) {
            throw ValidationException::withMessages(['account_id' => '取引済みロットの口座は変更できません。']);
        }
        $this->fill($lot, $data);
        $lot->save();

        return $lot;
    }

    public function remainingQuantity(BenefitLot $lot): string
    {
        return $this->readService->lotRemainingQuantity($lot->id);
    }

    public function listedQuantity(BenefitLot $lot): string
    {
        return $this->readService->lotListedQuantity($lot->id);
    }

    public function availableQuantity(BenefitLot $lot): string
    {
        return $this->readService->lotAvailableQuantity($lot->id);
    }

    public function use(BenefitLot $lot, string $quantity, string $date, ?string $purpose = null, ?int $value = null, ?string $memo = null): void
    {
        $this->transactions->use($lot->account, $quantity, $date, $purpose, $value, $lot, $memo);
    }

    public function allocateUse(BenefitAccount $account, string $quantity, string $date, array $allocations, string $unallocated, ?string $purpose = null, ?int $value = null, ?string $memo = null): void
    {
        DB::transaction(function () use ($account, $quantity, $date, $allocations, $unallocated, $purpose, $value, $memo): void {
            BenefitAccount::query()->lockForUpdate()->findOrFail($account->id);
            $lots = BenefitLot::query()->where('account_id', $account->id)->whereNull('cancelled_at')
                ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id')
                ->lockForUpdate()->get();

            $total = BigDecimal::zero();
            $parts = [];
            foreach ($lots as $lot) {
                $part = BigDecimal::of((string) ($allocations[$lot->id] ?? '0'));
                if ($part->isLessThan(0) || $part->getScale() > 4) {
                    throw ValidationException::withMessages(['allocations' => '配分数量が正しくありません。']);
                }
                if ($part->isGreaterThan($this->availableQuantity($lot))) {
                    throw ValidationException::withMessages(['allocations' => 'ロット #'.$lot->id.' の利用可能数を超えています。']);
                }
                if ($part->isGreaterThan(0)) {
                    $parts[] = [$lot, (string) $part];
                    $total = $total->plus($part);
                }
            }
            $plain = BigDecimal::of($unallocated);
            if ($plain->isLessThan(0) || $plain->getScale() > 4
                || $plain->isGreaterThan($this->readService->unallocatedBalance($account->id))) {
                throw ValidationException::withMessages(['unallocated_quantity' => 'ロットなし残高の範囲で入力してください。']);
            }
            if ($plain->isGreaterThan(0)) {
                $parts[] = [null, (string) $plain];
                $total = $total->plus($plain);
            }
            if ($total->isLessThanOrEqualTo(0) || ! $total->isEqualTo($quantity)) {
                throw ValidationException::withMessages(['quantity' => '利用数量と配分の合計を一致させてください。']);
            }

            $remainingValue = $value;
            foreach ($parts as $index => [$lot, $part]) {
                $partValue = null;
                if ($value !== null) {
                    $partValue = $index === array_key_last($parts) ? $remainingValue
                        : BigDecimal::of($value)->multipliedBy($part)->dividedBy($total, 0, RoundingMode::DOWN)->toInt();
                    $remainingValue -= $partValue;
                }
                $this->transactions->use($account, $part, $date, $purpose, $partValue, $lot, $memo);
            }
        });
    }

    public function expireRemaining(BenefitLot $lot, string $date): void
    {
        DB::transaction(function () use ($lot, $date): void {
            BenefitAccount::query()->lockForUpdate()->findOrFail($lot->account_id);
            $lockedLot = BenefitLot::query()->lockForUpdate()->findOrFail($lot->id);
            $remaining = $this->remainingQuantity($lockedLot);
            if (BigDecimal::of($remaining)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['lot' => '失効対象の残数がありません。']);
            }
            if (BigDecimal::of($this->listedQuantity($lockedLot))->isGreaterThan(0)) {
                throw ValidationException::withMessages(['lot' => '出品中の特典を先に取り下げてください。']);
            }
            $this->transactions->record($lockedLot->account, 'expire', $remaining, $date, null, null, '手動の失効処理', $lockedLot);
        });
    }

    public function cancel(BenefitLot $lot): void
    {
        DB::transaction(function () use ($lot): void {
            BenefitAccount::query()->lockForUpdate()->findOrFail($lot->account_id);
            $lockedLot = BenefitLot::query()->lockForUpdate()->findOrFail($lot->id);
            if ($lockedLot->cancelled_at !== null) {
                throw ValidationException::withMessages(['lot' => 'このロットはすでに登録取消済みです。']);
            }
            if (BigDecimal::of($this->listedQuantity($lockedLot))->isGreaterThan(0)) {
                throw ValidationException::withMessages(['lot' => '出品中の特典を先に取り下げてください。']);
            }
            if ($lockedLot->transactions()->count() !== 1 || $lockedLot->transactions()->first()->transaction_type !== 'earn') {
                throw ValidationException::withMessages(['lot' => '利用履歴があるロットは登録取消できません。調整で訂正してください。']);
            }
            $this->transactions->reverse($lockedLot->transactions()->first());
            $lockedLot->cancelled_at = now();
            $lockedLot->save();
        });
    }

    public function expiringWithin(int $days)
    {
        return $this->readService->expiringLots($days);
    }

    private function fill(BenefitLot $lot, array $data): void
    {
        $lot->display_name = $data['display_name'] ?? null;
        $lot->acquired_at = $data['acquired_at'] ?? null;
        $lot->expires_at = $data['expires_at'] ?? null;
        $lot->action_policy = $data['action_policy'] ?? 'undecided';
        foreach (['acquisition_cost_yen', 'face_value_yen', 'estimated_use_value_yen', 'estimated_sale_value_yen', 'usage_conditions', 'transfer_restriction', 'memo'] as $field) {
            $lot->{$field} = $data[$field] ?? null;
        }
    }
}
