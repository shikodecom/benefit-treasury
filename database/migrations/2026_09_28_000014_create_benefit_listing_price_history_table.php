<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_listing_price_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained('benefit_listings')->restrictOnDelete();
            $table->bigInteger('price_yen');
            $table->timestamp('changed_at');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_listing_price_history');
    }
};
