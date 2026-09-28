<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Models\BenefitProgram;
use App\Models\BenefitTransaction;
use App\Models\User;
use App\Services\BenefitReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_transactions_preserve_balance_and_prevent_overdraft(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'opening_balance', '100'))
            ->assertRedirect();
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'opening_balance', '100'))
            ->assertSessionHasErrors('transaction_type');
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'earn', '20'))
            ->assertRedirect();
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'use', '121'))
            ->assertSessionHasErrors('quantity');
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'use', '30'))
            ->assertRedirect();
        $this->assertSame('90.0000', app(BenefitReadService::class)->accountBalance($account->id));
        $this->get(route('ledger.accounts.transactions', $account))->assertOk()->assertSee('90.0000');
        $this->get(route('ledger.transactions.index'))->assertOk()->assertSee('Test Points');
        $this->get(route('ledger.home'))->assertOk()->assertSee('90.0000');
    }

    public function test_lot_acquisition_use_expiry_and_cancel_are_history_based(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        $this->post(route('ledger.lots.store'), [
            'account_id' => $account->id, 'display_name' => 'Dummy Coupon', 'quantity' => '5',
            'acquired_at' => '2026-09-01', 'expires_at' => '2026-10-01', 'action_policy' => 'undecided',
        ])->assertRedirect();
        $lot = BenefitLot::query()->firstOrFail();
        $this->assertDatabaseCount('benefit_transactions', 1);
        $this->assertSame('5.0000', app(BenefitReadService::class)->lotRemainingQuantity($lot->id));
        $this->post(route('ledger.lots.use', $lot), ['quantity' => '2', 'transaction_at' => '2026-09-02'])
            ->assertRedirect();
        $this->post(route('ledger.lots.use', $lot), ['quantity' => '4', 'transaction_at' => '2026-09-03'])
            ->assertSessionHasErrors('quantity');
        $this->assertSame('3.0000', app(BenefitReadService::class)->lotAvailableQuantity($lot->id));
        $this->post(route('ledger.lots.expire', $lot))->assertRedirect();
        $this->assertSame('0.0000', app(BenefitReadService::class)->lotRemainingQuantity($lot->id));
        $this->get(route('ledger.lots.show', $lot))->assertOk()->assertSee('Dummy Coupon');
        $this->get(route('ledger.lots.index'))->assertOk()->assertSee('Dummy Coupon');
    }

    public function test_reversal_is_single_use_and_lot_cancel_is_atomic(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'earn', '8'))
            ->assertRedirect();
        $transaction = BenefitTransaction::query()->firstOrFail();
        $this->post(route('ledger.transactions.reverse', $transaction))->assertRedirect();
        $this->post(route('ledger.transactions.reverse', $transaction))->assertSessionHasErrors('transaction');
        $this->assertSame('0.0000', app(BenefitReadService::class)->accountBalance($account->id));

        $this->post(route('ledger.lots.store'), [
            'account_id' => $account->id, 'quantity' => '2', 'action_policy' => 'undecided',
        ])->assertRedirect();
        $lot = BenefitLot::query()->firstOrFail();
        $this->post(route('ledger.lots.cancel', $lot))->assertRedirect();
        $this->assertNotNull($lot->fresh()->cancelled_at);
        $this->assertSame('0.0000', app(BenefitReadService::class)->lotRemainingQuantity($lot->id));
        $this->assertDatabaseCount('benefit_transactions', 4);
    }

    public function test_ledger_pages_require_login_and_adjustments_require_reason(): void
    {
        $this->get('/benefits')->assertRedirect('/login');
        $this->get('/lots')->assertRedirect('/login');
        $this->get('/transactions')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());
        $account = $this->account();
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'adjustment_in', '5'))
            ->assertSessionHasErrors('memo');
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'adjustment_in', '5') + ['memo' => 'Dummy reconciliation'])
            ->assertRedirect();
        $this->get(route('ledger.transactions.create'))->assertOk();
        $this->get(route('ledger.lots.create'))->assertOk();
    }

    public function test_unallocated_use_cannot_consume_lot_inventory(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        $this->post(route('ledger.lots.store'), [
            'account_id' => $account->id, 'quantity' => '5', 'action_policy' => 'undecided',
        ])->assertRedirect();
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'use', '1'))
            ->assertSessionHasErrors('quantity');
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'earn', '3'))
            ->assertRedirect();
        $this->post(route('ledger.transactions.store'), $this->transactionData($account, 'use', '3'))
            ->assertRedirect();
        $this->assertSame('5.0000', app(BenefitReadService::class)->accountBalance($account->id));
        $this->assertSame('5.0000', app(BenefitReadService::class)->lotRemainingQuantity(BenefitLot::query()->firstOrFail()->id));
    }

    public function test_failed_lot_acquisition_rolls_back_and_used_earn_cannot_be_reversed(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        $account->active = false;
        $account->save();
        $this->post(route('ledger.lots.store'), [
            'account_id' => $account->id, 'quantity' => '2', 'action_policy' => 'undecided',
        ])->assertSessionHasErrors('account_id');
        $this->assertDatabaseCount('benefit_lots', 0);

        $account->active = true;
        $account->save();
        $this->post(route('ledger.lots.store'), [
            'account_id' => $account->id, 'quantity' => '2', 'action_policy' => 'undecided',
        ])->assertRedirect();
        $lot = BenefitLot::query()->firstOrFail();
        $earn = $lot->transactions()->firstOrFail();
        $this->post(route('ledger.lots.use', $lot), ['quantity' => '1', 'transaction_at' => '2026-09-28'])
            ->assertRedirect();
        $this->post(route('ledger.transactions.reverse', $earn))->assertSessionHasErrors('transaction');
        $this->post(route('ledger.lots.cancel', $lot))->assertSessionHasErrors('lot');
        $this->assertSame('1.0000', app(BenefitReadService::class)->lotRemainingQuantity($lot->id));
    }

    public function test_multi_lot_use_allocates_atomically_and_allows_manual_adjustment(): void
    {
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        foreach (['2026-10-01', '2026-12-01'] as $expiry) {
            $this->post(route('ledger.lots.store'), [
                'account_id' => $account->id, 'quantity' => '2',
                'expires_at' => $expiry, 'action_policy' => 'undecided',
            ])->assertRedirect();
        }
        $lotIds = BenefitLot::query()->orderBy('expires_at')->pluck('id')->all();
        $this->get(route('ledger.accounts.use', $account))->assertOk()->assertSee('期限順に配分');
        $this->post(route('ledger.accounts.use.store', $account), [
            'quantity' => '3', 'transaction_at' => '2026-09-28',
            'allocations' => [$lotIds[0] => '2', $lotIds[1] => '2'],
            'unallocated_quantity' => '0',
        ])->assertSessionHasErrors('quantity');
        $this->assertSame('4.0000', app(BenefitReadService::class)->accountBalance($account->id));

        $this->post(route('ledger.accounts.use.store', $account), [
            'quantity' => '3', 'transaction_at' => '2026-09-28',
            'allocations' => [$lotIds[0] => '2', $lotIds[1] => '1'],
            'unallocated_quantity' => '0', 'value_yen' => '301',
        ])->assertRedirect();
        $this->assertSame('0.0000', app(BenefitReadService::class)->lotRemainingQuantity($lotIds[0]));
        $this->assertSame('1.0000', app(BenefitReadService::class)->lotRemainingQuantity($lotIds[1]));
        $this->assertSame('1.0000', app(BenefitReadService::class)->accountBalance($account->id));
        $this->assertSame(301, (int) BenefitTransaction::query()->where('transaction_type', 'use')->sum('value_yen'));
    }

    private function account(): BenefitAccount
    {
        $program = new BenefitProgram;
        $program->name = 'Test Points';
        $program->category = 'point';
        $program->unit_name = 'pt';
        $program->active = true;
        $program->save();

        $account = new BenefitAccount;
        $account->program_id = $program->id;
        $account->active = true;
        $account->save();

        return $account;
    }

    private function transactionData(BenefitAccount $account, string $type, string $quantity): array
    {
        return [
            'account_id' => $account->id, 'transaction_type' => $type,
            'transaction_at' => '2026-09-28', 'quantity' => $quantity,
        ];
    }
}
