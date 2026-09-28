<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_listings', function (Blueprint $table): void {
            $table->id();
            $table->string('marketplace', 100);
            $table->string('title', 255)->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('listed_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->bigInteger('listing_price_yen')->nullable();
            $table->timestamp('sold_at')->nullable()->index();
            $table->bigInteger('sold_price_yen')->nullable();
            $table->bigInteger('fee_yen')->nullable();
            $table->bigInteger('shipping_yen')->nullable();
            $table->bigInteger('net_proceeds_yen')->nullable();
            $table->text('listing_url')->nullable();
            $table->string('delivery_type', 30)->nullable();
            $table->timestamp('sale_reversed_at')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_listings');
    }
};
