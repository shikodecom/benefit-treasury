<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_transfer_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200)->nullable();
            $table->foreignId('household_member_id')->nullable()->constrained('household_members')->restrictOnDelete();
            $table->string('purpose', 255)->nullable();
            $table->foreignId('target_program_id')->nullable()->constrained('benefit_programs')->restrictOnDelete();
            $table->decimal('target_quantity', 18, 4)->nullable();
            $table->string('status', 30)->default('planned');
            $table->date('started_at')->nullable();
            $table->date('expected_complete_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_transfer_groups');
    }
};
