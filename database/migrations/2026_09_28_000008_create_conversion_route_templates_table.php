<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_route_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->foreignId('target_program_id')->nullable()->constrained('benefit_programs')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_route_templates');
    }
};
