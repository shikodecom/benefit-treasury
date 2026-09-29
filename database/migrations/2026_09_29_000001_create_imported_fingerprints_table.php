<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_records', function (Blueprint $table): void {
            $table->dropUnique('import_records_row_fingerprint_unique');
            $table->index('row_fingerprint');
        });
        Schema::create('imported_fingerprints', function (Blueprint $table): void {
            $table->string('row_fingerprint', 128)->primary();
            $table->foreignId('import_record_id')->unique()->constrained('import_records')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imported_fingerprints');
        Schema::table('import_records', function (Blueprint $table): void {
            $table->dropIndex('import_records_row_fingerprint_index');
            $table->unique('row_fingerprint');
        });
    }
};
