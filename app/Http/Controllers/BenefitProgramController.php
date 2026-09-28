<?php

namespace App\Http\Controllers;

use App\Domain\BenefitValues;
use App\Models\BenefitProgram;
use App\Models\BenefitProgramAlias;
use App\Services\BenefitProgramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BenefitProgramController extends Controller
{
    public function index(Request $request, BenefitProgramService $service): View
    {
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true)
            ? (int) $request->query('per_page') : 25;
        $programs = $service->search(
            trim((string) $request->query('q')) ?: null,
            $request->query('category'),
            in_array($request->query('status'), ['active', 'inactive', 'all'], true) ? $request->query('status') : 'active',
            $request->query('transferable') === null || $request->query('transferable') === '' ? null : $request->boolean('transferable'),
            $request->query('sellable') === null || $request->query('sellable') === '' ? null : $request->boolean('sellable'),
            $perPage,
        );

        return view('settings.programs.index', compact('programs'));
    }

    public function create(): View
    {
        return view('settings.programs.form', ['program' => new BenefitProgram]);
    }

    public function store(Request $request, BenefitProgramService $service): RedirectResponse
    {
        $program = $service->create($this->validated($request), $request->boolean('force_duplicate_name'));

        return redirect()->route('settings.programs.show', $program)->with('status', '特典制度を登録しました。');
    }

    public function show(BenefitProgram $program): View
    {
        $program->load(['aliases', 'accounts'])->loadCount('accounts');

        return view('settings.programs.show', compact('program'));
    }

    public function edit(BenefitProgram $program): View
    {
        return view('settings.programs.form', compact('program'));
    }

    public function update(Request $request, BenefitProgram $program, BenefitProgramService $service): RedirectResponse
    {
        $service->update($program, $this->validated($request), $request->boolean('force_duplicate_name'));

        return redirect()->route('settings.programs.show', $program)->with('status', '特典制度を更新しました。');
    }

    public function toggle(BenefitProgram $program, BenefitProgramService $service): RedirectResponse
    {
        $program->active ? $service->deactivate($program) : $service->activate($program);

        return back()->with('status', '利用状態を変更しました。');
    }

    public function addAlias(Request $request, BenefitProgram $program, BenefitProgramService $service): RedirectResponse
    {
        $data = $request->validate([
            'alias' => ['required', 'string', 'max:150'],
            'source_scope' => ['nullable', 'string', 'max:100'],
        ]);
        $service->addAlias($program, $data['alias'], $data['source_scope'] ?? null);

        return back()->with('status', '別名を追加しました。');
    }

    public function removeAlias(BenefitProgram $program, BenefitProgramAlias $alias, BenefitProgramService $service): RedirectResponse
    {
        $service->removeAlias($program, $alias);

        return back()->with('status', '別名を削除しました。');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'provider' => ['nullable', 'string', 'max:150'],
            'category' => ['required', Rule::in(BenefitValues::PROGRAM_CATEGORIES)],
            'unit_name' => ['required', 'string', 'max:30'],
            'default_unit_value_yen' => ['nullable', 'numeric', 'min:0'],
            'official_url' => ['nullable', 'url:http,https'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['transferable'] = $request->boolean('transferable');
        $data['sellable'] = $request->boolean('sellable');

        return $data;
    }
}
