<?php

namespace App\Services\Imports;

use App\Models\ImportRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MappedImportService
{
    public function __construct(private readonly TransferSheetImporter $transfers, private readonly ConversionRuleSheetImporter $rules) {}

    public function importer(string $sheet): ?MappedSheetImporter
    {
        foreach ([$this->transfers, $this->rules] as $importer) {
            if ($importer->supports($sheet)) {
                return $importer;
            }
        }

        return null;
    }

    public function preview(ImportRecord $record, array $mapping, ?array $override = null): void
    {
        $importer = $this->importer($record->source_sheet);
        $rowOptions = $record->normalized_data_json['_row_options'] ?? [];
        if ($override !== null) {
            $rowOptions = array_replace($rowOptions, $override);
        }
        $options = array_replace($mapping, $rowOptions);
        $data = ['_mapping' => $options, '_row_options' => $rowOptions];
        $record->error_message = null;
        if (($options['action'] ?? '') === 'skip') {
            $record->normalized_data_json = $data;
            $record->import_status = 'skipped_out_of_scope';
            $record->warning_code = null;
            $record->save();

            return;
        }
        try {
            $data = array_merge($importer->normalize($record, $options), $data);
            $record->normalized_data_json = $data;
            if ($this->duplicate($record, $importer, $data)) {
                return;
            }
            $importer->validatePreview($data);
            $record->import_status = 'ready';
            $record->warning_code = null;
        } catch (ValidationException $exception) {
            $record->normalized_data_json = $data;
            $record->import_status = 'needs_review';
            $record->warning_code = $exception->errors()['import'][0] ?? 'mapping_required';
        }
        $record->save();
    }

    private function duplicate(ImportRecord $record, MappedSheetImporter $importer, array $data): bool
    {
        if (! DB::table('imported_fingerprints')->where('row_fingerprint', $importer->scopeKey($record, $data))->exists()) {
            return false;
        }
        $record->import_status = 'skipped_duplicate';
        $record->warning_code = 'duplicate';
        $record->save();

        return true;
    }

    public function execute(ImportRecord $record): void
    {
        $importer = $this->importer($record->source_sheet);
        $previous = $record->normalized_data_json;
        if ($this->duplicate($record, $importer, $previous)) {
            return;
        }
        $data = array_merge($importer->normalize($record, $previous['_mapping']), [
            '_mapping' => $previous['_mapping'], '_row_options' => $previous['_row_options'],
        ]);
        if (DB::table('imported_fingerprints')->insertOrIgnore([
            'row_fingerprint' => $importer->scopeKey($record, $data), 'import_record_id' => $record->id, 'created_at' => now(),
        ]) === 0) {
            $record->import_status = 'skipped_duplicate';
            $record->warning_code = 'duplicate';
            $record->save();

            return;
        }
        [$table, $id] = $importer->commit($data);
        $record->normalized_data_json = $data;
        $record->target_table = $table;
        $record->target_id = $id;
        $record->import_status = 'imported';
        $record->warning_code = null;
        $record->error_message = null;
        $record->save();
    }
}
