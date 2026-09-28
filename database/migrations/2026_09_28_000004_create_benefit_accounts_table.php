<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('benefit_programs')->restrictOnDelete();
            $table->foreignId('household_member_id')->nullable()->constrained('household_members')->restrictOnDelete();
            $table->string('account_label', 150)->nullable();
            $table->string('external_account_hint', 100)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_accounts');
    }
};
