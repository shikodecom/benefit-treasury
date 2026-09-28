<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->restrictOnDelete();
            $table->string('source_sheet', 150);
            $table->unsignedInteger('source_row_number');
            $table->string('row_fingerprint', 128)->unique();
            $table->string('record_type', 50)->nullable();
            $table->string('target_table', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('import_status', 30);
            $table->string('warning_code', 100)->nullable();
            $table->json('raw_data_json')->nullable();
            $table->json('normalized_data_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['import_batch_id', 'source_sheet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_records');
    }
};
