<?php

namespace Tests\Feature;

use App\Services\BenefitReadService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BenefitSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_and_quantity_contract_exist(): void
    {
        $this->assertTrue(Schema::hasTable('benefit_transactions'));
        $this->assertTrue(Schema::hasTable('benefit_lots'));
        $this->assertTrue(Schema::hasTable('benefit_listing_items'));
        $this->assertTrue(Schema::hasTable('benefit_transfer_steps'));
        $this->assertFalse(Schema::hasColumn('benefit_lots', 'remaining_quantity'));
    }

    public function test_balance_and_listing_reservation_are_derived_from_history(): void
    {
        $programId = DB::table('benefit_programs')->insertGetId([
            'name' => 'Test券', 'category' => 'gift', 'unit_name' => '枚',
        ]);
        $accountId = DB::table('benefit_accounts')->insertGetId(['program_id' => $programId]);
        $lotId = DB::table('benefit_lots')->insertGetId(['account_id' => $accountId]);
        $this->transaction($accountId, $lotId, 'earn', 'in', '10');

        $draftId = DB::table('benefit_listings')->insertGetId(['marketplace' => 'test', 'status' => 'draft']);
        DB::table('benefit_listing_items')->insert(['listing_id' => $draftId, 'lot_id' => $lotId, 'quantity' => 2]);
        $listedId = DB::table('benefit_listings')->insertGetId(['marketplace' => 'test', 'status' => 'listed']);
        DB::table('benefit_listing_items')->insert(['listing_id' => $listedId, 'lot_id' => $lotId, 'quantity' => 4]);

        $service = app(BenefitReadService::class);
        $this->assertSame('10.0000', $service->accountBalance($accountId));
        $this->assertSame('10.0000', $service->lotRemainingQuantity($lotId));
        $this->assertSame('4.0000', $service->lotListedQuantity($lotId));
        $this->assertSame('6.0000', $service->lotAvailableQuantity($lotId));

        $this->transaction($accountId, $lotId, 'use', 'out', '2');
        $this->assertSame('8.0000', $service->lotRemainingQuantity($lotId));
        $this->assertSame('4.0000', $service->lotAvailableQuantity($lotId));
    }

    public function test_lot_transaction_must_belong_to_same_account(): void
    {
        $programId = DB::table('benefit_programs')->insertGetId([
            'name' => 'Test券', 'category' => 'gift', 'unit_name' => '枚',
        ]);
        $firstAccount = DB::table('benefit_accounts')->insertGetId(['program_id' => $programId]);
        $secondAccount = DB::table('benefit_accounts')->insertGetId(['program_id' => $programId]);
        $lotId = DB::table('benefit_lots')->insertGetId(['account_id' => $firstAccount]);

        $this->expectException(QueryException::class);
        $this->transaction($secondAccount, $lotId, 'earn', 'in', '1');
    }

    private function transaction(int $accountId, int $lotId, string $type, string $direction, string $quantity): void
    {
        DB::table('benefit_transactions')->insert([
            'account_id' => $accountId,
            'lot_id' => $lotId,
            'transaction_type' => $type,
            'quantity' => $quantity,
            'direction' => $direction,
            'transaction_at' => '2026-09-28',
            'source_type' => 'manual',
        ]);
    }
}
