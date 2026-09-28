<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('benefit_accounts')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable();
            $table->string('transaction_type', 40)->index();
            $table->decimal('quantity', 18, 4);
            $table->string('direction', 10);
            $table->date('transaction_at');
            $table->bigInteger('value_yen')->nullable();
            $table->bigInteger('acquisition_cost_yen')->nullable();
            $table->string('merchant_or_purpose', 255)->nullable();
            $table->foreignId('transfer_step_id')->nullable()->constrained('benefit_transfer_steps')->restrictOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('benefit_listings')->restrictOnDelete();
            $table->foreignId('reversal_of_transaction_id')->nullable()->unique()->constrained('benefit_transactions')->restrictOnDelete();
            $table->text('memo')->nullable();
            $table->string('source_type', 40);
            $table->foreignId('import_record_id')->nullable()->constrained('import_records')->restrictOnDelete();
            $table->timestamps();
            $table->foreign(['lot_id', 'account_id'], 'transactions_lot_account_fk')
                ->references(['id', 'account_id'])->on('benefit_lots')->restrictOnDelete();
            $table->index(['account_id', 'transaction_at']);
            $table->index(['lot_id', 'transaction_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE benefit_transactions ADD CONSTRAINT transactions_quantity_positive CHECK (quantity > 0)');
            DB::statement("ALTER TABLE benefit_transactions ADD CONSTRAINT transactions_direction_valid CHECK (direction IN ('in', 'out'))");
            DB::statement("ALTER TABLE benefit_transactions ADD CONSTRAINT transactions_type_direction_valid CHECK (
                (transaction_type IN ('opening_balance', 'earn', 'transfer_in', 'adjustment_in') AND direction = 'in')
                OR (transaction_type IN ('use', 'transfer_out', 'sell', 'expire', 'adjustment_out') AND direction = 'out')
                OR (transaction_type = 'reversal' AND direction IN ('in', 'out'))
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_transactions');
    }
};
