<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class BenefitReadService
{
    public function accountBalance(int $accountId): string
    {
        return $this->quantitySum('account_id', $accountId);
    }

    public function lotRemainingQuantity(int $lotId): string
    {
        return $this->quantitySum('lot_id', $lotId);
    }

    public function unallocatedBalance(int $accountId): string
    {
        $quantity = DB::table('benefit_transactions')->where('account_id', $accountId)
            ->whereNull('lot_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END), 0) AS balance")
            ->value('balance');

        return (string) BigDecimal::of((string) $quantity)->toScale(4);
    }

    public function lotListedQuantity(int $lotId): string
    {
        $quantity = DB::table('benefit_listing_items as item')
            ->join('benefit_listings as listing', 'listing.id', '=', 'item.listing_id')
            ->where('item.lot_id', $lotId)
            ->where('listing.status', 'listed')
            ->sum('item.quantity');

        return (string) BigDecimal::of((string) $quantity)->toScale(4);
    }

    public function lotAvailableQuantity(int $lotId): string
    {
        return (string) BigDecimal::of($this->lotRemainingQuantity($lotId))
            ->minus($this->lotListedQuantity($lotId))->toScale(4);
    }

    public function expiringLots(int $days): Builder
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('days must be nonnegative');
        }

        $end = now('Asia/Tokyo')->addDays($days)->toDateString();
        $today = now('Asia/Tokyo')->toDateString();

        return DB::table('benefit_lots as lot')
            ->whereNull('lot.cancelled_at')
            ->whereBetween('lot.expires_at', [$today, $end])
            ->whereRaw("(SELECT COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END), 0) FROM benefit_transactions WHERE lot_id = lot.id) > 0")
            ->orderBy('lot.expires_at');
    }

    public function pendingTransfers(): Builder
    {
        return DB::table('benefit_transfer_steps')->where('status', 'processing');
    }

    public function activeListings(): Builder
    {
        return DB::table('benefit_listings')->where('status', 'listed');
    }

    public function openListings(): Builder
    {
        return DB::table('benefit_listings')->whereIn('status', ['draft', 'listed']);
    }

    private function quantitySum(string $column, int $id): string
    {
        $quantity = DB::table('benefit_transactions')
            ->where($column, $id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END), 0) AS balance")
            ->value('balance');

        return (string) BigDecimal::of((string) $quantity)->toScale(4);
    }
}
