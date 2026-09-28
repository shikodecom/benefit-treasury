<?php

namespace App\Http\Controllers;

use App\Domain\BenefitValues;
use App\Models\BenefitLot;
use App\Models\HouseholdMember;
use App\Services\BenefitDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BenefitDashboardController extends Controller
{
    public function index(Request $request, BenefitDashboardService $service): View
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
