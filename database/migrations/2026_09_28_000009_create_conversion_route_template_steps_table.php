<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_route_template_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('route_template_id')->constrained('conversion_route_templates')->restrictOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->foreignId('rule_group_id')->constrained('conversion_rule_groups')->restrictOnDelete();
            $table->foreignId('preferred_rule_id')->nullable()->constrained('conversion_rules')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['route_template_id', 'sequence_no'], 'route_steps_sequence_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_route_template_steps');
    }
};
