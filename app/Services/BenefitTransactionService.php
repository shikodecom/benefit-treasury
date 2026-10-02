<?php

namespace App\Services;

use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Models\BenefitTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BenefitTransactionService
{
    private const DIRECTIONS = [
        'opening_balance' => 'in', 'earn' => 'in', 'use' => 'out',
        'adjustment_in' => 'in', 'adjustment_out' => 'out',
        'transfer_in' => 'in', 'transfer_out' => 'out',
        'sell' => 'out', 'expire' => 'out',
    ];

    public function __construct(private readonly BenefitReadService $readService) {}

    public function createOpeningBalance(BenefitAccount $account, string $date, string $quantity, ?string $memo): BenefitTransaction
    {
        return $this->record($account, 'opening_balance', $quantity, $date, null, null, $memo);
    }

    public function earn(BenefitAccount $account, string $quantity, string $date, ?string $purpose = null, ?int $value = null, ?BenefitLot $lot = null): BenefitTransaction
    {
        return $this->record($account, 'earn', $quantity, $date, $purpose, $value, null, $lot);
    }

    public function use(BenefitAccount $account, string $quantity, string $date, ?string $purpose = null, ?int $value = null, ?BenefitLot $lot = null, ?string $memo = null): BenefitTransaction
    {
        return $this->record($account, 'use', $quantity, $date, $purpose, $value, $memo, $lot);
    }

    public function adjustIn(BenefitAccount $account, string $quantity, string $date, string $memo, ?BenefitLot $lot = null): BenefitTransaction
    {
        return $this->record($account, 'adjustment_in', $quantity, $date, null, null, $memo, $lot);
    }

    public function adjustOut(BenefitAccount $account, string $quantity, string $date, string $memo, ?BenefitLot $lot = null): BenefitTransaction
    {
        return $this->record($account, 'adjustment_out', $quantity, $date, null, null, $memo, $lot);
    }

    public function record(BenefitAccount $account, string $type, string $quantity, string $date, ?string $purpose = null, ?int $value = null, ?string $memo = null, ?BenefitLot $lot = null): BenefitTransaction
    {
        if (! isset(self::DIRECTIONS[$type])) {
            throw ValidationException::withMessages(['transaction_type' => '取引種別が正しくありません。']);
        }

        $amount = $this->positiveQuantity($quantity);
        if (in_array($type, ['adjustment_in', 'adjustment_out'], true) && blank($memo)) {
            throw ValidationException::withMessages(['memo' => '残高調整には理由が必要です。']);
        }

        return DB::transaction(function () use ($account, $type, $amount, $date, $purpose, $value, $memo, $lot): BenefitTransaction {
            $lockedAccount = BenefitAccount::query()->lockForUpdate()->findOrFail($account->id);
            if (! $lockedAccount->active || ! $lockedAccount->program()->where('active', true)->exists()) {
                throw ValidationException::withMessages(['account_id' => '有効な口座と制度を選択してください。']);
            }
            if ($type === 'opening_balance' && $lockedAccount->transactions()->exists()) {
                throw ValidationException::withMessages(['transaction_type' => '初期残高は取引がない口座にのみ登録できます。']);
            }

            $lockedLot = null;
            if ($lot) {
                $lockedLot = BenefitLot::query()->lockForUpdate()->findOrFail($lot->id);
                if ($lockedLot->account_id !== $lockedAccount->id || $lockedLot->cancelled_at !== null) {
                    throw ValidationException::withMessages(['lot_id' => 'この口座で利用できるロットを選択してください。']);
                }
            }

            if (self::DIRECTIONS[$type] === 'out') {
                $balance = $lockedLot
                    ? $this->readService->lotAvailableQuantity($lockedLot->id)
                    : $this->readService->unallocatedBalance($lockedAccount->id);
                if (BigDecimal::of($balance)->isLessThan($amount)) {
                    throw ValidationException::withMessages(['quantity' => "利用可能残高は {$balance} {$lockedAccount->program->unit_name} です。"]);
                }
            }

            $transaction = new BenefitTransaction;
            $transaction->account_id = $lockedAccount->id;
            $transaction->lot_id = $lockedLot?->id;
            $transaction->transaction_type = $type;
            $transaction->direction = self::DIRECTIONS[$type];
            $transaction->quantity = $amount;
            $transaction->transaction_at = $date;
            $transaction->merchant_or_purpose = blank($purpose) ? null : trim($purpose);
            $transaction->value_yen = $value;
            $transaction->memo = blank($memo) ? null : trim($memo);
            $transaction->source_type = 'manual';
            $transaction->save();

            return $transaction;
        });
    }

    public function reverse(BenefitTransaction $original, bool $managedCorrection = false): BenefitTransaction
    {
        return DB::transaction(function () use ($original, $managedCorrection): BenefitTransaction {
            BenefitAccount::query()->lockForUpdate()->findOrFail($original->account_id);
            $lockedOriginal = BenefitTransaction::query()->lockForUpdate()->findOrFail($original->id);
            if (! $managedCorrection && $lockedOriginal->listing_id !== null && $lockedOriginal->transaction_type === 'sell') {
                throw ValidationException::withMessages(['transaction' => '出品詳細から売却取消を行ってください。']);
            }
            if (! $managedCorrection && $lockedOriginal->transfer_step_id !== null) {
                throw ValidationException::withMessages(['transaction' => '移行詳細から訂正してください。']);
            }
            if ($lockedOriginal->transaction_type === 'reversal') {
                throw ValidationException::withMessages(['transaction' => '取消取引を再び取り消すことはできません。']);
            }
            if (BenefitTransaction::query()->where('reversal_of_transaction_id', $lockedOriginal->id)->exists()) {
                throw ValidationException::withMessages(['transaction' => 'この取引はすでに取り消されています。']);
            }
            if ($lockedOriginal->lot_id) {
                BenefitLot::query()->lockForUpdate()->findOrFail($lockedOriginal->lot_id);
            }
            if ($lockedOriginal->direction === 'in') {
                $balance = $lockedOriginal->lot_id
                    ? $this->readService->lotAvailableQuantity($lockedOriginal->lot_id)
                    : $this->readService->unallocatedBalance($lockedOriginal->account_id);
                if (BigDecimal::of($balance)->isLessThan($lockedOriginal->quantity)) {
                    throw ValidationException::withMessages(['transaction' => 'この取引の数量はすでに利用されているため取り消せません。調整で訂正してください。']);
                }
            }

            $reversal = new BenefitTransaction;
            $reversal->account_id = $lockedOriginal->account_id;
            $reversal->lot_id = $lockedOriginal->lot_id;
            $reversal->transaction_type = 'reversal';
            $reversal->direction = $lockedOriginal->direction === 'in' ? 'out' : 'in';
            $reversal->quantity = $lockedOriginal->quantity;
            $reversal->transaction_at = now('Asia/Tokyo')->toDateString();
            $reversal->reversal_of_transaction_id = $lockedOriginal->id;
            $reversal->memo = '取引 #'.$lockedOriginal->id.' の取消';
            $reversal->source_type = 'manual';
            $reversal->save();

            return $reversal;
        });
    }

    public function accountBalance(BenefitAccount $account): string
    {
        return $this->readService->accountBalance($account->id);
    }

    private function positiveQuantity(string $quantity): string
    {
        try {
            $amount = BigDecimal::of($quantity);
            if ($amount->isLessThanOrEqualTo(0) || $amount->getScale() > 4 || $amount->isGreaterThanOrEqualTo('100000000000000')) {
                throw new \InvalidArgumentException;
            }

            return (string) $amount->toScale(4);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['quantity' => '数量は0より大きい数値（小数4桁以内）で入力してください。']);
        }
    }
}
