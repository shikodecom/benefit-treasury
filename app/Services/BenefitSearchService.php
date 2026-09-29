<?php

namespace App\Services;

use App\Models\BenefitAccount;
use App\Models\BenefitListing;
use App\Models\BenefitLot;
use App\Models\BenefitTransaction;
use App\Models\BenefitTransferGroup;
use App\Models\ConversionRule;
use Carbon\CarbonImmutable;
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
            ->select('benefit_lots.*')->selectRaw('balance.remaining, COALESCE(reserved.listed_quantity, 0) AS listed_quantity')
            ->whereNull('benefit_lots.cancelled_at')->where('balance.remaining', '>', 0);
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
        if ($member = $filters['member'] ?? null) {
            $lots->whereHas('account', fn ($q) => $member === 'shared' ? $q->whereNull('household_member_id') : $q->where('household_member_id', $member));
        }
        if ($category = $filters['category'] ?? null) {
            $lots->whereHas('account.program', fn ($q) => $q->where('category', $category));
        }
        if ($program = $filters['program'] ?? null) {
            $lots->whereHas('account', fn ($q) => $q->where('program_id', $program));
        }
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
        if (! empty($filters['active_only'])) {
            $lots->whereHas('account', fn ($q) => $q->where('active', true)->whereHas('program', fn ($p) => $p->where('active', true)));
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
            ->select('benefit_accounts.*')->selectRaw('plain.balance AS plain_balance')->where('plain.balance', '!=', 0);
        if ($term !== '') {
            $accounts->where(fn ($q) => $q->where('benefit_accounts.account_label', 'like', $like)
                ->orWhereHas('program', fn ($p) => $p->where('name', 'like', $like)->orWhere('provider', 'like', $like)
                    ->orWhereHas('aliases', fn ($alias) => $alias->where('alias', 'like', $like))));
        }
        if ($member = $filters['member'] ?? null) {
            if ($member === 'shared') {
                $accounts->whereNull('benefit_accounts.household_member_id');
            } else {
                $accounts->where('benefit_accounts.household_member_id', $member);
            }
        }
        if ($category = $filters['category'] ?? null) {
            $accounts->whereHas('program', fn ($q) => $q->where('category', $category));
        }
        if ($program = $filters['program'] ?? null) {
            $accounts->where('benefit_accounts.program_id', $program);
        }
        if (! empty($filters['active_only'])) {
            $accounts->where('benefit_accounts.active', true)->whereHas('program', fn ($q) => $q->where('active', true));
        }
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
        if ($term !== '' && ! array_filter($filters, fn ($value, $key) => ! in_array($key, ['q', 'sort', 'per_page'], true) && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH)) {
            $extra['transactions'] = BenefitTransaction::query()->with('account.program')->where(fn ($q) => $q->where('merchant_or_purpose', 'like', $like)->orWhere('memo', 'like', $like)
                ->orWhereHas('account.program', fn ($p) => $p->where('name', 'like', $like)))->orderByDesc('transaction_at')->limit(10)->get();
            $extra['listings'] = BenefitListing::query()->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('marketplace', 'like', $like)->orWhere('memo', 'like', $like))->orderByDesc('id')->limit(10)->get();
            $extra['transfers'] = BenefitTransferGroup::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('purpose', 'like', $like)->orWhere('memo', 'like', $like)
                ->orWhereHas('targetProgram', fn ($p) => $p->where('name', 'like', $like)))->orderByDesc('id')->limit(10)->get();
            $extra['rules'] = ConversionRule::query()->with(['fromProgram', 'toProgram'])->where(fn ($q) => $q->where('campaign_name', 'like', $like)->orWhere('conditions_text', 'like', $like)
                ->orWhere('notes', 'like', $like)->orWhereHas('fromProgram', fn ($p) => $p->where('name', 'like', $like))
                ->orWhereHas('toProgram', fn ($p) => $p->where('name', 'like', $like)))->orderByDesc('id')->limit(10)->get();
        }
        if (($filters['transfer_status'] ?? null) || ($filters['preset'] ?? null) === 'overdue') {
            $transferStatus = $filters['transfer_status'] ?? 'overdue';
            $extra['transfers'] = BenefitTransferGroup::query()->with('targetProgram')
                ->whereHas('steps', fn ($q) => $transferStatus === 'overdue'
                    ? $q->where('status', 'processing')->whereDate('expected_complete_at', '<', $today->toDateString())
                    : $q->where('status', $transferStatus))
                ->when($term !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('purpose', 'like', $like)))
                ->orderByDesc('id')->limit(25)->get();
        }

        return ['lots' => $lots->paginate($perPage, ['*'], 'lots_page')->withQueryString(),
            'accounts' => $accounts->orderBy('benefit_accounts.id')->paginate($perPage, ['*'], 'accounts_page')->withQueryString(),
            'extra' => $extra];
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
