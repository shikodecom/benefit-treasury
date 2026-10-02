<?php

namespace App\Http\Controllers;

use App\Models\BenefitProgram;
use App\Models\HouseholdMember;
use App\Services\BenefitSearchService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BenefitSearchController extends Controller
{
    public function index(Request $request, BenefitSearchService $service): View
    {
        if ($request->has('member')) {
            $request->merge(['member' => array_values(array_filter((array) $request->query('member'), fn ($v) => $v !== '' && $v !== null))]);
        }
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'member' => ['nullable', 'array', 'max:100'],
            'member.*' => ['required', 'string', 'max:30', 'distinct'],
            'include_history' => ['nullable', 'boolean'],
            'category' => ['nullable', Rule::in(['point', 'mile', 'e_money', 'gift', 'shareholder_benefit', 'coupon', 'discount', 'campaign', 'other'])],
            'program' => ['nullable', 'integer', Rule::exists('benefit_programs', 'id')],
            'policy' => ['nullable', Rule::in(['self_use', 'sell_now', 'hold', 'bundle', 'do_not_sell', 'transfer_to_points', 'undecided'])],
            'expires_within' => ['nullable', Rule::in(['0', '3', '7', '14', '30', '90', 'expired', 'none'])],
            'listing' => ['nullable', Rule::in(['listed', 'unlisted', 'partial', 'draft', 'ended_unsold', 'sold', 'cancelled'])],
            'transfer_status' => ['nullable', Rule::in(['planned', 'processing', 'overdue', 'completed', 'error'])],
            'restriction' => ['nullable', Rule::in(['non_transferable', 'transferable', 'none'])],
            'active_only' => ['nullable', 'boolean'],
            'preset' => ['nullable', Rule::in(['urgent', 'expiry7', 'expiry30', 'undecided', 'sell_unlisted', 'listed_expiring', 'overdue'])],
            'sort' => ['nullable', Rule::in(['expiry', 'name', 'updated'])],
            'per_page' => ['nullable', Rule::in(['25', '50', '100'])],
        ]);
        foreach ($filters['member'] ?? [] as $member) {
            if ($member !== 'shared' && (! ctype_digit($member) || ! HouseholdMember::query()->whereKey($member)->exists())) {
                abort(422);
            }
        }
        $results = $service->search($filters);
        $members = HouseholdMember::query()->orderBy('display_name')->get();
        $programs = BenefitProgram::query()->orderBy('name')->get();

        return view('search.index', compact('filters', 'results', 'members', 'programs'));
    }
}
