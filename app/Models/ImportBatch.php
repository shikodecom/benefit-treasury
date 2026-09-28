<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $table = 'import_batches';

    public const UPDATED_AT = null;

    public function records(): HasMany
    {
        return $this->hasMany(ImportRecord::class, 'import_batch_id');
    }
}
