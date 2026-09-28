<?php

namespace App\Services;

use App\Models\BenefitAccount;
use App\Models\BenefitListing;
use App\Models\BenefitListingItem;
use App\Models\BenefitListingPriceHistory;
use App\Models\BenefitLot;
use App\Models\BenefitTransaction;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BenefitListingService
{
    public function __construct(private readonly BenefitReadService $read, private readonly BenefitTransactionService $transactions) {}

    public function createDraft(array $data, array $items): BenefitListing
    {
        return DB::transaction(function () use ($data, $items): BenefitListing {
            $parts = $this->lockAndValidateItems($items, false);
            $listing = new BenefitListing;
            $this->fill($listing, $data);
            $listing->status = 'draft';
            $listing->save();
            foreach ($parts as [$lot, $quantity]) {
                $item = new BenefitListingItem;
                $item->listing_id = $listing->id;
                $item->lot_id = $lot->id;
                $item->quantity = $quantity;
                $item->save();
            }
            $this->recordPrice($listing);

            return $listing;
        });
    }

    public function publish(BenefitListing $listing, bool $confirmPolicy = false, bool $confirmMixedExpiry = false): BenefitListing
    {
        return DB::transaction(function () use ($listing, $confirmPolicy, $confirmMixedExpiry): BenefitListing {
            $listing = BenefitListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->requireStatus($listing, ['draft']);
            $parts = $this->lockAndValidateItems($listing->items()->pluck('quantity', 'lot_id')->all(), true);
            $this->confirmPublication($parts, $confirmPolicy, $confirmMixedExpiry);
            $listing->status = 'listed';
            $listing->listed_at = now();
            $listing->save();

            return $listing;
        });
    }

    public function updateListing(BenefitListing $listing, array $data, ?array $items = null, bool $confirmPolicy = false, bool $confirmMixedExpiry = false): BenefitListing
    {
        return DB::transaction(function () use ($listing, $data, $items, $confirmPolicy, $confirmMixedExpiry): BenefitListing {
            $listing = BenefitListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->requireStatus($listing, ['draft', 'listed']);
            if ($listing->status === 'listed' && (($data['marketplace'] ?? $listing->marketplace) !== $listing->marketplace
                || ($data['delivery_type'] ?? $listing->delivery_type) !== $listing->delivery_type)) {
                throw ValidationException::withMessages(['listing' => '出品中は出品先と受け渡し方法を変更できません。']);
            }
            if ($items !== null) {
                $parts = $this->lockAndValidateItems($items, $listing->status === 'listed', $listing->id,
                    $listing->items()->pluck('lot_id')->all());
                if ($listing->status === 'listed') $this->confirmPublication($parts, $confirmPolicy, $confirmMixedExpiry);
                $listing->items()->delete();
                foreach ($parts as [$lot, $quantity]) {
                    $item = new BenefitListingItem;
                    $item->listing_id = $listing->id;
                    $item->lot_id = $lot->id;
                    $item->quantity = $quantity;
                    $item->save();
                }
            }
            $oldPrice = $listing->listing_price_yen;
            $this->fill($listing, $data);
            $listing->save();
            if ($listing->listing_price_yen !== $oldPrice) {
                $this->recordPrice($listing, $data['price_reason'] ?? null);
            }

            return $listing;
        });
    }

    public function changePrice(BenefitListing $listing, int $price, ?string $reason = null): void
    {
        DB::transaction(function () use ($listing, $price, $reason): void {
            $listing = BenefitListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->requireStatus($listing, ['draft', 'listed']);
            if ($price < 0) {
                throw ValidationException::withMessages(['listing_price_yen' => '価格は0円以上で入力してください。']);
            }
            if ((int) $listing->listing_price_yen === $price && $listing->listing_price_yen !== null) {
                return;
            }
            $listing->listing_price_yen = $price;
            $listing->save();
            $this->recordPrice($listing, $reason);
        });
    }

    public function cancel(BenefitListing $listing): void
    {
        $this->finish($listing, 'cancelled', ['draft', 'listed']);
    }

    public function endUnsold(BenefitListing $listing): void
    {
        $this->finish($listing, 'ended_unsold', ['listed']);
    }

    public function relist(BenefitListing $listing): BenefitListing
    {
        $listing->refresh();
        $listing->load('items');
        $this->requireStatus($listing, ['ended_unsold', 'cancelled', 'sold']);
        if ($listing->status === 'sold' && $listing->sale_reversed_at === null) {
            throw ValidationException::withMessages(['listing' => '売却済みの出品は取消後に再出品してください。']);
        }
        $items = $listing->items->pluck('quantity', 'lot_id')->all();

        return $this->createDraft([
            'marketplace' => $listing->marketplace, 'title' => $listing->title,
            'listing_price_yen' => $listing->listing_price_yen, 'delivery_type' => $listing->delivery_type,
            'memo' => $listing->memo,
        ], $items);
    }

    public function sell(BenefitListing $listing, int $soldPrice, int $fee, int $shipping, string $soldAt, ?string $memo = null): void
    {
        DB::transaction(function () use ($listing, $soldPrice, $fee, $shipping, $soldAt, $memo): void {
            $listing = BenefitListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->requireStatus($listing, ['listed']);
            if (min($soldPrice, $fee, $shipping) < 0) {
                throw ValidationException::withMessages(['sold_price_yen' => '価格・費用は0円以上で入力してください。']);
            }
            $items = $listing->items()->orderBy('lot_id')->get();
            $parts = $this->lockAndValidateItems($items->pluck('quantity', 'lot_id')->all(), false);
            foreach ($parts as [$lot, $quantity]) {
                if (BigDecimal::of($this->read->lotRemainingQuantity($lot->id))->isLessThan($quantity)) {
                    throw ValidationException::withMessages(['items' => 'ロット #'.$lot->id.' の残数が不足しています。']);
                }
            }
            $listing->status = 'sold';
            $listing->sold_at = $soldAt;
            $listing->ended_at = now();
            $listing->sold_price_yen = $soldPrice;
            $listing->fee_yen = $fee;
            $listing->shipping_yen = $shipping;
            $listing->net_proceeds_yen = $soldPrice - $fee - $shipping;
            $listing->memo = $memo ?: $listing->memo;
            $listing->save();
            foreach ($parts as [$lot, $quantity]) {
                $transaction = new BenefitTransaction;
                $transaction->account_id = $lot->account_id;
                $transaction->lot_id = $lot->id;
                $transaction->listing_id = $listing->id;
                $transaction->transaction_type = 'sell';
                $transaction->direction = 'out';
                $transaction->quantity = $quantity;
                $transaction->transaction_at = $soldAt;
                $transaction->source_type = 'manual';
                $transaction->save();
            }
        });
    }

    public function reverseSale(BenefitListing $listing): void
    {
        DB::transaction(function () use ($listing): void {
            $listing = BenefitListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->requireStatus($listing, ['sold']);
            if ($listing->sale_reversed_at !== null) {
                throw ValidationException::withMessages(['listing' => '売却はすでに取り消されています。']);
            }
            $sales = $listing->transactions()->where('transaction_type', 'sell')->orderBy('account_id')->orderBy('lot_id')->get();
            if ($sales->count() !== $listing->items()->count()) {
                throw ValidationException::withMessages(['listing' => '売却取引が一致しません。']);
            }
            foreach ($sales as $sale) {
                $this->transactions->reverse($sale, true);
            }
            $listing->sale_reversed_at = now();
            $listing->save();
        });
    }

    public function activeListedQuantity(BenefitLot $lot): string
    {
        return $this->read->lotListedQuantity($lot->id);
    }

    public function salesSummary(?string $from = null, ?string $to = null): array
    {
        $query = BenefitListing::query()->where('status', 'sold')->whereNull('sale_reversed_at');
        if ($from) $query->whereDate('sold_at', '>=', $from);
        if ($to) $query->whereDate('sold_at', '<=', $to);

        return [
            'count' => (clone $query)->count(),
            'sold_price_yen' => (int) (clone $query)->sum('sold_price_yen'),
            'fee_yen' => (int) (clone $query)->sum('fee_yen'),
            'shipping_yen' => (int) (clone $query)->sum('shipping_yen'),
            'net_proceeds_yen' => (int) (clone $query)->sum('net_proceeds_yen'),
        ];
    }

    private function finish(BenefitListing $listing, string $status, array $allowed): void
    {
        DB::transaction(function () use ($listing, $status, $allowed): void {
            $listing = BenefitListing::query()->lockForUpdate()->findOrFail($listing->id);
            $this->requireStatus($listing, $allowed);
            $listing->status = $status;
            $listing->ended_at = now();
            $listing->save();
        });
    }

    private function lockAndValidateItems(array $items, bool $reserve, ?int $currentListingId = null, array $previousLotIds = []): array
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => '特典を1件以上選択してください。']);
        }
        $ids = array_map('intval', array_keys($items));
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => '同じロットを重複指定できません。']);
        }
        sort($ids);
        $accountIds = BenefitLot::query()->whereIn('id', array_unique(array_merge($ids, $previousLotIds)))
            ->pluck('account_id')->unique()->sort()->all();
        BenefitAccount::query()->whereIn('id', $accountIds)->orderBy('id')->lockForUpdate()->get();
        $lots = BenefitLot::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($lots->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => '特典ロットが見つかりません。']);
        }
        $parts = [];
        foreach ($ids as $id) {
            $lot = $lots[$id];
            try {
                $quantity = BigDecimal::of((string) $items[$id]);
                if ($quantity->isLessThanOrEqualTo(0) || $quantity->getScale() > 4 || $quantity->isGreaterThanOrEqualTo('100000000000000')) throw new \InvalidArgumentException;
            } catch (\Throwable) {
                throw ValidationException::withMessages(['items' => '数量は0より大きい数値（小数4桁以内）で入力してください。']);
            }
            if ($lot->cancelled_at !== null) throw ValidationException::withMessages(['items' => '登録取消済みロットは出品できません。']);
            if ($reserve && $lot->transfer_restriction === 'non_transferable') throw ValidationException::withMessages(['items' => '譲渡・転売不可の特典は出品できません。']);
            if ($reserve) {
                $available = BigDecimal::of($this->read->lotAvailableQuantity($id));
                if ($currentListingId !== null) {
                    $own = BenefitListingItem::query()->where('listing_id', $currentListingId)->where('lot_id', $id)->value('quantity');
                    $available = $available->plus((string) ($own ?? '0'));
                }
                if ($available->isLessThan($quantity)) throw ValidationException::withMessages(['items' => 'ロット #'.$id.' の出品可能数は '.$available.' です。']);
            }
            $parts[] = [$lot, (string) $quantity->toScale(4)];
        }

        return $parts;
    }

    private function fill(BenefitListing $listing, array $data): void
    {
        foreach (['marketplace', 'title', 'listing_price_yen', 'listing_url', 'delivery_type', 'memo'] as $field) {
            if (array_key_exists($field, $data)) $listing->{$field} = $data[$field];
        }
    }

    private function confirmPublication(array $parts, bool $confirmPolicy, bool $confirmMixedExpiry): void
    {
        $expiries = [];
        foreach ($parts as [$lot]) {
            $expiries[] = $lot->expires_at ?? '期限なし';
            if (! in_array($lot->action_policy, ['sell_now', 'bundle'], true) && ! $confirmPolicy) {
                throw ValidationException::withMessages(['confirm_policy' => '現在の方針は「今売る／セット販売」ではありません。確認して出品してください。']);
            }
        }
        if (count(array_unique($expiries)) > 1 && ! $confirmMixedExpiry) {
            throw ValidationException::withMessages(['confirm_mixed_expiry' => '異なる有効期限の特典が含まれます。確認して出品してください。']);
        }
    }

    private function recordPrice(BenefitListing $listing, ?string $reason = null): void
    {
        if ($listing->listing_price_yen === null) return;
        $history = new BenefitListingPriceHistory;
        $history->listing_id = $listing->id;
        $history->price_yen = $listing->listing_price_yen;
        $history->changed_at = now();
        $history->reason = $reason;
        $history->save();
    }

    private function requireStatus(BenefitListing $listing, array $allowed): void
    {
        if (! in_array($listing->status, $allowed, true)) {
            throw ValidationException::withMessages(['listing' => 'この出品は現在の状態では操作できません。']);
        }
    }
}
