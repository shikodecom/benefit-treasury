<?php

namespace App\Http\Controllers;

use App\Domain\BenefitValues;
use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Services\BenefitLotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BenefitLotController extends Controller
{
    public function index(BenefitLotService $service): View
    {
        $lots = BenefitLot::query()->with(['account.program', 'account.householdMember'])
            ->whereNull('cancelled_at')->orderByRaw('expires_at IS NULL')->orderBy('expires_at')
            ->orderBy('id')->paginate(25);
        $quantities = [];
        foreach ($lots as $lot) {
            $quantities[$lot->id] = [
                'remaining' => $service->remainingQuantity($lot),
                'listed' => $service->listedQuantity($lot),
                'available' => $service->availableQuantity($lot),
            ];
        }

        return view('ledger.lots.index', compact('lots', 'quantities'));
    }

    public function create(Request $request): View
    {
        return view('ledger.lots.form', [
            'lot' => new BenefitLot,
            'accounts' => BenefitAccount::query()->with(['program', 'householdMember'])
                ->where('active', true)->whereHas('program', fn ($query) => $query->where('active', true))
                ->orderBy('id')->get(),
            'selectedAccountId' => $request->query('account_id'),
        ]);
    }

    public function store(Request $request, BenefitLotService $service): RedirectResponse
    {
        $data = $this->validated($request, true);
        $lot = $service->acquire(BenefitAccount::query()->findOrFail($data['account_id']), $data);

        return redirect()->route('ledger.lots.show', $lot)->with('status', '特典ロットを取得登録しました。');
    }

    public function show(BenefitLot $lot, BenefitLotService $service): View
    {
        $lot->load(['account.program', 'account.householdMember']);
        $transactions = $lot->transactions()->orderByDesc('transaction_at')->orderByDesc('id')->get();
        $remaining = $service->remainingQuantity($lot);
        $listed = $service->listedQuantity($lot);
        $available = $service->availableQuantity($lot);
        $listings = $lot->listingItems()->with('listing')->orderByDesc('id')->get();

        return view('ledger.lots.show', compact('lot', 'transactions', 'remaining', 'listed', 'available', 'listings'));
    }

    public function edit(BenefitLot $lot): View
    {
        return view('ledger.lots.form', ['lot' => $lot->load('account.program')]);
    }

    public function update(Request $request, BenefitLot $lot, BenefitLotService $service): RedirectResponse
    {
        $service->update($lot, $this->validated($request, false));

        return redirect()->route('ledger.lots.show', $lot)->with('status', 'ロットを更新しました。');
    }

    public function use(Request $request, BenefitLot $lot, BenefitLotService $service): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'transaction_at' => ['required', 'date'],
            'merchant_or_purpose' => ['nullable', 'string', 'max:255'],
            'value_yen' => ['nullable', 'integer', 'min:0'],
            'memo' => ['nullable', 'string'],
        ]);
        $service->use($lot, (string) $data['quantity'], $data['transaction_at'],
            $data['merchant_or_purpose'] ?? null, $data['value_yen'] ?? null, $data['memo'] ?? null);

        return back()->with('status', '利用を記録しました。');
    }

    public function expire(BenefitLot $lot, BenefitLotService $service): RedirectResponse
    {
        $service->expireRemaining($lot, now('Asia/Tokyo')->toDateString());

        return back()->with('status', '残数を失効として記録しました。');
    }

    public function cancel(BenefitLot $lot, BenefitLotService $service): RedirectResponse
    {
        $service->cancel($lot);

        return back()->with('status', '登録を取り消しました。');
    }

    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'display_name' => ['nullable', 'string', 'max:200'],
            'acquired_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:acquired_at'],
            'action_policy' => ['required', Rule::in(BenefitValues::ACTION_POLICIES)],
            'acquisition_cost_yen' => ['nullable', 'integer', 'min:0'],
            'face_value_yen' => ['nullable', 'integer', 'min:0'],
            'estimated_use_value_yen' => ['nullable', 'integer', 'min:0'],
            'estimated_sale_value_yen' => ['nullable', 'integer', 'min:0'],
            'usage_conditions' => ['nullable', 'string'],
            'transfer_restriction' => ['nullable', 'string', 'max:40'],
            'memo' => ['nullable', 'string'],
        ];
        if ($creating) {
            $rules['account_id'] = ['required', 'integer', Rule::exists('benefit_accounts', 'id')];
            $rules['quantity'] = ['required', 'numeric', 'gt:0', 'decimal:0,4'];
        } else {
            $rules['account_id'] = ['prohibited'];
        }

        return $request->validate($rules);
    }
}
