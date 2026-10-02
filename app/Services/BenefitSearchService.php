<?php

namespace App\Services;

use App\Models\BenefitAccount;
use App\Models\BenefitListing;
use App\Models\BenefitLot;
use App\Models\BenefitTransaction;
use App\Models\BenefitTransferGroup;
use App\Models\ConversionRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BenefitSearchService
{
    public function search(array $filters): array
    {
        $perPage = in_array((int) ($filters['per_page'] ?? 25), [25, 50, 100], true) ? (int) ($filters['per_page'] ?? 25) : 25;
        $term = trim((string) ($filters['q'] ?? ''));
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
        $balance = DB::table('benefit_transactions')->whereNotNull('lot_id')->select('lot_id')
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) AS remaining")
            ->groupBy('lot_id');
        $listed = DB::table('benefit_listing_items as item')->join('benefit_listings as listing', 'listing.id', '=', 'item.listing_id')
            ->where('listing.status', 'listed')->select('item.lot_id')->selectRaw('SUM(item.quantity) AS listed_quantity')->groupBy('item.lot_id');
        $lots = BenefitLot::query()->with(['account.program', 'account.householdMember'])
            ->joinSub($balance, 'balance', 'balance.lot_id', '=', 'benefit_lots.id')
            ->leftJoinSub($listed, 'reserved', 'reserved.lot_id', '=', 'benefit_lots.id')
            ->select('benefit_lots.*')->selectRaw('balance.remaining, COALESCE(reserved.listed_quantity, 0) AS listed_quantity');
        if (empty($filters['include_history']) || filled($filters['preset'] ?? null)) {
            $lots->whereNull('benefit_lots.cancelled_at')->where('balance.remaining', '>', 0);
        }
        if ($term !== '') {
            $lots->where(function ($q) use ($like): void {
                $q->where('benefit_lots.display_name', 'like', $like)->orWhere('benefit_lots.usage_conditions', 'like', $like)
                    ->orWhere('benefit_lots.memo', 'like', $like)
                    ->orWhereHas('account', fn ($a) => $a->where('account_label', 'like', $like)
                        ->orWhereHas('program', fn ($p) => $p->where('name', 'like', $like)->orWhere('provider', 'like', $like)
                            ->orWhereHas('aliases', fn ($alias) => $alias->where('alias', 'like', $like))))
                    ->orWhereHas('listingItems.listing', fn ($listing) => $listing->where('title', 'like', $like)->orWhere('marketplace', 'like', $like));
            });
        }
        $lots->whereHas('account', fn ($q) => $this->filterAccount($q, $filters));
        if ($policy = $filters['policy'] ?? null) {
            $lots->where('benefit_lots.action_policy', $policy);
        }
        if ($restriction = $filters['restriction'] ?? null) {
            if ($restriction === 'none') {
                $lots->whereNull('benefit_lots.transfer_restriction');
            } else {
                $lots->where('benefit_lots.transfer_restriction', $restriction);
            }
        }
        $today = CarbonImmutable::now('Asia/Tokyo');
        if (($days = $filters['expires_within'] ?? null) !== null && $days !== '') {
            if ($days === 'expired') {
                $lots->whereDate('benefit_lots.expires_at', '<', $today->toDateString());
            } elseif ($days === 'none') {
                $lots->whereNull('benefit_lots.expires_at');
            } else {
                $lots->whereBetween('benefit_lots.expires_at', [$today->toDateString(), $today->addDays((int) $days)->toDateString()]);
            }
        }
        if (($listing = $filters['listing'] ?? null) === 'listed') {
            $lots->where('reserved.listed_quantity', '>', 0);
        }
        if ($listing === 'unlisted') {
            $lots->whereRaw('COALESCE(reserved.listed_quantity, 0) = 0');
        }
        if ($listing === 'partial') {
            $lots->where('reserved.listed_quantity', '>', 0)->whereColumn('reserved.listed_quantity', '<', 'balance.remaining');
        }
        if (in_array($listing, ['draft', 'ended_unsold', 'sold', 'cancelled'], true)) {
            $lots->whereHas('listingItems.listing', fn ($q) => $q->where('status', $listing));
        }
        if ($status = $filters['transfer_status'] ?? null) {
            $lots->whereExists(fn ($q) => $this->transferAccountExists($q, 'benefit_lots.account_id', $status, $today));
        }
        match ($filters['preset'] ?? null) {
            'urgent' => $lots->where(fn ($q) => $q->whereDate('benefit_lots.expires_at', '<=', $today->addDays(3)->toDateString())
                ->orWhere(fn ($inner) => $inner->where('benefit_lots.action_policy', 'sell_now')->whereRaw('COALESCE(reserved.listed_quantity, 0) = 0'))),
            'expiry7' => $lots->whereBetween('benefit_lots.expires_at', [$today->toDateString(), $today->addDays(7)->toDateString()]),
            'expiry30' => $lots->whereBetween('benefit_lots.expires_at', [$today->toDateString(), $today->addDays(30)->toDateString()]),
            'undecided' => $lots->where('benefit_lots.action_policy', 'undecided'),
            'sell_unlisted' => $lots->where('benefit_lots.action_policy', 'sell_now')->whereRaw('COALESCE(reserved.listed_quantity, 0) = 0'),
            'listed_expiring' => $lots->where('reserved.listed_quantity', '>', 0)->whereBetween('benefit_lots.expires_at', [$today->toDateString(), $today->addDays(7)->toDateString()]),
            'overdue' => $lots->whereExists(fn ($q) => $this->transferAccountExists($q, 'benefit_lots.account_id', 'overdue', $today)),
            default => null,
        };
        match ($filters['sort'] ?? 'expiry') {
            'name' => $lots->orderBy('benefit_lots.display_name')->orderBy('benefit_lots.id'),
            'updated' => $lots->orderByDesc('benefit_lots.updated_at')->orderByDesc('benefit_lots.id'),
            default => $lots->orderByRaw('benefit_lots.expires_at IS NULL')->orderBy('benefit_lots.expires_at')->orderBy('benefit_lots.id'),
        };

        $plain = DB::table('benefit_transactions')->whereNull('lot_id')->select('account_id')
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) AS balance")->groupBy('account_id');
        $accounts = BenefitAccount::query()->with(['program', 'householdMember'])->joinSub($plain, 'plain', 'plain.account_id', '=', 'benefit_accounts.id')
            ->select('benefit_accounts.*')->selectRaw('plain.balance AS plain_balance');
        if (empty($filters['include_history'])) {
            $accounts->where('plain.balance', '!=', 0);
        }
        if ($term !== '') {
            $accounts->where(fn ($q) => $q->where('benefit_accounts.account_label', 'like', $like)
                ->orWhereHas('program', fn ($p) => $p->where('name', 'like', $like)->orWhere('provider', 'like', $like)
                    ->orWhereHas('aliases', fn ($alias) => $alias->where('alias', 'like', $like))));
        }
        $this->filterAccount($accounts, $filters);
        if ($status = $filters['transfer_status'] ?? null) {
            $accounts->whereExists(fn ($q) => $this->transferAccountExists($q, 'benefit_accounts.id', $status, $today));
        }
        if (($filters['preset'] ?? null) === 'overdue') {
            $accounts->whereExists(fn ($q) => $this->transferAccountExists($q, 'benefit_accounts.id', 'overdue', $today));
        }
        if (filled($filters['policy'] ?? null) || filled($filters['restriction'] ?? null)
            || filled($filters['expires_within'] ?? null) || filled($filters['listing'] ?? null)
            || in_array($filters['preset'] ?? null, ['urgent', 'expiry7', 'expiry30', 'undecided', 'sell_unlisted', 'listed_expiring'], true)) {
            $accounts->whereRaw('1 = 0');
        }

        $extra = ['transactions' => collect(), 'listings' => collect(), 'transfers' => collect(), 'rules' => collect()];
        $lotOnly = filled($filters['policy'] ?? null) || filled($filters['restriction'] ?? null)
            || filled($filters['expires_within'] ?? null) || filled($filters['listing'] ?? null)
            || in_array($filters['preset'] ?? null, ['urgent', 'expiry7', 'expiry30', 'undecided', 'sell_unlisted', 'listed_expiring'], true);
        $status = $filters['transfer_status'] ?? (($filters['preset'] ?? null) === 'overdue' ? 'overdue' : null);
        $accountFilters = ! empty($filters['member']) || filled($filters['category'] ?? null)
            || filled($filters['program'] ?? null) || ! empty($filters['active_only']);
        $showExtra = ! $lotOnly && ($term !== '' || $status || ! empty($filters['member'])
            || filled($filters['category'] ?? null) || filled($filters['program'] ?? null));
        if ($showExtra) {
            if (! $status) {
                $extra['transactions'] = BenefitTransaction::query()->with('account.program')
                    ->whereHas('account', fn ($q) => $this->filterAccount($q, $filters))
                    ->when($term !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('merchant_or_purpose', 'like', $like)
                        ->orWhere('memo', 'like', $like)->orWhereHas('account', fn ($a) => $this->accountKeyword($a, $like))))
                    ->orderByDesc('transaction_at')->limit(10)->get();
                $extra['listings'] = BenefitListing::query()
                    ->when($accountFilters, fn ($q) => $q->whereHas('items.lot.account', fn ($a) => $this->filterAccount($a, $filters)))
                    ->when($term !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('title', 'like', $like)
                        ->orWhere('marketplace', 'like', $like)->orWhere('memo', 'like', $like)
                        ->orWhereHas('items.lot', fn ($lot) => $lot->where('display_name', 'like', $like)
                            ->orWhereHas('account', fn ($a) => $this->accountKeyword($a, $like)))))
                    ->orderByDesc('id')->limit(10)->get();
                // Rules have no holder. Exclude them when a holder condition is selected.
                if (empty($filters['member'])) {
                    $extra['rules'] = ConversionRule::query()->with(['fromProgram', 'toProgram'])
                        ->where(fn ($q) => $q->whereHas('fromProgram', fn ($p) => $this->filterProgram($p, $filters))
                            ->orWhereHas('toProgram', fn ($p) => $this->filterProgram($p, $filters)))
                        ->when($term !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('campaign_name', 'like', $like)
                            ->orWhere('conditions_text', 'like', $like)->orWhere('notes', 'like', $like)
                            ->orWhereHas('fromProgram', fn ($p) => $this->programKeyword($p, $like))
                            ->orWhereHas('toProgram', fn ($p) => $this->programKeyword($p, $like))))
                        ->orderByDesc('id')->limit(10)->get();
                }
            }
            $extra['transfers'] = BenefitTransferGroup::query()->with('targetProgram')
                ->when($accountFilters || $status, fn ($q) => $q->whereHas('steps', function ($step) use ($filters, $status, $today): void {
                    if ($status) {
                        $this->filterStep($step, $status, $today);
                    }
                    if (($filters['preset'] ?? null) === 'overdue') {
                        $this->filterStep($step, 'overdue', $today);
                    }
                    $step->where(fn ($q) => $q->whereHas('fromAccount', fn ($a) => $this->filterAccount($a, $filters))
                        ->orWhereHas('toAccount', fn ($a) => $this->filterAccount($a, $filters)));
                }))
                ->when($term !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', $like)
                    ->orWhere('purpose', 'like', $like)->orWhere('memo', 'like', $like)
                    ->orWhereHas('targetProgram', fn ($p) => $this->programKeyword($p, $like))
                    ->orWhereHas('steps', fn ($step) => $step->whereHas('fromAccount', fn ($a) => $this->accountKeyword($a, $like))
                        ->orWhereHas('toAccount', fn ($a) => $this->accountKeyword($a, $like)))))
                ->orderByDesc('id')->limit(25)->get();
        }

        return ['lots' => $lots->paginate($perPage, ['*'], 'lots_page')->withQueryString(),
            'accounts' => $accounts->orderBy('benefit_accounts.id')->paginate($perPage, ['*'], 'accounts_page')->withQueryString(),
            'extra' => $extra, 'show_extra' => $showExtra];
    }

    private function filterAccount(Builder $query, array $filters): void
    {
        $members = array_values(array_filter((array) ($filters['member'] ?? []), fn ($v) => $v !== '' && $v !== null));
        if ($members !== []) {
            $ids = array_values(array_filter($members, fn ($v) => $v !== 'shared'));
            $query->where(function ($q) use ($members, $ids): void {
                $q->whereIn('household_member_id', $ids);
                if (in_array('shared', $members, true)) {
                    $q->orWhereNull('household_member_id');
                }
            });
        }
        if (! empty($filters['active_only'])) {
            $query->where('active', true);
        }
        $query->whereHas('program', fn ($p) => $this->filterProgram($p, $filters));
    }

    private function filterProgram(Builder $query, array $filters): void
    {
        if (filled($filters['program'] ?? null)) {
            $query->whereKey($filters['program']);
        }
        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['active_only'])) {
            $query->where('active', true);
        }
    }

    private function accountKeyword(Builder $query, string $like): void
    {
        $query->where(fn ($q) => $q->where('account_label', 'like', $like)
            ->orWhereHas('program', fn ($p) => $this->programKeyword($p, $like)));
    }

    private function programKeyword(Builder $query, string $like): void
    {
        $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('provider', 'like', $like)
            ->orWhereHas('aliases', fn ($alias) => $alias->where('alias', 'like', $like)));
    }

    private function filterStep(Builder $query, string $status, CarbonImmutable $today): void
    {
        if ($status === 'overdue') {
            $query->where('status', 'processing')->whereDate('expected_complete_at', '<', $today->toDateString());
        } else {
            $query->where('status', $status);
        }
    }

    private function transferAccountExists($query, string $accountColumn, string $status, CarbonImmutable $today): void
    {
        $query->selectRaw('1')->from('benefit_transfer_steps as search_step')
            ->where(fn ($q) => $q->whereColumn('search_step.from_account_id', $accountColumn)
                ->orWhereColumn('search_step.to_account_id', $accountColumn));
        if ($status === 'overdue') {
            $query->where('search_step.status', 'processing')->whereDate('search_step.expected_complete_at', '<', $today->toDateString());
        } else {
            $query->where('search_step.status', $status);
        }
    }
}
