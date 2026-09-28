<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_listing_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained('benefit_listings')->restrictOnDelete();
            $table->foreignId('lot_id')->constrained('benefit_lots')->restrictOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->timestamp('created_at')->nullable();
            $table->unique(['listing_id', 'lot_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE benefit_listing_items ADD CONSTRAINT listing_items_quantity_positive CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_listing_items');
    }
};
