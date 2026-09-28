<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitListing;
use App\Models\BenefitLot;
use App\Models\BenefitProgram;
use App\Models\User;
use App\Services\BenefitListingService;
use App\Services\BenefitLotService;
use App\Services\BenefitReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_publish_price_sale_and_reversal_keep_inventory_and_history(): void
    {
        $this->actingAs(User::factory()->create());
        $lot = $this->lot('2026-10-31');
        $service = app(BenefitListingService::class);
        $read = app(BenefitReadService::class);
        $listing = $service->createDraft(['marketplace' => 'ラクマ', 'listing_price_yen' => 1800, 'delivery_type' => 'digital'], [$lot->id => '4']);
        $this->assertSame('10.0000', $read->lotAvailableQuantity($lot->id));
        $service->publish($listing);
        $this->assertSame('10.0000', $read->lotRemainingQuantity($lot->id));
        $this->assertSame('4.0000', $read->lotListedQuantity($lot->id));
        $this->assertSame('6.0000', $read->lotAvailableQuantity($lot->id));
        $this->get(route('listings.show', $listing))->assertOk()->assertSee('ラクマ');
        $this->get(route('listings.edit', $listing))->assertOk()->assertSee('出品を編集');
        $this->get(route('listings.sell.form', $listing))->assertOk()->assertSee('売却を確定');
        $this->get(route('ledger.lots.show', $lot))->assertOk()->assertSee('出品状況');
        $service->changePrice($listing, 1500, '値下げ');
        $this->assertSame([1800, 1500], $listing->priceHistory()->orderBy('id')->pluck('price_yen')->all());
        $service->sell($listing, 1500, 150, 0, '2026-09-29');
        $this->assertSame('sold', $listing->fresh()->status);
        $this->assertSame(1350, (int) $listing->fresh()->net_proceeds_yen);
        $this->assertSame('6.0000', $read->lotRemainingQuantity($lot->id));
        $this->assertSame('0.0000', $read->lotListedQuantity($lot->id));
        $this->assertSame(1, $listing->transactions()->where('transaction_type', 'sell')->count());
        $service->reverseSale($listing);
        $this->assertNotNull($listing->fresh()->sale_reversed_at);
        $this->assertSame('10.0000', $read->lotRemainingQuantity($lot->id));
        $this->assertSame(1, $listing->transactions()->where('transaction_type', 'sell')->count());
        $this->expectException(ValidationException::class);
        $service->reverseSale($listing);
    }

    public function test_reservation_is_rechecked_and_restricted_lots_cannot_publish(): void
    {
        $lot = $this->lot('2026-10-31');
        $service = app(BenefitListingService::class);
        $first = $service->createDraft(['marketplace' => 'メルカリ'], [$lot->id => '6']);
        $second = $service->createDraft(['marketplace' => 'ラクマ'], [$lot->id => '6']);
        $service->publish($first);
        try {
            $service->publish($second);
            $this->fail('Over-reservation must fail');
        } catch (ValidationException) {
            $this->assertSame('draft', $second->fresh()->status);
        }
        $this->assertSame('6.0000', app(BenefitReadService::class)->lotListedQuantity($lot->id));
        $service->cancel($first);
        $service->publish($second);
        $this->assertSame('6.0000', app(BenefitReadService::class)->lotListedQuantity($lot->id));
        $service->endUnsold($second);
        $this->assertSame('0.0000', app(BenefitReadService::class)->lotListedQuantity($lot->id));
        $new = $service->relist($second);
        $this->assertNotSame($second->id, $new->id);
        $this->assertSame('draft', $new->status);

        $lot->transfer_restriction = 'non_transferable';
        $lot->save();
        $this->expectException(ValidationException::class);
        $service->publish($new);
    }

    public function test_multi_lot_sale_and_negative_proceeds_and_policy_confirmation(): void
    {
        $first = $this->lot('2026-09-30');
        $second = $this->lot('2027-03-31');
        $second->action_policy = 'self_use';
        $second->save();
        $service = app(BenefitListingService::class);
        $listing = $service->createDraft(['marketplace' => 'ラクマ'], [$first->id => '2', $second->id => '3']);
        try {
            $service->publish($listing);
            $this->fail('Policy confirmation required');
        } catch (ValidationException) {
            $this->assertSame('draft', $listing->fresh()->status);
        }
        try {
            $service->publish($listing, true);
            $this->fail('Mixed expiry confirmation required');
        } catch (ValidationException) {
            $this->assertSame('draft', $listing->fresh()->status);
        }
        $service->publish($listing, true, true);
        $service->sell($listing, 100, 80, 50, '2026-09-29');
        $this->assertSame(-30, (int) $listing->fresh()->net_proceeds_yen);
        $this->assertSame(2, $listing->transactions()->where('transaction_type', 'sell')->count());
        $this->assertSame('8.0000', app(BenefitReadService::class)->lotRemainingQuantity($first->id));
        $this->assertSame('7.0000', app(BenefitReadService::class)->lotRemainingQuantity($second->id));
    }

    public function test_listing_routes_require_login_and_validate_input(): void
    {
        $lot = $this->lot('2026-10-31');
        $this->get(route('listings.index'))->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->post(route('listings.store'), ['marketplace' => 'test', 'listing_url' => 'javascript:alert(1)', 'items' => [$lot->id => '4']])
            ->assertSessionHasErrors('listing_url');
        $this->post(route('listings.store'), ['marketplace' => 'test', 'items' => [$lot->id => '4']])->assertRedirect();
        $this->assertSame(1, BenefitListing::query()->count());
        $this->get(route('listings.index', ['status' => 'draft']))->assertOk()->assertSee('test');
        $this->get(route('listings.create', ['lot_id' => $lot->id]))->assertOk()->assertSee('テスト券');
    }

    public function test_listed_quantity_edit_rechecks_available_and_releases_old_reservation(): void
    {
        $lot = $this->lot('2026-10-31');
        $service = app(BenefitListingService::class);
        $first = $service->createDraft(['marketplace' => 'ラクマ', 'listing_price_yen' => 1000], [$lot->id => '4']);
        $second = $service->createDraft(['marketplace' => 'メルカリ'], [$lot->id => '4']);
        $service->publish($first);
        $service->publish($second);
        try {
            $service->updateListing($first, ['marketplace' => 'ラクマ'], [$lot->id => '7']);
            $this->fail('Over-reservation must fail');
        } catch (ValidationException) {
            $this->assertEquals(4, $first->items()->first()->quantity);
        }
        $service->updateListing($first, ['marketplace' => 'ラクマ'], [$lot->id => '2']);
        $this->assertSame('6.0000', app(BenefitReadService::class)->lotListedQuantity($lot->id));
        $this->assertSame('4.0000', app(BenefitReadService::class)->lotAvailableQuantity($lot->id));
    }

    private function lot(string $expiry): BenefitLot
    {
        $program = new BenefitProgram;
        $program->name = 'テスト優待';
        $program->category = 'shareholder_benefit';
        $program->unit_name = '枚';
        $program->active = true;
        $program->save();
        $account = new BenefitAccount;
        $account->program_id = $program->id;
        $account->active = true;
        $account->save();

        return app(BenefitLotService::class)->acquire($account, [
            'display_name' => 'テスト券', 'quantity' => '10', 'expires_at' => $expiry,
            'action_policy' => 'sell_now',
        ]);
    }
}
