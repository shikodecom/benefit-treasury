<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benefit_program_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained('benefit_programs')->restrictOnDelete();
            $table->string('alias', 150);
            $table->string('source_scope', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['alias', 'source_scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benefit_program_aliases');
    }
};
