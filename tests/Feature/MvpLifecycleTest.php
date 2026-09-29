<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\User;
use App\Services\BenefitListingService;
use App\Services\BenefitLotService;
use App\Services\BenefitReadService;
use App\Services\BenefitSearchService;
use App\Services\BenefitTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvpLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_e2e_01_shareholder_lot_from_acquisition_through_expiry(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('Synthetic voucher', 'shareholder_benefit', '枚');
        $lots = app(BenefitLotService::class);
        $listings = app(BenefitListingService::class);
        $read = app(BenefitReadService::class);
        $lot = $lots->acquire($account, [
            'display_name' => 'Synthetic voucher lot', 'quantity' => '10',
            'acquired_at' => today('Asia/Tokyo')->toDateString(),
            'expires_at' => today('Asia/Tokyo')->addDays(7)->toDateString(),
            'action_policy' => 'sell_now',
        ]);
        $listing = $listings->createDraft(['marketplace' => 'Synthetic market', 'listing_price_yen' => 1800], [$lot->id => '4']);
        $listings->publish($listing);
        $this->assertSame('4.0000', $read->lotListedQuantity($lot->id));
        $this->assertSame('6.0000', $read->lotAvailableQuantity($lot->id));
        $this->get('/dashboard')->assertOk()->assertSee('Synthetic voucher lot');

        $listings->sell($listing, 1800, 0, 0, today('Asia/Tokyo')->toDateString());
        $this->assertSame('6.0000', $read->lotRemainingQuantity($lot->id));
        $lots->use($lot, '2', today('Asia/Tokyo')->toDateString());
        $this->assertSame('4.0000', $read->lotRemainingQuantity($lot->id));
        $this->travel(8)->days();
        $this->get(route('ledger.lots.show', $lot))->assertOk()->assertSee('期限切れ');
        $lots->expireRemaining($lot, today('Asia/Tokyo')->toDateString());
        $this->assertSame('0.0000', $read->lotRemainingQuantity($lot->id));
        $this->assertSame(4, $lot->transactions()->count());
        $this->assertSame('sold', $listing->fresh()->status);
    }

    public function test_e2e_02_miles_balance_matches_ledger_search_and_dashboard_after_reversal(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account('Synthetic ANA miles', 'mile', 'mile');
        $transactions = app(BenefitTransactionService::class);
        $read = app(BenefitReadService::class);
        $date = today('Asia/Tokyo')->toDateString();
        $transactions->createOpeningBalance($account, $date, '25608', null);
        $transactions->earn($account, '2500', $date);
        $transactions->earn($account, '318', $date);
        $used = $transactions->use($account, '15000', $date, 'Synthetic redemption');
        $this->assertSame('13426.0000', $read->accountBalance($account->id));
        $transactions->reverse($used);
        $this->assertSame('28426.0000', $read->accountBalance($account->id));
        $this->assertSame(1, app(BenefitSearchService::class)->search(['q' => 'Synthetic ANA miles'])['accounts']->total());
        $this->get(route('ledger.accounts.transactions', $account))->assertOk()->assertSee('28426.0000');
        $this->get('/dashboard')->assertOk()->assertSee('Synthetic ANA miles')->assertSee('28426.0000');
        $this->assertSame(5, $account->transactions()->count());
    }

    private function account(string $name, string $category, string $unit): BenefitAccount
    {
        $program = new BenefitProgram;
        $program->name = $name;
        $program->category = $category;
        $program->unit_name = $unit;
        $program->active = true;
        $program->sellable = true;
        $program->save();

        $account = new BenefitAccount;
        $account->program_id = $program->id;
        $account->active = true;
        $account->save();

        return $account;
    }
}
