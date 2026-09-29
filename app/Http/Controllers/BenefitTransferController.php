<?php

namespace App\Http\Controllers;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\BenefitTransferGroup;
use App\Models\BenefitTransferStep;
use App\Models\HouseholdMember;
use App\Services\BenefitReadService;
use App\Services\BenefitTransferService;
use App\Services\ConversionRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BenefitTransferController extends Controller
{
    public function index(Request $request, BenefitTransferService $service): View
    {
        $status = $request->query('status', 'processing');
        if (! in_array($status, ['processing', 'planned', 'overdue', 'completed', 'error', 'cancelled', 'all'], true)) {
            $status = 'processing';
        }
        $query = BenefitTransferGroup::query()->with(['steps.fromAccount.program', 'steps.toAccount.program', 'steps.planningEquivalentProgram', 'targetProgram']);
        if ($status === 'overdue') {
            $query->whereHas('steps', fn ($query) => $query->where('status', 'processing')
                ->whereDate('expected_complete_at', '<', now('Asia/Tokyo')->toDateString()));
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $groups = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('transfers.index', compact('groups', 'status'));
    }

    public function create(): View
    {
        return view('transfers.create', [
            'programs' => BenefitProgram::query()->where('active', true)->orderBy('name')->get(),
            'members' => HouseholdMember::query()->where('active', true)->orderBy('display_name')->get(),
        ]);
    }

    public function store(Request $request, BenefitTransferService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:200'], 'household_member_id' => ['nullable', 'integer', Rule::exists('household_members', 'id')],
            'purpose' => ['nullable', 'string', 'max:255'], 'target_program_id' => ['nullable', 'integer', Rule::exists('benefit_programs', 'id')],
            'target_quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'expected_complete_at' => ['nullable', 'date'], 'memo' => ['nullable', 'string'],
        ]);
        if (isset($data['target_quantity']) && ! isset($data['target_program_id'])) {
            throw ValidationException::withMessages(['target_program_id' => '目標数量を入力する場合は最終目的制度も選択してください。']);
        }
        $group = $service->createGroup($data);

        return redirect()->route('transfers.steps.create', $group)->with('status', '移行計画を作成しました。最初のステップを追加してください。');
    }

    public function show(BenefitTransferGroup $group): View
    {
        $group->load(['targetProgram', 'householdMember', 'steps.fromAccount.program', 'steps.toAccount.program',
            'steps.planningEquivalentProgram', 'steps.conversionRule']);
        $steps = $group->steps->sortBy('sequence_no')->values();

        return view('transfers.show', compact('group', 'steps'));
    }

    public function stepCreate(BenefitTransferGroup $group, BenefitReadService $read, ConversionRuleService $conversionRules): View
    {
        $accounts = BenefitAccount::query()->with(['program', 'householdMember'])->where('active', true)
            ->whereHas('program', fn ($query) => $query->where('active', true))->orderBy('id')->get();
        $balances = [];
        foreach ($accounts as $account) {
            $balances[$account->id] = $read->accountBalance($account->id);
        }
        $rules = $conversionRules->currentRules();
        $programs = BenefitProgram::query()->where('active', true)->orderBy('name')->get();
        $last = $group->steps()->orderByDesc('sequence_no')->first();

        return view('transfers.step-form', compact('group', 'accounts', 'balances', 'rules', 'programs', 'last'));
    }

    public function addStep(Request $request, BenefitTransferGroup $group, BenefitTransferService $service): RedirectResponse
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', Rule::exists('benefit_accounts', 'id')],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', Rule::exists('benefit_accounts', 'id')],
            'source_quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'expected_destination_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'planning_equivalent_program_id' => ['nullable', 'integer', Rule::exists('benefit_programs', 'id')],
            'planning_equivalent_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'conversion_rule_id' => ['nullable', 'integer', Rule::exists('conversion_rules', 'id')],
            'expected_complete_at' => ['nullable', 'date'], 'external_reference_hint' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string'],
        ]);
        $service->addStep($group, $data);

        return redirect()->route('transfers.show', $group)->with('status', '移行ステップを追加しました。');
    }

    public function start(Request $request, BenefitTransferStep $step, BenefitTransferService $service): RedirectResponse
    {
        $data = $request->validate(['started_at' => ['required', 'date']]);
        $service->startStep($step, $data['started_at']);

        return redirect()->route('transfers.show', $step->transfer_group_id)->with('status', '申請と移行元の残高減算を記録しました。');
    }

    public function completeForm(BenefitTransferStep $step): View
    {
        abort_unless(in_array($step->status, ['processing', 'error'], true), 404);
        $step->load(['fromAccount.program', 'toAccount.program']);

        return view('transfers.complete', compact('step'));
    }

    public function complete(Request $request, BenefitTransferStep $step, BenefitTransferService $service): RedirectResponse
    {
        $data = $request->validate(['completed_at' => ['required', 'date'], 'actual_destination_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,4']]);
        $service->completeStep($step, (string) $data['actual_destination_quantity'], $data['completed_at']);

        return redirect()->route('transfers.show', $step->transfer_group_id)->with('status', '着弾と移行先の残高加算を記録しました。');
    }

    public function cancelPlanned(BenefitTransferStep $step, BenefitTransferService $service): RedirectResponse
    {
        $service->cancelPlannedStep($step);

        return back()->with('status', '計画を取り消しました。');
    }

    public function cancelWithRefund(Request $request, BenefitTransferStep $step, BenefitTransferService $service): RedirectResponse
    {
        $data = $request->validate(['memo' => ['required', 'string']]);
        $service->cancelProcessingStepWithReversal($step, $data['memo']);

        return back()->with('status', '移行を取り消し、返還を記録しました。');
    }

    public function markError(Request $request, BenefitTransferStep $step, BenefitTransferService $service): RedirectResponse
    {
        $data = $request->validate(['memo' => ['required', 'string']]);
        $service->markError($step, $data['memo']);

        return back()->with('status', 'エラーとして記録しました。残高は変更していません。');
    }

    public function updateExpectedDate(Request $request, BenefitTransferStep $step, BenefitTransferService $service): RedirectResponse
    {
        $data = $request->validate(['expected_complete_at' => ['required', 'date'], 'memo' => ['nullable', 'string']]);
        $service->updateExpectedDate($step, $data['expected_complete_at'], $data['memo'] ?? null);

        return back()->with('status', '着弾予定日を更新しました。');
    }
}
