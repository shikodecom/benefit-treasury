<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_group_id')->constrained('conversion_rule_groups')->restrictOnDelete();
            $table->unsignedInteger('version_no');
            $table->foreignId('from_program_id')->constrained('benefit_programs')->restrictOnDelete();
            $table->foreignId('to_program_id')->constrained('benefit_programs')->restrictOnDelete();
            $table->decimal('from_quantity', 18, 4);
            $table->decimal('to_quantity', 18, 4);
            $table->decimal('minimum_from_quantity', 18, 4)->nullable();
            $table->decimal('increment_from_quantity', 18, 4)->nullable();
            $table->decimal('maximum_from_quantity', 18, 4)->nullable();
            $table->decimal('fee_quantity', 18, 4)->nullable();
            $table->foreignId('fee_program_id')->nullable()->constrained('benefit_programs')->restrictOnDelete();
            $table->unsignedInteger('estimated_days_min')->nullable();
            $table->unsignedInteger('estimated_days_max')->nullable();
            $table->string('duration_text', 150)->nullable();
            $table->boolean('campaign_only')->default(false);
            $table->string('campaign_name', 255)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('instructions')->nullable();
            $table->text('official_url')->nullable();
            $table->text('conditions_text')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['rule_group_id', 'version_no']);
            $table->index(['from_program_id', 'to_program_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE conversion_rules ADD CONSTRAINT conversion_from_positive CHECK (from_quantity > 0)');
            DB::statement('ALTER TABLE conversion_rules ADD CONSTRAINT conversion_to_nonnegative CHECK (to_quantity >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_rules');
    }
};
