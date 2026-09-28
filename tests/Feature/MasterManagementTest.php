<?php

namespace Tests\Feature;

use App\Models\BenefitAccount;
use App\Models\BenefitProgram;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\BenefitAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_pages_require_login(): void
    {
        $this->get('/settings/benefits')->assertRedirect('/login');
        $this->get('/settings/benefit-programs')->assertRedirect('/login');
        $this->get('/settings/benefit-accounts')->assertRedirect('/login');
    }

    public function test_login_and_logout_work_without_public_registration(): void
    {
        User::factory()->create(['email' => 'admin@example.invalid', 'password' => 'test-password-123']);

        $this->post('/login', ['email' => 'admin@example.invalid', 'password' => 'test-password-123'])
            ->assertRedirect('/settings/benefits');
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/register')->assertNotFound();
    }

    public function test_member_program_alias_and_account_can_be_registered(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('settings.members.store'), [
            'display_name' => ' Alice ', 'relation_type' => 'self',
        ])->assertRedirect();
        $member = HouseholdMember::query()->firstOrFail();
        $this->assertSame('Alice', $member->display_name);

        $this->post(route('settings.programs.store'), $this->programData())->assertRedirect();
        $program = BenefitProgram::query()->firstOrFail();
        $this->post(route('settings.programs.aliases.store', $program), ['alias' => 'TA'])
            ->assertRedirect();
        $this->get(route('settings.programs.index', ['q' => 'TA']))->assertSee('Test Assets');

        $this->post(route('settings.accounts.store'), [
            'program_id' => $program->id,
            'household_member_id' => $member->id,
            'account_label' => 'メイン',
        ])->assertRedirect();
        $account = BenefitAccount::query()->firstOrFail();
        $this->assertSame('0.0000', app(BenefitAccountService::class)->balance($account));
        $this->get(route('settings.accounts.show', $account))->assertSee('Test Assets')->assertSee('Alice');
    }

    public function test_invalid_url_and_inactive_master_cannot_be_used_for_new_account(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('settings.programs.store'), $this->programData(['official_url' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('official_url');
        $this->assertDatabaseCount('benefit_programs', 0);

        $program = $this->program();
        $program->active = false;
        $program->save();
        $this->post(route('settings.accounts.store'), ['program_id' => $program->id])
            ->assertSessionHasErrors('program_id');
        $this->assertDatabaseCount('benefit_accounts', 0);
    }

    public function test_duplicate_account_requires_explicit_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $program = $this->program();
        $data = ['program_id' => $program->id, 'account_label' => 'メイン'];
        $this->post(route('settings.accounts.store'), $data)->assertRedirect();
        $this->post(route('settings.accounts.store'), $data)->assertSessionHasErrors('account_label');
        $this->assertDatabaseCount('benefit_accounts', 1);
        $this->post(route('settings.accounts.store'), $data + ['force_duplicate' => '1'])->assertRedirect();
        $this->assertDatabaseCount('benefit_accounts', 2);
    }

    public function test_program_unit_is_locked_after_first_transaction(): void
    {
        $this->actingAs(User::factory()->create());
        $program = $this->program();
        $account = new BenefitAccount;
        $account->program_id = $program->id;
        $account->active = true;
        $account->save();
        DB::table('benefit_transactions')->insert([
            'account_id' => $account->id,
            'transaction_type' => 'earn',
            'quantity' => 1,
            'direction' => 'in',
            'transaction_at' => '2026-09-28',
            'source_type' => 'manual',
        ]);

        $this->put(route('settings.programs.update', $program), $this->programData(['unit_name' => '円']))
            ->assertSessionHasErrors('unit_name');
        $this->assertSame('pt', $program->fresh()->unit_name);
    }

    public function test_program_and_account_filters_show_the_requested_records_and_balance(): void
    {
        $this->actingAs(User::factory()->create());
        $alice = new HouseholdMember;
        $alice->display_name = 'Alice';
        $alice->relation_type = 'self';
        $alice->active = true;
        $alice->save();

        $point = $this->program();
        $point->provider = 'Example Provider';
        $point->transferable = true;
        $point->sellable = false;
        $point->save();
        $mile = $this->program();
        $mile->name = 'Other Miles';
        $mile->category = 'mile';
        $mile->transferable = false;
        $mile->sellable = true;
        $mile->save();

        $account = new BenefitAccount;
        $account->program_id = $point->id;
        $account->household_member_id = $alice->id;
        $account->external_account_hint = '末尾 1234';
        $account->active = true;
        $account->save();
        DB::table('benefit_transactions')->insert([
            'account_id' => $account->id, 'transaction_type' => 'earn', 'quantity' => 42,
            'direction' => 'in', 'transaction_at' => '2026-09-28', 'source_type' => 'manual',
        ]);

        $this->get(route('settings.programs.index', ['transferable' => '1', 'sellable' => '0']))
            ->assertOk()->assertSee('Test Assets')->assertDontSee('Other Miles');
        $this->get(route('settings.accounts.index', ['q' => '1234', 'program_id' => $point->id,
            'household_member_id' => $alice->id, 'category' => 'point']))
            ->assertOk()->assertSee('42.0000')->assertSee('Test Assets');
        $this->get(route('settings.accounts.index', ['category' => 'mile']))
            ->assertOk()->assertDontSee('残高 42.0000');

        $this->post(route('settings.accounts.toggle', $account))->assertRedirect();
        $this->get(route('settings.accounts.index'))->assertDontSee('残高 42.0000');
        $this->get(route('settings.accounts.index', ['status' => 'inactive']))->assertSee('42.0000');
        $this->get(route('settings.accounts.show', $account))->assertSee('42.0000')->assertSee('移行中件数');
    }

    public function test_all_master_forms_and_lists_render(): void
    {
        $this->actingAs(User::factory()->create());
        $program = $this->program();
        $member = new HouseholdMember;
        $member->display_name = 'Alice';
        $member->relation_type = 'self';
        $member->active = true;
        $member->save();
        $account = new BenefitAccount;
        $account->program_id = $program->id;
        $account->household_member_id = $member->id;
        $account->active = true;
        $account->save();

        foreach ([
            route('settings.home'),
            route('settings.members.index'),
            route('settings.members.create'),
            route('settings.members.edit', $member),
            route('settings.programs.index'),
            route('settings.programs.create'),
            route('settings.programs.show', $program),
            route('settings.programs.edit', $program),
            route('settings.accounts.index'),
            route('settings.accounts.create'),
            route('settings.accounts.show', $account),
            route('settings.accounts.edit', $account),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    private function program(): BenefitProgram
    {
        $program = new BenefitProgram;
        $program->name = 'Test Assets';
        $program->category = 'point';
        $program->unit_name = 'pt';
        $program->active = true;
        $program->save();

        return $program;
    }

    private function programData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Assets', 'category' => 'point', 'unit_name' => 'pt',
        ], $overrides);
    }
}
