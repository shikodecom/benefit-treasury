<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitProgramAlias extends Model
{
    protected $table = 'benefit_program_aliases';

    public const UPDATED_AT = null;

    public function program(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'program_id');
    }
}
