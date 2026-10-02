<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitListing;
use App\Models\BenefitProgram;
use App\Models\BenefitProgramAlias;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\BenefitListingService;
use App\Services\BenefitLotService;
use App\Services\BenefitTransactionService;
use App\Services\BenefitTransferService;
use App\Services\ConversionRuleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 09:00', 'Asia/Tokyo'));
        $this->actingAs(User::factory()->create());
    }

    public function test_transfer_filters_apply_member_program_category_and_status_together(): void
    {
        $a = HouseholdMember::query()->forceCreate(['display_name' => '合成名義A']);
        $b = HouseholdMember::query()->forceCreate(['display_name' => '合成名義B']);
        $source = $this->program('合成ポイント', 'point');
        $destination = $this->program('合成マイル', 'mile');
        $fromA = $this->account($source, $a);
        $toA = $this->account($destination, $a);
        $groupA = $this->transfer($fromA, $toA, '名義A予定');
        $this->transfer($this->account($source, $b), $this->account($destination, $b), '名義B予定');
        $filters = ['member' => (string) $a->id, 'program' => $destination->id, 'category' => 'mile', 'transfer_status' => 'planned'];
        $this->get(route('search.index', $filters))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['extra']['transfers']->pluck('id')->all() === [$groupA->id]);
        $this->get(route('search.index', array_replace($filters, ['category' => 'point'])))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['extra']['transfers']->isEmpty());
        foreach ([$groupA, $this->transfer($this->account($source, $b), $this->account($destination, $b), '名義B超過')] as $group) {
            $step = $group->steps()->firstOrFail();
            app(BenefitTransactionService::class)->earn($step->fromAccount, '100', '2026-10-01');
            app(BenefitTransferService::class)->startStep($step, '2026-10-01');
        }
        $this->get(route('search.index', array_replace($filters, ['transfer_status' => 'overdue'])))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['extra']['transfers']->pluck('id')->all() === [$groupA->id]);
        $this->get(route('search.index', ['member' => [$a->id], 'preset' => 'overdue']))->assertOk()->assertSee('名義A予定')->assertDontSee('名義B超過');
        $this->get(route('search.index', ['member' => [$a->id], 'preset' => 'overdue', 'transfer_status' => 'planned']))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['extra']['transfers']->isEmpty());
    }

    public function test_common_conditions_match_one_endpoint_and_active_filter_excludes_inactive_accounts(): void
    {
        $a = HouseholdMember::query()->forceCreate(['display_name' => '名義A']);
        $b = HouseholdMember::query()->forceCreate(['display_name' => '名義B']);
        $source = $this->program('ポイント', 'point');
        $destination = $this->program('マイル', 'mile');
        $from = $this->account($source, $a);
        $to = $this->account($destination, $b);
        $transfer = $this->transfer($from, $to, '端点を分けた移行');
        $this->get(route('search.index', ['member' => [$a->id], 'program' => $destination->id, 'transfer_status' => 'planned']))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['extra']['transfers']->isEmpty());
        app(BenefitTransactionService::class)->earn($from, '100', '2026-10-01');
        $from->forceFill(['active' => false])->save();
        $filters = ['q' => 'ポイント', 'member' => [$a->id]];
        $this->get(route('search.index', $filters))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['accounts']->total() === 1 && $r['extra']['transfers']->contains('id', $transfer->id));
        $this->get(route('search.index', $filters + ['active_only' => 1]))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['accounts']->total() === 0 && $r['extra']['transactions']->isEmpty() && $r['extra']['transfers']->isEmpty());
        $rules = app(ConversionRuleService::class);
        $group = $rules->createRuleGroup(['from_program_id' => $source->id, 'to_program_id' => $destination->id]);
        $rules->createRuleVersion($group, ['from_quantity' => '100', 'to_quantity' => '50', 'active' => true]);
        $this->get(route('search.index', ['program' => $source->id, 'category' => 'mile']))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['extra']['rules']->isEmpty());
        $source->forceFill(['active' => false])->save();
        $this->get(route('search.index', ['program' => $source->id, 'active_only' => 1]))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['extra']['rules']->isEmpty());
    }

    public function test_status_and_account_conditions_must_match_the_same_transfer_step(): void
    {
        $a = HouseholdMember::query()->forceCreate(['display_name' => '合成名義A']);
        $b = HouseholdMember::query()->forceCreate(['display_name' => '合成名義B']);
        $first = $this->account($this->program('制度1'), $a);
        $middle = $this->account($this->program('制度2'), $b);
        $last = $this->account($this->program('制度3'), $b);
        $group = $this->transfer($first, $middle, '条件が別stepに分かれた移行');
        $second = app(BenefitTransferService::class)->addStep($group, [
            'from_account_id' => $middle->id, 'to_account_id' => $last->id, 'source_quantity' => '10',
            'expected_complete_at' => '2026-10-02',
        ]);
        $firstStep = $group->steps()->orderBy('sequence_no')->firstOrFail();
        app(BenefitTransactionService::class)->earn($first, '100', '2026-10-01');
        app(BenefitTransferService::class)->startStep($firstStep, '2026-10-01');
        app(BenefitTransferService::class)->completeStep($firstStep, '10', '2026-10-01');
        app(BenefitTransferService::class)->startStep($second, '2026-10-01');
        $this->get(route('search.index', ['member' => [$a->id], 'transfer_status' => 'overdue']))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['extra']['transfers']->isEmpty());
    }

    public function test_alias_matches_accounts_transactions_listings_transfers_and_rules(): void
    {
        $program = $this->program('合成ポイント');
        BenefitProgramAlias::query()->forceCreate(['program_id' => $program->id, 'alias' => '略称XYZ']);
        $account = $this->account($program);
        $to = $this->account($this->program('交換先'));
        $transaction = app(BenefitTransactionService::class)->earn($account, '100', '2026-10-01');
        $lot = app(BenefitLotService::class)->acquire($account, ['display_name' => '合成券', 'quantity' => '2', 'acquired_at' => '2026-10-01']);
        $listing = app(BenefitListingService::class)->createDraft(['title' => '合成出品', 'marketplace' => 'test'], [$lot->id => '1']);
        $transfer = $this->transfer($account, $to, '合成交換');
        $rules = app(ConversionRuleService::class);
        $ruleGroup = $rules->createRuleGroup(['from_program_id' => $account->program_id, 'to_program_id' => $to->program_id]);
        $rule = $rules->createRuleVersion($ruleGroup, ['from_quantity' => '100', 'to_quantity' => '50', 'active' => true]);
        foreach (['略称XYZ', '合成ポイント'] as $keyword) {
            $this->get(route('search.index', ['q' => $keyword]))->assertOk()->assertViewHas('results', fn ($r) => $r['accounts']->total() === 1 && $r['lots']->total() === 1
                && $r['extra']['transactions']->contains('id', $transaction->id)
                && $r['extra']['listings']->contains('id', $listing->id)
                && $r['extra']['transfers']->contains('id', $transfer->id)
                && $r['extra']['rules']->contains('id', $rule->id));
        }
    }

    public function test_member_selection_supports_multiple_holders_shared_and_legacy_scalar_urls(): void
    {
        $program = $this->program('合成ポイント');
        $a = HouseholdMember::query()->forceCreate(['display_name' => '合成名義A']);
        $b = HouseholdMember::query()->forceCreate(['display_name' => '合成名義B']);
        $c = HouseholdMember::query()->forceCreate(['display_name' => '合成名義C']);
        foreach ([$a, $b, $c, null] as $member) {
            app(BenefitTransactionService::class)->earn($this->account($program, $member), '100', '2026-10-01');
        }
        $this->get(route('search.index', ['member' => [$a->id, $b->id, 'shared']]))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['accounts']->total() === 3 && $r['extra']['transactions']->count() === 3)
            ->assertSee('合成名義A、合成名義B、家族共通')->assertViewHas('filters', fn ($f) => count($f['member']) === 3);
        $this->get(route('search.index', ['member' => $a->id]))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['accounts']->total() === 1);
        $this->get(route('search.index', ['member' => ['shared']]))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['accounts']->total() === 1);
        $this->getJson(route('search.index', ['member' => ['999999']]))->assertStatus(422);
        $this->getJson(route('search.index', ['member' => ['1 OR 1=1']]))->assertStatus(422);
        $this->getJson(route('search.index', ['member' => [['nested']]]))->assertStatus(422);
    }

    public function test_keyword_search_keeps_drafts_without_linked_inventory_or_transfer_steps(): void
    {
        $program = $this->program('目標制度');
        BenefitProgramAlias::query()->forceCreate(['program_id' => $program->id, 'alias' => '目標略称']);
        $group = app(BenefitTransferService::class)->createGroup(['name' => '合成下書き', 'target_program_id' => $program->id]);
        $listing = BenefitListing::query()->forceCreate(['marketplace' => 'test', 'title' => '合成下書き']);
        $this->get(route('search.index', ['q' => '合成下書き']))->assertOk()->assertViewHas('results', fn ($r) => $r['extra']['listings']->contains('id', $listing->id) && $r['extra']['transfers']->contains('id', $group->id));
        $this->get(route('search.index', ['q' => '目標略称']))->assertOk()->assertViewHas('results', fn ($r) => $r['extra']['transfers']->contains('id', $group->id));
        $this->get(route('search.index', ['q' => '合成下書き', 'member' => ['shared']]))->assertOk()->assertViewHas('results', fn ($r) => $r['extra']['listings']->isEmpty() && $r['extra']['transfers']->isEmpty());
    }

    public function test_history_includes_consumed_cancelled_lots_and_zero_accounts_without_changing_presets(): void
    {
        $account = $this->account($this->program('履歴制度'));
        $lots = app(BenefitLotService::class);
        $consumed = $lots->acquire($account, ['display_name' => '消費済み', 'quantity' => '1', 'acquired_at' => '2026-10-01']);
        $lots->use($consumed, '1', '2026-10-02');
        $cancelled = $lots->acquire($account, ['display_name' => '取消済み', 'quantity' => '1', 'acquired_at' => '2026-10-01']);
        $lots->cancel($cancelled);
        app(BenefitTransactionService::class)->earn($account, '100', '2026-10-01');
        app(BenefitTransactionService::class)->use($account, '100', '2026-10-02');
        $this->get(route('search.index'))->assertOk()->assertViewHas('results', fn ($r) => $r['lots']->total() === 0 && $r['accounts']->total() === 0);
        $this->get(route('search.index', ['include_history' => 1]))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['lots']->total() === 2 && $r['accounts']->total() === 1);
        $this->get(route('search.index', ['include_history' => 1, 'preset' => 'undecided']))->assertOk()
            ->assertViewHas('results', fn ($r) => $r['lots']->total() === 0);
    }

    public function test_common_filters_do_not_leak_into_unrelated_results_or_unscoped_rules(): void
    {
        $program = $this->program('合成ポイント');
        $a = HouseholdMember::query()->forceCreate(['display_name' => '合成名義A']);
        $b = HouseholdMember::query()->forceCreate(['display_name' => '合成名義B']);
        foreach ([$a, $b] as $member) {
            $account = $this->account($program, $member);
            app(BenefitTransactionService::class)->earn($account, '100', '2026-10-01', '共通キーワード');
            $lot = app(BenefitLotService::class)->acquire($account, ['quantity' => '2', 'acquired_at' => '2026-10-01']);
            app(BenefitListingService::class)->createDraft(['title' => '共通キーワード'.$member->display_name, 'marketplace' => 'test'], [$lot->id => '1']);
        }
        $filters = ['q' => '共通キーワード', 'member' => [$a->id], 'program' => $program->id, 'category' => 'point'];
        $this->get(route('search.index', $filters))->assertOk()->assertViewHas('results', fn ($r) => $r['extra']['transactions']->count() === 1 && $r['extra']['listings']->count() === 1 && $r['extra']['rules']->isEmpty());
        $this->get(route('search.index', array_replace($filters, ['expires_within' => '7'])))->assertOk()
            ->assertViewHas('results', fn ($r) => ! $r['show_extra'] && collect($r['extra'])->every(fn ($items) => $items->isEmpty()));
    }

    public function test_pagination_details_and_reset_keep_or_clear_search_conditions(): void
    {
        $member = HouseholdMember::query()->forceCreate(['display_name' => '合成名義']);
        $account = $this->account($this->program('合成制度'), $member);
        for ($i = 0; $i < 26; $i++) {
            app(BenefitLotService::class)->acquire($account, ['display_name' => '合成券'.$i, 'quantity' => '1', 'acquired_at' => '2026-10-01']);
        }
        $filters = ['member' => [(string) $member->id, 'shared'], 'q' => '合成券', 'include_history' => 1];
        $response = $this->get(route('search.index', $filters))->assertOk();
        $response->assertViewHas('results', function ($r): bool {
            parse_str(parse_url($r['lots']->nextPageUrl(), PHP_URL_QUERY), $query);

            return $query['member'] === ['1', 'shared'] && $query['include_history'] === '1' && $query['q'] === '合成券';
        });
        $paged = $this->get(route('search.index', $filters + ['lots_page' => 2]))->assertOk();
        $lot = $paged->viewData('results')['lots']->first();
        $detail = route('ledger.lots.show', ['lot' => $lot, 'search' => $filters + ['lots_page' => 2]]);
        $paged->assertSee(e($detail), false);
        $this->get($detail)->assertOk()->assertSee('検索結果へ戻る')
            ->assertSee(e(route('search.index', $filters + ['lots_page' => 2])), false);
        $this->get(route('search.index'))->assertOk()->assertViewHas('filters', fn ($f) => $f === []);
    }

    private function program(string $name, string $category = 'point'): BenefitProgram
    {
        return BenefitProgram::query()->forceCreate(['name' => $name, 'category' => $category, 'unit_name' => 'pt', 'active' => true]);
    }

    private function account(BenefitProgram $program, ?HouseholdMember $member = null): BenefitAccount
    {
        return BenefitAccount::query()->forceCreate(['program_id' => $program->id, 'household_member_id' => $member?->id, 'active' => true]);
    }

    private function transfer(BenefitAccount $from, BenefitAccount $to, string $name)
    {
        $service = app(BenefitTransferService::class);
        $group = $service->createGroup(['name' => $name, 'household_member_id' => $from->household_member_id]);
        $service->addStep($group, ['from_account_id' => $from->id, 'to_account_id' => $to->id,
            'source_quantity' => '10', 'expected_complete_at' => '2026-10-02']);

        return $group;
    }
}
