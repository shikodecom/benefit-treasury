<?php

namespace App\Http\Controllers;

use App\Models\BenefitAccount;
use App\Models\ConversionRouteTemplate;
use App\Services\ConversionRouteService;
use App\Services\ConversionTransferDraftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConversionTransferDraftController extends Controller
{
    public function create(ConversionRouteTemplate $route, ConversionRouteService $service): View
    {
        $route->load(['steps.ruleGroup.fromProgram', 'steps.ruleGroup.toProgram']);
        $accounts = BenefitAccount::query()->with(['program', 'householdMember'])->where('active', true)->whereHas('program', fn ($q) => $q->where('active', true))->orderBy('id')->get();
        $candidates = [];
        foreach ($route->steps as $step) {
            $candidates[$step->id] = $service->availableRulesForStep($step);
        }

        return view('conversion.routes.draft', compact('route', 'accounts', 'candidates'));
    }

    public function preview(Request $request, ConversionRouteTemplate $route, ConversionTransferDraftService $service): View
    {
        $input = $this->validated($request);
        $rows = $service->preview($route, $input);

        return view('conversion.routes.draft-preview', compact('route', 'rows', 'input'));
    }

    public function store(Request $request, ConversionRouteTemplate $route, ConversionTransferDraftService $service): RedirectResponse
    {
        $group = $service->create($route, $this->validated($request));

        return redirect()->route('transfers.show', $group)->with('status', 'ルートから移行計画を作成しました。');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'source_account_id' => ['required', 'integer', Rule::exists('benefit_accounts', 'id')],
            'source_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'started_at' => ['nullable', 'date'], 'purpose' => ['nullable', 'string', 'max:255'],
            'steps' => ['required', 'array'], 'steps.*.rule_id' => ['nullable', 'integer', Rule::exists('conversion_rules', 'id')],
            'steps.*.to_account_id' => ['required', 'integer', Rule::exists('benefit_accounts', 'id')],
            'steps.*.source_quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'steps.*.expected_complete_at' => ['nullable', 'date'],
        ]);
    }
}
