<?php

namespace App\Http\Controllers;

use App\Models\BenefitProgram;
use App\Models\ConversionRule;
use App\Models\ConversionRuleGroup;
use App\Services\ConversionRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConversionRuleController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'from_program_id' => ['nullable', 'integer', Rule::exists('benefit_programs', 'id')],
            'to_program_id' => ['nullable', 'integer', Rule::exists('benefit_programs', 'id')],
            'status' => ['nullable', Rule::in(['current', 'active', 'inactive', 'all'])],
            'campaign' => ['nullable', Rule::in(['0', '1'])], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $status = $filters['status'] ?? 'current';
        $today = now('Asia/Tokyo')->toDateString();
        $query = ConversionRule::query()->with(['fromProgram', 'toProgram', 'group']);
        if ($status === 'current') $query->where('active', true)
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today))
            ->where(fn ($query) => $query->where('campaign_only', false)
                ->orWhere(fn ($query) => $query->whereNotNull('valid_from')->whereNotNull('valid_to')));
        elseif ($status === 'active' || $status === 'inactive') $query->where('active', $status === 'active');
        if (! empty($filters['from_program_id'])) $query->where('from_program_id', $filters['from_program_id']);
        if (! empty($filters['to_program_id'])) $query->where('to_program_id', $filters['to_program_id']);
        if (isset($filters['campaign'])) $query->where('campaign_only', $filters['campaign'] === '1');
        if (! empty($filters['q'])) {
            $keyword = trim($filters['q']);
            $query->where(fn ($query) => $query->where('campaign_name', 'like', "%{$keyword}%")
                ->orWhere('conditions_text', 'like', "%{$keyword}%")->orWhere('notes', 'like', "%{$keyword}%")
                ->orWhereHas('fromProgram', fn ($query) => $query->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('toProgram', fn ($query) => $query->where('name', 'like', "%{$keyword}%")));
        }
        $rules = $query->orderBy('from_program_id')->orderBy('to_program_id')->orderByDesc('version_no')->paginate(25)->withQueryString();
        $programs = BenefitProgram::query()->orderBy('name')->get();

        return view('conversion.rules.index', compact('rules', 'programs', 'filters', 'status'));
    }

    public function create(): View
    {
        return view('conversion.rules.form', [
            'base' => new ConversionRule, 'programs' => BenefitProgram::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, ConversionRuleService $service): RedirectResponse
    {
        $data = $this->validated($request, true);
        $rule = DB::transaction(function () use ($data, $service): ConversionRule {
            $group = $service->createRuleGroup($data);

            return $service->createRuleVersion($group, $data);
        });

        return redirect()->route('conversion.rules.show', $rule)->with('status', '交換ルールを登録しました。');
    }

    public function show(ConversionRule $rule, ConversionRuleService $service): View
    {
        $rule->load(['fromProgram', 'toProgram', 'group.rules', 'group.fromProgram', 'group.toProgram']);
        $usedCount = \App\Models\BenefitTransferStep::query()->where('conversion_rule_id', $rule->id)->count();
        $overlapCount = $service->overlapCount($rule);
        $current = $service->isCurrentlyValid($rule);

        return view('conversion.rules.show', compact('rule', 'usedCount', 'overlapCount', 'current'));
    }

    public function newVersion(ConversionRule $rule): View
    {
        return view('conversion.rules.form', [
            'base' => $rule->load('group'), 'programs' => BenefitProgram::query()->orderBy('name')->get(),
        ]);
    }

    public function storeVersion(Request $request, ConversionRule $rule, ConversionRuleService $service): RedirectResponse
    {
        $data = $this->validated($request, false);
        $new = $service->createRuleVersion($rule->group, $data, $rule, $request->boolean('deactivate_previous'));

        return redirect()->route('conversion.rules.show', $new)->with('status', '新しいバージョンを登録しました。');
    }

    public function updateNotes(Request $request, ConversionRule $rule, ConversionRuleService $service): RedirectResponse
    {
        $data = $request->validate([
            'instructions' => ['nullable', 'string'], 'official_url' => ['nullable', 'url:http,https'], 'notes' => ['nullable', 'string'],
        ]);
        $service->updateNonEconomicFields($rule, $data);

        return back()->with('status', '表示情報を更新しました。');
    }

    public function deactivate(ConversionRule $rule, ConversionRuleService $service): RedirectResponse
    {
        $service->deactivate($rule);

        return back()->with('status', '現在の候補から外しました。');
    }

    private function validated(Request $request, bool $newGroup): array
    {
        $rules = [
            'from_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'to_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'minimum_from_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'increment_from_quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'maximum_from_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'fee_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'fee_program_id' => ['nullable', 'integer', Rule::exists('benefit_programs', 'id')],
            'estimated_days_min' => ['nullable', 'integer', 'min:0'], 'estimated_days_max' => ['nullable', 'integer', 'min:0'],
            'duration_text' => ['nullable', 'string', 'max:150'], 'campaign_only' => ['nullable', 'boolean'],
            'campaign_name' => ['nullable', 'string', 'max:255'], 'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'], 'conditions_text' => ['nullable', 'string'],
            'instructions' => ['nullable', 'string'], 'official_url' => ['nullable', 'url:http,https'],
            'notes' => ['nullable', 'string'], 'active' => ['nullable', 'boolean'],
        ];
        if ($newGroup) $rules += [
            'from_program_id' => ['required', 'integer', Rule::exists('benefit_programs', 'id')],
            'to_program_id' => ['required', 'integer', 'different:from_program_id', Rule::exists('benefit_programs', 'id')],
            'name' => ['nullable', 'string', 'max:200'],
        ];
        $data = $request->validate($rules);
        $data['campaign_only'] = $request->boolean('campaign_only');
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
