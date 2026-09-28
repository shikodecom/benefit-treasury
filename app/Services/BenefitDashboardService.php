<?php

namespace App\Services;

use App\Models\BenefitLot;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BenefitDashboardService
{
    public function lots(array $filters = []): Collection
    {
        $balances = DB::table('benefit_transactions')
            ->select('lot_id')
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) AS remaining")
            ->whereNotNull('lot_id')->groupBy('lot_id');
        $listed = DB::table('benefit_listing_items as item')
            ->join('benefit_listings as listing', 'listing.id', '=', 'item.listing_id')
            ->where('listing.status', 'listed')
            ->select('item.lot_id')->selectRaw('SUM(item.quantity) AS listed_quantity')
            ->groupBy('item.lot_id');

        $query = BenefitLot::query()->with(['account.program', 'account.householdMember'])
            ->leftJoinSub($balances, 'balance', 'balance.lot_id', '=', 'benefit_lots.id')
            ->leftJoinSub($listed, 'reserved', 'reserved.lot_id', '=', 'benefit_lots.id')
            ->select('benefit_lots.*')
            ->selectRaw('balance.remaining AS remaining_quantity, COALESCE(reserved.listed_quantity, 0) AS listed_quantity')
            ->whereNull('benefit_lots.cancelled_at')->where('balance.remaining', '>', 0);

        if (isset($filters['member'])) {
            $query->whereHas('account', function ($account) use ($filters): void {
                if ($filters['member'] === 'shared') {
                    $account->whereNull('household_member_id');
                } else {
                    $account->where('household_member_id', $filters['member']);
                }
            });
        }
        if (isset($filters['category'])) {
            $query->whereHas('account.program', fn ($program) => $program->where('category', $filters['category']));
        }
        if (isset($filters['policy'])) {
            $query->where('benefit_lots.action_policy', $filters['policy']);
        }
        if (isset($filters['expiry'])) {
            $today = CarbonImmutable::now('Asia/Tokyo');
            match ($filters['expiry']) {
                'expired' => $query->whereDate('benefit_lots.expires_at', '<', $today->toDateString()),
                '7' => $query->whereBetween('benefit_lots.expires_at', [$today->toDateString(), $today->addDays(7)->toDateString()]),
                '30' => $query->whereBetween('benefit_lots.expires_at', [$today->toDateString(), $today->addDays(30)->toDateString()]),
                'none' => $query->whereNull('benefit_lots.expires_at'),
            };
        }

        return $query->get()->sort(function (BenefitLot $a, BenefitLot $b): int {
            return $this->calculatePriority($b) <=> $this->calculatePriority($a)
                ?: ($a->expires_at ?? '9999-12-31') <=> ($b->expires_at ?? '9999-12-31')
                ?: ($this->estimatedValue($b) ?? -1) <=> ($this->estimatedValue($a) ?? -1)
                ?: $a->id <=> $b->id;
        })->values();
    }

    public function summary(Collection $lots): array
    {
        $today = CarbonImmutable::now('Asia/Tokyo')->toDateString();
        $within7 = CarbonImmutable::now('Asia/Tokyo')->addDays(7)->toDateString();
        $within30 = CarbonImmutable::now('Asia/Tokyo')->addDays(30)->toDateString();
        $groups = [
            'expired' => $lots->filter(fn ($lot) => $lot->expires_at !== null && $lot->expires_at < $today),
            'within7' => $lots->filter(fn ($lot) => $lot->expires_at !== null && $lot->expires_at >= $today && $lot->expires_at <= $within7),
            'within30' => $lots->filter(fn ($lot) => $lot->expires_at !== null && $lot->expires_at >= $today && $lot->expires_at <= $within30),
        ];
        $result = [];
        foreach ($groups as $key => $group) {
            $result[$key] = ['count' => $group->count(), 'value' => $group->sum(fn ($lot) => $this->estimatedValue($lot) ?? 0)];
        }

        $lotIds = $lots->pluck('id');
        $listings = $lotIds->isEmpty() ? collect() : DB::table('benefit_listing_items as item')
            ->join('benefit_listings as listing', 'listing.id', '=', 'item.listing_id')
            ->where('listing.status', 'listed')->whereIn('item.lot_id', $lotIds)
            ->distinct()->get(['listing.id', 'listing.listing_price_yen']);
        $result['listed'] = [
            'count' => $listings->count(),
            'value' => $listings->sum(fn ($listing) => $listing->listing_price_yen ?? 0),
        ];

        return $result;
    }

    public function urgentItems(Collection $lots): Collection
    {
        return $lots->filter(fn ($lot) => ($this->daysUntilExpiry($lot) !== null && $this->daysUntilExpiry($lot) <= 3)
            || ($lot->action_policy === 'sell_now' && BigDecimal::of((string) $lot->listed_quantity)->isEqualTo(0)))->values();
    }

    public function expiringWithin(Collection $lots, int $days): Collection
    {
        return $lots->filter(fn ($lot) => ($left = $this->daysUntilExpiry($lot)) !== null && $left >= 0 && $left <= $days)->values();
    }

    public function undecidedItems(Collection $lots): Collection
    {
        return $lots->where('action_policy', 'undecided')->values();
    }

    public function calculatePriority(BenefitLot $lot): int
    {
        $days = $this->daysUntilExpiry($lot);
        $score = match (true) {
            $days === null => 0,
            $days < 0 => 100,
            $days === 0 => 90,
            $days <= 3 => 80,
            $days <= 7 => 60,
            $days <= 14 => 40,
            $days <= 30 => 20,
            default => 0,
        };
        $score += match ($lot->action_policy) {
            'sell_now' => 20,
            'undecided' => 10,
            'self_use' => 5,
            default => 0,
        };
        $remaining = BigDecimal::of((string) ($lot->remaining_quantity ?? 0));
        $listed = BigDecimal::of((string) ($lot->listed_quantity ?? 0));
        if ($lot->action_policy === 'sell_now' && $listed->isEqualTo(0)) {
            $score += 20;
        }
        if ($listed->isGreaterThan(0) && $days !== null && $days >= 0 && $days <= 3) {
            $score += 15;
        }
        if ($listed->isGreaterThan(0) && $listed->isLessThan($remaining)) {
            $score += 10;
        }
        if ($days !== null && $days < 0) {
            $score += 30;
        }

        return $score;
    }

    public function recommendedAction(BenefitLot $lot): string
    {
        $days = $this->daysUntilExpiry($lot);
        $listed = BigDecimal::of((string) ($lot->listed_quantity ?? 0))->isGreaterThan(0);

        return match (true) {
            $days !== null && $days < 0 => '失効処理または期限修正',
            $lot->action_policy === 'sell_now' && ! $listed => '出品する',
            $lot->action_policy === 'sell_now' && $listed && $days !== null && $days <= 7 => '価格見直し',
            $lot->action_policy === 'self_use' && $days !== null && $days <= 7 => '利用予定を決める',
            $lot->action_policy === 'undecided' && $days !== null && $days <= 30 => '方針を決める',
            $lot->action_policy === 'bundle' && $days !== null && $days <= 30 => 'セット販売を準備',
            default => '状況を確認',
        };
    }

    public function daysUntilExpiry(BenefitLot $lot): ?int
    {
        return $lot->expires_at === null ? null : (int) CarbonImmutable::now('Asia/Tokyo')->startOfDay()
            ->diffInDays(CarbonImmutable::parse($lot->expires_at, 'Asia/Tokyo')->startOfDay(), false);
    }

    public function estimatedValue(BenefitLot $lot): ?int
    {
        $field = match ($lot->action_policy) {
            'self_use' => 'estimated_use_value_yen',
            'sell_now' => 'estimated_sale_value_yen',
            default => 'face_value_yen',
        };

        return $lot->{$field} === null ? null : (int) $lot->{$field};
    }
}
