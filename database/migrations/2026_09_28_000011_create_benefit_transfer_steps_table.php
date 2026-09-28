<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_transfer_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_group_id')->constrained('benefit_transfer_groups')->restrictOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->foreignId('from_account_id')->constrained('benefit_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('benefit_accounts')->restrictOnDelete();
            $table->foreignId('conversion_rule_id')->nullable()->constrained('conversion_rules')->restrictOnDelete();
            $table->date('started_at')->nullable();
            $table->date('expected_complete_at')->nullable()->index();
            $table->date('completed_at')->nullable();
            $table->decimal('source_quantity', 18, 4);
            $table->decimal('expected_destination_quantity', 18, 4)->nullable();
            $table->decimal('actual_destination_quantity', 18, 4)->nullable();
            $table->foreignId('planning_equivalent_program_id')->nullable()->constrained('benefit_programs')->restrictOnDelete();
            $table->decimal('planning_equivalent_quantity', 18, 4)->nullable();
            $table->string('status', 30)->default('planned')->index();
            $table->string('external_reference_hint', 100)->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();
            $table->unique(['transfer_group_id', 'sequence_no'], 'transfer_steps_sequence_unique');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE benefit_transfer_steps ADD CONSTRAINT transfer_source_positive CHECK (source_quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_transfer_steps');
    }
};
