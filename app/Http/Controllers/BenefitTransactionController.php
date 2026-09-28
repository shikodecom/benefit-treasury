<?php

namespace App\Http\Controllers;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\BenefitTransaction;
use App\Models\HouseholdMember;
use App\Services\BenefitAccountService;
use App\Services\BenefitLotService;
use App\Services\BenefitReadService;
use App\Services\BenefitTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BenefitTransactionController extends Controller
{
    public function index(Request $request): View
    {
        $query = BenefitTransaction::query()->with(['account.program', 'account.householdMember', 'lot']);
        foreach (['from' => '>=', 'to' => '<='] as $param => $operator) {
            if ($request->filled($param)) {
                $query->whereDate('transaction_at', $operator, $request->query($param));
            }
        }
        foreach (['account_id', 'transaction_type', 'direction', 'source_type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        foreach (['program_id' => 'program_id', 'household_member_id' => 'household_member_id'] as $param => $column) {
            if ($request->filled($param)) {
                $query->whereHas('account', fn ($account) => $account->where($column, $request->query($param)));
            }
        }
        if ($request->query('has_lot') === '1') {
            $query->whereNotNull('lot_id');
        } elseif ($request->query('has_lot') === '0') {
            $query->whereNull('lot_id');
        }
        if ($request->filled('q')) {
            $keyword = trim($request->query('q'));
            $query->where(fn ($query) => $query->where('merchant_or_purpose', 'like', "%{$keyword}%")
                ->orWhere('memo', 'like', "%{$keyword}%")
                ->orWhereHas('account', fn ($account) => $account->where('account_label', 'like', "%{$keyword}%")
                    ->orWhereHas('program', fn ($program) => $program->where('name', 'like', "%{$keyword}%"))));
        }

        $summary = (clone $query)->selectRaw('transaction_type, SUM(quantity) AS total')->groupBy('transaction_type')
            ->pluck('total', 'transaction_type');
        $transactions = $query->orderByDesc('transaction_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('ledger.transactions.index', [
            'transactions' => $transactions,
            'summary' => $summary,
            'accounts' => BenefitAccount::query()->with('program')->orderBy('id')->get(),
            'programs' => BenefitProgram::query()->orderBy('name')->get(),
            'members' => HouseholdMember::query()->orderBy('display_name')->get(),
        ]);
    }

    public function create(Request $request, BenefitAccountService $accounts): View
    {
        $options = BenefitAccount::query()->with(['program', 'householdMember'])
            ->where('active', true)->whereHas('program', fn ($query) => $query->where('active', true))
            ->orderBy('id')->get();
        $balances = [];
        foreach ($options as $account) {
            $balances[$account->id] = $accounts->balance($account);
        }

        return view('ledger.transactions.form', [
            'accounts' => $options, 'balances' => $balances,
            'selectedAccountId' => $request->query('account_id'),
            'selectedType' => $request->query('type', 'earn'),
        ]);
    }

    public function store(Request $request, BenefitTransactionService $service): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('benefit_accounts', 'id')],
            'transaction_type' => ['required', Rule::in(['opening_balance', 'earn', 'use', 'adjustment_in', 'adjustment_out'])],
            'transaction_at' => ['required', 'date'],
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'merchant_or_purpose' => ['nullable', 'string', 'max:255'],
            'value_yen' => ['nullable', 'integer', 'min:0'],
            'memo' => ['nullable', 'string', Rule::requiredIf(in_array($request->input('transaction_type'), ['adjustment_in', 'adjustment_out'], true))],
        ]);
        $account = BenefitAccount::query()->findOrFail($data['account_id']);
        $service->record($account, $data['transaction_type'], (string) $data['quantity'], $data['transaction_at'],
            $data['merchant_or_purpose'] ?? null, $data['value_yen'] ?? null, $data['memo'] ?? null);

        return redirect()->route('ledger.accounts.transactions', $account)->with('status', '取引を登録しました。');
    }

    public function account(BenefitAccount $account, BenefitAccountService $accounts): View
    {
        $account->load(['program', 'householdMember']);
        $transactions = $account->transactions()->with('lot')->orderByDesc('transaction_at')->orderByDesc('id')
            ->paginate(25);
        $balance = $accounts->balance($account);

        return view('ledger.transactions.account', compact('account', 'transactions', 'balance'));
    }

    public function useForm(BenefitAccount $account, BenefitLotService $lots, BenefitReadService $read): View
    {
        $account->load(['program', 'householdMember']);
        $availableLots = $account->lots()->whereNull('cancelled_at')
            ->orderByRaw('expires_at IS NULL')->orderBy('expires_at')->orderBy('id')->get()
            ->filter(fn ($lot) => (float) $lots->availableQuantity($lot) > 0);
        $available = [];
        foreach ($availableLots as $lot) {
            $available[$lot->id] = $lots->availableQuantity($lot);
        }
        $unallocated = $read->unallocatedBalance($account->id);

        return view('ledger.transactions.use', compact('account', 'availableLots', 'available', 'unallocated'));
    }

    public function allocateUse(Request $request, BenefitAccount $account, BenefitLotService $lots): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'transaction_at' => ['required', 'date'],
            'allocations' => ['nullable', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'unallocated_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'merchant_or_purpose' => ['nullable', 'string', 'max:255'],
            'value_yen' => ['nullable', 'integer', 'min:0'],
            'memo' => ['nullable', 'string'],
        ]);
        $lots->allocateUse($account, (string) $data['quantity'], $data['transaction_at'],
            $data['allocations'] ?? [], (string) ($data['unallocated_quantity'] ?? '0'),
            $data['merchant_or_purpose'] ?? null, $data['value_yen'] ?? null, $data['memo'] ?? null);

        return redirect()->route('ledger.accounts.transactions', $account)->with('status', '利用を記録しました。');
    }

    public function show(BenefitTransaction $transaction): View
    {
        $transaction->load(['account.program', 'account.householdMember', 'lot']);
        $reversal = BenefitTransaction::query()->where('reversal_of_transaction_id', $transaction->id)->first();

        return view('ledger.transactions.show', compact('transaction', 'reversal'));
    }

    public function reverse(BenefitTransaction $transaction, BenefitTransactionService $service): RedirectResponse
    {
        $service->reverse($transaction);

        return redirect()->route('ledger.transactions.show', $transaction)->with('status', '取消取引を記録しました。');
    }
}
