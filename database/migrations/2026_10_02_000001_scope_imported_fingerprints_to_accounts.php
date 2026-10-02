<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $rows = DB::table('imported_fingerprints as fingerprint')
                ->join('import_records as record', 'record.id', '=', 'fingerprint.import_record_id')
                ->leftJoin('benefit_transactions as transaction', function ($join): void {
                    $join->on('transaction.id', '=', 'record.target_id')
                        ->on('transaction.import_record_id', '=', 'record.id')
                        ->where('record.target_table', 'benefit_transactions');
                })
                ->get(['fingerprint.row_fingerprint as key', 'record.row_fingerprint as source',
                    'fingerprint.import_record_id', 'transaction.account_id']);

            foreach ($rows as $row) {
                if ($row->account_id === null) {
                    throw new RuntimeException('Cannot resolve the committed account for import record '.$row->import_record_id.'. No fingerprints were changed.');
                }
            }
            foreach ($rows as $row) {
                // Freeze the v1 format here so future service changes cannot alter this migration.
                DB::table('imported_fingerprints')->where('import_record_id', $row->import_record_id)
                    ->update(['row_fingerprint' => 'account-v1:'.hash('sha256', $row->account_id.':'.$row->source)]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $rows = DB::table('imported_fingerprints as fingerprint')
                ->join('import_records as record', 'record.id', '=', 'fingerprint.import_record_id')
                ->get(['fingerprint.import_record_id', 'record.row_fingerprint as source']);
            if ($rows->pluck('source')->unique()->count() !== $rows->count()) {
                throw new RuntimeException('Account-scoped imports share source fingerprints. Rollback would lose deduplication history; keep this migration and restore compatible application code.');
            }
            foreach ($rows as $row) {
                DB::table('imported_fingerprints')->where('import_record_id', $row->import_record_id)
                    ->update(['row_fingerprint' => $row->source]);
            }
        });
    }
};
