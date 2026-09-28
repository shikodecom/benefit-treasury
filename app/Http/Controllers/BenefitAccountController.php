<?php

namespace App\Http\Controllers;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\BenefitTransferStep;
use App\Models\HouseholdMember;
use App\Services\BenefitAccountService;
use App\Services\BenefitProgramService;
use App\Services\HouseholdMemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BenefitAccountController extends Controller
{
    public function index(Request $request, BenefitAccountService $service): View
    {
        $perPage = in_array((int) $request->query('per_page'), [25, 50, 100], true)
            ? (int) $request->query('per_page') : 25;
        $accounts = $service->search(
            trim((string) $request->query('q')) ?: null,
            $request->integer('program_id') ?: null,
            $request->integer('household_member_id') ?: null,
            $request->query('category'),
            in_array($request->query('status'), ['active', 'inactive', 'all'], true) ? $request->query('status') : 'active',
            $perPage,
        );
        $balances = [];
        foreach ($accounts as $account) {
            $balances[$account->id] = $service->balance($account);
        }
        $programs = BenefitProgram::query()->orderBy('name')->get(['id', 'name']);
        $members = HouseholdMember::query()->orderBy('display_name')->get(['id', 'display_name']);

        return view('settings.accounts.index', compact('accounts', 'balances', 'programs', 'members'));
    }

    public function create(BenefitProgramService $programs, HouseholdMemberService $members): View
    {
        return view('settings.accounts.form', [
            'account' => new BenefitAccount,
            'programs' => $programs->listActive(),
            'members' => $members->listActive(),
        ]);
    }

    public function store(Request $request, BenefitAccountService $service): RedirectResponse
    {
        $account = $service->create($this->validated($request), $request->boolean('force_duplicate'));

        return redirect()->route('settings.accounts.show', $account)->with('status', '保有口座を登録しました。');
    }

    public function show(BenefitAccount $account, BenefitAccountService $service): View
    {
        $account->load(['program', 'householdMember'])->loadCount(['transactions', 'lots']);
        $balance = $service->balance($account);
        $lastTransaction = $account->transactions()->max('transaction_at');
        $processingTransfers = BenefitTransferStep::query()->where('status', 'processing')
            ->where(fn ($query) => $query->where('from_account_id', $account->id)
                ->orWhere('to_account_id', $account->id))->count();

        return view('settings.accounts.show', compact('account', 'balance', 'lastTransaction', 'processingTransfers'));
    }

    public function edit(BenefitAccount $account, BenefitProgramService $programs, HouseholdMemberService $members): View
    {
        $programOptions = $programs->listActive();
        if (! $programOptions->contains('id', $account->program_id)) {
            $programOptions->push($account->program);
        }
        $memberOptions = $members->listActive();
        if ($account->household_member_id && ! $memberOptions->contains('id', $account->household_member_id)) {
            $memberOptions->push($account->householdMember);
        }

        return view('settings.accounts.form', [
            'account' => $account,
            'programs' => $programOptions,
            'members' => $memberOptions,
        ]);
    }

    public function update(Request $request, BenefitAccount $account, BenefitAccountService $service): RedirectResponse
    {
        $service->update($account, $this->validated($request));

        return redirect()->route('settings.accounts.show', $account)->with('status', '保有口座を更新しました。');
    }

    public function toggle(BenefitAccount $account, BenefitAccountService $service): RedirectResponse
    {
        $account->active ? $service->deactivate($account) : $service->activate($account);

        return back()->with('status', '利用状態を変更しました。');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'program_id' => ['required', 'integer', Rule::exists('benefit_programs', 'id')],
            'household_member_id' => ['nullable', 'integer', Rule::exists('household_members', 'id')],
            'account_label' => ['nullable', 'string', 'max:150'],
            'external_account_hint' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
