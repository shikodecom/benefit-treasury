<?php

namespace App\Http\Controllers;

use App\Models\BenefitAccount;
use App\Services\BenefitAccountService;
use App\Services\BenefitReadService;
use Brick\Math\BigDecimal;
use Illuminate\View\View;

class BenefitLedgerController extends Controller
{
    public function index(BenefitAccountService $accounts, BenefitReadService $read): View
    {
        $items = BenefitAccount::query()->with(['program', 'householdMember'])
            ->where('active', true)->orderBy('id')->paginate(25);
        $balances = [];
        $expiring = [];
        $nextExpiry = [];
        foreach ($items as $account) {
            $balances[$account->id] = $accounts->balance($account);
            $activeLots = $account->lots()->whereNull('cancelled_at')->whereNotNull('expires_at')
                ->orderBy('expires_at')->get()
                ->filter(fn ($lot) => BigDecimal::of($read->lotRemainingQuantity($lot->id))->isGreaterThan(0));
            $expiring[$account->id] = $activeLots->count();
            $nextExpiry[$account->id] = $activeLots->first()?->expires_at;
        }

        return view('ledger.home', compact('items', 'balances', 'expiring', 'nextExpiry'));
    }
}
