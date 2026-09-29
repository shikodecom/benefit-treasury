<?php

namespace App\Http\Controllers;

use App\Models\BenefitProgram;
use App\Models\ConversionRouteTemplate;
use App\Models\ConversionRule;
use App\Models\ConversionRuleGroup;
use App\Services\ConversionRouteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConversionRouteController extends Controller
{
    public function index(Request $request, ConversionRouteService $service): View
    {
        $status = $request->query('status', 'active');
        if (! in_array($status, ['active', 'inactive', 'all'], true)) {
            $status = 'active';
        }
        $query = ConversionRouteTemplate::query()->with(['targetProgram', 'steps.ruleGroup.fromProgram', 'steps.ruleGroup.toProgram']);
        if ($status !== 'all') {
            $query->where('active', $status === 'active');
        }
        $routes = $query->orderBy('name')->paginate(20)->withQueryString();
        $usable = [];
        foreach ($routes as $route) {
            $usable[$route->id] = $service->canUse($route);
        }

        return view('conversion.routes.index', compact('routes', 'status', 'usable'));
    }

    public function create(): View
    {
        return view('conversion.routes.form', [
            'programs' => BenefitProgram::query()->where('active', true)->orderBy('name')->get(),
            'groups' => ConversionRuleGroup::query()->with(['fromProgram', 'toProgram'])->orderBy('id')->get(),
            'rules' => ConversionRule::query()->with(['fromProgram', 'toProgram'])->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request, ConversionRouteService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'], 'target_program_id' => ['nullable', 'integer', Rule::exists('benefit_programs', 'id')],
            'description' => ['nullable', 'string'], 'active' => ['nullable', 'boolean'],
            'steps' => ['required', 'array'], 'steps.*.rule_group_id' => ['nullable', 'integer', Rule::exists('conversion_rule_groups', 'id')],
            'steps.*.preferred_rule_id' => ['nullable', 'integer', Rule::exists('conversion_rules', 'id')],
        ]);
        $steps = array_values(array_filter($data['steps'], fn ($step) => ! empty($step['rule_group_id'])));
        $route = $service->createRoute($data + ['active' => $request->boolean('active')], $steps);

        return redirect()->route('conversion.routes.show', $route)->with('status', '交換ルートを登録しました。');
    }

    public function show(Request $request, ConversionRouteTemplate $route, ConversionRouteService $service): View
    {
        $route->load(['targetProgram', 'steps.ruleGroup.fromProgram', 'steps.ruleGroup.toProgram', 'steps.preferredRule']);
        $steps = $route->steps->sortBy('sequence_no')->values();
        $resolved = [];
        foreach ($steps as $step) {
            $resolved[$step->id] = [
                'rule' => $service->resolveCurrentRule($step),
                'candidates' => $service->availableRulesForStep($step),
            ];
        }
        $input = $request->validate(['quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4']]);
        $simulation = isset($input['quantity']) ? $service->simulate($route, (string) $input['quantity']) : null;
        $usable = $service->canUse($route);

        return view('conversion.routes.show', compact('route', 'steps', 'resolved', 'simulation', 'usable', 'input'));
    }

    public function deactivate(ConversionRouteTemplate $route): RedirectResponse
    {
        $route->active = false;
        $route->save();

        return back()->with('status', 'ルートを現在の候補から外しました。');
    }
}
