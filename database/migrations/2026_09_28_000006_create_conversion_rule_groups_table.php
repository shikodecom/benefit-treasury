<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_rule_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('from_program_id')->constrained('benefit_programs')->restrictOnDelete();
            $table->foreignId('to_program_id')->constrained('benefit_programs')->restrictOnDelete();
            $table->string('name', 200)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE conversion_rule_groups ADD CONSTRAINT conversion_programs_different CHECK (from_program_id <> to_program_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_rule_groups');
    }
};
