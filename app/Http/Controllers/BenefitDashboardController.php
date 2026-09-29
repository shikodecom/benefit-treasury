<?php

namespace App\Http\Controllers;

use App\Domain\BenefitValues;
use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Models\BenefitTransferStep;
use App\Models\HouseholdMember;
use App\Services\BenefitDashboardService;
use App\Services\BenefitTransferService;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BenefitDashboardController extends Controller
{
    public function index(Request $request, BenefitDashboardService $service, BenefitTransferService $transfers): View
    {
        $filters = $request->validate([
            'member' => ['nullable', Rule::in(array_merge(['shared'], HouseholdMember::query()->pluck('id')->map(fn ($id) => (string) $id)->all()))],
            'category' => ['nullable', Rule::in(BenefitValues::PROGRAM_CATEGORIES)],
            'policy' => ['nullable', Rule::in(BenefitValues::ACTION_POLICIES)],
            'expiry' => ['nullable', Rule::in(['expired', '7', '30', 'none'])],
        ]);
        $filters = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $lots = $service->lots($filters);
        $week = $service->expiringWithin($lots, 7);
        $month = $service->expiringWithin($lots, 30)
            ->filter(fn ($lot) => $service->daysUntilExpiry($lot) > 7)->values();
        $pendingTransfers = BenefitTransferStep::query()->with('planningEquivalentProgram')
            ->where('status', 'processing')->get();
        $overdueTransfers = $transfers->overdueSteps();
        $equivalents = $pendingTransfers->filter(fn ($step) => $step->planning_equivalent_program_id !== null)
            ->groupBy('planning_equivalent_program_id')->map(fn ($items) => [
                'program' => $items->first()->planningEquivalentProgram,
                'quantity' => (string) $items->reduce(fn ($sum, $item) => $sum->plus($item->planning_equivalent_quantity), BigDecimal::zero())->toScale(4),
            ]);
        $accountBalances = DB::table('benefit_transactions')->select('account_id')
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) AS balance")
            ->groupBy('account_id');
        $balanceAccounts = BenefitAccount::query()->with('program')
            ->joinSub($accountBalances, 'balances', 'balances.account_id', '=', 'benefit_accounts.id')
            ->select('benefit_accounts.*')->selectRaw('balances.balance AS current_balance')
            ->where('balances.balance', '>', 0)->orderBy('benefit_accounts.id')->get();

        return view('dashboard.index', [
            'service' => $service,
            'filters' => $filters,
            'members' => HouseholdMember::query()->orderBy('display_name')->get(),
            'summary' => $service->summary($lots),
            'urgent' => $service->urgentItems($lots),
            'week' => $week,
            'month' => $month,
            'listed' => $lots->filter(fn ($lot) => (float) $lot->listed_quantity > 0)->values(),
            'undecided' => $service->undecidedItems($lots),
            'pendingTransfers' => $pendingTransfers,
            'overdueTransfers' => $overdueTransfers,
            'equivalents' => $equivalents,
            'balanceAccounts' => $balanceAccounts,
        ]);
    }

    public function updatePolicy(Request $request, BenefitLot $lot): RedirectResponse
    {
        $data = $request->validate(['action_policy' => ['required', Rule::in(BenefitValues::ACTION_POLICIES)]]);
        abort_if($lot->cancelled_at !== null, 404);
        $lot->action_policy = $data['action_policy'];
        $lot->save();

        return back()->with('status', '運用方針を更新しました。');
    }
}
