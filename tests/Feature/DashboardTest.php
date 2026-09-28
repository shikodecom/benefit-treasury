<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitLot;
use App\Models\BenefitProgram;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\BenefitDashboardService;
use App\Services\BenefitLotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_japan_dates_balances_and_active_listing_reservations(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 00:30:00', 'Asia/Tokyo'));
        $this->actingAs(User::factory()->create());
        $account = $this->account();
        $expired = $this->lot($account, '期限切れ', '2026-09-28', 'undecided', 100);
        $soon = $this->lot($account, 'すぐ期限', '2026-10-02', 'sell_now', 200);
        $later = $this->lot($account, '20日後', '2026-10-19', 'self_use', 300);
        $noExpiry = $this->lot($account, '期限なし', null, 'undecided', 400);
        $used = $this->lot($account, '使用済み', '2026-09-29', 'undecided', 500);
        app(BenefitLotService::class)->use($used, '2', '2026-09-29');

        $listing = DB::table('benefit_listings')->insertGetId(['marketplace' => 'test', 'status' => 'listed', 'listing_price_yen' => 200]);
        DB::table('benefit_listing_items')->insert(['listing_id' => $listing, 'lot_id' => $soon->id, 'quantity' => '1']);
        $draft = DB::table('benefit_listings')->insertGetId(['marketplace' => 'test', 'status' => 'draft']);
        DB::table('benefit_listing_items')->insert(['listing_id' => $draft, 'lot_id' => $soon->id, 'quantity' => '1']);

        $service = app(BenefitDashboardService::class);
        $lots = $service->lots();
        $this->assertSame(['期限切れ', 'すぐ期限', '20日後', '期限なし'], $lots->pluck('display_name')->all());
        $this->assertSame('1', (string) $lots->firstWhere('id', $soon->id)->listed_quantity);
        $this->assertSame(3, $service->daysUntilExpiry($soon));
        $this->assertSame('失効処理または期限修正', $service->recommendedAction($lots->firstWhere('id', $expired->id)));
        $this->assertSame('価格見直し', $service->recommendedAction($lots->firstWhere('id', $soon->id)));
        $this->assertSame(2, $service->urgentItems($lots)->count());
        $this->assertSame(1, $service->expiringWithin($lots, 7)->count());
        $this->assertSame(2, $service->expiringWithin($lots, 30)->count());
        $this->assertSame(2, $service->undecidedItems($lots)->count());
        $this->assertSame([
            'expired' => ['count' => 1, 'value' => 100],
            'within7' => ['count' => 1, 'value' => 200],
            'within30' => ['count' => 2, 'value' => 500],
            'listed' => ['count' => 1, 'value' => 200],
        ], $service->summary($lots));
        $this->get(route('dashboard.index'))->assertOk()->assertSee('期限切れ')->assertSee('すぐ期限')
            ->assertSee('20日後')->assertSee('期限なし')->assertDontSee('使用済み');
    }

    public function test_filters_include_inactive_accounts_and_policy_update_is_validated(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 00:30:00', 'Asia/Tokyo'));
        $this->get('/dashboard')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $member = new HouseholdMember;
        $member->display_name = '本人';
        $member->relation_type = 'self';
        $member->active = false;
        $member->save();
        $account = $this->account($member->id);
        $lot = $this->lot($account, '停止中の特典', '2026-10-01', 'undecided', null);
        $account->active = false;
        $account->save();
        $account->program->active = false;
        $account->program->save();

        $this->get(route('dashboard.index', ['member' => (string) $member->id, 'category' => 'point', 'expiry' => '7']))
            ->assertOk()->assertSee('停止中の特典')->assertSee('価値未設定');
        $this->get(route('dashboard.index', ['member' => 'shared']))->assertDontSee('停止中の特典');
        $this->post(route('dashboard.lots.policy', $lot), ['action_policy' => 'bad'])->assertSessionHasErrors('action_policy');
        $this->post(route('dashboard.lots.policy', $lot), ['action_policy' => 'self_use'])->assertRedirect();
        $this->assertSame('self_use', $lot->fresh()->action_policy);
    }

    public function test_priority_distinguishes_unlisted_partial_and_fully_listed_lots(): void
    {
        $this->travelTo(Carbon::parse('2026-09-29 00:30:00', 'Asia/Tokyo'));
        $account = $this->account();
        $unlisted = $this->lot($account, '未出品', '2026-10-02', 'sell_now', 100);
        $partial = $this->lot($account, '一部出品', '2026-10-02', 'sell_now', 100);
        $full = $this->lot($account, '全数出品', '2026-10-02', 'sell_now', 100);
        foreach ([[$partial, '1'], [$full, '2']] as [$lot, $quantity]) {
            $listing = DB::table('benefit_listings')->insertGetId(['marketplace' => 'test', 'status' => 'listed', 'listing_price_yen' => 100]);
            DB::table('benefit_listing_items')->insert(['listing_id' => $listing, 'lot_id' => $lot->id, 'quantity' => $quantity]);
        }
        $service = app(BenefitDashboardService::class);
        $lots = $service->lots();
        $this->assertSame([$partial->id, $unlisted->id, $full->id], $lots->pluck('id')->all());
        $this->assertSame(125, $service->calculatePriority($lots->firstWhere('id', $partial->id)));
        $this->assertSame(120, $service->calculatePriority($lots->firstWhere('id', $unlisted->id)));
        $this->assertSame(115, $service->calculatePriority($lots->firstWhere('id', $full->id)));
        $this->assertSame('出品する', $service->recommendedAction($lots->firstWhere('id', $unlisted->id)));
        $this->assertSame(['count' => 2, 'value' => 200], $service->summary($lots)['listed']);
    }

    private function account(?int $memberId = null): BenefitAccount
    {
        $program = new BenefitProgram;
        $program->name = 'テストポイント';
        $program->category = 'point';
        $program->unit_name = 'pt';
        $program->active = true;
        $program->save();
        $account = new BenefitAccount;
        $account->program_id = $program->id;
        $account->household_member_id = $memberId;
        $account->active = true;
        $account->save();

        return $account;
    }

    private function lot(BenefitAccount $account, string $name, ?string $expiry, string $policy, ?int $value): BenefitLot
    {
        return app(BenefitLotService::class)->acquire($account, [
            'display_name' => $name, 'quantity' => '2', 'expires_at' => $expiry,
            'action_policy' => $policy, 'face_value_yen' => $value,
            'estimated_sale_value_yen' => $value, 'estimated_use_value_yen' => $value,
        ]);
    }
}
