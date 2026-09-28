<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('benefit_accounts')->restrictOnDelete();
            $table->string('display_name', 200)->nullable();
            $table->date('acquired_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->string('action_policy', 40)->default('undecided')->index();
            $table->bigInteger('acquisition_cost_yen')->nullable();
            $table->bigInteger('face_value_yen')->nullable();
            $table->bigInteger('estimated_use_value_yen')->nullable();
            $table->bigInteger('estimated_sale_value_yen')->nullable();
            $table->text('usage_conditions')->nullable();
            $table->string('transfer_restriction', 40)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('memo')->nullable();
            $table->string('source', 100)->nullable();
            $table->timestamps();
            $table->unique(['id', 'account_id'], 'lots_id_account_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_lots');
    }
};
