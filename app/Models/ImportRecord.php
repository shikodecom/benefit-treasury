<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRecord extends Model
{
    protected $table = 'import_records';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['raw_data_json' => 'array', 'normalized_data_json' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
