<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_programs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('provider', 150)->nullable();
            $table->string('category', 40);
            $table->string('unit_name', 30);
            $table->decimal('default_unit_value_yen', 12, 4)->nullable();
            $table->boolean('transferable')->default(false);
            $table->boolean('sellable')->default(false);
            $table->text('official_url')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_programs');
    }
};
